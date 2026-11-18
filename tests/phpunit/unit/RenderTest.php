<?php

namespace MediaWiki\Extension\Timeline\Tests\Unit;

use MediaWiki\Extension\Timeline\Render\CompileError;
use MediaWiki\Extension\Timeline\Render\Compiler;
use MediaWiki\Extension\Timeline\Render\Plotter;
use MediaWiki\Extension\Timeline\Render\PngCanvas;
use MediaWiki\Extension\Timeline\Render\SvgCanvas;
use MediaWikiUnitTestCase;

/**
 * Renders the timelines in tests/phpunit/data and compares with the expected output.
 * The compile step of these fixtures matches the former EasyTimeline.pl byte for byte.
 * PNGs are rendered without a font, so the result does not depend on installed fonts.
 *
 * @covers \MediaWiki\Extension\Timeline\Render\Compiler
 * @covers \MediaWiki\Extension\Timeline\Render\Plotter
 * @covers \MediaWiki\Extension\Timeline\Render\SvgCanvas
 * @covers \MediaWiki\Extension\Timeline\Render\PngCanvas
 */
class RenderTest extends MediaWikiUnitTestCase {

	public static function provideTimelines(): iterable {
		foreach ( glob( __DIR__ . '/../data/*.txt' ) as $file ) {
			yield basename( $file, '.txt' ) => [ substr( $file, 0, -4 ) ];
		}
	}

	/**
	 * @dataProvider provideTimelines
	 */
	public function testRender( string $base ): void {
		$compiler = new Compiler( 'https://example.org/wiki/$1', false );
		try {
			$scripts = $compiler->compile( file_get_contents( "$base.txt" ) );
		} catch ( CompileError $e ) {
			$this->assertStringEqualsFile( "$base.err", $e->getMessage() );
			return;
		}
		$svg = new SvgCanvas( $scripts['svgMap'] );
		( new Plotter( $svg, 'FreeSans' ) )->run( $scripts['svg'] );
		$this->assertStringEqualsFile( "$base.svg", $compiler->fixSvg( $svg->result() ) );

		// A font that does not exist: the PNG must not depend on installed fonts
		$png = new PngCanvas( $scripts['pngMap'] );
		( new Plotter( $png, 'NoSuchFont' ) )->run( $scripts['png'] );
		$this->assertStringEqualsFile( "$base.png.sha1", sha1( $png->result() ) . "\n" );
		$this->assertStringEqualsFile( "$base.map", Compiler::fixMap( $png->map() ?? '' ) );
	}
}
