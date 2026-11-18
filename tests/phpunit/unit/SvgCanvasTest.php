<?php

namespace MediaWiki\Extension\Timeline\Tests\Unit;

use MediaWiki\Extension\Timeline\Render\SvgCanvas;
use MediaWikiUnitTestCase;

/**
 * The color forms of ploticus that the fixtures of RenderTest do not have.
 *
 * @covers \MediaWiki\Extension\Timeline\Render\SvgCanvas
 */
class SvgCanvasTest extends MediaWikiUnitTestCase {

	public static function provideColors(): array {
		return [
			'hex' => [ 'xff8000' ],
			'gray level' => [ '0.5' ],
			'rgb with a value that is no number' => [ 'rgb(1,a,0)' ],
		];
	}

	/**
	 * @dataProvider provideColors
	 */
	public function testColor( string $color ): void {
		$canvas = new SvgCanvas( false );
		$canvas->begin( 2.0, 1.0 );
		$this->assertIsString( $canvas->color( $color ) );
		$canvas->finish( 0.0, 0.0, 2.0, 1.0, [] );
		$this->assertStringEndsWith( "</svg>\n", $canvas->result() );
	}
}
