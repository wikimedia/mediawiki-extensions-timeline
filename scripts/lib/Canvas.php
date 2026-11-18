<?php

namespace MediaWiki\Extension\Timeline\Render;

/**
 * Output device of the Plotter. Coordinates are in inches from the bottom left corner.
 *
 * Ops that one of the two devices ignores have an empty default implementation.
 */
abstract class Canvas {
	private const COLOR_NAMES = [
		'white' => [ 1, 1, 1 ], 'black' => [ 0, 0, 0 ], 'transparent' => [ 1, 1, 1 ],
		'yellow' => [ 1, 1, 0 ], 'yellow2' => [ 0.92, 0.92, 0 ], 'dullyellow' => [ 1, 0.9, 0.6 ],
		'yelloworange' => [ 1, 0.85, 0 ], 'red' => [ 1, 0, 0 ], 'magenta' => [ 1, 0.3, 0.5 ],
		'tan1' => [ 0.9, 0.83, 0.79 ], 'tan2' => [ 0.7, 0.6, 0.6 ], 'coral' => [ 1, 0.6, 0.6 ],
		'claret' => [ 0.7, 0.3, 0.3 ], 'pink' => [ 1.0, 0.8, 0.8 ], 'brightgreen' => [ 0, 1, 0 ],
		'green' => [ 0, 0.7, 0 ], 'teal' => [ 0.0, 0.5, 0.2 ], 'drabgreen' => [ 0.6, 0.8, 0.6 ],
		'kelleygreen' => [ 0.3, 0.6, 0.3 ], 'yellowgreen' => [ 0.6, 0.9, 0.6 ], 'limegreen' => [ 0.8, 1, 0.7 ],
		'brightblue' => [ 0, 0, 1 ], 'blue' => [ 0, 0.4, 0.8 ], 'skyblue' => [ 0.7, 0.8, 1 ],
		'darkblue' => [ 0, 0, 0.60 ], 'oceanblue' => [ 0, 0.5, 0.8 ], 'purple' => [ 0.47, 0, 0.47 ],
		'lightpurple' => [ 0.67, 0.3, 0.67 ], 'lavender' => [ 0.8, 0.7, 0.8 ], 'powderblue' => [ 0.6, 0.6, 1 ],
		'powderblue2' => [ 0.7, 0.7, 1 ], 'orange' => [ 1, 0.62, 0.14 ], 'redorange' => [ 1, 0.5, 0 ],
		'lightorange' => [ 1, 0.80, 0.60 ], 'lightgray' => [ 0.85, 0.85, 0.85 ],
	];
	private const FLOAT_RE = '/^[ \t\n\r\f\x0B]*([+-]?(?:(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?|inf(?:inity)?|nan))/i';

	/**
	 * @param bool $clickmap collect link regions (an HTML map for PNG, link areas in SVG)
	 */
	public function __construct( public readonly bool $clickmap ) {
	}

	/**
	 * Start a page of the given size in inches.
	 */
	abstract public function begin( float $width, float $height ): void;

	abstract public function moveTo( float $x, float $y ): void;

	abstract public function lineTo( float $x, float $y ): void;

	/**
	 * Text at the last moveTo point.
	 *
	 * @param string $align T (left), C (centered) or J (right)
	 * @param string $s
	 */
	abstract public function text( string $align, string $s ): void;

	/**
	 * @return string the color spec as the C driver left it after parsing it in place
	 */
	abstract public function color( string $color ): string;

	abstract public function textSize( int $points ): void;

	abstract public function lineWidth( float $width ): void;

	/**
	 * Device coordinates of a point, for link regions.
	 *
	 * @return int[]
	 */
	abstract public function mapPoint( float $x, float $y ): array;

	/**
	 * Close the page; the bounding box of everything drawn is given in inches.
	 *
	 * @param float $x1
	 * @param float $y1
	 * @param float $x2
	 * @param float $y2
	 * @param array[] $regions link regions [ url, title, x1, y1, x2, y2 ] from mapPoint()
	 */
	abstract public function finish( float $x1, float $y1, float $x2, float $y2, array $regions ): void;

	/**
	 * Polygon vertex; the path is closed by fill().
	 */
	public function pathTo( float $x, float $y ): void {
	}

	public function fill(): void {
	}

	public function font( string $font ): void {
	}

	/**
	 * End the line in progress.
	 */
	public function endLine(): void {
	}

	/**
	 * PLG_colorname_to_rgb (unknown names are black)
	 *
	 * @return float[]
	 */
	protected static function namedColor( string $name ): array {
		return array_map( 'floatval', self::COLOR_NAMES[$name] ?? [ 0, 0, 0 ] );
	}

	/**
	 * PLG_xrgb_to_rgb: 6 or 12 hex digits, each component divided by 255.
	 *
	 * @return float[]|null
	 */
	protected static function xrgb( string $s ): ?array {
		preg_match( '/^[0-9a-fA-F]{0,12}/', $s, $m );
		$h = $m[0];
		if ( strlen( $h ) === 6 ) {
			return [ hexdec( substr( $h, 0, 2 ) ) / 255.0, hexdec( substr( $h, 2, 2 ) ) / 255.0,
				hexdec( substr( $h, 4, 2 ) ) / 255.0 ];
		}
		if ( strlen( $h ) === 12 ) {
			return [ hexdec( substr( $h, 0, 4 ) ) / 255.0, hexdec( substr( $h, 4, 4 ) ) / 255.0,
				hexdec( substr( $h, 8, 4 ) ) / 255.0 ];
		}
		return null;
	}

	/**
	 * get_targetstr: a link may start with "[target]".
	 *
	 * @return string[] [ url, target attribute ]
	 */
	protected static function splitTarget( string $url ): array {
		if ( !str_starts_with( $url, '[' ) ) {
			return [ $url, '' ];
		}
		$j = strpos( $url, ']' );
		if ( $j === false ) {
			return [ str_replace( '[', ' ', $url ), '' ];
		}
		$head = str_replace( '[', ' ', substr( $url, 0, $j ) ) . ' ';
		return [ substr( $url, $j + 1 ), self::tokens( $head, 1 )[0] ?? '' ];
	}

	// ---------------------------------------------------------------- values with C semantics
	// Color specs here and script values in Plotter are read like the C code read them.

	/**
	 * strtod() prefix parse.
	 *
	 * @return array{0: float, 1: int} value and number of consumed bytes (0 if no number)
	 */
	private static function strtod( string $s ): array {
		if ( !preg_match( self::FLOAT_RE, $s, $m ) ) {
			return [ 0.0, 0 ];
		}
		$t = strtolower( $m[1] );
		$neg = str_starts_with( $t, '-' );
		$u = ltrim( $t, '+-' );
		if ( str_starts_with( $u, 'inf' ) ) {
			$v = INF;
		} elseif ( $u === 'nan' ) {
			$v = NAN;
		} else {
			$v = (float)$u;
		}
		return [ $neg ? -$v : $v, strlen( $m[0] ) ];
	}

	public static function atof( string $s ): float {
		return self::strtod( $s )[0];
	}

	public static function atoi( string $s ): int {
		if ( !preg_match( '/^[ \t\n\r\f\x0B]*([+-]?\d+)/', $s, $m ) ) {
			return 0;
		}
		return (int)$m[1];
	}

	/**
	 * sscanf( s, "%lf" ): the number or null.
	 */
	public static function scanFloat( string $s ): ?float {
		[ $v, $n ] = self::strtod( $s );
		return $n > 0 ? $v : null;
	}

	/**
	 * sscanf( s, "%s %s ..." ): whitespace separated tokens.
	 *
	 * @return string[]
	 */
	public static function tokens( string $s, int $max = PHP_INT_MAX ): array {
		$t = preg_split( '/[ \t\n\r\f\x0B]+/', trim( $s, " \t\n\r\f\v" ), -1, PREG_SPLIT_NO_EMPTY );
		return array_slice( $t, 0, $max );
	}

	/**
	 * Truncation toward zero, like a C (int) cast of a double.
	 */
	public static function trunc( float $x ): int {
		if ( is_nan( $x ) || is_infinite( $x ) ) {
			return PHP_INT_MIN;
		}
		return (int)$x;
	}

	/**
	 * GL_goodnum: a plain decimal number, optionally signed, with an e+NN or e-NN exponent.
	 */
	public static function isNumber( string $s ): bool {
		$s = trim( $s, " \t\n\r\x0B\f" );
		if ( $s === '' || $s === '.' || $s === '+' || $s === '-' ) {
			return false;
		}
		$len = strlen( $s );
		$dot = false;
		for ( $p = 0; $p < $len; $p++ ) {
			$c = $s[$p];
			if ( $c === '.' ) {
				if ( $dot ) {
					return false;
				}
				$dot = true;
			} elseif ( $p === 0 && ( $c === '-' || $c === '+' ) ) {
				continue;
			} elseif ( $p > 0 && strtolower( $c ) === 'e' ) {
				$p++;
				if ( ( $s[$p] ?? '' ) !== '-' && ( $s[$p] ?? '' ) !== '+' ) {
					return false;
				}
				if ( $p + 1 >= $len ) {
					return false;
				}
			} elseif ( !ctype_digit( $c ) ) {
				return false;
			}
		}
		return true;
	}
}
