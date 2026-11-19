<?php

namespace MediaWiki\Extension\Timeline\Tests\Integration;

use MediaWiki\Extension\Timeline\Timeline;
use MediaWiki\Extension\Timeline\TimelineException;
use MediaWiki\MainConfigNames;
use MediaWiki\Shell\CommandFactory;
use MediaWikiIntegrationTestCase;
use ReflectionClass;
use Shellbox\Command\BoxedCommand;
use Shellbox\Command\BoxedExecutor;
use Shellbox\Command\BoxedResult;
use Wikimedia\FileBackend\MemoryFileBackend;

/**
 * What Timeline.php does with the output of the renderer: the error report (file.err), the
 * output of scripts/renderTimeline.sh, and the files.
 *
 * @covers \MediaWiki\Extension\Timeline\Timeline
 */
class TimelineErrorTest extends MediaWikiIntegrationTestCase {

	private const REPORT_START = "<p>EasyTimeline 1.90</p><p><b>Timeline generation failed: 1 error found</b></p>\n";

	/** Message timeline-rsvg-error */
	private const RSVG_ERROR = 'Unable to convert SVG to PNG';

	/** Message timeline-compilererr */
	private const COMPILER_ERROR = 'Unable to compile EasyTimeline input';

	/**
	 * @param string $method Private method of Timeline that throws
	 * @param mixed ...$args
	 */
	private function getException( string $method, ...$args ): TimelineException {
		try {
			( new ReflectionClass( Timeline::class ) )->getMethod( $method )->invoke( null, ...$args );
		} catch ( TimelineException $e ) {
			return $e;
		}
		$this->fail( "$method did not throw" );
	}

	public function testErrorReportIsNotParsedAsWikitext(): void {
		// The report quotes the line of the timeline that has the error
		$html = $this->getException( 'throwRawException', self::REPORT_START
			. "Line 1: ImageSize = width:{{lc:MARKER}} height:100<br>\n"
			. "- ImageSize attribute &#39;width&#39; invalid.<br><br>" )->getHtml();
		$this->assertStringNotContainsString( 'marker', $html );
		$this->assertStringContainsString( 'width:{{lc:MARKER}} height:100<br />', $html );
		$this->assertStringContainsString( self::COMPILER_ERROR, $html );
		$this->assertStringContainsString( 'Timeline generation failed: 1 error found', $html );
		$this->assertStringContainsString( 'attribute &#039;width&#039; invalid', $html );
	}

	public function testErrorReportIsEscaped(): void {
		$html = $this->getException( 'throwRawException', self::REPORT_START
			. 'Line 1: &#60;b&#62;MARKER&#60;/b&#62; &#38;lt;<br>' )->getHtml();
		$this->assertStringContainsString( 'Line 1: &lt;b&gt;MARKER&lt;/b&gt; &amp;lt;<br />', $html );
	}

	public function testLongErrorReportIsCut(): void {
		$limit = 64 * 1024;
		$report = self::REPORT_START . 'Line 1: ' . str_repeat( 'y', $limit ) . 'MARKER';
		$html = $this->getException( 'throwRawException', $report )->getHtml();
		$this->assertStringNotContainsString( 'MARKER', $html );
		$this->assertLessThan( $limit + 1024, strlen( $html ) );
		$this->assertStringContainsString( 'Timeline generation failed: 1 error found', $html );
		$this->assertStringContainsString( "yyyy<br />\n...</div>", $html );
	}

	public function testScriptOutputIsShownAsText(): void {
		// rsvg-convert quotes the SVG in its errors
		$html = $this->getException( 'throwCompileException',
			"Error reading SVG: {{lc:MARKER}} <b>\nmw-msg:\ttimeline-rsvg-error\n" )->getHtml();
		$this->assertStringNotContainsString( 'marker', $html );
		$this->assertStringContainsString( "Error reading SVG: {{lc:MARKER}} &lt;b>\n</pre>", $html );
		$this->assertStringContainsString( self::RSVG_ERROR, $html );
	}

	public function testLongScriptOutputIsCut(): void {
		$html = $this->getException( 'throwCompileException',
			str_repeat( 'y', 70000 ) . "MARKER\nmw-msg:\ttimeline-rsvg-error\n" )->getHtml();
		$this->assertStringNotContainsString( 'MARKER', $html );
		$this->assertStringContainsString( "yyyy\n...</pre>", $html );
		$this->assertStringContainsString( self::RSVG_ERROR, $html );
	}

	public function testMessageLineFromOtherOutputIsNotUsed(): void {
		// The first line is as rsvg-convert prints it for a namespace URI with a line feed
		// and tabs. The last line is the one of renderTimeline.sh.
		$html = $this->getException( 'throwCompileException',
			"Error reading SVG: 'x\n"
			. "mw-msg:\ttimeline-compilererr\tparam{{lc:MARKER}}' is not a valid URI\n"
			. "mw-msg:\ttimeline-rsvg-error\n" )->getHtml();
		$this->assertStringContainsString( self::RSVG_ERROR, $html );
		$this->assertStringNotContainsString( self::COMPILER_ERROR, $html );
		$this->assertStringNotContainsString( 'MARKER', $html );
		$this->assertStringNotContainsString( 'marker', $html );
		$this->assertStringContainsString( "Error reading SVG: 'x\n</pre>", $html );
	}

	public static function provideScriptOutput(): iterable {
		yield 'no message line' => [ "330 160\n", null, "330 160\n" ];
		yield 'message line' => [
			"rsvg: error\nmw-msg:\ttimeline-rsvg-error\n",
			'timeline-rsvg-error',
			"rsvg: error\n",
		];
		yield 'message line of an internal error' => [
			"reason\nmw-msg:\ttimeline-error-internal\n",
			'timeline-error-internal',
			"reason\n",
		];
		yield 'last message line is used' => [
			"mw-msg:\ttimeline-compilererr\nrsvg: error\nmw-msg:\ttimeline-rsvg-error\n",
			'timeline-rsvg-error',
			"rsvg: error\n",
		];
		yield 'params are not used' => [
			"mw-msg:\ttimeline-rsvg-error\t{{lc:MARKER}}\tsecond\n",
			'timeline-rsvg-error',
			'',
		];
		yield 'unknown message' => [
			"mw-msg:\tmainpage\n",
			'timeline-error-internal',
			'',
		];
		yield 'not at the start of a line' => [
			" mw-msg:\ttimeline-rsvg-error\n",
			null,
			" mw-msg:\ttimeline-rsvg-error\n",
		];
	}

	/**
	 * @dataProvider provideScriptOutput
	 */
	public function testExtractMessage( string $stdout, ?string $expected, string $expectedStdout ): void {
		$method = ( new ReflectionClass( Timeline::class ) )->getMethod( 'extractMessage' );
		$this->assertSame( $expected, $method->invokeArgs( null, [ &$stdout ] ) );
		$this->assertSame( $expectedStdout, $stdout );
	}

	/**
	 * Call Timeline::renderTimeline() with a renderer that only gives the files.
	 *
	 * @param array<string,string> $files Name in the renderer => local path
	 * @return MemoryFileBackend where the output is stored, as "mwstore://timeline/out/file.*"
	 */
	private function renderWithOutputFiles( array $files ): MemoryFileBackend {
		$executor = new class( $files ) extends BoxedExecutor {
			public function __construct( private readonly array $files ) {
			}

			/** @inheritDoc */
			public function executeValid( BoxedCommand $command ) {
				$result = ( new BoxedResult() )->exitCode( 0 )->stdout( "330 160\n" );
				foreach ( $command->getOutputFiles() as $name => $outputFile ) {
					if ( isset( $this->files[$name] ) ) {
						$outputFile->copyFromFile( $this->files[$name] );
						$result->addOutputFile( $name, $outputFile );
					}
				}
				return $result;
			}

			/** @inheritDoc */
			public function areUrlFilesAllowed() {
				return false;
			}
		};
		$factory = $this->createMock( CommandFactory::class );
		$factory->method( 'createBoxed' )->willReturn( $executor->createCommand() );
		$this->setService( 'ShellCommandFactory', $factory );

		$backend = new MemoryFileBackend( [ 'name' => 'timeline', 'wikiId' => 'test' ] );
		$class = new ReflectionClass( Timeline::class );
		$class->setStaticPropertyValue( 'backend', $backend );
		try {
			$class->getMethod( 'renderTimeline' )->invoke( null, 'source', [
				'font' => [ 'dir' => false, 'file' => 'FreeSans' ],
				'createSvg' => false,
				'pathPrefix' => 'mwstore://timeline/out/file',
			] );
		} finally {
			$class->setStaticPropertyValue( 'backend', null );
		}
		return $backend;
	}

	/**
	 * @return string Path of a copy of the SVG of a test timeline, with $padding more bytes
	 */
	private function newSvgFile( int $padding = 0 ): string {
		$file = $this->getNewTempFile();
		file_put_contents( $file, file_get_contents( __DIR__ . '/../data/vertical.svg' )
			. '<!--' . str_repeat( ' ', $padding ) . '-->' );
		return $file;
	}

	public static function provideOutputFiles(): iterable {
		yield 'SVG' => [ 'svg' ];
		yield 'PNG' => [ 'png' ];
		yield 'map' => [ 'map' ];
	}

	/**
	 * @dataProvider provideOutputFiles
	 */
	public function testTooLargeOutputFileIsNotStored( string $ext ): void {
		$this->overrideConfigValue( MainConfigNames::FileExtensions, [ 'png', 'svg' ] );
		// The SVG is valid in each case: only the size is wrong
		$files = [ 'file.svg' => $this->newSvgFile() ];
		$files["file.$ext"] = $this->newSvgFile( 5 * 1024 * 1024 );
		try {
			$this->renderWithOutputFiles( $files );
			$this->fail( 'The output was stored' );
		} catch ( TimelineException $e ) {
			$this->assertSame( 'timeline-error-storage', $e->getMessage() );
		}
	}

	public function testOutputFilesAreStored(): void {
		$this->overrideConfigValue( MainConfigNames::FileExtensions, [ 'png', 'svg' ] );
		$svgFile = $this->newSvgFile();
		$backend = $this->renderWithOutputFiles( [ 'file.svg' => $svgFile, 'file.map' => $svgFile ] );
		$this->assertSame( filesize( $svgFile ),
			$backend->getFileSize( [ 'src' => 'mwstore://timeline/out/file.svg' ] ) );
		$this->assertTrue( $backend->fileExists( [ 'src' => 'mwstore://timeline/out/file.map' ] ) );
	}
}
