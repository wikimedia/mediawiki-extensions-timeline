<?php
/**
 * Render an EasyTimeline: reads ./file, writes file.png, file.svg, file.map or file.err.
 *
 * Usage: php EasyTimeline.php <article path> <font name> <svg only: yes|no>
 *
 * libgd looks up the font in GDFONTPATH. The SVG width and height are printed on stdout.
 */

namespace MediaWiki\Extension\Timeline\Render;

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

// Timeline.php shows stdout and stderr to the user and takes mw-msg lines from them as
// message keys, so PHP errors, which can quote the input, must not be printed.
ini_set( 'display_errors', '0' );
ini_set( 'log_errors', '0' );
// Fail cleanly before the Shellbox limits kill the process; real timelines need < 10 MB and < 1 s.
ini_set( 'memory_limit', '256M' );
set_time_limit( 120 );
// Shellbox gives the command only PATH. A php.ini that takes its values from the environment
// then has an empty pcre.backtrack_limit, and each preg_* function fails with no message.
ini_set( 'pcre.backtrack_limit', '1000000' );
ini_set( 'pcre.recursion_limit', '100000' );

// Exit status for a failure that is not an error in the timeline (EX_SOFTWARE of sysexits.h).
// renderTimeline.sh takes each status that is not 0 as such a failure.
const EXIT_INTERNAL = 70;

// Largest SVG to write. Timeline.php checks the size again before it verifies the file.
const MAX_SVG_SIZE = 5 * 1024 * 1024;

// Largest width and height of the SVG in pixels. The image is at most 1600x2000, but text can
// extend the SVG past the image. rsvg-convert makes a PNG of this size.
const MAX_SVG_PIXELS = 10000;
// Largest area of the SVG in pixels. The largest image has 3.2 million.
const MAX_SVG_AREA = 16000000;

// The classes are in includes/Render/. Shellbox makes nested directories incorrectly, so
// Timeline.php sends them next to this script.
spl_autoload_register( static function ( string $class ): void {
	$name = substr( strrchr( $class, '\\' ), 1 ) . '.php';
	$file = __DIR__ . "/../includes/Render/$name";
	require is_file( $file ) ? $file : __DIR__ . "/$name";
} );

/**
 * Stop for a failure that is not an error in the timeline. renderTimeline.sh shows the
 * reason to the user, with the message timeline-error-internal. The reason must have no
 * text from the timeline: Timeline.php takes an "mw-msg:" line of the output as a message key.
 */
function internalError( string $reason ): never {
	fwrite( STDERR, $reason . "\n" );
	exit( EXIT_INTERNAL );
}

[ , $articlePath, $fontName, $svgOnly ] = $argv + [ '', '/$1', 'FreeSans', 'no' ];
$source = file_get_contents( 'file' );
// With svg only, rsvg-convert makes the PNG and the PNG of this script is not used.
if ( $svgOnly !== 'yes' && !PngCanvas::hasFont( $fontName ) ) {
	internalError( 'libgd cannot use the font: it is not in GDFONTPATH, its name is a full path, or '
		. 'the gd extension has no FreeType support. The PNG would have no text.' );
}
$compiler = new Compiler( $articlePath, $svgOnly === 'yes' );
try {
	$scripts = $compiler->compile( $source === false ? '' : $source );

	$svg = new SvgCanvas( $scripts['svgMap'] );
	( new Plotter( $svg, $fontName ) )->run( $scripts['svg'] );
	// MediaWiki checks the SVG like an upload and stores it, so bound its size like that of the PNG.
	// Before the PNG: a timeline over a limit does not get one.
	$svgText = $compiler->fixSvg( $svg->result() );
	if ( strlen( $svgText ) > MAX_SVG_SIZE ) {
		$compiler->fail( 'Output SVG size exceeds ' . ( MAX_SVG_SIZE >> 20 ) . " MB. Image deleted.\n" );
	}
	$hasSize = Regex::match( '/width="([0-9.]+)" height="([0-9.]+)"/', $svgText, $size );
	if ( !$hasSize ) {
		// A very large size is written with an exponent
		$compiler->fail( "Output SVG has no usable size. Image deleted.\n" );
	}
	if ( (float)$size[1] > MAX_SVG_PIXELS || (float)$size[2] > MAX_SVG_PIXELS ) {
		$compiler->fail( 'Output SVG is larger than ' . MAX_SVG_PIXELS . 'x' . MAX_SVG_PIXELS
			. " pixels. Image deleted.\n" );
	}
	if ( (float)$size[1] * (float)$size[2] > MAX_SVG_AREA ) {
		$compiler->fail( 'Output SVG has more than ' . ( MAX_SVG_AREA / 1000000 ) . " megapixels. Image deleted.\n" );
	}
	$png = new PngCanvas( $scripts['pngMap'] );
	( new Plotter( $png, $fontName ) )->run( $scripts['png'] );

	$image = $png->result();
	if ( $image !== null && strlen( $image ) > 500 * 1024 ) {
		$compiler->fail( "Output image size exceeds 500 K. Image deleted.\n"
			. "Run with option -b (bypass checks) when this is correct.\n" );
	}
	if ( $image !== null ) {
		file_put_contents( 'file.png', $image );
	}
	$map = $png->map();
	if ( $map !== null ) {
		file_put_contents( 'file.map', Compiler::fixMap( $map ) );
	}
	file_put_contents( 'file.svg', $svgText );
	echo (int)$size[1] . ' ' . (int)$size[2] . "\n";
} catch ( CompileError $e ) {
	file_put_contents( 'file.err', $e->getMessage() );
} catch ( \Throwable $e ) {
	// Not the message of the exception: it can have text from the timeline.
	$caller = $e->getTrace()[0] ?? [];
	internalError( get_class( $e ) . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine()
		. ', from ' . basename( $caller['file'] ?? '?' ) . ':' . ( $caller['line'] ?? '?' ) );
}
