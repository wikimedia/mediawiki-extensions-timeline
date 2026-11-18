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

spl_autoload_register( static function ( string $class ): void {
	require __DIR__ . '/lib/' . substr( strrchr( $class, '\\' ), 1 ) . '.php';
} );

[ , $articlePath, $fontName, $svgOnly ] = $argv + [ '', '/$1', 'FreeSans', 'no' ];
$source = file_get_contents( 'file' );
$compiler = new Compiler( $articlePath, $svgOnly === 'yes' );
try {
	$scripts = $compiler->compile( $source === false ? '' : $source );

	$svg = new SvgCanvas( $scripts['svgMap'] );
	( new Plotter( $svg, $fontName ) )->run( $scripts['svg'] );
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
	$svgText = $compiler->fixSvg( $svg->result() );
	file_put_contents( 'file.svg', $svgText );
	if ( preg_match( '/width="([0-9.]+)" height="([0-9.]+)"/', $svgText, $m ) ) {
		echo (int)$m[1] . ' ' . (int)$m[2] . "\n";
	}
} catch ( CompileError $e ) {
	file_put_contents( 'file.err', $e->getMessage() );
} catch ( \Throwable ) {
	echo "Internal error\n";
	exit( 1 );
}
