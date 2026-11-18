<?php

namespace MediaWiki\Extension\Timeline\Tests\Unit;

use MediaWikiUnitTestCase;

/**
 * Runs scripts/EasyTimeline.php as Timeline.php does through Shellbox.
 *
 * @coversNothing
 */
class EasyTimelineScriptTest extends MediaWikiUnitTestCase {

	private const SCRIPTS = __DIR__ . '/../../../scripts';

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
		foreach ( glob( self::SCRIPTS . '/lib/*.php' ) as $file ) {
			copy( $file, "$this->dir/scripts/" . basename( $file ) );
		}
	}

	/**
	 * @param string[] $args
	 * @param string[] $phpOptions
	 * @return array{int,string} exit status and stdout
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
		stream_get_contents( $pipes[2] );
		return [ proc_close( $process ), $stdout ];
	}

	public function testFlatLayoutAndNoRegexLimit(): void {
		$this->copyScriptsFlat();
		// A php.ini that takes this value from an environment variable that is not set
		[ $status, $stdout ] = $this->runScript(
			[ 'https://example.org/wiki/$1', 'FreeSans', 'yes' ], [ '-d', 'pcre.backtrack_limit=0' ] );
		$this->assertSame( 0, $status );
		$this->assertMatchesRegularExpression( '/^\d+ \d+\n$/', $stdout );
		$this->assertFileDoesNotExist( "$this->dir/file.err" );
		$this->assertFileDoesNotExist( "$this->dir/file.log" );
		$this->assertStringContainsString( 'First phase', file_get_contents( "$this->dir/file.svg" ) );
	}
}
