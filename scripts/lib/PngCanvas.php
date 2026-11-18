<?php

namespace MediaWiki\Extension\Timeline\Render;

use GdImage;

/**
 * PNG output with an HTML image map, drawn with libgd (PHP gd extension) the way the
 * ploticus GD driver (grgd.c) did it, so the files are byte for byte the same.
 */
final class PngCanvas extends Canvas {
	private const DPI = 100;
	/** Number of brushes, the line width selects one */
	private const BRUSHES = 8;

	private GdImage $im;
	/** @var GdImage[] */
	private array $brushes = [];
	/** Target of text measurement, which libgd only does while drawing */
	private GdImage $scratch;
	private int $width = 0;
	private int $height = 0;
	private float $scaleX = 1.0;
	private float $scaleY = 1.0;

	private float $x = 0.0;
	private float $y = 0.0;
	private string $fontName = '';
	private string $curColorName = '';
	private int $curColor = 0;
	private float $curLineWidth = 1.0;
	private float $textSize = 0.0;
	private ?string $png = null;
	private ?string $map = null;

	public function begin( float $width, float $height ): void {
		$this->width = self::trunc( $width * self::DPI );
		$this->height = self::trunc( $height * self::DPI );
		$this->scaleX = $this->width / $width;
		$this->scaleY = $this->height / $height;
		$this->im = imagecreate( $this->width, $this->height );
		for ( $i = 0; $i < self::BRUSHES; $i++ ) {
			$this->brushes[$i] = imagecreate( $i + 1, $i + 1 );
		}
		$this->scratch = imagecreate( 1, 1 );
		$this->color( 'white' );
		$this->color( 'black' );
		imagesetbrush( $this->im, $this->brushes[0] );
	}

	public function moveTo( float $x, float $y ): void {
		$this->x = $x;
		$this->y = $y;
	}

	public function lineTo( float $x, float $y ): void {
		// ploticus draws with gdStyledBrushed; the style of solid lines is all on.
		imageline( $this->im, $this->px( $this->x ), $this->py( $this->y ), $this->px( $x ), $this->py( $y ),
			IMG_COLOR_BRUSHED );
		$this->x = $x;
		$this->y = $y;
	}

	/**
	 * Filled rectangle in the given color (PLG_gifrect).
	 */
	public function rect( float $x1, float $y1, float $x2, float $y2, string $color ): void {
		[ $a, $b, $c, $d ] = [ $this->px( $x1 ), $this->py( $y1 ), $this->px( $x2 ), $this->py( $y2 ) ];
		$this->color( $color );
		imagefilledrectangle( $this->im, $a, $b, $c, $d, $this->curColor );
	}

	public function text( string $align, string $s ): void {
		$x = $this->px( $this->x );
		$y = $this->py( $this->y );
		if ( $align !== 'T' ) {
			// The bounding box depends on the position, so measure where the text starts.
			$box = $this->drawText( $this->scratch, $x, $y, $s );
			if ( $box === null ) {
				return;
			}
			$w = $box[4] - $box[0];
			$x -= $align === 'C' ? intdiv( $w, 2 ) : $w;
		}
		$this->drawText( $this->im, $x, $y, $s );
	}

	/**
	 * PLGG_font: a font name for libgd to look up in GDFONTPATH.
	 */
	public function font( string $font ): void {
		$name = self::gdFontName( $font );
		if ( $name !== null ) {
			$this->fontName = $name;
		}
	}

	/**
	 * The name that libgd gets for a font, or null for a name that the canvas ignores
	 * (a full path).
	 */
	private static function gdFontName( string $font ): ?string {
		return str_starts_with( $font, '/' ) ? null : Regex::replace( '/\.ttf$/', '', $font );
	}

	/**
	 * Whether the canvas can draw text with this font. It cannot when the name is a full
	 * path, when the font is not in GDFONTPATH, or when the gd extension has no FreeType
	 * support.
	 */
	public static function hasFont( string $font ): bool {
		$name = self::gdFontName( $font );
		if ( $name === null || !function_exists( 'imagettfbbox' ) ) {
			return false;
		}
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		return @imagettfbbox( 10, 0, $name, 'x' ) !== false;
	}

	public function color( string $color ): string {
		if ( $color === $this->curColorName ) {
			return $color;
		}
		$this->curColorName = $color;
		$color = strtr( $color, '(),/:|-', '       ' );
		if ( str_starts_with( $color, 'rgb' ) ) {
			$t = self::tokens( $color );
			$rgb = [ self::scanFloat( $t[1] ?? '' ), self::scanFloat( $t[2] ?? '' ), self::scanFloat( $t[3] ?? '' ) ];
			if ( in_array( null, $rgb, true ) ) {
				return $color;
			}
		} elseif ( str_starts_with( $color, 'gray' ) || str_starts_with( $color, 'grey' ) ) {
			$g = self::scanFloat( self::tokens( $color )[1] ?? '' );
			if ( $g === null ) {
				return $color;
			}
			$rgb = [ $g, $g, $g ];
		} elseif ( str_starts_with( $color, 'x' ) ) {
			$rgb = self::xrgb( substr( $color, str_starts_with( $color, 'xrgb' ) ? 5 : 1 ) );
			if ( $rgb === null ) {
				return $color;
			}
		} elseif ( self::isNumber( $color ) ) {
			$g = self::atof( $color );
			$rgb = [ $g, $g, $g ];
		} else {
			$rgb = self::namedColor( $color );
		}
		// The PNG keeps the low byte; 12 digit xrgb values go past 255, which PHP rejects.
		[ $r, $g, $b ] = array_map( static fn ( $c ) => self::trunc( $c * 255 ) & 0xff, $rgb );

		$this->curColor = self::allocate( $this->im, $r, $g, $b );
		foreach ( $this->brushes as $i => $brush ) {
			imagefilledrectangle( $brush, 0, 0, $i + 1, $i + 1, max( 0, self::allocate( $brush, $r, $g, $b ) ) );
		}
		imagesetbrush( $this->im, $this->brushes[$this->brushIndex( $this->curLineWidth )] );
		return $color;
	}

	public function textSize( int $points ): void {
		$this->textSize = (float)$points;
	}

	/**
	 * Only solid lines: the width selects the brush.
	 */
	public function lineWidth( float $width ): void {
		imagesetbrush( $this->im, $this->brushes[$this->brushIndex( $width )] );
		$this->curLineWidth = $width;
	}

	public function mapPoint( float $x, float $y ): array {
		return [ $this->px( $x ), $this->py( $y ) ];
	}

	/**
	 * Crop to the bounding box, encode, and write the image map.
	 */
	public function finish( float $x1, float $y1, float $x2, float $y2, array $regions ): void {
		if ( $x2 < -998 && $y2 < -998 ) {
			// nothing drawn
			return;
		}
		$x1 = max( $x1, 0.0 );
		$y1 = max( $y1, 0.0 );
		if ( $x2 > $this->width / (float)self::DPI || $x2 < 0.0 ) {
			$x2 = $this->width / (float)self::DPI;
		}
		if ( $y2 > $this->height / (float)self::DPI || $y2 < 0.0 ) {
			$y2 = $this->height / (float)self::DPI;
		}
		$left = $this->px( $x1 );
		$top = $this->py( $y2 );
		$width = $this->px( $x2 ) - $left;
		$height = $this->py( $y1 ) - $top;
		if ( $height < 10 || $width < 10 ) {
			return;
		}
		$out = imagecreate( $width, $height );
		imagecopy( $out, $this->im, 0, 0, $left, $top, $width, $height );
		ob_start();
		imagepng( $out );
		$this->png = ob_get_clean();

		if ( $regions ) {
			$this->map = '';
			foreach ( array_reverse( $regions ) as [ $url, $title, $rx1, $ry1, $rx2, $ry2 ] ) {
				[ $url, $target ] = self::splitTarget( $url );
				$this->map .= '<area shape="rect" ' . ( $url === '' ? 'nohref ' : "href=\"$url\"" )
					. sprintf( ' coords="%d,%d,%d,%d" ', $rx1 - $left, $ry1 - $top, $rx2 - $left, $ry2 - $top )
					. ( $title !== '' ? "title=\"$title\" alt=\"$title\" $target />\n" : "alt=\"\" $target />\n" );
			}
		}
	}

	public function result(): ?string {
		return $this->png;
	}

	public function map(): ?string {
		return $this->map;
	}

	private function px( float $x ): int {
		return self::trunc( $x * $this->scaleX );
	}

	private function py( float $y ): int {
		return $this->height - self::trunc( $y * $this->scaleY );
	}

	private function brushIndex( float $lineWidth ): int {
		return max( 0, min( self::trunc( $lineWidth ), self::BRUSHES - 1 ) );
	}

	/**
	 * gdImageStringFT at angle 0.
	 *
	 * @return int[]|null bounding rectangle, or null when the font is not available
	 */
	private function drawText( GdImage $im, int $x, int $y, string $s ): ?array {
		if ( $this->fontName === '' ) {
			return null;
		}
		// A missing font is not an error: ploticus skipped the text then.
		set_error_handler( static fn () => true );
		try {
			$box = imagettftext( $im, $this->textSize, 0, $x, $y, $this->curColor, $this->fontName, $s );
		} finally {
			restore_error_handler();
		}
		return $box ?: null;
	}

	/**
	 * Exact match, else a new palette entry, else the closest (-1 for an empty palette).
	 */
	private static function allocate( GdImage $im, int $r, int $g, int $b ): int {
		$c = imagecolorexact( $im, $r, $g, $b );
		if ( $c < 0 ) {
			$c = imagecolorallocate( $im, $r, $g, $b );
			if ( $c === false ) {
				$c = imagecolorclosest( $im, $r, $g, $b );
			}
		}
		return $c;
	}
}
