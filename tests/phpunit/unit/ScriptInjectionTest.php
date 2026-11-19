<?php

namespace MediaWiki\Extension\Timeline\Tests\Unit;

use DOMDocument;
use DOMXPath;
use MediaWiki\Extension\Timeline\Render\CompileError;
use MediaWiki\Extension\Timeline\Render\Compiler;
use MediaWiki\Extension\Timeline\Render\Plotter;
use MediaWiki\Extension\Timeline\Render\PngCanvas;
use MediaWiki\Extension\Timeline\Render\SvgCanvas;
use MediaWikiUnitTestCase;
use Wikimedia\TestingAccessWrapper;

/**
 * Text of the timeline must stay text: in the plot script that the Compiler writes for the
 * Plotter, and in the SVG.
 *
 * In a timeline, "%?+" is a "#" that does not start a comment, "_" in a legend is a space.
 *
 * @covers \MediaWiki\Extension\Timeline\Render\Compiler
 * @covers \MediaWiki\Extension\Timeline\Render\Plotter
 * @covers \MediaWiki\Extension\Timeline\Render\SvgCanvas
 */
class ScriptInjectionTest extends MediaWikiUnitTestCase {

	private const HEADER = "ImageSize = width:400 height:200\n"
		. "PlotArea = left:60 right:20 bottom:30 top:20\n"
		. "DateFormat = yyyy\n"
		. "Period = from:1900 till:2000\n"
		. "TimeAxis = orientation:horizontal\n";

	private const PLOT_HEADER = "#proc page\n  pagesize: 4 3\n  dopagebox: no\n";

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

	private function xpath( string $svg ): DOMXPath {
		$doc = new DOMDocument();
		$this->assertTrue( $doc->loadXML( $svg ), 'The SVG is well-formed' );
		$xpath = new DOMXPath( $doc );
		$xpath->registerNamespace( 's', 'http://www.w3.org/2000/svg' );
		$xpath->registerNamespace( 'xlink', 'http://www.w3.org/1999/xlink' );
		return $xpath;
	}

	public function testBarLabelCannotStartDirective(): void {
		// As directives, the three labels would be an annotation that draws only "MARKER"
		$svg = self::render( self::HEADER
			. "BarData =\n"
			. "  bar:A text:%?+proc annotate\n"
			. "  bar:B text:location 1.5 1.5\n"
			. "  bar:C text:text MARKER\n"
			. "  bar:D text:Plain\n"
			. "PlotData =\n"
			. "  bar:A from:1900 till:1950 color:red\n" );
		$this->assertStringNotContainsString( '>MARKER</text>', $svg );
		$this->assertStringContainsString( '>#proc annotate</text>', $svg );
		$this->assertStringContainsString( '>text MARKER</text>', $svg );
		$this->assertStringContainsString( '>Plain</text>', $svg );
	}

	public function testLegendTextCannotStartDirective(): void {
		$svg = self::render( self::HEADER
			. "Colors =\n"
			. "  id:c1 value:red legend:Normal\n"
			. "  id:c2 value:blue legend:%?+proc_annotate\n"
			. "  id:c3 value:green legend:location_1_1\n"
			. "  id:c4 value:gray(0.5) legend:text_MARKER\n"
			. "Legend = orientation:vertical position:right\n"
			. "PlotData =\n"
			. "  bar:A from:1900 till:1950 color:c1\n" );
		$this->assertStringNotContainsString( '>MARKER</text>', $svg );
		$this->assertStringContainsString( '>#proc annotate</text>', $svg );
		$this->assertStringContainsString( '>text MARKER</text>', $svg );
		$this->assertStringContainsString( '>Normal</text>', $svg );
	}

	public static function provideUnsafeIds(): iterable {
		yield 'comma: more fields in a record of the plot script' => [
			"PlotData =\n  bar:A from:1900 till:1950 color:red\n"
				. "  bar:1,1850,2100,blue,x from:1900 till:1901 color:red\n",
			'PlotData attribute &#39;bar&#39; invalid',
		];
		yield 'BarData barset with a comma' => [
			"BarData =\n  barset:1,1850,2100,red,x\nPlotData =\n  from:1900 till:1950 color:red\n",
			'BarData attribute barset:&#39;1,1850,2100,red,x&#39; invalid',
		];
		yield 'BarData barset' => [
			"BarData =\n  barset:%?+proc\nPlotData =\n  from:1900 till:1950 color:red\n",
			'BarData attribute barset:&#39;#proc&#39; invalid',
		];
		yield 'PlotData bar' => [
			"PlotData =\n  bar:%?+proc from:1900 till:1950 color:red\n",
			'PlotData attribute &#39;bar&#39; invalid',
		];
		yield 'PlotData barset' => [
			"PlotData =\n  barset:%?+endproc from:1900 till:1950 color:red\n",
			'PlotData attribute &#39;barset&#39; invalid',
		];
		yield 'BarData bar' => [
			"BarData =\n  bar:%?+proc text:x\nPlotData =\n  bar:%?+proc from:1900 till:1950 color:red\n",
			'BarData attribute bar:&#39;#proc&#39; invalid',
		];
	}

	/**
	 * An id is written at the start of a line of the plot script.
	 *
	 * @dataProvider provideUnsafeIds
	 */
	public function testUnsafeIdIsRejected( string $body, string $expectedError ): void {
		try {
			self::render( self::HEADER . $body );
			$this->fail( 'The timeline was rendered' );
		} catch ( CompileError $e ) {
			$this->assertStringContainsString( $expectedError, $e->getMessage() );
		}
	}

	public static function provideUsableIds(): iterable {
		yield 'letters, digits and underscore' => [ 'Good_1', 'Set2' ];
		// Existing timelines have such ids, so only the start of an id is checked
		yield 'hyphen' => [ 'Smith-Jones', 'Set-2' ];
		yield 'number sign inside' => [ 'No%?+1', 'Set%?+2' ];
	}

	/**
	 * @dataProvider provideUsableIds
	 */
	public function testOtherIdStillRenders( string $bar, string $barSet ): void {
		$svg = self::render( self::HEADER
			. "BarData =\n  bar:$bar text:First\n  barset:$barSet\n"
			. "PlotData =\n  bar:$bar from:1900 till:1950 color:red\n"
			. "  barset:$barSet from:1950 till:2000 color:blue\n" );
		$this->assertStringContainsString( '>First</text>', $svg );
		$this->assertStringContainsString( 'fill="#ff0000"', $svg );
		$this->assertStringContainsString( 'fill="#0066cc"', $svg );
	}

	public function testVariableValueCannotStartDirective(): void {
		// areadef keeps the first word of xrange in @XMIN, and sets it when the next proc starts
		$script = self::PLOT_HEADER
			. "#proc areadef\n  rectangle: 0 0 4 3\n  xrange: #proc 100\n  yrange: 0 100\n"
			. "#proc annotate\n  location: 3 3\n  text: first\n"
			. "@XMIN annotate\n  location: 1 1\n  text: MARKER\n";
		$svg = self::plot( $script );
		// A second annotation would draw "MARKER" alone
		$this->assertStringNotContainsString( '>MARKER</text>', $svg );
		$this->assertStringContainsString( '>first</text>', $svg );

		$svg = self::plot( str_replace( '@XMIN annotate', '#proc annotate', $script ) );
		$this->assertStringContainsString( '>MARKER</text>', $svg );
	}

	public function testPercentInLinkedTextIsNotDecodedTwice(): void {
		// "%0A%" is how the Compiler hides a line feed. Typed in a text, it is just text.
		$text = 'TD%0A%#proc rect%0A%  rectangle: 0 0 3 0.5%0A%  clickmapurl: //other.example/';
		$compiler = new Compiler( 'https://example.org/wiki/$1', false );
		$scripts = $compiler->compile( self::HEADER
			. "TextData =\n  pos:(10,50) link:https://link.example/ text:\"$text\"\n" );
		$png = new PngCanvas( $scripts['pngMap'] );
		( new Plotter( $png, 'NoSuchFont' ) )->run( $scripts['png'] );
		$map = Compiler::fixMap( $png->map() ?? '' );
		$this->assertStringNotContainsString( 'other.example', $map );
		$this->assertSame( 1, substr_count( $map, '<area ' ) );
		$this->assertStringContainsString( 'href="https://link.example/"', $map );
		// Once in black and once as the link, as typed
		$this->assertSame( 2, substr_count( $scripts['png'], "  text: $text\n" ) );
	}

	public function testPlaceholderInTextIsNotReplaced(): void {
		// The first text has characters to escape, so the Compiler puts a placeholder for it
		// in the plot script. The other texts look like placeholders.
		$svg = self::render( self::HEADER . "TextData =\n"
			. "  pos:(10,10) text:\"A <b> and 'c'\"\n"
			// Shorter than its placeholder
			. "  pos:(10,70) text:\"'68\"\n"
			. "  pos:(10,30) text:\"typed {{0}}xxx end\"\n"
			. "  pos:(10,50) text:\"control \x010\x01xxx end\"\n" );
		$this->assertSame( 1, substr_count( $svg, '>A &#60;b&#62; and &#39;c&#39;</text>' ) );
		$this->assertStringContainsString( '>&#39;68</text>', $svg );
		$this->assertStringNotContainsString( "\x01", $svg );
		$this->assertStringContainsString( '>typed {{0}}xxx end</text>', $svg );
		// The control character of the real placeholders is removed from the source
		$this->assertStringContainsString( '>control 0xxx end</text>', $svg );
	}

	public function testEncodedPlaceholderCharacterIsRemoved(): void {
		// Attribute values are made lower case, which breaks the encoded "=" (%3D%), so that
		// its last "%" and the next one enclose "01"
		$compiler = new Compiler( 'https://example.org/wiki/$1', false );
		$scripts = $compiler->compile( self::HEADER
			. "Colors =\n  id:=01(01(x value:blue legend:Entry\n"
			. "Legend = orientation:vertical position:right\n"
			. "PlotData =\n  bar:A from:1900 till:1950 color:red\n" );
		$this->assertStringNotContainsString( "\x01", $scripts['svg'] );
		$this->assertStringContainsString( 'Entry', $scripts['svg'] );
	}

	public function testLinkCannotAddLineToScript(): void {
		// "_" is a space, written as %20. Its "%" must not pair with the "%" of the encoded "(".
		$compile = static function ( string $link ): array {
			$compiler = new Compiler( 'https://example.org/wiki/$1', false );
			return $compiler->compile( "ImageSize = width:400 height:200\n"
				. "PlotArea = left:60 right:20 bottom:30 top:20\n"
				. "DateFormat = x.y\nPeriod = from:0 till:30000\nTimeAxis = orientation:horizontal\n"
				. "PlotData =\n  bar:A from:100 till:200 color:red link:$link\n" );
		};
		$control = $compile( 'http://example.org/x_y' );
		$scripts = $compile( 'http://example.org/x_(0A(2E,(30,(39,(30,(' );
		foreach ( [ 'svg', 'png' ] as $type ) {
			$this->assertSame( substr_count( $control[$type], "\n" ), substr_count( $scripts[$type], "\n" ) );
		}
		$this->assertStringContainsString( 'http://example.org/x%20y', $control['svg'] );
	}

	public function testBarLabelLinkWithSpace(): void {
		$svg = self::render( self::HEADER
			. "BarData =\n  bar:A text:Label link:https://example.org/wiki/Foo_bar\n  bar:B text:Plain\n"
			. "PlotData =\n  bar:A from:1900 till:1950 color:red\n" );
		$xpath = $this->xpath( $svg );
		$this->assertSame( 1,
			$xpath->query( '//s:a[@xlink:href="https://example.org/wiki/Foo%20bar"][text()="Label"]' )->length );
	}

	public function testRawColorCannotAddMarkup(): void {
		$svg = self::plot( self::PLOT_HEADER
			. "#proc annotate\n  location: 1 1\n  textdetails: color=x\"><marker\n  text: One\n"
			. "#proc annotate\n  location: 1 2\n  textdetails: color=x33cc66\n  text: Two\n"
			. "#proc annotate\n  location: 2 2\n  textdetails: color=xabc\n  text: Three\n" );
		$this->assertStringNotContainsString( 'marker', $svg );
		$canvas = new SvgCanvas( false );
		$canvas->color( "xff0000\n" );
		$this->assertStringNotContainsString( "#ff0000\n", TestingAccessWrapper::newFromObject( $canvas )->curColor );
		$this->assertStringContainsString( '>One</text>', $svg );
		$this->assertStringContainsString( 'style="fill:#33cc66;stroke:#33cc66;', $svg );
		$this->assertStringContainsString( 'style="fill:#abc;stroke:#abc;', $svg );
	}

	public function testInlineLinkUrlIsEscapedInAttribute(): void {
		// "" is a quote in a quoted text
		$svg = self::render( self::HEADER . "PlotData =\n"
			. "  bar:A from:1900 till:1950 text:\"[https://example.org/\"\" marker=\"\"1\"\" b=\"\"]\"\n"
			. "  bar:A from:1950 till:1960 text:\"[https://example.org/a?x=1&y=2]\"\n"
			. "  bar:A from:1960 till:1970 text:\"[[Main Page|Three]]\"\n" );
		$this->assertStringNotContainsString( 'marker="1"', $svg );
		$xpath = $this->xpath( $svg );
		$this->assertSame( 3, $xpath->query( '//s:a[count(@*)=2 and @style and @xlink:href]' )->length );
		$this->assertSame( 1, $xpath->query( '//s:a[starts-with(@xlink:href, \'https://example.org/"\')]' )->length );
		$this->assertSame( 1, $xpath->query( '//s:a[@xlink:href="https://example.org/a?x=1&y=2"]' )->length );
		$this->assertSame( 1,
			$xpath->query( '//s:a[@xlink:href="https://example.org/wiki/Main_Page"][text()="Three"]' )->length );
	}

	private function texts( string $svg ): array {
		$texts = [];
		foreach ( $this->xpath( $svg )->query( '//s:text' ) as $node ) {
			$texts[] = $node->textContent;
		}
		return $texts;
	}

	public function testEmptyLegendEntryDoesNotEndTheLegend(): void {
		// An empty line ends the list of legend entries in the plot script. The entry after it
		// was then an attribute of the legend, here the size of its text.
		$svg = self::render( self::HEADER
			. "Colors =\n"
			. "  id:a value:red legend:First\n"
			. "  id:b value:blue legend:_\n"
			. "  id:c value:green legend:textdetails_size=40\n"
			. "  id:d value:black legend:Last\n"
			. "Legend = left:60 top:50\n"
			. "PlotData =\n  bar:x from:1900 till:1950 color:a\n" );
		$texts = $this->texts( $svg );
		$this->assertContains( 'First', $texts );
		$this->assertContains( 'textdetails size=40', $texts );
		$this->assertContains( 'Last', $texts );
		$this->assertStringNotContainsString( '&fs;40pt', $svg );
	}

	public function testBarLabelCannotStartComment(): void {
		// The Plotter drops a line that starts with "//", and the labels after it moved up
		$svg = self::render( self::HEADER
			. "BarData =\n"
			. "  bar:A text:\"//first\"\n"
			. "  bar:B text:Second\n"
			. "  bar:C text:Third\n"
			. "PlotData =\n  bar:A from:1900 till:1950 color:red\n" );
		$texts = $this->texts( $svg );
		$this->assertContains( '//first', $texts );
		$this->assertContains( 'Second', $texts );
		$this->assertContains( 'Third', $texts );
	}

	public function testAtSignIsNotAVariable(): void {
		// The Plotter replaces "@NAME" by a variable and "@@" by "@"
		$compiler = new Compiler( 'https://example.org/wiki/$1', false );
		$scripts = $compiler->compile( self::HEADER
			. "BarData =\n"
			. "  bar:A text:\"@DEVICE x@@y\"\n"
			. "  bar:B text:Second\n"
			. "PlotData =\n"
			. "  bar:A from:1900 till:1950 color:red link:http://example.org/@DEVICE\n"
			. "TextData =\n  pos:(60,30) text:\"t @PLVERSION\"\n" );
		$texts = $this->texts( $compiler->fixSvg( self::plot( $scripts['svg'] ) ) );
		$this->assertContains( '@DEVICE x@@y', $texts );
		$this->assertContains( 't @PLVERSION', $texts );
		$png = new PngCanvas( $scripts['pngMap'] );
		( new Plotter( $png, 'NoSuchFont' ) )->run( $scripts['png'] );
		$this->assertStringContainsString( '%40DEVICE', (string)$png->map() );
	}

	public function testTypedEncodedAtSignStaysAsTyped(): void {
		// "%" is "%25%" in the script, so a typed "%40%" has the encoded form of "@" in it
		$compiler = new Compiler( 'https://example.org/wiki/$1', false );
		$scripts = $compiler->compile( self::HEADER
			. "Colors =\n  id:c value:red legend:L_5%40%x\n"
			. "Legend = left:100 top:100\n"
			. "BarData =\n  bar:A\n"
			. "PlotData =\n"
			. "  bar:A from:1900 till:1950 color:c text:\"T 5%40%x\" link:http://example.org/a%40%2Fb\n" );
		$texts = $this->texts( $compiler->fixSvg( self::plot( $scripts['svg'] ) ) );
		$this->assertContains( 'L 5%40%x', $texts );
		$png = new PngCanvas( $scripts['pngMap'] );
		( new Plotter( $png, 'NoSuchFont' ) )->run( $scripts['png'] );
		$this->assertStringContainsString( 'http://example.org/a%40%2Fb', (string)$png->map() );
		$this->assertStringContainsString( "text: T 5%40%x\n", $scripts['png'] );
	}

	public function testEncodedCharacterInLowerCaseIsDecoded(): void {
		// The id of a color is made lower case, with the "%3D%" for its "=". When that was not
		// decoded, its "%" and the "08(" after it made a control character in the script.
		$compiler = new Compiler( 'https://example.org/wiki/$1', false );
		$script = $compiler->compile( self::HEADER
			. "Colors =\n  id:=08(08(x value:red legend:Entry\n"
			. "PlotData =\n  bar:x from:1900 till:1950 color:red\n" )['svg'];
		$this->assertStringContainsString( 'tag: =08(08(x', $script );
		$this->assertDoesNotMatchRegularExpression( '/[\x00-\x08]/', $script );
	}

	public function testAmpersandInTextWithOtherSpecialCharacter(): void {
		$svg = self::render( self::HEADER
			. "PlotData =\n  bar:x from:1900 till:1950 color:red text:\"Tom & Jerry's\"\n"
			. "TextData =\n  pos:(60,30) text:\"R&amp;D <1> &#65;\"\n" );
		$texts = $this->texts( $svg );
		$this->assertContains( "Tom & Jerry's", $texts );
		// A character reference in the text stays one
		$this->assertContains( 'R&D <1> A', $texts );
	}
}
