<?php

namespace MediaWiki\Extension\Timeline;

/**
 * Exit with a localized error message
 *
 * @param string $msg
 */
function errorExit( string $msg ) {
	fwrite( STDERR, "mw-msg:\t$msg\n" );
	exit( 20 );
}

function extractSVGSize() {
	global $argv;

	if ( PHP_SAPI !== 'cli' ) {
		exit( 1 );
	}
	if ( !isset( $argv[1] ) ) {
		fwrite( STDERR, "Usage: extractSVGSize.php <filename>\n" );
		exit( 1 );
	}

	// Lower backtracking limit as extra hardening
	ini_set( 'pcre.backtrack_limit', '500' );

	$fileName = $argv[1];
	$f = fopen( $fileName, 'r' );
	if ( !$f ) {
		errorExit( 'timeline-readerr' );
	}
	while ( !feof( $f ) ) {
		$line = fgets( $f );
		if ( $line === false ) {
			errorExit( 'timeline-readerr' );
		}
		if ( preg_match( '/width="([0-9.]+)" height="([0-9.]+)"/', $line, $m ) ) {
			// width then height
			echo (int)$m[1] . ' ' . (int)$m[2] . "\n";
			exit( 0 );
		}
	}
	errorExit( 'timeline-readerr' );
}

extractSVGSize();
