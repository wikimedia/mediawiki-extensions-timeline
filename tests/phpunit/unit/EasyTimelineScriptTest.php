<?php

namespace MediaWiki\Extension\Timeline\Tests\Unit;

use MediaWiki\Extension\Timeline\Render\Compiler;
use MediaWiki\Extension\Timeline\Render\Plotter;
use MediaWiki\Extension\Timeline\Render\PngCanvas;
use MediaWiki\Extension\Timeline\Render\SvgCanvas;
use MediaWikiUnitTestCase;

/**
 * Runs scripts/EasyTimeline.php as Timeline.php does through Shellbox, and tests the parts
 * of the output that the fixtures of RenderTest do not have.
 *
 * @covers \MediaWiki\Extension\Timeline\Render\Compiler
 * @covers \MediaWiki\Extension\Timeline\Render\Plotter
 * @covers \MediaWiki\Extension\Timeline\Render\PngCanvas
 */
class EasyTimelineScriptTest extends MediaWikiUnitTestCase {

	private const SCRIPTS = __DIR__ . '/../../../scripts';
	private const FONT_DIR = '/usr/share/fonts/truetype/freefont';

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/timeline-test-' . bin2hex( random_bytes( 6 ) );
		mkdir( "$this->dir/scripts", 0777, true );
		copy( __DIR__ . '/../data/horizontal.txt', "$this->dir/file" );
	}

	protected function tearDown(): void {
		foreach ( array_merge( glob( "$this->dir/scripts/*" ), glob( "$this->dir/*" ) ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		rmdir( "$this->dir/scripts" );
		rmdir( $this->dir );
		parent::tearDown();
	}

	/**
	 * The layout that Timeline.php sends to Shellbox: all files in one directory.
	 */
	private function copyScriptsFlat(): void {
		copy( self::SCRIPTS . '/EasyTimeline.php', "$this->dir/scripts/EasyTimeline.php" );
		foreach ( glob( __DIR__ . '/../../../includes/Render/*.php' ) as $file ) {
			copy( $file, "$this->dir/scripts/" . basename( $file ) );
		}
	}

	/**
	 * @param string[] $args
	 * @param string[] $phpOptions
	 * @return array{int,string,string} exit status, stdout and stderr
	 */
	private function runScript( array $args, array $phpOptions = [], string $fontPath = '/nonexistent' ): array {
		// Only PATH, as in Shellbox
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.proc_open
		$process = proc_open(
			[ PHP_BINARY, ...$phpOptions, 'scripts/EasyTimeline.php', ...$args ],
			[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
			$pipes, $this->dir, [ 'PATH' => getenv( 'PATH' ), 'GDFONTPATH' => $fontPath ]
		);
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		return [ proc_close( $process ), $stdout, $stderr ];
	}

	public function testFlatLayoutAndNoRegexLimit(): void {
		$this->copyScriptsFlat();
		// A php.ini that takes this value from an environment variable that is not set
		[ $status, $stdout ] = $this->runScript(
			[ 'https://example.org/wiki/$1', 'FreeSans', 'yes' ], [ '-d', 'pcre.backtrack_limit=0' ] );
		$this->assertSame( 0, $status );
		$this->assertMatchesRegularExpression( '/^\d+ \d+\n$/', $stdout );
		$this->assertFileDoesNotExist( "$this->dir/file.err" );
		$this->assertStringContainsString( 'First phase', file_get_contents( "$this->dir/file.svg" ) );
	}

	public function testMissingFontIsInternalError(): void {
		$this->copyScriptsFlat();
		[ $status, $stdout, $stderr ] = $this->runScript( [ 'https://example.org/wiki/$1', 'NoSuchFont', 'no' ] );
		$this->assertSame( 70, $status );
		$this->assertSame( '', $stdout );
		$this->assertStringContainsString( 'libgd cannot use the font', $stderr );
		$this->assertFileDoesNotExist( "$this->dir/file.png" );
		$this->assertFileDoesNotExist( "$this->dir/file.err" );
	}

	public function testFullPathFontIsInternalError(): void {
		// The canvas ignores a full path, so the check must not pass for one.
		$this->copyScriptsFlat();
		[ $status ] = $this->runScript(
			[ 'https://example.org/wiki/$1', self::FONT_DIR . '/FreeSans.ttf', 'no' ], [], self::FONT_DIR );
		$this->assertSame( 70, $status );
		$this->assertFileDoesNotExist( "$this->dir/file.png" );
	}

	public function testScriptMakesPngWithFont(): void {
		if ( !is_file( self::FONT_DIR . '/FreeSans.ttf' ) || !function_exists( 'imagettfbbox' ) ) {
			$this->markTestSkipped( 'Needs FreeSans.ttf and the gd extension with FreeType' );
		}
		$this->copyScriptsFlat();
		[ $status, $stdout ] = $this->runScript(
			[ 'https://example.org/wiki/$1', 'FreeSans', 'no' ], [ '-d', 'pcre.backtrack_limit=0' ], self::FONT_DIR );
		$this->assertSame( 0, $status );
		$this->assertMatchesRegularExpression( '/^\d+ \d+\n$/', $stdout );
		$this->assertSame( "\x89PNG", substr( file_get_contents( "$this->dir/file.png" ), 0, 4 ) );
	}

	public function testRegexFailureIsInternalError(): void {
		// A line that is too long for a regular expression of the compiler. Without the check
		// in Regex, the compiler ignored the line and made an image.
		$this->copyScriptsFlat();
		$source = file_get_contents( "$this->dir/file" ) . "\nTextData =\n  pos:(10,10) text:\""
			. str_repeat( '~', 1500000 ) . "\"\n";
		file_put_contents( "$this->dir/file", $source );
		[ $status, $stdout, $stderr ] = $this->runScript( [ 'https://example.org/wiki/$1', 'FreeSans', 'yes' ] );
		$this->assertSame( 70, $status );
		$this->assertSame( '', $stdout );
		$this->assertStringContainsString( 'RuntimeException at Regex.php', $stderr );
		$this->assertStringNotContainsString( '~~~', $stderr );
		$this->assertFileDoesNotExist( "$this->dir/file.svg" );
		$this->assertFileDoesNotExist( "$this->dir/file.err" );
	}

	private const HEADER = "ImageSize = width:400 height:200\nPlotArea = left:60 right:20 bottom:30 top:20\n"
		. "DateFormat = yyyy\nPeriod = from:1900 till:2000\nTimeAxis = orientation:horizontal\n";

	public static function provideTotalText(): iterable {
		// A Define makes each text much longer than its line of the source
		yield 'under the limits' => [ str_repeat( 'a', 100 ) . '~', 20, null ];
		yield 'too long' => [ str_repeat( 'a', 999 ), 26, 'longer than 500000 characters in total' ];
		yield 'too many segments' => [ str_repeat( 'a~', 25 ), 11, 'more than 5000 segments in total' ];
	}

	/**
	 * @dataProvider provideTotalText
	 */
	public function testTotalTextIsLimited( string $define, int $lines, ?string $error ): void {
		$this->copyScriptsFlat();
		$text = str_repeat( '$a', 20 );
		file_put_contents( "$this->dir/file", self::HEADER . "Define \$a = $define\nPlotData =\n"
			. str_repeat( "  bar:A from:1900 till:2000 text:$text\n", $lines ) );
		[ $status, $stdout ] = $this->runScript( [ 'https://example.org/wiki/$1', 'NoSuchFont', 'yes' ] );
		$this->assertSame( 0, $status );
		if ( $error !== null ) {
			$this->assertSame( '', $stdout );
			$this->assertStringContainsString( $error, file_get_contents( "$this->dir/file.err" ) );
			$this->assertFileDoesNotExist( "$this->dir/file.svg" );
		} else {
			$this->assertMatchesRegularExpression( '/^\d+ \d+\n$/', $stdout );
			$this->assertFileDoesNotExist( "$this->dir/file.err" );
		}
	}

	public static function provideLargeSvgs(): iterable {
		yield 'side' => [ 'fontsize:15000 text:"one~two~three"', 'Output SVG is larger than 10000x10000 pixels' ];
		yield 'size with an exponent' => [ 'fontsize:1e30 text:"one~two~three"', 'Output SVG has no usable size' ];
	}

	/**
	 * @dataProvider provideLargeSvgs
	 */
	public function testSvgSideIsLimited( string $attributes, string $error ): void {
		$this->copyScriptsFlat();
		file_put_contents( "$this->dir/file", self::HEADER
			. "PlotData =\n  bar:A from:1900 till:2000 $attributes\n" );
		[ $status, $stdout ] = $this->runScript( [ 'https://example.org/wiki/$1', 'NoSuchFont', 'yes' ] );
		$this->assertSame( 0, $status );
		$this->assertSame( '', $stdout );
		$this->assertStringContainsString( $error, file_get_contents( "$this->dir/file.err" ) );
		$this->assertFileDoesNotExist( "$this->dir/file.svg" );
	}

	public function testSvgDimensionsAreLimited(): void {
		// Large text extends the SVG far past the image, and rsvg-convert makes a PNG of that size
		$this->copyScriptsFlat();
		$text = str_repeat( 'W', 70 ) . str_repeat( '~W', 70 );
		file_put_contents( "$this->dir/file", self::HEADER
			. "PlotData =\n  bar:A from:1900 till:2000 fontsize:100 text:\"$text\"\n" );
		[ $status, $stdout ] = $this->runScript( [ 'https://example.org/wiki/$1', 'NoSuchFont', 'yes' ] );
		$this->assertSame( 0, $status );
		$this->assertSame( '', $stdout );
		$this->assertStringContainsString( 'Output SVG has more than 16 megapixels',
			file_get_contents( "$this->dir/file.err" ) );
		$this->assertFileDoesNotExist( "$this->dir/file.svg" );
	}

	public function testMissingFontIsNoErrorWithSvgOnly(): void {
		$this->copyScriptsFlat();
		[ $status ] = $this->runScript( [ 'https://example.org/wiki/$1', 'NoSuchFont', 'yes' ] );
		$this->assertSame( 0, $status );
		$this->assertFileExists( "$this->dir/file.svg" );
	}

	public function testPngHasText(): void {
		$this->assertFalse( PngCanvas::hasFont( 'NoSuchFont' ) );
		$old = getenv( 'GDFONTPATH' );
		putenv( 'GDFONTPATH=' . self::FONT_DIR );
		try {
			if ( !PngCanvas::hasFont( 'FreeSans' ) ) {
				$this->markTestSkipped( 'No FreeSans font, or gd has no FreeType support' );
			}
			$compiler = new Compiler( 'https://example.org/wiki/$1', false );
			$scripts = $compiler->compile( file_get_contents( "$this->dir/file" ) );
			$dark = [];
			foreach ( [ 'FreeSans', 'NoSuchFont' ] as $font ) {
				$png = new PngCanvas( $scripts['pngMap'] );
				( new Plotter( $png, $font ) )->run( $scripts['png'] );
				$im = imagecreatefromstring( $png->result() );
				$dark[$font] = 0;
				for ( $x = 0; $x < imagesx( $im ); $x++ ) {
					for ( $y = 0; $y < imagesy( $im ); $y++ ) {
						$c = imagecolorsforindex( $im, imagecolorat( $im, $x, $y ) );
						$dark[$font] += (int)( $c['red'] + $c['green'] + $c['blue'] < 200 );
					}
				}
			}
			// The axis labels are black text
			$this->assertGreaterThan( $dark['NoSuchFont'] + 100, $dark['FreeSans'] );
		} finally {
			putenv( $old === false ? 'GDFONTPATH' : "GDFONTPATH=$old" );
		}
	}

	public function testLegendColumnWithNoEntries(): void {
		// Three columns for two entries: the last column has no entries
		$source = <<<'TIMELINE'
ImageSize  = width:600 height:200
PlotArea   = left:50 right:50 bottom:80 top:20
DateFormat = yyyy
Period     = from:1900 till:2000
TimeAxis   = orientation:horizontal
Legend     = orientation:vertical columns:3 position:bottom
Colors =
  id:first  value:red  legend:FirstEntry
  id:second value:blue legend:SecondEntry
PlotData =
  bar:a from:1900 till:1950 color:first
  bar:b from:1950 till:2000 color:second
TIMELINE;
		$compiler = new Compiler( 'https://example.org/wiki/$1', false );
		$scripts = $compiler->compile( $source );
		$svg = new SvgCanvas( $scripts['svgMap'] );
		( new Plotter( $svg, 'FreeSans' ) )->run( $scripts['svg'] );
		$text = $compiler->fixSvg( $svg->result() );
		$this->assertSame( 1, substr_count( $text, 'FirstEntry' ) );
		$this->assertSame( 1, substr_count( $text, 'SecondEntry' ) );
	}
}
