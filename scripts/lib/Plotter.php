<?php

namespace MediaWiki\Extension\Timeline\Render;

/**
 * Runs a plot script from the Compiler on a Canvas.
 *
 * This is the part of ploticus 2.42 (Stephen C. Grubb, GPL-2.0-or-later) that the scripts
 * use: the script reader, the drawing layer with units, dates and the bounding box, the
 * procs (page, areadef, getdata, bars, axis, annotate, line, rect, legend) and link regions.
 * Quirks of the C code that change the output are kept.
 */
final class Plotter {
	private const LINEAR = 0;
	private const DATE = 2;
	private const FILLCHAR = "\x03";
	private const SPACECHAR = "\x04";

	private string $stdFont;

	// script reader / execline state
	/** @var string[] */
	private array $procline = [];
	private array $objname = [];
	private array $objstart = [ 0 ];
	private array $objlen = [];
	private int $nobj = 0;
	private int $nlhold = 0;
	private int $curline = 0;
	private int $procstop = 0;
	private bool $lastbs = false;
	private string $procname = '';
	private string $saveasName = '';
	private string $lastProctok = '';
	private string $clonelist = '';
	private bool $holdmem = false;
	private int $attrState = 0;
	private int $cloneIx = 0;
	private bool $eready = false;
	private bool $skipout = false;
	private int $npages = 0;
	/** @var array<string,string> */
	private array $vars = [];

	// graphics state (PLG)
	private string $stdTextColor = 'black';
	private string $stdBkColor = 'white';
	private int $stdTextSize = 10;
	private float $winw = 8.0;
	private float $winh = 8.0;
	private string $curFont = '';
	private int $curTextSize = 0;
	private float $curTextHeight = 0.0;
	private float $curTextWidth = 0.0;
	private float $curLineWidth = -1.0;
	private string $curColor = '';
	private string $curBkColor = '';
	private string $nextColor = '';
	private bool $flip = false;
	private float $xlo = 0;
	private float $xhi = 0;
	private float $ylo = 0;
	private float $yhi = 0;
	private float $dxlo = 0;
	private float $dxhi = 0;
	private float $dylo = 0;
	private float $dyhi = 0;
	private float $scaleX = 1.0;
	private float $scaleY = 1.0;
	private float $ex1 = 0.0;
	private float $ey1 = 0.0;

	// pcode state
	private bool $colorChgPending = false;
	private string $prevOp = '';
	private bool $keepingBb = true;
	private float $bbX1 = 999;
	private float $bbY1 = 999;
	private float $bbX2 = -999;
	private float $bbY2 = -999;
	private float $lmlx = 0.0;
	private float $lmly = 0.0;

	// units / dates
	private array $unittyp = [ 'x' => self::LINEAR, 'y' => self::LINEAR ];
	private bool $dashindate = false;
	private string $dashindateaxis = '0';
	private string $fmtstring = 'mmddyy';
	private bool $convError = false;

	// data and legend
	/** @var string[][] */
	private array $records = [];
	private int $nfields = 0;
	/**
	 * Legend entries as stored by proc_legend.c: six int arrays of 100 entries without a
	 * bounds check and a 5000 byte text buffer. Offsets (in ints) follow the layout in the
	 * production ploticus binary, so entries past 100 overwrite what they overwrite there.
	 *
	 * @var int[]
	 */
	private array $legMem = [];
	private string $legText = '';
	private const LE_TAG = 0;
	private const LE_LABEL = 104;
	private const LE_PARM3 = 208;
	private const LE_PARM2 = 312;
	private const LE_PARM1 = 416;
	private const LE_TYPE = 520;
	private const LE_AVAIL = 620;
	private const LE_NLE = 621;
	/** @var array[] clickmap entries [ url, title, x1, y1, x2, y2 ] */
	private array $clickmap = [];

	/**
	 * @param Canvas $canvas
	 * @param string $font font name for SVG text
	 */
	public function __construct( private Canvas $canvas, string $font ) {
		$this->stdFont = $font;
		$this->vars = [ 'CM_UNITS' => '0', 'PLVERSION' => '2.42',
			'DEVICE' => $canvas instanceof SvgCanvas ? 'svg' : 'png' ];
	}

	/**
	 * Run a script; the output is in the canvas.
	 */
	public function run( string $script ): void {
		$this->eready = false;
		$lines = explode( "\n", $script );
		if ( end( $lines ) === '' ) {
			array_pop( $lines );
		}
		foreach ( $lines as $line ) {
			$tok = self::getok( $line );
			if ( str_starts_with( $tok, '//' ) ) {
				continue;
			}
			if ( str_ends_with( $line, "\r" ) ) {
				$line = substr( $line, 0, -1 );
			}
			if ( !in_array( $tok, [ '#if', '#elseif', '#set', '#call', '#setifnotgiven' ], true ) ) {
				$line = $this->valueSubst( $line );
			}
			if ( str_starts_with( $tok, '#' ) ) {
				if ( $tok === '#+' || $tok === '#print' ) {
					$line = (string)substr( $line, strpos( $line, $tok ) + strlen( $tok ) + 1 );
				} elseif ( !Regex::match( '/^(?:#proc|#endproc$|#clone|#ifspec|#saveas|#intrailer$|##)/', $tok ) ) {
					// #if and friends, secondary ops and unknown directives: not rendered
					continue;
				}
			}
			$first = self::getok( substr( $line, 0, 40 ) );
			$ix = strlen( $first ) + strspn( substr( $line, 0, 40 ), " \t\n" );
			if ( $first === '#proc' && self::getok( substr( $line, $ix ) ) === 'trailer' ) {
				break;
			}
			$this->execline( $line );
			if ( $this->skipout ) {
				break;
			}
		}
		$this->execline( '#endproc' );
		if ( $this->eready ) {
			$this->pcode( 'Q', 0.0, 0.0, '' );
		}
	}

	/**
	 * TDH_value_subst with no data: @@ becomes @, known @VARIABLES are replaced.
	 */
	private function valueSubst( string $in ): string {
		$out = '';
		$len = strlen( $in );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $in[$i];
			if ( $c === '@' && ( $in[$i + 1] ?? '' ) === '@' ) {
				$out .= '@';
				$i++;
				continue;
			}
			if ( $c === '@' && !ctype_space( $in[$i + 1] ?? ' ' ) ) {
				Regex::match( '/^[A-Za-z0-9_]*/', substr( $in, $i + 1 ), $m );
				$name = $m[0];
				$out .= $this->vars[$name] ?? ( '@' . $name );
				$i += strlen( $name );
				continue;
			}
			$out .= $c;
		}
		return $out;
	}

	/**
	 * GL_getok: first whitespace-delimited token (at most 256 chars).
	 */
	private static function getok( string $s, int &$ix = 0 ): string {
		$len = strlen( $s );
		while ( $ix < $len && str_contains( " \t\n", $s[$ix] ) ) {
			$ix++;
		}
		$start = $ix;
		while ( $ix < $len && !str_contains( " \t\n\r", $s[$ix] ) && $ix - $start <= 255 ) {
			$ix++;
		}
		return substr( $s, $start, $ix - $start );
	}

	// ------------------------------------------------------------ execline

	private function execline( string $line ): void {
		if ( $this->skipout ) {
			return;
		}
		$line = rtrim( $line, " \t\n\r\x0B\f" );
		$first = self::getok( substr( $line, 0, 40 ) );
		$endproc = false;
		if ( $first === '#endproc' ) {
			$first = '#proc';
			$endproc = true;
			if ( count( $this->procline ) > 0 && count( $this->procline ) < 4999 ) {
				$this->procline[] = "\n";
			}
		}
		if ( str_starts_with( $first, '#proc' ) && !$this->lastbs ) {
			if ( $this->saveasName !== '' ) {
				$this->objname[$this->nobj] = $this->saveasName;
			}
			$this->objlen[$this->nobj] = count( $this->procline ) - $this->nlhold;
			if ( $this->lastProctok === '#proc' ) {
				// Scripts start with proc page, which sets up the canvas.
				if ( $this->procname === 'page' ) {
					$this->eready = true;
				}
				$this->procCall( $this->procname );
			}
			if ( $endproc ) {
				$this->procname = 'endproc';
			} else {
				$t = Canvas::tokens( $line, 2 );
				$this->procname = $t[1] ?? $this->procname;
			}
			if ( !$this->holdmem && $this->saveasName === '' ) {
				array_splice( $this->procline, $this->nlhold );
			} else {
				$this->nobj++;
			}
			$this->holdmem = false;
			$this->lastProctok = $first;
			$this->procname = rtrim( $this->procname, ':' );
			$this->saveasName = '';
			$this->clonelist = '';
			$this->objname[$this->nobj] = '';
			$this->objstart[$this->nobj] = count( $this->procline );
			$this->nlhold = count( $this->procline );
			return;
		}
		if ( $this->procname === '' ) {
			return;
		}
		if ( !$this->lastbs && str_starts_with( $first, '#clone' ) ) {
			$name = Canvas::tokens( $line, 2 )[1] ?? '';
			if ( $name !== '' ) {
				$this->clonelist .= "$name ";
			}
		} elseif ( !$this->lastbs && str_starts_with( $first, '#saveas' ) ) {
			$this->saveasName = Canvas::tokens( $line, 2 )[1] ?? '';
		} elseif ( !$this->lastbs && str_starts_with( $first, '#ifspec' ) ) {
			// no variables are ever set for #ifspec
			$this->procline[] = '';
		} else {
			// -maxproclines: the lines of the current proc plus all held procs
			if ( count( $this->procline ) >= 4999 ) {
				$this->skipout = true;
				return;
			}
			$this->procline[] = $line;
			$this->lastbs = strlen( $line ) >= 2 && $line[strlen( $line ) - 2] === '\\';
		}
	}

	/**
	 * PL_getnextattr: next attribute line of the current proc (clones first).
	 *
	 * @return array|null [ attr, value ]
	 */
	private function nextAttr( bool $first ): ?array {
		if ( $first ) {
			$this->attrState = 0;
			$this->cloneIx = 0;
		}
		while ( true ) {
			if ( $this->attrState === 3 ) {
				return null;
			}
			if ( $this->attrState === 0 ) {
				$clone = self::getok( $this->clonelist, $this->cloneIx );
				if ( $clone !== '' ) {
					for ( $j = $this->nobj - 1; $j >= 0; $j-- ) {
						if ( ( $this->objname[$j] ?? '' ) === $clone ) {
							break;
						}
					}
					if ( $j < 0 ) {
						continue;
					}
					$this->curline = $this->objstart[$j];
					$this->procstop = $this->objstart[$j] + $this->objlen[$j];
					$this->attrState = 1;
				} else {
					$this->curline = $this->objstart[$this->nobj];
					$this->procstop = count( $this->procline );
					$this->attrState = 2;
				}
			}
			if ( $this->curline >= count( $this->procline ) ) {
				return null;
			}
			$line = $this->procline[$this->curline];
			$ix = 0;
			$attr = substr( self::getok( $line, $ix ), 0, 38 );
			if ( $attr === '' ) {
				$this->curline++;
				if ( $this->curline >= $this->procstop ) {
					if ( $this->attrState === 1 ) {
						$this->attrState = 0;
					} else {
						$this->attrState = 3;
						return null;
					}
				}
				continue;
			}
			if ( str_ends_with( $attr, ':' ) ) {
				$attr = substr( $attr, 0, -1 );
			}
			$len = strlen( $line );
			while ( $ix < $len && ctype_space( $line[$ix] ) ) {
				$ix++;
			}
			$this->curline++;
			if ( $this->curline >= $this->procstop ) {
				$this->attrState = $this->attrState === 1 ? 0 : 3;
			}
			return [ $attr, (string)substr( $line, $ix ) ];
		}
	}

	/**
	 * PL_getmultiline: value plus following lines up to the next blank line.
	 */
	private function getMultiline( string $firstline ): string {
		$mem = '';
		$fl = ltrim( $firstline, " \t\n\r\x0B\f" );
		if ( $fl !== '' ) {
			$mem = "$fl\n";
		}
		for ( $i = $this->curline; $i <= $this->procstop; $i++ ) {
			$line = $this->procline[$i] ?? null;
			if ( $line === null ) {
				break;
			}
			$l = ltrim( $line, " \t\n\r\x0B\f" );
			if ( $l === '' ) {
				break;
			}
			if ( $l[0] === '\\' ) {
				$l = substr( $l, 1 );
			}
			$mem .= "$l\n";
		}
		$this->curline = $i;
		return $mem;
	}

	/**
	 * @return string[][] attributes of the current proc as [ attr, value ] (multiline values joined)
	 */
	private function attrs( array|\Closure $multiline = [] ): array {
		$out = [];
		for ( $a = $this->nextAttr( true ); $a !== null; $a = $this->nextAttr( false ) ) {
			if ( is_array( $multiline ) ? in_array( $a[0], $multiline, true ) : $multiline( $a[0], $a[1] ) ) {
				$a[1] = $this->getMultiline( $a[1] );
			}
			$out[] = $a;
		}
		return $out;
	}

	private function procCall( string $name ): void {
		match ( $name ) {
			'page' => $this->procPage(),
			'areadef' => $this->procAreadef(),
			'xaxis' => $this->procAxis( 'x' ),
			'yaxis' => $this->procAxis( 'y' ),
			'getdata' => $this->procGetdata(),
			'legendentry' => $this->procLegendentry(),
			'legend' => $this->procLegend(),
			'bars' => $this->procBars(),
			'annotate' => $this->procAnnotate(),
			'line' => $this->procLine(),
			'rect' => $this->procRect(),
			default => null,
		};
	}

	// ------------------------------------------------------------ graphics init (init.c, stub.c)

	private function init(): void {
		$this->canvas->begin( $this->winw, $this->winh );
		// PLG_setdefaults
		$this->font( $this->stdFont );
		$this->textsize( 10 );
		$this->linetype( 0.6 );
		$this->color( $this->stdTextColor );
		$this->pcode( 'v', 0.0, 0.0, '' );
		$this->curBkColor = $this->stdBkColor;
	}

	private function font( string $s ): void {
		if ( $s === '' ) {
			$this->pcode( 'F', 0.0, 0.0, $this->stdFont );
			$this->curFont = $this->stdFont;
		} elseif ( $s !== $this->curFont ) {
			$this->pcode( 'F', 0.0, 0.0, $s );
			$this->curFont = $s;
		}
	}

	private function textsize( int $x ): void {
		$p = $x === 0 ? (float)$this->stdTextSize : $x * ( $this->stdTextSize / 10.0 );
		$this->curTextHeight = ( $p + 2.0 ) / 72.0;
		if ( Canvas::trunc( $p ) !== $this->curTextSize ) {
			$this->pcode( 'I', $p, 0.0, '' );
			$this->curTextSize = Canvas::trunc( $p );
			if ( $p >= 14 ) {
				$this->curTextHeight = $p / 72.0;
			}
			$this->curTextWidth = $this->curTextHeight * 0.4;
		}
	}

	/**
	 * PLG_linetype: EasyTimeline only draws solid lines, so only the width matters.
	 */
	private function linetype( float $linewidth ): void {
		if ( $linewidth != $this->curLineWidth ) {
			$this->pcode( 'Y', $linewidth, 1.0, '0' );
			$this->curLineWidth = $linewidth;
		}
	}

	private function color( string $s ): void {
		if ( $s === '' ) {
			$this->curColor = $this->stdTextColor;
		}
		$this->pcode( 'r', 0.0, 0.0, $s );
	}

	private function colorfill( string $c ): void {
		$old = $this->nextColor;
		$this->color( $c );
		$this->pcode( 's', 0.0, 0.0, '' );
		$this->color( $old );
	}

	private function mov( float $x, float $y ): void {
		$this->flip ? $this->pcode( 'M', $y, $x, '' ) : $this->pcode( 'M', $x, $y, '' );
	}

	private function lin( float $x, float $y ): void {
		$this->flip ? $this->pcode( 'L', $y, $x, '' ) : $this->pcode( 'L', $x, $y, '' );
	}

	private function path( float $x, float $y ): void {
		$this->flip ? $this->pcode( 'P', $y, $x, '' ) : $this->pcode( 'P', $x, $y, '' );
	}

	/**
	 * PLG_dotext: draw text with embedded newlines.
	 */
	private function dotext( string $s, string $op ): void {
		$x = $this->ex1;
		$y = $this->ey1;
		$op = match ( strtolower( $op ) ) {
			'l' => 'T',
			'r' => 'J',
			default => strtoupper( $op ),
		};
		if ( !str_contains( 'TCJDU', $op ) ) {
			$op = 'C';
		}
		$slen = strlen( $s );
		for ( $i = 0; ; ) {
			$chunk = self::getseg( $s, $i, "\n" );
			$this->pcode( $op, 0.0, 0.0, $chunk );
			if ( $i >= $slen ) {
				break;
			}
			$y -= $this->curTextHeight;
			$this->flip ? $this->mov( $y, $x ) : $this->mov( $x, $y );
		}
	}

	/**
	 * GL_getseg: field up to the next separator; backslash escapes a separator.
	 */
	private static function getseg( string $s, int &$i, string $sep ): string {
		$out = '';
		$len = strlen( $s );
		while ( true ) {
			if ( $i >= $len ) {
				break;
			}
			$c = $s[$i];
			if ( str_contains( $sep, $c ) ) {
				$i++;
				break;
			}
			if ( $c === '\\' && $i + 1 < $len && str_contains( $sep, $s[$i + 1] ) ) {
				$i++;
				$out .= $s[$i++];
				continue;
			}
			$out .= $c;
			$i++;
			if ( strlen( $out ) >= 511 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * PLG_cblock: filled block, optionally outlined.
	 */
	private function cblock( float $xlo, float $ylo, float $xhi, float $yhi, string $color, bool $outline ): void {
		if ( $color !== '' && strncasecmp( $color, 'hatch', 5 ) !== 0 ) {
			if ( $this->canvas instanceof PngCanvas ) {
				if ( $this->flip ) {
					[ $gxlo, $gylo, $gxhi, $gyhi ] = [ $ylo, $xlo, $yhi, $xhi ];
				} else {
					[ $gxlo, $gylo, $gxhi, $gyhi ] = [ $xlo, $ylo, $xhi, $yhi ];
				}
				if ( $gxhi < $gxlo ) {
					[ $gxlo, $gxhi ] = [ $gxhi, $gxlo ];
				}
				if ( $gyhi < $gylo ) {
					[ $gylo, $gyhi ] = [ $gyhi, $gylo ];
				}
				// PLG_gifrect
				$old = $this->curColor;
				$this->curColor = $color;
				$this->canvas->rect( $gxlo, $gyhi, $gxhi, $gylo, $color );
				$this->bb( $gxlo, $gylo );
				$this->bb( $gxhi, $gyhi );
				$this->color( $old );
			} else {
				$this->mov( $xlo, $ylo );
				$this->path( $xlo, $yhi );
				$this->path( $xhi, $yhi );
				$this->path( $xhi, $ylo );
				$this->colorfill( $color );
			}
		}
		if ( $outline ) {
			$this->mov( $xlo, $ylo );
			$this->lin( $xlo, $yhi );
			$this->lin( $xhi, $yhi );
			$this->lin( $xhi, $ylo );
			$this->lin( $xlo, $ylo );
			$this->lin( $xlo, $yhi );
		}
	}

	// ------------------------------------------------------------ pcode

	public function pcode( string $op, float $x, float $y, string $s ): void {
		if ( $op === 'r' ) {
			$this->nextColor = $s;
			$this->colorChgPending = $s !== $this->curColor;
			return;
		}
		if ( $this->colorChgPending && str_contains( 'LTCJszv.', $op ) ) {
			$this->pcode( 'k', 0.0, 0.0, $this->nextColor );
			$this->colorChgPending = false;
			if ( $op === 'v' ) {
				return;
			}
		}
		if ( $op === 'k' ) {
			$this->curColor = $s;
		}
		if ( $op === 'z' ) {
			$this->keepingBb = false;
			$this->cblock( 0.0, 0.0, $this->winw, $this->winh, $s, false );
			$this->keepingBb = true;
			return;
		}
		if ( $op === 'M' ) {
			$this->ex1 = $x;
			$this->ey1 = $y;
		}

		$c = $this->canvas;
		switch ( $op ) {
			case 'L':
				$c->lineTo( $x, $y );
				break;
			case 'M':
				$c->moveTo( $x, $y );
				break;
			case 'P':
				$c->pathTo( $x, $y );
				break;
			case 'T':
			case 'C':
			case 'J':
				$c->text( $op, $s );
				break;
			case 's':
				$c->fill();
				return;
			case 'k':
				$this->nextColor = $c->color( $s );
				return;
			case 'I':
				$c->textSize( Canvas::trunc( $x ) );
				return;
			case 'F':
				$c->font( $s );
				// Unlike the SVG driver, the PNG driver keeps this op as the previous op.
				if ( $c instanceof SvgCanvas ) {
					return;
				}
				break;
			case 'Y':
				$c->lineWidth( $x );
				return;
			case 'Q':
				$c->finish( $this->bbX1, $this->bbY1, $this->bbX2, $this->bbY2, $this->clickmap );
				$this->resetBb();
				return;
			default:
				$c->endLine();
		}

		if ( $op === 'M' ) {
			$this->lmlx = $x;
			$this->lmly = $y;
		}
		$txtWidth = 0.0;
		if ( str_contains( 'TCJ', $op ) ) {
			$txtWidth = strlen( $s ) * $this->curTextWidth;
		}
		if ( $this->keepingBb ) {
			if ( str_contains( 'LP.', $op ) ) {
				if ( $this->prevOp === 'M' ) {
					$this->bb( $this->lmlx, $this->lmly );
				}
				$this->bb( $x, $y );
			} elseif ( $op === 'T' ) {
				if ( $this->prevOp === 'M' ) {
					$this->bb( $this->lmlx, $this->lmly );
				}
				$this->bb( $this->lmlx + $txtWidth + 0.05, $this->lmly + $this->curTextHeight );
			} elseif ( $op === 'C' ) {
				$this->bb( $this->lmlx - ( ( $txtWidth / 2.0 ) + 0.05 ), $this->lmly );
				$this->bb( $this->lmlx + ( ( $txtWidth / 2.0 ) + 0.05 ), $this->lmly + $this->curTextHeight );
			} elseif ( $op === 'J' ) {
				$this->bb( $this->lmlx - ( $txtWidth + 0.05 ), $this->lmly );
				$this->bb( $this->lmlx, $this->lmly + $this->curTextHeight );
			}
		}
		$this->prevOp = $op;
	}

	public function bb( float $x, float $y ): void {
		if ( $this->keepingBb ) {
			if ( $x < $this->bbX1 ) {
				$this->bbX1 = $x;
			}
			if ( $x > $this->bbX2 ) {
				$this->bbX2 = $x;
			}
			if ( $y < $this->bbY1 ) {
				$this->bbY1 = $y;
			}
			if ( $y > $this->bbY2 ) {
				$this->bbY2 = $y;
			}
		}
	}

	private function resetBb(): void {
		$this->bbX1 = 999;
		$this->bbY1 = 999;
		$this->bbX2 = -999;
		$this->bbY2 = -999;
	}

	// ------------------------------------------------------------ scaling and units

	private function setScale( string $axis, float $alo, float $ahi, string $lo, string $hi ): bool {
		$slo = $this->evalbound( $lo, $axis );
		$shi = $this->evalbound( $hi, $axis );
		if ( $axis === 'x' ) {
			$this->xlo = $alo;
			$this->xhi = $ahi;
			$this->dxlo = $slo;
			$this->dxhi = $shi;
			if ( $shi - $slo <= 0 || $ahi - $alo <= 0 ) {
				return false;
			}
			$this->scaleX = ( $ahi - $alo ) / ( $shi - $slo );
		} else {
			$this->ylo = $alo;
			$this->yhi = $ahi;
			$this->dylo = $slo;
			$this->dyhi = $shi;
			if ( $shi - $slo <= 0 || $ahi - $alo <= 0 ) {
				return false;
			}
			$this->scaleY = ( $ahi - $alo ) / ( $shi - $slo );
		}
		return true;
	}

	private function evalbound( string $val, string $axis ): float {
		$len = strlen( $val );
		$op = 0;
		$cut = $len;
		$sub = '';
		for ( $i = 1; $i < $len; $i++ ) {
			$c = $val[$i];
			if ( ( $c === '+' || ( $c === '-' && !$this->dashindate ) ) && strtolower( $val[$i - 1] ) !== 'e' ) {
				$op = $c === '+' ? 1 : 2;
				$cut = min( $cut, $i );
				$sub = substr( $val, $i + 1 );
			}
		}
		$r = $this->conv( $axis, substr( $val, 0, $cut ) );
		if ( $op === 1 ) {
			$r += Canvas::atof( $sub );
		} elseif ( $op === 2 ) {
			$r -= Canvas::atof( $sub );
		}
		return $r;
	}

	private function limit( string $axis, string $end, string $units ): float {
		if ( $axis === 'x' ) {
			if ( $units === 's' ) {
				return $end === 'l' ? $this->dxlo : $this->dxhi;
			}
			return $end === 'l' ? $this->xlo : $this->xhi;
		}
		if ( $units === 's' ) {
			return $end === 'l' ? $this->dylo : $this->dyhi;
		}
		return $end === 'l' ? $this->ylo : $this->yhi;
	}

	private function ax( float $d ): float {
		return $this->xlo + ( ( $d - $this->dxlo ) * $this->scaleX );
	}

	private function ay( float $d ): float {
		return $this->ylo + ( ( $d - $this->dylo ) * $this->scaleY );
	}

	/**
	 * PLG_a: absolute location of a data value, honoring flip.
	 */
	private function a( string $xory, float $d ): float {
		if ( $this->flip ) {
			return $xory === 'x' ? $this->ay( $d ) : $this->ax( $d );
		}
		return $xory === 'x' ? $this->ax( $d ) : $this->ay( $d );
	}

	private function setUnits( string $axis, string $s ): void {
		$t = Canvas::tokens( $s, 2 );
		$tok = $t[0] ?? '';
		if ( $tok === 'date' ) {
			$this->unittyp[$axis] = self::DATE;
			$this->dashindate = false;
			$this->dashindateaxis = '0';
			if ( isset( $t[1] ) ) {
				$this->fmtstring = $t[1];
			}
			if ( str_contains( $this->fmtstring, '-' ) ) {
				$this->dashindate = true;
				$this->dashindateaxis = $axis;
			}
		} else {
			$this->unittyp[$axis] = self::LINEAR;
		}
	}

	private function conv( string $axis, string $s ): float {
		$this->convError = false;
		if ( $this->unittyp[$axis] === self::DATE ) {
			$j = $this->jdate( $s );
			if ( $j === null ) {
				$this->convError = true;
				return 0.0;
			}
			return (float)$j;
		}
		$f = Canvas::scanFloat( $s );
		if ( $f === null ) {
			$this->convError = true;
			return 0.0;
		}
		return $f;
	}

	/**
	 * PL_posex / PL_lenex: position expression such as "1950(s)+0.1".
	 */
	private function posex( string $in, string $axis, bool $length = false ): float {
		if ( $in === '' ) {
			return 0.0;
		}
		$this->convError = false;
		$negOk = !( $this->dashindate && $this->dashindateaxis === $axis );
		$len = strlen( $in );
		$op = 0;
		$cut = $len;
		$sub = '';
		for ( $i = 1; $i < $len; $i++ ) {
			$c = $in[$i];
			if ( ( $c === '+' || ( $c === '-' && $negOk ) ) && strtolower( $in[$i - 1] ) !== 'e' ) {
				$op = $c === '+' ? 1 : 2;
				$cut = min( $cut, $i );
				$sub = substr( $in, $i + 1 );
			}
		}
		$r = $this->doPex( substr( $in, 0, $cut ), $axis, $length );
		if ( $op ) {
			$r2 = $this->doPex( $sub, $axis, true );
			$r = $op === 1 ? $r + $r2 : $r - $r2;
		}
		return $r;
	}

	private function doPex( string $val, string $axis, bool $length ): float {
		if ( str_starts_with( $val, 'min' ) ) {
			return $this->limit( $axis, 'l', 'a' );
		}
		if ( str_starts_with( $val, 'max' ) ) {
			return $this->limit( $axis, 'h', 'a' );
		}
		$buf = $val;
		$len = strlen( $buf );
		$modifier = '';
		if ( $len >= 3 && $buf[$len - 1] === ')' && $buf[$len - 3] === '(' ) {
			$modifier = substr( $buf, $len - 3 );
			$buf = substr( $buf, 0, $len - 3 );
		}
		$f = Canvas::scanFloat( $buf );
		$result = 0.0;
		if ( $f === null ) {
			$modifier = '(s)';
		} else {
			$result = $f;
		}
		if ( $modifier === '(s)' ) {
			if ( $length ) {
				return $this->a( $axis, Canvas::atof( $buf ) ) - $this->a( $axis, 0.0 );
			}
			$r = $this->a( $axis, $this->conv( $axis, $buf ) );
			if ( $this->convError && str_contains( $buf, '_' ) ) {
				$r = $this->a( $axis, $this->conv( $axis, str_replace( '_', ' ', $buf ) ) );
			}
			return $r;
		}
		return $result;
	}

	/**
	 * PL_getcoords
	 *
	 * @return float[]
	 */
	private function coords( string $val ): array {
		$t = Canvas::tokens( $val, 2 );
		return [ $this->posex( $t[0] ?? '', 'x' ), $this->posex( $t[1] ?? '', 'y' ) ];
	}

	/**
	 * PL_getbox
	 *
	 * @return float[]
	 */
	private function box( string $val ): array {
		$t = Canvas::tokens( $val, 4 );
		return [ $this->posex( $t[0] ?? '', 'x' ), $this->posex( $t[1] ?? '', 'y' ),
			$this->posex( $t[2] ?? '', 'x' ), $this->posex( $t[3] ?? '', 'y' ) ];
	}

	// ------------------------------------------------------------ dates (dates.c)

	/**
	 * DT_jdate for the date formats EasyTimeline uses (dd/mm/yyyy, mm/dd/yyyy, yyyy-mm-dd).
	 */
	private function jdate( string $s ): ?int {
		$iso = $this->fmtstring === 'yyyy-mm-dd';
		$sep = $iso ? '-' : '/';
		if ( !Regex::match( "#^\\s*([+-]?\\d+)$sep\\s*([+-]?\\d+)$sep\\s*([+-]?\\d+)#", $s, $m ) ) {
			return null;
		}
		$len = strlen( $s );
		if ( $iso ? $len !== 10 : ( ( $len !== 8 && $len !== 10 ) || !Canvas::isNumber( substr( $s, 6 ) ) ) ) {
			return null;
		}
		[ $a, $b, $c ] = [ (int)$m[1], (int)$m[2], (int)$m[3] ];
		[ $yr, $mon, $day ] = match ( $this->fmtstring ) {
			'yyyy-mm-dd' => [ $a, $b, $c ],
			'dd/mm/yyyy' => [ $c, $b, $a ],
			default => [ $c, $a, $b ],
		};
		if ( $yr < 1754 || $yr > 2400 || $mon < 1 || $day < 0 || $day > 31 ) {
			return null;
		}
		return self::jul( $yr, $mon, $day );
	}

	private static function jul( int $y, int $m, int $d ): ?int {
		$mdays = [ 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 ];
		$refyear = 1970;
		if ( $m < 1 || $m > 12 ) {
			return null;
		}
		$m--;
		if ( $y < 100 ) {
			$y += $y >= 70 ? 1900 : 2000;
		}
		if ( $y < 1754 ) {
			return null;
		}
		if ( $y < 1970 ) {
			$refyear = 1754;
		}
		$leap = ( $y % 4 === 0 && ( $y % 100 !== 0 || $y % 400 === 0 ) ) ? 1 : 0;
		if ( $leap && $m === 1 ) {
			if ( $d < 1 || $d > $mdays[$m] + $leap ) {
				return null;
			}
		} elseif ( $d < 1 || $d > $mdays[$m] ) {
			return null;
		}
		$jul = ( $y - $refyear ) * 365;
		for ( $i = 0; $i < $m; $i++ ) {
			$jul += $mdays[$i];
		}
		$jul += $d - 1;
		$yfc = $y % 4;
		$rfc = $refyear % 4;
		$win1 = $refyear + $rfc;
		$win2 = $y - $yfc;
		if ( $win1 <= $win2 ) {
			if ( $win1 !== $win2 ) {
				$jul += intdiv( $win2 - $win1, 4 );
			}
			if ( $yfc > 0 || $m > 1 ) {
				$jul += 1;
			}
		}
		if ( $refyear === 1754 ) {
			if ( $y === 1800 && $m > 1 ) {
				$jul--;
			} elseif ( $y > 1800 ) {
				$jul--;
			}
			if ( $y === 1900 && $m > 1 ) {
				$jul--;
			} elseif ( $y > 1900 ) {
				$jul--;
			}
			$jul -= 78892;
		}
		return $jul;
	}

	// ------------------------------------------------------------ details.c

	/**
	 * PL_textdet: apply text details; returns [ align, adjx, adjy ].
	 */
	private function textdet( string $spec, int $sizehint ): array {
		$color = $this->stdTextColor;
		$p = $this->stdTextSize + $sizehint;
		$align = '?';
		$adjx = 0.0;
		$adjy = 0.0;
		$at = Canvas::tokens( $spec, 6 );
		if ( ( $at[0] ?? '' ) === 'yes' || str_starts_with( $at[0] ?? '', 'no' ) ) {
			$at = [];
		}
		foreach ( $at as $a ) {
			if ( str_starts_with( $a, 'size=' ) ) {
				$p = Canvas::atoi( substr( $a, 5 ) );
			} elseif ( str_starts_with( $a, 'color=' ) ) {
				$color = substr( $a, 6 );
			} elseif ( str_starts_with( $a, 'align=' ) ) {
				$align = strtoupper( $a[6] ?? "\0" );
			} elseif ( str_starts_with( $a, 'adjust=' ) ) {
				Regex::match( '/^([^,]*)(?:,(.*))?$/', substr( $a, 7 ), $m );
				$v1 = Canvas::scanFloat( $m[1] ?? '' );
				$v2 = $v1 === null ? null : Canvas::scanFloat( $m[2] ?? '' );
				if ( $this->flip ) {
					$adjy = $v1 ?? $adjy;
					$adjx = $v2 ?? $adjx;
				} else {
					$adjx = $v1 ?? $adjx;
					$adjy = $v2 ?? $adjy;
				}
			}
		}
		if ( $this->stdTextColor !== '' ) {
			$this->color( $color );
		}
		$this->textsize( $p );
		if ( !str_contains( 'LCRDU?', $align ) ) {
			$align = '?';
		}
		return [ $align, $adjx, $adjy ];
	}

	/**
	 * PL_linedet
	 */
	private function linedet( string $spec, float $defaultwidth ): void {
		$width = $defaultwidth;
		$color = $this->stdTextColor;
		$at = Canvas::tokens( $spec, 5 );
		if ( ( $at[0] ?? '' ) === 'yes' || str_starts_with( $at[0] ?? '', 'no' ) ) {
			$at = [];
		}
		foreach ( $at as $a ) {
			if ( str_starts_with( $a, 'width=' ) || str_starts_with( $a, 'thick=' ) ) {
				$w = Canvas::scanFloat( substr( $a, 6 ) );
				if ( $w === null || $w < 0.1 ) {
					return;
				}
				$width = $w;
			} elseif ( str_starts_with( $a, 'color=' ) ) {
				$color = substr( $a, 6 );
			} elseif ( !str_starts_with( $a, 'style=' ) ) {
				return;
			}
		}
		$this->linetype( $width );
		$this->color( $color );
	}

	/**
	 * PL_measuretext
	 *
	 * @return int[] [ nlines, maxlen ]
	 */
	private static function measuretext( string $txt ): array {
		$i = 0;
		$n = 0;
		$max = 0;
		$len = strlen( $txt );
		while ( $i < $len ) {
			$l = strlen( self::getseg( $txt, $i, "\n" ) );
			$max = max( $max, $l );
			$n++;
		}
		return [ $n, $max ];
	}

	// ------------------------------------------------------------ clickmap

	private function clickmapEntry( string $url, float $x1, float $y1, float $x2, float $y2, bool $textpad,
		string $title
	): void {
		if ( !$this->canvas->clickmap || count( $this->clickmap ) >= 499 ) {
			return;
		}
		if ( $url === '' && $title === '' ) {
			return;
		}
		$url = str_replace( [ ' ', "\n" ], '_', $url );
		$url = str_replace( '\\n', '__', $url );
		if ( $textpad ) {
			$x1 -= 0.15;
			$x2 += 0.1;
			$y1 -= 0.04;
		}
		$this->clickmap[] = [ $url, $title, ...$this->canvas->mapPoint( $x1, $y2 ),
			...$this->canvas->mapPoint( $x2, $y1 ) ];
	}

	// ------------------------------------------------------------ procs

	private function procPage(): void {
		$dopagebox = false;
		foreach ( $this->attrs() as [ $attr, $val ] ) {
			if ( $attr === 'backgroundcolor' ) {
				$this->stdBkColor = self::tok( $val, 40 );
				$this->curBkColor = $this->stdBkColor;
			} elseif ( $attr === 'dopagebox' ) {
				$dopagebox = strncasecmp( $val, 'y', 1 ) === 0;
			} elseif ( $attr === 'pagesize' ) {
				[ $w, $h ] = $this->coords( $val );
				$this->winw = $w;
				$this->winh = $h;
			}
		}
		if ( $this->npages === 0 ) {
			$this->init();
		}
		$this->npages++;
		if ( $dopagebox ) {
			$this->cblock( 0.0, 0.0, $this->winw, $this->winh, $this->curBkColor, false );
		} else {
			$this->pcode( 'z', 0.0, 0.0, $this->curBkColor );
		}
	}

	/**
	 * tokncpy: first token of a value, at most $max-1 chars.
	 */
	private static function tok( string $val, int $max ): string {
		$n = strcspn( $val, " \t\n\r\x0B\f" );
		return substr( $val, 0, min( $n, $max - 1 ) );
	}

	private function procAreadef(): void {
		$xscaletype = 'linear';
		$yscaletype = 'linear';
		$xlo = 1.0;
		$ylo = 1.0;
		$xhi = 7.6;
		$yhi = 4.5;
		$xr = [ '', '' ];
		$yr = [ '', '' ];
		foreach ( $this->attrs() as [ $attr, $val ] ) {
			if ( $attr === 'rectangle' ) {
				[ $xlo, $ylo, $xhi, $yhi ] = $this->box( $val );
			} elseif ( $attr === 'xrange' ) {
				$xr = Canvas::tokens( $val, 2 ) + [ '', '' ];
			} elseif ( $attr === 'yrange' ) {
				$yr = Canvas::tokens( $val, 2 ) + [ '', '' ];
			} elseif ( $attr === 'xscaletype' ) {
				$xscaletype = $val;
			} elseif ( $attr === 'yscaletype' ) {
				$yscaletype = $val;
			}
		}
		$this->setUnits( 'x', $xscaletype );
		$this->setUnits( 'y', $yscaletype );
		$ok = $this->setScale( 'x', $xlo, $xhi, $xr[0], $xr[1] );
		$ok = $this->setScale( 'y', $ylo, $yhi, $yr[0], $yr[1] ) && $ok;
		if ( !$ok ) {
			$this->skipout = true;
			return;
		}
		$this->vars['AREATOP'] = Printf::g( $this->yhi );
		$this->vars['AREABOTTOM'] = Printf::g( $this->ylo );
		$this->vars['AREALEFT'] = Printf::g( $this->xlo );
		$this->vars['AREARIGHT'] = Printf::g( $this->xhi );
		$this->vars['XMIN'] = $xr[0];
		$this->vars['XMAX'] = $xr[1];
		$this->vars['YMIN'] = $yr[0];
		$this->vars['YMAX'] = $yr[1];
	}

	private function procLegendentry(): void {
		$label = '';
		$sampletype = 'none';
		$spec1 = '';
		$tag = '';
		foreach ( $this->attrs() as [ $attr, $val ] ) {
			if ( $attr === 'label' ) {
				$label = $val;
			} elseif ( $attr === 'tag' ) {
				$tag = $val;
			} elseif ( $attr === 'sampletype' ) {
				$sampletype = $val;
			} elseif ( $attr === 'details' ) {
				$spec1 = $val;
			}
		}
		// LEGEND_COLOR = 1, none = 0
		$this->addLegent( $sampletype === 'color' ? 1 : 0, $label, $tag, $spec1 );
	}

	/**
	 * PL_add_legent, in the order the compiled code reads and writes memory.
	 */
	private function addLegent( int $typ, string $label, string $tag, string $parm1 ): void {
		$n = $this->legMem[self::LE_NLE] ?? 0;
		$this->legMem[self::LE_TYPE + $n] = $typ;
		$label = $typ === 0 ? "\n\n" : str_replace( '\\n', "\n", $label );
		$avail = $this->legMem[self::LE_AVAIL] ?? 0;
		$this->legMem[self::LE_LABEL + $n] = $avail;
		foreach ( [ $label, $tag, $parm1, '', '' ] as $k => $str ) {
			if ( $k === 1 ) {
				$this->legMem[self::LE_TAG + $n] = $avail;
			}
			if ( $avail + strlen( $str ) >= 5000 ) {
				return;
			}
			if ( $k >= 2 ) {
				$this->legMem[[ self::LE_PARM1, self::LE_PARM2, self::LE_PARM3 ][$k - 2] + $n] = $avail;
			}
			$text = str_pad( $this->legText, 5000, "\0" );
			$this->legText = substr_replace( $text, "$str\0", $avail, strlen( $str ) + 1 );
			$avail += strlen( $str ) + 1;
			$this->legMem[self::LE_AVAIL] = $avail;
		}
		$this->legMem[self::LE_NLE] = $n + 1;
	}

	/**
	 * Legend entry field: 'type', 'label', 'tag' or 'parm1'.
	 */
	private function legent( int $i, string $field ): string|int {
		if ( $field === 'type' ) {
			return $this->legMem[self::LE_TYPE + $i] ?? 0;
		}
		$base = [ 'label' => self::LE_LABEL, 'tag' => self::LE_TAG, 'parm1' => self::LE_PARM1 ][$field];
		$off = $this->legMem[$base + $i] ?? 0;
		$end = strpos( $this->legText, "\0", $off );
		return substr( $this->legText, $off, $end === false ? null : $end - $off );
	}

	/**
	 * PL_get_legent: parm1 (the color) of the entry with this tag.
	 */
	private function legentColor( string $tag ): string {
		$nle = $this->legMem[self::LE_NLE] ?? 0;
		for ( $i = 0; $i < $nle; $i++ ) {
			if ( $this->legent( $i, 'tag' ) === $tag ) {
				return $this->legent( $i, 'parm1' );
			}
		}
		return '';
	}

	private function procGetdata(): void {
		// EasyTimeline data is always comma delimited
		$rows = [];
		for ( $a = $this->nextAttr( true ); $a !== null; $a = $this->nextAttr( false ) ) {
			[ $attr, $val ] = $a;
			if ( $attr === 'data' ) {
				if ( $val !== '' ) {
					$rows[] = $val;
				}
				for ( $i = $this->curline; $i <= $this->procstop; $i++ ) {
					$line = $this->procline[$i] ?? null;
					if ( $line === null || trim( $line, " \t\n\r\x0B\f" ) === '' ) {
						break;
					}
					$rows[] = $line;
				}
				$this->curline = $i;
				$this->holdmem = true;
			}
		}
		$this->records = [];
		$this->nfields = 0;
		foreach ( $rows as $row ) {
			$t = ltrim( $row, " \t\n\r\x0B\f" );
			if ( $t === '' || str_starts_with( $t, '//' ) || str_contains( $row, '#set ' ) ) {
				continue;
			}
			$fields = self::parseCsv( $row, $this->records ? $this->nfields : 0 );
			if ( !$this->records ) {
				$this->nfields = count( $fields );
			}
			$this->records[] = array_pad( array_slice( $fields, 0, $this->nfields ), $this->nfields, '' );
		}
		$this->vars['NRECORDS'] = (string)count( $this->records );
		$this->vars['NFIELDS'] = (string)$this->nfields;
	}

	/**
	 * PL_parsedata for one comma delimited row.
	 *
	 * @return string[]
	 */
	private static function parseCsv( string $data, int $reqnf ): array {
		$len = strlen( $data );
		$qon = false;
		for ( $i = 0; $i < $len; $i++ ) {
			if ( $data[$i] === '"' ) {
				$qon = !$qon;
				if ( $i > 0 && $data[$i - 1] === self::FILLCHAR ) {
					$data[$i - 1] = "'";
					$data[$i] = "'";
				} else {
					$data[$i] = self::FILLCHAR;
				}
			} elseif ( $qon && $data[$i] === ',' ) {
				$data[$i] = self::SPACECHAR;
			}
		}
		// Fields end at the separator or at the first quote (C string terminator).
		$fields = [];
		$state = 0;
		$cur = -1;
		$ended = false;
		for ( $j = 0; $j < $len; $j++ ) {
			$c = $data[$j];
			if ( $state === 0 ) {
				if ( $c === self::FILLCHAR ) {
					continue;
				}
				$fields[] = '';
				$cur++;
				$ended = false;
				$state = 1;
			}
			if ( $c === ',' ) {
				if ( $reqnf > 0 && count( $fields ) >= $reqnf ) {
					break;
				}
				$state = 0;
				continue;
			}
			if ( $c === self::FILLCHAR ) {
				$ended = true;
				continue;
			}
			if ( !$ended ) {
				$fields[$cur] .= $c === self::SPACECHAR ? ',' : $c;
			}
		}
		return $fields;
	}

	private function procBars(): void {
		$axis = 'y';
		$locfield = -1;
		$barwidth = 0.0;
		$outline = 'yes';
		$stackf = [];
		$stopfld = -1;
		$segment = false;
		$colorfield = -1;
		$mapurl = '';
		$maplabel = '';
		$barcolor = '0.7';
		foreach ( $this->attrs() as [ $attr, $val ] ) {
			if ( $attr === 'locfield' ) {
				$locfield = Canvas::atoi( $val ) - 1;
			} elseif ( $attr === 'axis' ) {
				$axis = $val[0] ?? "\0";
			} elseif ( $attr === 'horizontalbars' ) {
				$axis = 'x';
			} elseif ( $attr === 'outline' ) {
				$outline = $val;
			} elseif ( $attr === 'barwidth' ) {
				$barwidth = Canvas::atof( self::tok( $val, 80 ) );
			} elseif ( str_starts_with( $attr, 'segmentfield' ) ) {
				foreach ( Canvas::tokens( $val ) as $i => $t ) {
					if ( $i === 0 ) {
						$stopfld = Canvas::atoi( $t );
					} elseif ( $i === 1 ) {
						$stackf = [ $stopfld ];
						$stopfld = Canvas::atoi( $t );
					}
				}
				$segment = true;
			} elseif ( $attr === 'colorfield' ) {
				$colorfield = Canvas::atoi( $val ) - 1;
			} elseif ( $attr === 'clickmapurl' ) {
				$mapurl = $val;
			} elseif ( $attr === 'clickmaplabel' ) {
				$maplabel = $val;
			} elseif ( $attr === 'color' || $attr === 'barcolor' ) {
				$barcolor = $val;
			}
		}
		$baseax = $axis === 'y' ? 'x' : 'y';
		if ( !$this->records ) {
			return;
		}
		if ( $baseax === 'y' ) {
			$this->flip = true;
		}
		$rlo = $this->limit( $baseax, 'l', 's' );
		$rhi = $this->limit( $baseax, 'h', 's' );
		$halfw = $barwidth > 0.0 ? $barwidth * 0.5 : 0.1;
		$thinbar = $halfw < 0.012;
		$doOutline = !( $outline === '' || str_starts_with( $outline, 'no' ) );
		$cr = $this->limit( $axis, 'l', 's' );
		$this->linedet( $outline, 0.5 );
		$this->pcode( 'v', 0.0, 0.0, '' );
		if ( $thinbar ) {
			$this->linedet( "color=$barcolor", 0.3 );
		}
		foreach ( $this->records as $irow => $rec ) {
			$x = $this->conv( $baseax, $rec[$locfield] ?? '' );
			if ( $this->convError || $x < $rlo || $x > $rhi ) {
				continue;
			}
			$y0 = $cr;
			foreach ( $stackf as $sf ) {
				$fval = $this->conv( $axis, $rec[$sf - 1] ?? '' );
				if ( $segment ) {
					$fval -= $cr;
				}
				$y0 += $fval;
			}
			$xleft = $this->a( 'x', $x ) - $halfw;
			$xright = $this->a( 'x', $x ) + $halfw;
			$y = $this->conv( $axis, $rec[$stopfld - 1] ?? '' );
			if ( $segment && $this->convError ) {
				continue;
			}
			$color = $barcolor;
			if ( $colorfield >= 0 ) {
				$color = $this->legentColor( $rec[$colorfield] ?? '' );
			}
			$expUrl = $this->fieldSubst( $mapurl, $rec, true );
			$expLabel = $this->fieldSubst( $maplabel, $rec, false );
			if ( $thinbar ) {
				$this->mov( $xleft + $halfw, $this->a( 'y', $y0 ) );
				$this->lin( $xleft + $halfw, $this->a( 'y', $y ) );
				continue;
			}
			$this->cblock( $xleft, $this->a( 'y', $y0 ), $xright, $this->a( 'y', $y ), $color, false );
			if ( $doOutline ) {
				$this->mov( $xleft, $this->a( 'y', $y0 ) );
				$this->lin( $xleft, $this->a( 'y', $y ) );
				$this->lin( $xright, $this->a( 'y', $y ) );
				$this->lin( $xright, $this->a( 'y', $y0 ) );
				$this->lin( $xleft, $this->a( 'y', $y0 ) );
			}
			if ( $mapurl !== '' || $maplabel !== '' ) {
				if ( $this->flip ) {
					$this->clickmapEntry( $expUrl, $this->a( 'y', $y0 ), $xleft, $this->a( 'y', $y ), $xright,
						false, $expLabel );
				} else {
					$this->clickmapEntry( $expUrl, $xleft, $this->a( 'y', $y0 ), $xright, $this->a( 'y', $y ),
						false, $expLabel );
				}
			}
		}
		if ( $baseax === 'y' ) {
			$this->flip = false;
		}
	}

	/**
	 * PL_value_subst with data fields (@1, @2 ..), optionally URL-encoded.
	 *
	 * @param string $in
	 * @param string[] $rec
	 * @param bool $urlencode
	 * @return string
	 */
	private function fieldSubst( string $in, array $rec, bool $urlencode ): string {
		$out = '';
		$outlen = 0;
		$len = strlen( $in );
		for ( $i = 0; $i < $len; $i++ ) {
			if ( $in[$i] === '@' && ( $in[$i + 1] ?? '' ) === '@' ) {
				// strcat without advancing outlen: the next char overwrites it
				$out = substr( $out, 0, $outlen ) . '@';
				$i++;
				continue;
			}
			if ( $in[$i] === '@' ) {
				Regex::match( '/^[A-Za-z0-9_]*/', Canvas::tokens( substr( $in, $i + 1 ), 1 )[0] ?? '', $m );
				$name = $m[0] ?? '';
				$ifld = Canvas::atoi( $name );
				if ( $ifld > 0 && $ifld < 128 ) {
					$v = $rec[$ifld - 1] ?? '';
				} else {
					$v = $this->vars[$name] ?? $name;
				}
				if ( $urlencode ) {
					$v = self::urlencode( $v );
				}
				$out = substr( $out, 0, $outlen ) . $v;
				$outlen += strlen( $v );
				$i += strlen( $name );
			} else {
				$out = substr( $out, 0, $outlen ) . $in[$i];
				$outlen++;
			}
		}
		return $out;
	}

	/**
	 * GL_urlencode (bytes >= 0x80 come out as "%FF" because of a sign bug).
	 */
	private static function urlencode( string $in ): string {
		$out = '';
		$len = strlen( $in );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $in[$i];
			$o = ord( $c );
			if ( ctype_alnum( $c ) || str_contains( '.-~_', $c ) ) {
				$out .= $c;
			} elseif ( $o >= 0x80 ) {
				$out .= '%FF';
			} else {
				$out .= sprintf( '%%%X', $o );
			}
		}
		return $out;
	}

	private function procAnnotate(): void {
		$x = 3.0;
		$y = 3.0;
		$text = '';
		$details = '';
		$mapurl = '';
		$maplabel = '';
		foreach ( $this->attrs( [ 'text' ] ) as [ $attr, $val ] ) {
			if ( $attr === 'location' ) {
				[ $x, $y ] = $this->coords( $val );
			} elseif ( $attr === 'text' ) {
				$text = $val;
			} elseif ( $attr === 'textdetails' ) {
				$details = $val;
			} elseif ( $attr === 'clickmapurl' ) {
				$mapurl = $val;
			} elseif ( $attr === 'clickmaplabel' ) {
				$maplabel = $val;
			}
		}
		[ $align, $adjx, $adjy ] = $this->textdet( $details, 0 );
		if ( $align === '?' ) {
			$align = 'C';
		}
		$px = $x + $adjx;
		$py = $y + $adjy;
		[ $nlines, $maxlen ] = self::measuretext( $text );
		$boxw = ( $maxlen + 2 ) * $this->curTextWidth;
		$boxh = ( $nlines * 1.2 ) * $this->curTextHeight;
		$uly = $py + $this->curTextHeight;
		if ( $align === 'L' ) {
			$ulx = $px;
		} elseif ( $align === 'R' ) {
			$ulx = $px - $boxw;
		} else {
			$ulx = $px - ( $boxw / 2.0 );
		}
		if ( $mapurl !== '' || $maplabel !== '' ) {
			$this->clickmapEntry( $mapurl, $ulx, $uly - $boxh, $ulx + $boxw, $uly, true, $maplabel );
		}
		[ $align, $adjx, $adjy ] = $this->textdet( $details, 0 );
		if ( $align === '?' ) {
			$align = 'C';
		}
		$this->mov( $x + $adjx, $y + $adjy );
		$this->dotext( $text, $align );
	}

	private function procLine(): void {
		$points = '';
		$details = '';
		foreach ( $this->attrs( [ 'points' ] ) as [ $attr, $val ] ) {
			if ( $attr === 'points' ) {
				$points = $val;
			} elseif ( $attr === 'linedetails' ) {
				$details = $val;
			}
		}
		$this->linedet( $details, 1.0 );
		$first = true;
		foreach ( explode( "\n", $points ) as $buf ) {
			$t = Canvas::tokens( $buf, 4 );
			if ( count( $t ) === 4 || $first ) {
				$this->mov( $this->posex( $t[0] ?? '', 'x' ), $this->posex( $t[1] ?? '', 'y' ) );
				$this->lin( $this->posex( $t[2] ?? '', 'x' ), $this->posex( $t[3] ?? '', 'y' ) );
			} elseif ( count( $t ) === 2 ) {
				$this->lin( $this->posex( $t[0], 'x' ), $this->posex( $t[1], 'y' ) );
			}
			$first = false;
		}
	}

	private function procRect(): void {
		$color = 'dullyellow';
		$box = null;
		$mapurl = '';
		$maplabel = '';
		foreach ( $this->attrs() as [ $attr, $val ] ) {
			if ( $attr === 'rectangle' ) {
				$box = $this->box( $val );
			} elseif ( $attr === 'color' ) {
				$color = $val;
			} elseif ( $attr === 'clickmapurl' ) {
				$mapurl = $val;
			} elseif ( $attr === 'clickmaplabel' ) {
				$maplabel = $val;
			}
		}
		if ( $box === null ) {
			return;
		}
		if ( $color === 'none' ) {
			$color = '';
		}
		$this->linedet( '', 0.5 );
		$this->cblock( $box[0], $box[1], $box[2], $box[3], $color, false );
		if ( $mapurl !== '' || $maplabel !== '' ) {
			$this->clickmapEntry( $mapurl, $box[0], $box[1], $box[2], $box[3], false, $maplabel );
		}
	}

	private function procLegend(): void {
		$x = -9999.0;
		$y = -9999.0;
		$across = false;
		$details = '';
		$swatch = 0.1;
		$noclear = false;
		$order = '';
		$orderGiven = false;
		foreach ( $this->attrs( [ 'specifyorder' ] ) as [ $attr, $val ] ) {
			if ( $attr === 'location' ) {
				[ $x, $y ] = $this->coords( $val );
			} elseif ( $attr === 'format' ) {
				$f = $val[0] ?? '';
				$across = $f === 'a' || $f === 's';
			} elseif ( $attr === 'textdetails' ) {
				$details = $val;
			} elseif ( $attr === 'noclear' ) {
				$noclear = strncasecmp( $val, 'y', 1 ) === 0;
			} elseif ( $attr === 'specifyorder' ) {
				$order = $val;
				$orderGiven = true;
			} elseif ( $attr === 'swatchsize' ) {
				$swatch = Canvas::atof( self::tok( $val, 80 ) );
			}
		}
		$nle = $this->legMem[self::LE_NLE] ?? 0;
		// EasyTimeline.pl wrote an empty specifyorder for a legend column that has no
		// entries. ploticus then read a buffer that it did not write: it drew nothing, or all
		// entries one more time, as the memory was. Nothing is what the timeline asks for.
		if ( $nle < 1 || ( $orderGiven && $order === '' ) ) {
			if ( !$noclear ) {
				$this->legMem[self::LE_NLE] = 0;
				$this->legMem[self::LE_AVAIL] = 0;
			}
			return;
		}
		if ( $x < -9000.0 ) {
			$x = $this->xhi - 1.5;
			$y = $this->yhi - 0.1;
		}
		$ix = 0;
		$buflen = strlen( $order );
		$startx = $x;
		$starty = $y;
		$maxtwidth = 0;
		$this->textdet( $details, -2 );
		$y -= $this->curTextHeight;
		$sampwidth = 0.0;
		for ( $i = 0; $i < $nle; $i++ ) {
			$j = $i;
			if ( $order !== '' ) {
				while ( true ) {
					$buf = self::getchunk( $order, $ix, "\n" );
					if ( $ix >= $buflen ) {
						break 2;
					}
					for ( $j = 0; $j < $nle; $j++ ) {
						if ( strncmp( $buf, $this->legent( $j, 'label' ), strlen( $buf ) ) === 0 ) {
							break 2;
						}
					}
				}
			}
			$label = $this->legent( $j, 'label' );
			if ( $this->legent( $j, 'type' ) === 1 ) {
				$sampwidth = $swatch + 0.1;
				if ( $across && $x > $startx ) {
					$x += $sampwidth;
				}
				$color = Canvas::tokens( $this->legent( $j, 'parm1' ), 1 )[0] ?? '';
				$outline = $color === $this->curBkColor;
				if ( $outline ) {
					$this->linetype( 0.5 );
					$this->color( $this->stdTextColor );
					$this->pcode( 'v', 0.0, 0.0, '' );
				}
				$this->cblock( $x - ( $swatch + 0.1 ), $y, $x - 0.1, $y + $swatch, $color, $outline );
			}
			[ $nlines, $maxlen ] = self::measuretext( $label );
			$maxtwidth = max( $maxtwidth, $maxlen );
			[ , $adjx, $adjy ] = $this->textdet( $details, -2 );
			$this->mov( $x + $adjx, $y + $adjy );
			$this->dotext( $label, 'T' );
			if ( !$across ) {
				$y -= ( ( $this->curTextHeight * $nlines ) + 0.03 );
				if ( $y < 0.0 ) {
					$x = $x + ( $maxtwidth * $this->curTextWidth ) + $sampwidth + 0.35;
					$y = $starty - $this->curTextHeight;
					$maxtwidth = 0;
				}
			} else {
				$x += ( ( $this->curTextWidth * $maxlen ) + 0.3 );
				if ( $x < $startx ) {
					$x = $startx;
				}
			}
		}
		if ( !$noclear ) {
			$this->legMem[self::LE_NLE] = 0;
			$this->legMem[self::LE_AVAIL] = 0;
		}
	}

	/**
	 * GL_getchunk: token up to a separator, skipping leading separators.
	 */
	private static function getchunk( string $s, int &$i, string $sep ): string {
		$len = strlen( $s );
		while ( $i < $len && str_contains( $sep, $s[$i] ) ) {
			$i++;
		}
		$start = $i;
		while ( $i < $len && !str_contains( $sep, $s[$i] ) ) {
			$i++;
		}
		return substr( $s, $start, $i - $start );
	}

	private function procAxis( string $xory ): void {
		$opax = $xory === 'x' ? 'y' : 'x';
		$min = $this->limit( $xory, 'l', 's' );
		$max = $this->limit( $xory, 'h', 's' );
		$pos = $this->limit( $opax, 'l', 'a' );
		$stubstart = $axlinestart = $min;
		$stubstop = $axlinestop = $max;
		$axisline = '';
		$tics = '';
		$stubdetails = '';
		$griddet = 'none';
		$stubs = '';
		$isrc = 0;
		$incamount = 0.0;
		$incunits = '';
		$ticincamount = 0.0;
		$ticincunits = '';
		$ticout = 0.07;
		$ticin = 0.0;
		$stubslide = 0.0;
		$doingtics = true;
		$doinggrid = false;
		$stubrangegiven = false;
		$revsign = false;
		$multi = static function ( $attr, $val ) {
			$tok = self::tok( $val, 80 );
			return $attr === 'stubs' && !str_starts_with( $tok, 'inc' ) && $tok !== 'list' && $tok !== 'none';
		};
		foreach ( $this->attrs( $multi ) as [ $attr, $val ] ) {
			if ( $attr === 'stubs' ) {
				$tok = self::tok( $val, 80 );
				if ( str_starts_with( $tok, 'inc' ) ) {
					$isrc = 1;
					$t = Canvas::tokens( $val, 3 );
					$incamount = Canvas::scanFloat( $t[1] ?? '' ) ?? 0.0;
					$incunits = $t[2] ?? '';
				} elseif ( $tok === 'list' ) {
					$isrc = 2;
					$stubs = str_replace( '\\n', "\n", $val );
				} elseif ( $tok === 'none' ) {
					$isrc = 0;
				} else {
					$isrc = 2;
					$stubs = $val;
				}
			} elseif ( $attr === 'stubdetails' ) {
				$stubdetails = $val;
			} elseif ( $attr === 'stubrange' ) {
				$t = Canvas::tokens( $val, 2 );
				if ( count( $t ) === 2 ) {
					$stubstart = $this->conv( $xory, $t[0] );
					$stubstop = $this->conv( $xory, $t[1] );
				} elseif ( count( $t ) === 1 ) {
					$stubstart = $this->conv( $xory, $t[0] );
					$stubstop = $max;
				}
				$stubrangegiven = true;
			} elseif ( $attr === 'stubslide' ) {
				$stubslide = $this->posex( $val, $xory, true );
			} elseif ( $attr === 'axisline' ) {
				$axisline = $val;
			} elseif ( $attr === 'tics' ) {
				$tics = $val;
				$doingtics = !str_starts_with( $tics, 'no' );
			} elseif ( $attr === 'ticincrement' ) {
				$t = Canvas::tokens( $val, 2 );
				$ticincamount = Canvas::scanFloat( $t[0] ?? '' ) ?? 0.0;
				$ticincunits = $t[1] ?? '';
			} elseif ( $attr === 'ticlen' ) {
				$t = Canvas::tokens( $val, 2 );
				$ticout = Canvas::scanFloat( $t[0] ?? '' ) ?? $ticout;
				if ( isset( $t[1] ) ) {
					$ticin = Canvas::scanFloat( $t[1] ) ?? $ticin;
				}
			} elseif ( $attr === 'grid' ) {
				$griddet = $val;
				if ( !str_starts_with( $griddet, 'no' ) ) {
					$doinggrid = true;
				}
			} elseif ( $attr === 'signreverse' ) {
				$revsign = strncasecmp( $val, 'y', 1 ) === 0;
			}
		}
		if ( $stubstart < $min || $stubstart > $max ) {
			$stubstart = $min;
		}
		if ( $stubstop > $max || $stubstop < $min ) {
			$stubstop = $max;
		}
		if ( $xory === 'x' ) {
			$this->flip = true;
		}
		if ( $isrc !== 0 || $doingtics || $doinggrid ) {
			$inc = 1.0;
			$overrun = $inc / 10.0;
			$stubreverse = false;
			if ( !$stubrangegiven && $isrc === 2 ) {
				$stubstart = 1.0;
			}
			if ( $xory === 'y' && $isrc === 2 ) {
				$stubreverse = true;
				if ( !$stubrangegiven ) {
					$stubstop = $max - 1.0;
				}
			}
			$glemin = $glemax = 0.0;
			if ( $doinggrid ) {
				$glemin = $this->posex( 'min', $opax );
				$glemax = $this->posex( 'max', $opax );
			}
			if ( $isrc === 0 ) {
				$incunits = $ticincunits;
				$incamount = $ticincamount;
			}
			if ( !str_starts_with( $incunits, 'month' ) && Canvas::atof( $incunits ) == 0.0 ) {
				$incunits = '';
			}
			if ( $isrc === 1 || $isrc === 0 ) {
				if ( $incamount > 0.0 ) {
					$inc = $incunits === '' ? $incamount : $incamount * ( Canvas::scanFloat( $incunits ) ?? 0.0 );
				}
				$overrun = $inc / 10.0;
			}
			$this->vars[$xory === 'x' ? 'XINC' : 'YINC'] = Printf::g( $inc );
			if ( $inc <= 0.0 ) {
				$this->flip = false;
				return;
			}
			$this->textdet( $stubdetails, -2 );
			$y = $stubreverse ? $stubstop : $stubstart;
			$ibb = 0;
			$txtlen = strlen( $stubs );
			if ( $isrc === 2 ) {
				if ( str_starts_with( $stubs, 'text' ) ) {
					self::getseg( $stubs, $ibb, "\n" );
				} elseif ( str_starts_with( $stubs, 'list' ) ) {
					self::getok( $stubs, $ibb );
				}
			}
			for ( $sanecount = 0; $sanecount < 2000; $sanecount++ ) {
				$txt = '';
				if ( $isrc === 1 ) {
					$yy = ( $revsign && abs( $y ) > 0.0001 ) ? $y * -1 : $y;
					if ( $stubstart < 0.00001 && $stubstop >= 0.0 && $yy < 0.000000001 && $yy > -0.000000001 ) {
						$yy = 0.0;
					}
					$ftest = Canvas::scanFloat( $incunits );
					$txt = Printf::g( $ftest !== null ? $yy / $ftest : $yy );
				}
				if ( $isrc === 2 ) {
					if ( $ibb >= $txtlen ) {
						break;
					}
					$txt = self::getseg( $stubs, $ibb, "\n" );
				}
				if ( !( $min - $y > $overrun || $y - $max > $overrun ) ) {
					if ( $doinggrid ) {
						$this->linedet( $griddet, 0.5 );
						$this->mov( $glemin, $this->a( 'y', $y ) );
						$this->lin( $glemax, $this->a( 'y', $y ) );
					}
					$txt = str_replace( '\\n', "\n", $txt );
					if ( $isrc > 0 ) {
						[ $align, $adjx, $adjy ] = $this->textdet( $stubdetails, -2 );
						if ( $xory === 'y' ) {
							if ( $align === '?' ) {
								$align = 'R';
							}
							$ofsx = $adjx + -0.15;
							$ofsy = $adjy + ( $this->curTextHeight * -0.3 ) + $stubslide;
						} else {
							if ( $align === '?' ) {
								$align = 'C';
							}
							$ofsx = $adjx + ( $this->curTextHeight + $ticout ) * -1.0;
							$ofsy = $adjy + $stubslide;
						}
						$last = ( $this->a( 'y', $y ) + $ofsy ) - $this->a( 'y', $stubstop );
						if ( $last <= $overrun || $stubreverse ) {
							$this->mov( $pos + $ofsx, $this->a( 'y', $y ) + $ofsy );
							$this->dotext( $txt, $align );
						}
					}
					if ( $doingtics ) {
						$this->linedet( $tics, 0.5 );
						$this->mov( $pos - $ticout, $this->a( 'y', $y ) );
						$this->lin( $pos + $ticin, $this->a( 'y', $y ) );
					}
				}
				if ( $stubreverse ) {
					$y -= $inc;
					if ( $stubstart - $y > $overrun ) {
						break;
					}
				} else {
					$y += $inc;
					if ( ( $y - $stubstop ) > $overrun ) {
						break;
					}
				}
			}
		}
		if ( $axisline !== 'none' ) {
			$this->linedet( $axisline, 0.5 );
			$this->mov( $pos, $this->a( 'y', $axlinestart ) );
			$this->lin( $pos, $this->a( 'y', $axlinestop ) );
		}
		$this->flip = false;
	}
}
