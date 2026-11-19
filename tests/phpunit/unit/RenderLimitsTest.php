<?php

namespace MediaWiki\Extension\Timeline\Tests\Unit;

use MediaWiki\Extension\Timeline\Render\CompileError;
use MediaWiki\Extension\Timeline\Render\Compiler;
use MediaWiki\Extension\Timeline\Render\Plotter;
use MediaWiki\Extension\Timeline\Render\SvgCanvas;
use MediaWikiUnitTestCase;

/**
 * The limits that keep the time and the output of a render in proportion to the timeline.
 *
 * @covers \MediaWiki\Extension\Timeline\Render\Compiler
 * @covers \MediaWiki\Extension\Timeline\Render\Plotter
 */
class RenderLimitsTest extends MediaWikiUnitTestCase {

	private const HEADER = "ImageSize = width:400 height:200\n"
		. "PlotArea = left:60 right:20 bottom:30 top:20\n"
		. "DateFormat = yyyy\n"
		. "Period = from:1900 till:2000\n"
		. "TimeAxis = orientation:horizontal\n";

	private static function plot( string $script ): string {
		$svg = new SvgCanvas( false );
		( new Plotter( $svg, 'FreeSans' ) )->run( $script );
		return $svg->result();
	}

	/**
	 * @throws CompileError
	 */
	private static function render( string $source ): string {
		$compiler = new Compiler( 'https://example.org/wiki/$1', false );
		return $compiler->fixSvg( self::plot( $compiler->compile( $source )['svg'] ) );
	}

	/**
	 * @return string The error report, as the renderer writes it to file.err
	 */
	private function renderError( string $source ): string {
		try {
			self::render( $source );
		} catch ( CompileError $e ) {
			return $e->getMessage();
		}
		$this->fail( 'The timeline was rendered' );
	}

	public function testDefineThatGrowsOverTheLimitIsRejected(): void {
		// Each Define uses the one before twice, so a few lines make a very long value
		$source = self::HEADER . 'Define $a0 = ' . str_repeat( 'x', 300 ) . "\n"
			. "Define \$a1 = \$a0\$a0\n"
			. "Define \$a2 = \$a1\$a1\n";
		$error = $this->renderError( $source );
		$this->assertStringContainsString( 'Line 8: Define $a2', $error );
		$this->assertStringContainsString( 'longer than 1000 characters', $error );
		$this->assertStringNotContainsString( 'Line 7:', $error );
	}

	public function testDefineInsideOtherDefineStillWorks(): void {
		$svg = self::render( "Define \$w = 400\nDefine \$size = width:\$w height:200\nImageSize = \$size\n"
			. substr( self::HEADER, strpos( self::HEADER, 'PlotArea' ) )
			. "TextData =\n  pos:(10,50) text:Label\n" );
		$this->assertStringContainsString( '>Label</text>', $svg );
	}

	public static function provideTooManyBars(): iterable {
		yield 'bars of PlotData' => [ '', '  bar:b%d from:1900 till:1950' ];
		yield 'bars of a bar set' => [ "BarData =\n  barset:s\n", '  barset:s from:1900 till:1950' ];
	}

	/**
	 * @dataProvider provideTooManyBars
	 */
	public function testTooManyBarsAreRejected( string $barData, string $line ): void {
		$source = self::HEADER . $barData . "PlotData =\n";
		for ( $i = 0; $i <= 1000; $i++ ) {
			$source .= sprintf( $line, $i ) . "\n";
		}
		$this->assertStringContainsString( 'More than 1000 bars defined', $this->renderError( $source ) );
	}

	public function testThousandBarsStillRender(): void {
		$source = self::HEADER . "PlotData =\n";
		for ( $i = 0; $i < 1000; $i++ ) {
			$source .= "  bar:b$i from:1900 till:1950 color:red\n";
		}
		$this->assertStringContainsString( 'fill="#ff0000"', self::render( $source ) );
	}

	public static function provideLongText(): iterable {
		// Regular expressions for links take time quadratic in the length of such a text
		yield 'TextData, run of brackets' => [ "TextData =\n  pos:(10,10) text:\"%s\"\n", '[' ];
		yield 'PlotData, short lines' => [ "PlotData =\n  bar:A from:1900 till:2000 text:\"%s\"\n", 'a~' ];
	}

	/**
	 * @dataProvider provideLongText
	 */
	public function testTextOverTheLimitIsRejected( string $format, string $part ): void {
		$text = substr( str_repeat( $part, 20001 ), 0, 20001 );
		$error = $this->renderError( self::HEADER . sprintf( $format, $text ) );
		$this->assertStringContainsString( 'longer than 20000 characters', $error );
	}

	public function testTextAtTheLimitStillRenders(): void {
		$text = str_repeat( str_repeat( 'a', 249 ) . '~', 80 );
		$svg = self::render( self::HEADER . "PlotData =\n  bar:A from:1900 till:2000 text:\"$text\"\n" );
		$this->assertSame( 80, substr_count( $svg, '>' . str_repeat( 'a', 249 ) . '</text>' ) );
	}

	public static function provideFontSizes(): iterable {
		yield 'over the limit of the Plotter' => [ '15000', '100pt' ];
		// These sizes are kept: existing timelines have them
		yield 'large' => [ '40', '40pt' ];
		yield 'small' => [ '5', '5pt' ];
		yield 'usual' => [ '12', '12pt' ];
		yield 'name' => [ 'XL', '17pt' ];
		yield 'word' => [ 'Large', '13pt' ];
	}

	/**
	 * @dataProvider provideFontSizes
	 */
	public function testPlotDataFontSizeIsLimited( string $fontSize, string $expected ): void {
		$svg = self::render( self::HEADER
			. "PlotData =\n  bar:A from:1900 till:2000 fontsize:$fontSize text:Label\n" );
		$this->assertMatchesRegularExpression( '/&fs;' . $expected . ';&\w+;">\n<text [^>]*>Label</', $svg );
	}

	public static function providePlotterTextSizes(): iterable {
		yield 'over the limit' => [ '5000', '100pt' ];
		yield 'under the limit' => [ '40', '40pt' ];
	}

	/**
	 * @dataProvider providePlotterTextSizes
	 */
	public function testPlotterLimitsTextSize( string $size, string $expected ): void {
		$svg = self::plot( "#proc page\n  pagesize: 4 3\n  dopagebox: no\n"
			. "#proc annotate\n  location: 1 1\n  textdetails: size=$size\n  text: Label\n" );
		$this->assertMatchesRegularExpression( '/&fs;' . $expected . ';&\w+;">\n<text [^>]*>Label</', $svg );
	}

	public function testTextSegmentsOfTextDataAreLimited(): void {
		// "^" starts a segment in TextData
		$source = self::HEADER . 'Define $a = ' . str_repeat( 'a^', 500 ) . "\nTextData =\n"
			. str_repeat( "  pos:(10,10) text:\$a\n", 11 );
		$this->assertStringContainsString( 'more than 5000 segments in total', $this->renderError( $source ) );
	}

	public function testManyLinesWithoutTextStillRender(): void {
		$lines = str_repeat( "  bar:A from:1900 till:1950 color:red\n", 6000 );
		$this->assertStringContainsString( '<svg', self::render( self::HEADER . "PlotData =\n$lines" ) );
	}

	public function testTooManyBarWidthsAreRejected(): void {
		// The Compiler makes a pass over all bars for each width
		$lines = '';
		for ( $i = 1; $i <= 101; $i++ ) {
			$lines .= "  bar:A from:1900 till:1901 color:red width:$i\n";
		}
		$this->assertStringContainsString( 'More than 100 different bar widths',
			$this->renderError( self::HEADER . "PlotData =\n$lines" ) );
	}

	public function testHundredBarWidthsStillRender(): void {
		$lines = '';
		for ( $i = 1; $i <= 100; $i++ ) {
			$lines .= "  bar:A from:1900 till:1901 color:red width:$i\n";
		}
		$this->assertStringContainsString( '<svg', self::render( self::HEADER . "PlotData =\n$lines" ) );
	}

	public function testErrorReportCutsLongLines(): void {
		// Each error quotes its line, and up to 11 errors are reported
		$line = 'ScaleMajor = unit:' . str_repeat( 'y', 10000 ) . "\n";
		$error = $this->renderError( self::HEADER . str_repeat( $line, 11 ) );
		$this->assertStringContainsString( 'More than 10 errors found', $error );
		$this->assertStringContainsString( 'Line 6: ScaleMajor = unit:yyyy', $error );
		$this->assertStringContainsString( 'ScaleMajor definition incomplete', $error );
		$this->assertLessThan( 20000, strlen( $error ) );
	}

	public function testDefineInManyLinesIsLimited(): void {
		// Not a text attribute, which has a limit of its own: 2500 legend entries of 999 bytes
		$source = self::HEADER . 'Define $a = ' . str_repeat( 'x', 999 ) . "\nColors =\n";
		for ( $i = 0; $i < 2500; $i++ ) {
			$source .= "  id:c$i value:red legend:\$a\n";
		}
		$this->assertStringContainsString( 'after the expansion of its Defines', $this->renderError( $source ) );
	}
}
