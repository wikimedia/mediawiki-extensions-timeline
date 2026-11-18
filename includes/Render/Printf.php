<?php

namespace MediaWiki\Extension\Timeline\Render;

/**
 * Number formatting as C printf does it, which the scripts and the SVG output depend on.
 */
final class Printf {

	/**
	 * printf "%.{prec}g" with C sign handling.
	 */
	public static function g( float $x, int $prec = 6 ): string {
		if ( $x == 0.0 ) {
			return fdiv( 1, $x ) < 0 ? '-0' : '0';
		}
		if ( is_nan( $x ) ) {
			return $x < 0 ? '-nan' : 'nan';
		}
		if ( is_infinite( $x ) ) {
			return $x > 0 ? 'inf' : '-inf';
		}
		[ $mant, $exp ] = explode( 'e', sprintf( '%.' . ( $prec - 1 ) . 'e', $x ) );
		$e = (int)$exp;
		if ( $e < -4 || $e >= $prec ) {
			if ( str_contains( $mant, '.' ) ) {
				$mant = rtrim( rtrim( $mant, '0' ), '.' );
			}
			return $mant . 'e' . ( $e < 0 ? '-' : '+' ) . sprintf( '%02d', abs( $e ) );
		}
		$s = sprintf( '%.' . ( $prec - 1 - $e ) . 'f', $x );
		if ( str_contains( $s, '.' ) ) {
			$s = rtrim( rtrim( $s, '0' ), '.' );
		}
		return $s;
	}

	/**
	 * printf "%{spec}f" (for example spec "-5.2"); keeps the sign of negative zero like C.
	 */
	public static function f( float $x, string $spec ): string {
		if ( !( $x == 0.0 && fdiv( 1, $x ) < 0 ) ) {
			return sprintf( "%{$spec}f", $x );
		}
		Regex::match( '/^(-?)(\d*)(?:\.(\d+))?$/', $spec, $m );
		$s = '-' . sprintf( '%.' . ( $m[3] ?? '6' ) . 'f', 0.0 );
		return str_pad( $s, (int)$m[2], ' ', $m[1] ? STR_PAD_RIGHT : STR_PAD_LEFT );
	}
}
