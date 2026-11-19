<?php

namespace MediaWiki\Extension\Timeline\Render;

/**
 * Compiles EasyTimeline markup into the plot scripts for the SVG and the PNG pass, and
 * post-processes the rendered SVG and image map.
 *
 * Ported from EasyTimeline.pl (Erik Zachte, GPL-2.0). Odd behaviour of the Perl original,
 * including its scalar semantics (see num() and str()), is kept on purpose: it decides
 * the output of existing timelines.
 */
final class Compiler {
	private const VERSION = '1.90';
	/** Delimits the placeholder of an SVG text, see writeProcAnnotate() and fixSvg() */
	private const PLACEHOLDER = "\x01";
	private const LINK_COLOR = 'brightblue';

	// Encoded forms of special characters, see encodeInput()
	private const H_PERC = '%25%';
	private const H_DOLLAR = '%24%';
	private const H_BRO = '%28%';
	private const H_BRC = '%29%';
	private const H_IS = '%3D%';

	private string $articlePath;
	private bool $svgOnly;

	/** @var string[] remaining input lines, with line terminators */
	private array $lines = [];
	private int $lineNo = 0;
	private bool $inputParsed = false;
	private string $commandNext = '';
	private string $command = '';
	private string $line = '';
	private bool $noData = false;
	private bool $firstCmd = true;
	private string $prevPresetCommand = '';

	private int $cntErrors = 0;
	/** @var string[] */
	private array $errors = [];

	private ?string $dateFormat = null;
	private ?string $alignBars = null;
	private string $preset = '';
	private mixed $maxBarWidth = null;
	private bool $mapPng = false;
	private bool $mapSvg = false;
	private int $warnTextOutsideArea = 0;

	/** @var array<string,mixed> */
	private array $attributes = [];

	private array $consts = [];
	private array $colors = [];
	private array $colorLabels = [];
	private array $backgroundColors = [];
	private array $axis = [];
	/** @var string[] */
	private array $bars = [];
	private array $barLegend = [];
	private array $barLink = [];
	private array $barWidths = [];
	private array $legendData = [];
	private array $lineDefs = [];
	private array $period = [];
	private array $drawLines = [];
	private array $image = [];
	private array $legend = [];
	private array $plotArea = [];
	private array $plotDefs = [];
	private array $plotBars = [];
	private array $plotLines = [];
	private array $plotText = [];
	private array $presetList = [];
	private array $scales = [];
	private array $textDefs = [];

	private array $plotTextsPng = [];
	private array $plotTextsSvg = [];
	/** @var string[] */
	private array $linksSvg = [];
	/** @var string[] */
	private array $textsSvg = [];

	private string $script = '';
	private array $plotBarsNow = [];
	private mixed $posy1 = null;
	private mixed $posy2 = null;

	public function __construct( string $articlePath, bool $svgOnly ) {
		$this->articlePath = $articlePath;
		$this->svgOnly = $svgOnly;
	}

	/**
	 * Compile timeline source into ploticus scripts.
	 *
	 * @return array [ 'svg' => script, 'png' => script, 'svgMap' => bool, 'pngMap' => bool ]
	 * @throws CompileError
	 */
	public function compile( string $source ): array {
		// The SVG text placeholders use this character, so the source must not have it
		$source = str_replace( self::PLACEHOLDER, '', $source );
		Regex::matchAll( '/[^\n]*\n|[^\n]+$/', $source, $m );
		$this->lines = $m[0];
		$this->parseScript();
		$result = null;
		if ( $this->cntErrors === 0 ) {
			$result = $this->writePlotFile();
		}
		$this->checkErrors();
		// Every path without a result recorded an error, so checkErrors() threw.
		return $result ?? throw new \LogicException( 'unreachable' );
	}

	/**
	 * Report an error found after rendering (Perl Error2) and abort.
	 *
	 * @throws CompileError
	 */
	public function fail( string $msg ): never {
		$this->error2( $msg );
		$this->checkErrors();
		throw new \LogicException( 'unreachable' );
	}

	/**
	 * @throws CompileError
	 */
	private function checkErrors(): void {
		if ( $this->cntErrors === 1 ) {
			$this->abort( '1 error found' );
		} elseif ( $this->cntErrors > 1 ) {
			$this->abort( "{$this->cntErrors} errors found" );
		}
	}

	// ---------------------------------------------------------------- input

	/**
	 * @throws CompileError
	 */
	private function parseScript(): void {
		$this->dateFormat = 'x.y';
		$this->firstCmd = true;
		$this->getCommand();

		$this->storeColor( 'white', self::encodeInput( 'gray(0.999)' ), '' );
		$this->storeColor( 'barcoldefault', self::encodeInput( 'rgb(0,0.6,0)' ), '' );

		while ( !$this->inputParsed ) {
			if ( Regex::match( '/^\s*$/', $this->command ) ) {
				$this->getCommand();
				continue;
			}
			if ( !str_contains( $this->command, self::H_IS ) ) {
				$this->error( "Invalid statement. No '=' found." );
				$this->getCommand();
				continue;
			}
			if ( Regex::match( '/%3D%.*%3D%/', $this->command ) ) {
				$this->error( "Invalid statement. Multiple '=' found." );
				$this->getCommand();
				continue;
			}

			$parts = explode( self::H_IS, $this->command );
			$name = Regex::replace( '/^\s*(.*?)\s*$/', '$1', $parts[0] );
			$value = $parts[1] ?? null;

			if ( Regex::match( '/PlotDividers/i', $name ) ) {
				$this->error( "Command 'PlotDividers' has been renamed to 'LineData', please adjust." );
				$this->getCommand();
				continue;
			}
			if ( Regex::match( '/DrawLines/i', $name ) ) {
				$this->error( "Command 'DrawLines' has been renamed to 'LineData', please adjust.\n"
					. "  Reason for change is consistency: LineData now follows the same syntax rules as "
						. "PlotData and TextData." );
				$this->getCommand();
				continue;
			}
			if ( !Regex::match( '/^(?:Define)\s/', $name ) && !Regex::match(
				'/^(?:AlignBars|BarData|BackgroundColors|Colors|DateFormat|LineData|ScaleMajor|ScaleMinor|'
				. 'LegendLeft|LegendTop|ImageSize|PlotArea|Legend|Period|PlotData|Preset|TextData|TimeAxis)$/i',
				$name
			) ) {
				$this->error( "Command '" . Regex::replace( '/[^a-zA-Z].*$/s', '', $this->command ) . "' unknown." );
				$this->getCommand();
				continue;
			}

			if ( $value !== null ) {
				$value = Regex::replace( '/^\s*(.*?)\s*/', '$1', $value, 1 );
			}
			if ( !Regex::match( '/^(?:BarData|Colors|LineData|PlotData|TextData)$/i', $name )
				&& ( $value === null || $value === '' )
			) {
				if ( Regex::match( '/Preset/i', $name ) ) {
					$this->error( "$name definition incomplete. No value specified\n"
						. "  Two presets are available:\n"
						. "  TimeVertical_OneBar_UnitYear and TimeHorizontal_AutoPlaceBars_UnitYear\n"
						. '  See documentation at '
					. 'https://www.mediawiki.org/wiki/Extension:EasyTimeline/syntax#Presets' );
				} else {
					$this->error( "$name definition incomplete. No attributes specified" );
				}
				$this->getCommand();
				continue;
			}

			if ( Regex::match( '/^(?:BackgroundColors|Colors|Period|ScaleMajor|ScaleMinor|TimeAxis)$/i', $name ) ) {
				foreach ( self::split( ' ', $value ) as $attribute ) {
					$attrValue = explode( ':', $attribute )[1] ?? '';
					// The attribute name check of the Perl original never fails (precedence bug).
					if ( $attrValue === '' ) {
						$attrName = explode( ':', $attribute )[0];
						$this->error( "$name definition incomplete. No value specified for attribute '$attrName'." );
						$this->getCommand();
					}
				}
			}

			$c = $this->command;
			$next = true;
			if ( Regex::match( '/^AlignBars/i', $c ) ) {
				$this->parseAlignBars();
			} elseif ( Regex::match( '/^BackgroundColors/i', $c ) ) {
				$next = $this->parseBackgroundColors();
			} elseif ( Regex::match( '/^BarData/i', $c ) ) {
				$this->parseBarData();
			} elseif ( Regex::match( '/^Colors/i', $c ) ) {
				$this->parseColors();
			} elseif ( Regex::match( '/^DateFormat/i', $c ) ) {
				$this->parseDateFormat();
			} elseif ( Regex::match( '/^Define/i', $c ) ) {
				$this->parseDefine();
			} elseif ( Regex::match( '/^ImageSize/i', $c ) ) {
				$this->parseImageSize();
			} elseif ( Regex::match( '/^Legend/i', $c ) ) {
				$this->parseLegend();
			} elseif ( Regex::match( '/^LineData/i', $c ) ) {
				$this->parseLineData();
			} elseif ( Regex::match( '/^Period/i', $c ) ) {
				$this->parsePeriod();
			} elseif ( Regex::match( '/^PlotArea/i', $c ) ) {
				$this->parsePlotArea();
			} elseif ( Regex::match( '/^PlotData/i', $c ) ) {
				$this->parsePlotData();
			} elseif ( Regex::match( '/^Preset/i', $c ) ) {
				$this->parsePreset();
			} elseif ( Regex::match( '/^Scale/i', $c ) ) {
				$this->parseScale();
			} elseif ( Regex::match( '/^TextData/i', $c ) ) {
				$this->parseTextData();
			} elseif ( Regex::match( '/^TimeAxis/i', $c ) ) {
				$this->parseTimeAxis();
			}
			if ( !$next ) {
				// Perl 'next' out of a sub: skip reading the next command
				continue;
			}
			$this->getCommand();
			$this->firstCmd = false;
		}

		if ( $this->cntErrors === 0 ) {
			$this->detectMissingCommands();
		}
		if ( $this->cntErrors === 0 ) {
			$this->validateAndNormalizeDimensions();
		}
	}

	/**
	 * @throws CompileError
	 */
	private function getLine(): string {
		if ( !$this->lines ) {
			$this->inputParsed = true;
			return '';
		}
		// The part behind <timeline> does not count as line 1
		if ( Regex::match( '/^\s*$/', $this->lines[0] ) ) {
			$this->line = array_shift( $this->lines );
		}

		$commentStart = null;
		$this->line = '';
		while ( $this->lines && Regex::match( '/^\s*$/', $this->line ) ) {
			$this->lineNo++;
			$line = array_shift( $this->lines );
			if ( str_ends_with( $line, "\n" ) ) {
				$line = substr( $line, 0, -1 );
			}
			// preserve '#' within double quotes
			$line = Regex::replaceCallback( '/("[^"]*")/',
				static fn ( $m ) => str_replace( '#', '%?+', $m[1] ), $line );

			$line = Regex::replace( '/#>.*?<#/', '', $line );
			if ( str_contains( $line, '#>' ) ) {
				$commentStart = $this->lineNo;
				$line = Regex::replace( '/#>.*?$/', '', $line, 1 );
			} elseif ( str_contains( $line, '<#' ) ) {
				$commentStart = null;
				$line = Regex::replace( '/^.*?<#/', '', $line, 1 );
			} elseif ( $commentStart !== null ) {
				$this->line = '';
				continue;
			}

			// remove single line comments (keep html char tags, like &#32;)
			$line = str_replace( '&#', '&$%', $line );
			$line = Regex::replace( '/#.*$/', '', $line, 1 );
			$line = str_replace( '&$%', '&#', $line );
			$line = str_replace( '%?+', '#', $line );
			$line = Regex::replace( '/\s*$/', '', $line );
			$this->line = str_replace( "\t", ' ', $line );
		}

		if ( !Regex::match( '/^\s*$/', $this->line ) ) {
			if ( !Regex::match( '/^\s*Define/i', $this->line ) ) {
				$this->line = Regex::replaceCallback( '/(\$[a-zA-Z0-9]+)/',
					fn ( $m ) => $this->getDefine( $m[1] ), $this->line );
			}
			$this->line = self::encodeInput( $this->line );
		}

		if ( !$this->lines && $commentStart !== null ) {
			$this->error2( "No matching end of comment found for comment block starting at line $commentStart.\n"
				. "Text between #> and <# (multiple lines) or following # (single line) will be treated as comment." );
		}
		return $this->line;
	}

	/**
	 * @throws CompileError
	 */
	private function getCommand(): void {
		$this->attributes = [];
		if ( $this->commandNext !== '' ) {
			$this->command = $this->commandNext;
			$this->commandNext = '';
		} else {
			$this->command = $this->getLine();
		}

		if ( Regex::match( '/^\s/', $this->command ) ) {
			$this->error( "New command expected instead of data line (= line starting with spaces). Data "
				. "line(s) ignored.\n" );
			$this->command = $this->getLine();
			while ( $this->lines && Regex::match( '/^\s/', $this->command ) ) {
				$this->command = $this->getLine();
			}
		}

		if ( Regex::match( '/^[^\s]/', $this->command ) ) {
			$this->collectAttributes( Regex::replace( '/^.*%3D%\s*/', '', $this->command, 1 ) );
		}
	}

	/**
	 * @throws CompileError
	 */
	private function getData(): void {
		$this->attributes = [];
		$this->command = '';
		$this->noData = false;
		$line = $this->getLine();

		if ( Regex::match( '/^[^\s]/', $line ) ) {
			$this->commandNext = $line;
			$this->noData = true;
			return;
		}
		if ( Regex::match( '/^\s*$/', $line ) ) {
			$this->noData = true;
			return;
		}
		$this->collectAttributes( Regex::replace( '/^\s*/', '', $line ) );
	}

	/**
	 * @throws CompileError
	 */
	private function collectAttributes( string $line ): void {
		// replace colon (:), would conflict with syntax
		$line = Regex::replace( '/(\slink\:[^\s\:]*)\:/i', "\$1'colon'", $line, 1 );
		$line = Regex::replace( '/(\stext\:[^\s\:]*)\:/i', "\$1'colon'", $line, 1 );
		$line = Regex::replace( '/(https?)\:/i', "\$1'colon'", $line, 1 );

		[ $line, $text ] = $this->extractText( $line );
		$text = Regex::replace( "/'colon'/", ':', $text, 1 );

		$line = Regex::replaceCallback( '/(%28%.+?%29%)/',
			static fn ( $m ) => Regex::replace( '/\s/', '', $m[1] ), $line );
		$line = Regex::replace( '/\s*\:\s*/', ':', $line );
		$line = Regex::replaceCallback( '/([a-zA-Z0-9\_]+)\:/', static fn ( $m ) => strtolower( $m[1] ) . ':', $line );

		$name = null;
		foreach ( self::split( ' ', $line ) as $field ) {
			if ( str_contains( $field, ':' ) ) {
				$parts = explode( ':', $field );
				$name = strtolower( $parts[0] );
				$value = $parts[1] ?? '';
				if ( $name !== 'bar' && $name !== 'text' && $name !== 'link' && $name !== 'legend' ) {
					$value = strtolower( $value );
				}
				if ( $name === 'link' ) {
					$value = Regex::replace( "/'colon'/", ':', $value, 1 );
				}
				if ( $value === '' ) {
					if ( !Regex::match( '/Text/i', $name ) ) {
						$this->error( "No value specified for attribute '$name'. Attribute ignored." );
					}
				} elseif ( $name === 'until' ) {
					// T275249
					$this->attributes['till'] = $value;
				} else {
					$this->attributes[$name] = $value;
				}
			} elseif ( isset( $this->attributes['single'] ) ) {
				$this->error( "Invalid attribute '$field' ignored.\nSpecify attributes as 'name:value' pair(s)." );
			} else {
				$this->attributes['single'] = $field;
			}
		}
		if ( ( $name ?? '' ) !== '' && ( $this->attributes['single'] ?? '' ) !== '' ) {
			$this->error( "Invalid attribute '" . $this->attributes['single']
				. "' ignored.\nSpecify attributes as 'name:value' pairs." );
			unset( $this->attributes['single'] );
		}

		if ( $text !== '' ) {
			$this->attributes['text'] = self::parseText( $text );
		}
	}

	/**
	 * @throws CompileError
	 */
	private function getDefine( string $const ): string {
		$const = strtolower( self::encodeInput( $const ) );
		$value = $this->consts[$const] ?? null;
		if ( $value === null ) {
			$this->error( "Unknown constant. 'Define $const = ... ' expected." );
			return $const;
		}
		return $value;
	}

	// ---------------------------------------------------------------- commands

	/**
	 * @throws CompileError
	 */
	private function parseAlignBars(): void {
		$this->checkPreset( 'AlignBars' );
		$align = self::str( $this->attributes['single'] ?? null );
		if ( !Regex::match( '/^(?:justify|early|late)$/i', $align ) ) {
			$this->error( "AlignBars value '$align' invalid. Specify 'justify', 'early' or 'late'." );
			return;
		}
		$this->alignBars = strtolower( $align );
	}

	/**
	 * @return bool false when the Perl original did 'next' out of this sub
	 * @throws CompileError
	 */
	private function parseBackgroundColors(): bool {
		if ( !$this->validAttributes( 'BackgroundColors' ) ) {
			$this->getData();
			return false;
		}
		$this->checkPreset( 'BackGroundColors' );

		foreach ( array_keys( $this->attributes ) as $attribute ) {
			$attrValue = self::str( $this->attributes[$attribute] );
			if ( Regex::match( '/Canvas/i', $attribute ) ) {
				if ( !$this->colorPredefined( $attrValue ) && !isset( $this->colors[strtolower( $attrValue )] ) ) {
					$this->error( "BackgroundColors definition invalid. Attribute '$attribute': unknown color "
						. "'$attrValue'.\n"
						. "  Specify command 'Color' before this command." );
					return true;
				}
				$this->attributes['canvas'] = $this->colors[strtolower( $attrValue )] ?? strtolower( $attrValue );
			} elseif ( Regex::match( '/Bars/i', $attribute ) ) {
				if ( !isset( $this->colors[strtolower( $attrValue )] ) ) {
					$this->error( "BackgroundColors definition invalid. Attribute '$attribute' unknown color "
						. "'$attrValue'.\n"
						. "  Specify command 'Color' before this command." );
					return true;
				}
				$this->attributes['bars'] = strtolower( $attrValue );
			}
		}
		$this->backgroundColors = $this->attributes;
		return true;
	}

	/**
	 * @throws CompileError
	 */
	private function parseBarData(): void {
		$this->getData();
		if ( $this->noData ) {
			$this->error( "Data expected for command 'BarData', but line is not indented.\n" );
			return;
		}

		while ( !$this->inputParsed && !$this->noData ) {
			if ( !$this->validAttributes( 'BarData' ) ) {
				$this->getData();
				continue;
			}
			$bar = '';
			$link = '';
			$hint = '';
			$barset = '';
			$text = '';

			foreach ( $this->attributes as $attribute => $attrValue ) {
				$attrValue = self::str( $attrValue );
				if ( Regex::match( '/^Bar$/i', $attribute ) ) {
					$bar = $attrValue;
				} elseif ( Regex::match( '/^BarSet$/i', $attribute ) ) {
					$barset = $attrValue;
				} elseif ( Regex::match( '/^Text$/i', $attribute ) ) {
					$text = self::newlinesToTildes( $attrValue );
				} elseif ( Regex::match( '/^Link$/i', $attribute ) ) {
					$link = self::parseText( $attrValue );
					if ( Regex::match( '/\[.*\]/', $link ) ) {
						$this->error( "BarData attribute 'link' contains implicit (wiki style) link.\n"
							. "Use implicit link style with attribute 'text' only.\n" );
						$this->getData();
						continue 2;
					}
					$link = self::encodeUrl( self::normalizeUrl( $link ) );
					$this->mapPng = true;
				}
			}

			if ( $bar === '' && $barset === '' ) {
				$this->error( "BarData attribute missing. Specify either 'bar' of 'barset'.\n" );
				$this->getData();
				continue;
			}
			if ( $bar !== '' && $barset !== '' ) {
				$this->error( "BarData attributes 'bar' and 'barset' are mutually exclusive.\nSpecify one of "
					. "these per data line\n" );
				$this->getData();
				continue;
			}
			if ( $barset !== '' && $link !== '' ) {
				$this->error( "BarData attribute 'link' not valid in combination with attribute 'barset'.\n" );
				$this->getData();
				continue;
			}
			if ( $link !== '' ) {
				if ( Regex::match( '/\[.*\]/', $text ) ) {
					$text = Regex::replace( '/\[+ (?:[^\|]* \|)? ([^\]]*) \]+/x', '$1', $text );
				}
			}
			if ( $barset !== '' && !self::isValidId( $barset ) ) {
				$this->error( "BarData attribute barset:'$barset' invalid.\nUse only characters 'a'-'z', 'A'-'Z', "
					. "'0'-'9', '_'. The id must not start with '#' or contain ','.\n" );
				$this->getData();
				continue;
			}
			if ( $bar !== '' && !self::isValidId( $bar ) ) {
				$this->error( "BarData attribute bar:'$bar' invalid.\nUse only characters 'a'-'z', 'A'-'Z', "
					. "'0'-'9', '_'. The id must not start with '#' or contain ','.\n" );
				$this->getData();
				continue;
			}

			if ( $bar !== '' ) {
				$this->addBar( $bar );
				$this->barLegend[strtolower( $bar )] = $text !== '' ? $text : ' ';
				if ( $link !== '' ) {
					$this->barLink[strtolower( $bar )] = $link;
				}
			} else {
				$bar = $barset . '#1';
				$this->addBar( $bar );
				// Perl appended the never-set global $b here
				$this->barLegend[strtolower( $bar )] = $text !== '' ? $text . ' - ' : ' ';
			}
			$this->getData();
		}
	}

	private function addBar( string $bar ): void {
		if ( ( $this->axis['time'] ?? '' ) === 'x' ) {
			$this->bars[] = $bar;
		} else {
			array_unshift( $this->bars, $bar );
		}
	}

	/**
	 * @throws CompileError
	 */
	private function parseColors(): void {
		$colorName = null;
		$this->getData();
		if ( $this->noData ) {
			$this->error( "Data expected for command 'Colors', but line is not indented.\n" );
			return;
		}

		while ( !$this->inputParsed && !$this->noData ) {
			if ( !$this->validAttributes( 'Colors' ) ) {
				$this->getData();
				continue;
			}
			$this->checkPreset( 'Colors' );

			$legendValue = '';
			$colorValue = '';
			foreach ( $this->attributes as $attribute => $attrValue ) {
				$attrValue = self::str( $attrValue );
				if ( Regex::match( '/Id/i', $attribute ) ) {
					$colorName = $attrValue;
				} elseif ( Regex::match( '/Legend/i', $attribute ) ) {
					$legendValue = $attrValue;
					if ( Regex::match( '/^[yY]$/', $legendValue ) ) {
						$this->legendData[] = $colorName;
					} elseif ( !Regex::match( '/^[nN]$/', $attrValue ) ) {
						$legendValue = self::parseText( $legendValue );
						$this->legendData[] = $legendValue;
					}
				} elseif ( Regex::match( '/Value/i', $attribute ) ) {
					$colorValue = $attrValue;
					if ( Regex::match( '/^white$/i', $colorValue ) ) {
						$colorValue = 'gray' . self::H_BRO . '0.999' . self::H_BRC;
					}
				}
			}

			$valid = null;
			$message = 'Color value invalid.';
			if ( $this->colorPredefined( $colorValue ) ) {
				$valid = true;
			} elseif ( Regex::match( '/^[a-z]+$/i', $colorValue )
				&& !Regex::match( '/^(?:gray|rgb|hsb)/i', $colorValue )
			) {
				$valid = false;
				$message = "Color value invalid: unknown constant '$colorValue'.";
			} elseif ( !Regex::match( '/^(?:gray|rgb|hsb|xrgb) %28% .+? %29%/xi', $colorValue ) ) {
				$valid = false;
				$message = "Color value invalid. Specify constant or 'gray/rgb/hsb(numeric values)' ";
			} elseif ( Regex::match( '/^gray/i', $colorValue ) ) {
				$valid = (bool)Regex::match( '/gray %28% (?:0|1|0\.\d+) %29%/xi', $colorValue );
				$message = "Color value invalid. Specify 'gray(x) where 0 <= x <= 1' ";
			} elseif ( Regex::match( '/^rgb/i', $colorValue ) ) {
				$valid = (bool)Regex::match( '/rgb %28% (?:0|1|0\.\d+) \, (?:0|1|0\.\d+) \, (?:0|1|0\.\d+) %29%/xi',
					$colorValue );
				$message = "Color value invalid. Specify 'rgb(r,g,b) where 0 <= r,g,b <= 1' ";
			} elseif ( Regex::match( '/^xrgb/i', $colorValue ) ) {
				$valid = (bool)Regex::match( '/xrgb %28% ([\da-f0-9]{6}|[\da-f0-9]{12}) %29%/xi', $colorValue );
				$message = "Color value invalid. Specify 'xrgb(ABCDEF) using 6 or 12 hex chars' ";
			} elseif ( Regex::match( '/^hsb/i', $colorValue ) ) {
				$valid = (bool)Regex::match( '/hsb %28% (?:0|1|0\.\d+) \, (?:0|1|0\.\d+) \, (?:0|1|0\.\d+) %29%/xi',
					$colorValue );
				$message = "Color value invalid. Specify 'hsb(h,s,b) where 0 <= h,s,b <= 1' ";
			}
			if ( $valid ) {
				$this->storeColor( self::str( $colorName ), $colorValue, $legendValue );
			} else {
				$this->error( $message );
			}
			$this->getData();
		}
	}

	private function storeColor( string $colorName, string $colorValue, string $legendValue ): void {
		$this->colors[strtolower( $colorName )] = strtolower( $colorValue );
		if ( $legendValue !== '' ) {
			$this->colorLabels[strtolower( $colorName )] = $legendValue;
		}
	}

	/**
	 * @throws CompileError
	 */
	private function parseDateFormat(): void {
		$this->checkPreset( 'DateFormat' );
		$dateValue = strtolower( Regex::replace( '/\s/', '', self::str( $this->attributes['single'] ?? null ) ) );
		if ( !in_array( $dateValue, [ 'dd/mm/yyyy', 'mm/dd/yyyy', 'yyyy-mm-dd', 'yyyy', 'x.y' ], true ) ) {
			$this->error( "Invalid DateFormat. Specify as 'dd/mm/yyyy', 'mm/dd/yyyy', 'yyyy-mm-dd',\n"
				. " 'yyyy' or 'x.y'\n"
				. "  (use first two only for years >= 1800)\n" );
			return;
		}
		$this->dateFormat = $dateValue;
	}

	/**
	 * @throws CompileError
	 */
	private function parseDefine(): void {
		$command2 = Regex::replace( '/^Define\s*/i', '', $this->command, 1 );
		$parts = explode( self::H_IS, $command2 );
		$name = Regex::replace( '/^\s*(.*?)\s*$/', '$1', $parts[0] );
		$value = Regex::replace( '/^\s*(.*?)\s*$/', '$1', $parts[1] ?? '' );

		if ( !str_starts_with( $name, self::H_DOLLAR ) ) {
			$this->error( "Define '$name' invalid. Name does not start with '\$'." );
			return;
		}
		if ( !Regex::match( '/^%24%[a-zA-Z0-9\_]+$/', $name ) ) {
			$this->error( "Define '$name' invalid. Valid characters are 'a'-'z', 'A'-'Z', '0'-'9', '_'." );
			return;
		}
		$value = self::decodeInput( $value );
		$value = Regex::replaceCallback( '/(\$[a-zA-Z0-9]+)/', fn ( $m ) => $this->getDefine( $m[1] ), $value );
		$this->consts[strtolower( $name )] = $value;
	}

	/**
	 * @throws CompileError
	 */
	private function parseLineData(): void {
		$this->getData();
		if ( $this->noData ) {
			$this->error( "Data expected for command 'LineData', but line is not indented.\n" );
			return;
		}
		if ( !isset( $this->period['from'] ) ) {
			$this->error( "LineData invalid. No (valid) command 'Period' specified in previous lines." );
			while ( !$this->inputParsed && !$this->noData ) {
				$this->getData();
			}
			return;
		}

		$color = '';
		$layer = 'front';
		$width = 2.0;
		while ( !$this->inputParsed && !$this->noData ) {
			$at = '';
			$from = '';
			$till = '';
			$atpos = '';
			$frompos = '';
			$tillpos = '';
			$points = '';

			$this->checkPreset( 'LineData' );
			if ( !$this->validAttributes( 'LineData' ) ) {
				$this->getData();
				continue;
			}
			$color = $this->lineDefs['color'] ?? $color;
			$layer = $this->lineDefs['layer'] ?? $layer;
			$width = $this->lineDefs['width'] ?? $width;
			$frompos = $this->lineDefs['frompos'] ?? $frompos;
			$tillpos = $this->lineDefs['tillpos'] ?? $tillpos;
			$atpos = $this->lineDefs['atpos'] ?? $atpos;

			foreach ( $this->attributes as $attribute => $attrValue ) {
				$attrValue = self::str( $attrValue );
				if ( Regex::match( '/^(?:At|From|Till)$/i', $attribute ) ) {
					if ( Regex::match( '/^Start$/i', $attrValue ) ) {
						$attrValue = self::str( $this->period['from'] );
					}
					if ( Regex::match( '/^End$/i', $attrValue ) ) {
						$attrValue = self::str( $this->period['till'] ?? null );
					}
					if ( !$this->validDateFormat( $attrValue ) ) {
						$this->error( "LineData attribute '$attribute' invalid.\n"
							. "Date does not conform to specified DateFormat '{$this->dateFormat}'." );
						$this->getData();
						continue 2;
					}
					if ( !$this->validDateRange( $attrValue ) ) {
						$this->error( "LineData attribute '$attribute' invalid.\n"
							. "Date '$attrValue' not within range as specified by command Period." );
						$this->getData();
						continue 2;
					}
					if ( Regex::match( '/At/i', $attribute ) ) {
						$at = $attrValue;
						$from = '';
						$till = '';
					} elseif ( Regex::match( '/From/i', $attribute ) ) {
						$from = $attrValue;
						$at = '';
					} else {
						$till = $attrValue;
						$at = '';
					}
				} elseif ( Regex::match( '/^(?:atpos|frompos|untilpos|tillpos)$/i', $attribute ) ) {
					if ( Regex::match( '/^(?:Start|End)$/i', $attrValue ) ) {
						$attrValue = strtolower( $attrValue );
					} elseif ( !self::validAbs( $attrValue ) ) {
						$this->error( "LineData attribute '$attribute' invalid.\n"
							. "Specify value as x[.y][px, in, cm] examples: '200', '20px', '1.3in'" );
						$this->getData();
						continue 2;
					}
					if ( Regex::match( '/atpos/i', $attribute ) ) {
						$atpos = self::normalize( $attrValue );
					} elseif ( Regex::match( '/frompos/i', $attribute ) ) {
						$frompos = self::normalize( $attrValue );
					} else {
						$tillpos = self::normalize( $attrValue );
					}
				} elseif ( Regex::match( '/Color/i', $attribute ) ) {
					if ( !$this->colorPredefined( $attrValue ) && !isset( $this->colors[strtolower( $attrValue )] ) ) {
						$this->error( "LineData  attribute '$attribute' invalid. Unknown color '$attrValue'.\n"
							. "  Specify command 'Color' before this command." );
						$this->getData();
						continue 2;
					}
					if ( !$this->colorPredefined( $attrValue ) ) {
						$attrValue = $this->colors[strtolower( $attrValue )];
					}
					$color = $attrValue;
				} elseif ( Regex::match( '/Layer/i', $attribute ) ) {
					if ( !Regex::match( '/^(?:back|front)$/i', $attrValue ) ) {
						$this->error( "LineData attribute '$attrValue' invalid.\nSpecify back(default) or front" );
						$this->getData();
						continue 2;
					}
					$layer = $attrValue;
				} elseif ( Regex::match( '/Points/i', $attribute ) ) {
					if ( !Regex::match( '/^%28%(\d+)\,(\d+)%29%%28%(\d+)\,(\d+)%29%$/', $attrValue, $m ) ) {
						$this->error( "LineData attribute '$attrValue' invalid.\nSpecify 'points:(x1,y1)(x2,y2)'" );
						$this->getData();
						continue 2;
					}
					$points = "$m[1],$m[2],$m[3],$m[4]";
				} elseif ( Regex::match( '/Width/i', $attribute ) ) {
					if ( !self::validAbs( $attrValue ) ) {
						$this->error( "LineData attribute '$attribute' invalid.\n"
							. "Specify value as x[.y][px, in, cm] examples: '200', '20px', '1.3in'" );
						$this->getData();
						continue 2;
					}
					if ( self::num( $attrValue ) < 0.1 || self::num( $attrValue ) > 10 ) {
						$this->error( "LineData attribute '$attribute' invalid.\n"
							. "Specify value as between 0.1 and 10" );
						$this->getData();
						continue 2;
					}
					$width = $attrValue;
				}
			}

			// update defaults
			if ( $at === '' && $from === '' && $till === '' && $points === '' ) {
				if ( $color !== '' ) {
					$this->lineDefs['color'] = $color;
				}
				if ( $layer !== '' ) {
					$this->lineDefs['layer'] = $layer;
				}
				if ( self::str( $width ) !== '' ) {
					$this->lineDefs['width'] = $width;
				}
				if ( $atpos !== '' ) {
					$this->lineDefs['atpos'] = $atpos;
				}
				if ( $frompos !== '' ) {
					$this->lineDefs['frompos'] = $frompos;
				}
				if ( $tillpos !== '' ) {
					$this->lineDefs['tillpos'] = $tillpos;
				}
			}
			if ( $layer === '' ) {
				$layer = 'back';
			}
			if ( $color === '' ) {
				$color = 'black';
			}

			$explanation = "\nA line is defined as follows:\n"
				. "  Perpendicular to the time axis: 'at frompos tillpos'\n"
				. "  Parralel to the time axis: 'from till atpos'\n"
				. "  Any direction: points(x1,y1)(x2,y2)\n"
				. "  at,from,till expect date/time values, just like with command PlotData\n"
				. "  frompos,tillpos,atpos,x1,x2,y1,y2 expect coordinates (e.g. pixels values)\n"
				. "  'until' and 'untilpos' may be used instead of 'till' and 'tillpos'.";
			if ( $at !== '' && ( $from !== '' || $till !== '' || $points !== '' ) ) {
				$this->error( "LineData attribute 'at' can not be combined with 'from', 'till' or "
					. "'points'\n" . $explanation );
				$this->getData();
				continue;
			}
			if ( ( $from !== '' && $till === '' ) || ( $from === '' && $till !== '' ) ) {
				$this->error( "LineData attributes 'from' and 'till' should always be specified "
					. "together\n" . $explanation );
				$this->getData();
				continue;
			}
			if ( $points !== '' && ( $from !== '' || $till !== '' || $at !== '' ) ) {
				$this->error( "LineData attribute 'points' can not be combined with 'at', 'from' or "
					. "'till'\n" . $explanation );
				$this->getData();
				continue;
			}

			$w = self::str( $width );
			$lc = strtolower( $color );
			$ll = strtolower( $layer );
			if ( $at !== '' ) {
				$this->drawLines[] = "1|$at|$frompos|$tillpos|$lc|$w|$ll\n";
			}
			if ( $from !== '' ) {
				$this->drawLines[] = "2|$atpos|$from|$till|$lc|$w|$ll\n";
			}
			if ( $points !== '' ) {
				$this->drawLines[] = "3|$points|$lc|$w|$ll\n";
			}
			$this->getData();
		}
	}

	/**
	 * @throws CompileError
	 */
	private function parseImageSize(): void {
		if ( !$this->validAttributes( 'ImageSize' ) ) {
			return;
		}
		$this->checkPreset( 'ImageSize' );

		foreach ( $this->attributes as $attribute => $attrValue ) {
			$attrValue = self::str( $attrValue );
			if ( Regex::match( '/Width|Height/i', $attribute ) ) {
				if ( !Regex::match( '/auto/i', $attrValue ) && !self::validAbs( $attrValue ) ) {
					$this->error( "ImageSize attribute '$attribute' invalid.\n"
						. "Specify value as x[.y][px, in, cm] examples: '200', '20px', '1.3in'" );
					return;
				}
			} elseif ( Regex::match( '/BarIncrement/i', $attribute ) ) {
				if ( !self::validAbs( $attrValue ) ) {
					$this->error( "ImageSize attribute '$attribute' invalid.\n"
						. "Specify value as x[.y][px, in, cm] examples: '200', '20px', '1.3in'" );
					return;
				}
				$this->attributes['barinc'] = $attrValue;
			}
		}

		$a = $this->attributes;
		$auto = Regex::match( '/auto/i', self::str( $a['width'] ?? null ) )
			|| Regex::match( '/auto/i', self::str( $a['height'] ?? null ) );
		if ( $auto && self::str( $a['barinc'] ?? null ) === '' ) {
			$this->error( "ImageSize attribute 'barincrement' missing.\n"
				. "Automatic determination of image width or height implies specification of this attribute" );
			return;
		}
		if ( !$auto && self::str( $a['barinc'] ?? null ) !== '' ) {
			$this->error( "ImageSize attribute 'barincrement' not valid now.\n"
				. "This attribute is only valid (and mandatory) in combination with 'width:auto' or 'height:auto'" );
			return;
		}
		$this->image = $this->attributes;
	}

	/**
	 * @throws CompileError
	 */
	private function parseLegend(): void {
		if ( !$this->validAttributes( 'Legend' ) ) {
			return;
		}
		$this->checkPreset( 'Legend' );

		$invalidPos = "invalid.\nSpecify value as x[.y][px, in, cm] examples: '200', '20px', '1.3in'";
		foreach ( $this->attributes as $attribute => $attrValue ) {
			$attrValue = self::str( $attrValue );
			if ( Regex::match( '/Columns/i', $attribute ) ) {
				if ( self::num( $attrValue ) < 1 || self::num( $attrValue ) > 4 ) {
					$this->error( "Legend attribute 'columns' invalid. Specify 1,2,3 or 4" );
					return;
				}
			} elseif ( Regex::match( '/Orientation/i', $attribute ) ) {
				if ( !Regex::match( '/^(?:hor|horizontal|ver|vertical)$/i', $attrValue ) ) {
					$this->error( "Legend attribute '$attrValue' invalid. Specify hor[izontal] or ver[tical]" );
					return;
				}
				$this->attributes['orientation'] = substr( $attrValue, 0, 3 );
			} elseif ( Regex::match( '/Position/i', $attribute ) ) {
				if ( !Regex::match( '/^(?:top|bottom|right)$/i', $attrValue ) ) {
					$this->error( "Legend attribute '$attrValue' invalid.\nSpecify top, bottom or right" );
					return;
				}
			} elseif ( Regex::match( '/Left|Top|ColumnWidth/i', $attribute ) ) {
				if ( !self::validAbsRel( $attrValue ) ) {
					$this->error( "Legend attribute '$attribute' $invalidPos" );
					return;
				}
			}
		}

		$a = &$this->attributes;
		if ( isset( $a['position'] ) ) {
			if ( isset( $a['left'] ) ) {
				$this->error( "Legend definition invalid. Attributes 'position' and 'left' are mutually exclusive." );
				return;
			}
		} elseif ( !isset( $a['left'] ) && !isset( $a['top'] ) ) {
			$a['position'] = 'bottom';
		} elseif ( !isset( $a['left'] ) || !isset( $a['top'] ) ) {
			$this->error( "Legend definition invalid. Specify 'position', or 'left' & 'top'." );
			return;
		}

		$position = self::str( $a['position'] ?? null );
		$orientation = self::str( $a['orientation'] ?? null );
		if ( Regex::match( '/right/i', $position ) ) {
			if ( isset( $a['columns'] ) ) {
				$this->error( "Legend definition invalid.\nAttribute 'columns' and 'position:right' are "
					. "mutually exclusive." );
				return;
			}
			if ( isset( $a['columnwidth'] ) ) {
				$this->error( "Legend definition invalid.\n"
					. "Attribute 'columnwidth' and 'position:right' are mutually exclusive." );
				return;
			}
		}
		if ( Regex::match( '/hor/i', $orientation ) ) {
			if ( Regex::match( '/right/i', $position ) ) {
				$this->error( "Legend definition invalid.\n"
					. "'position:right' and 'orientation:horizontal' are mutually exclusive." );
				return;
			}
			if ( isset( $a['columns'] ) ) {
				$this->error( "Legend definition invalid.\n"
					. "Attribute 'columns' and 'orientation:horizontal' are mutually exclusive." );
				return;
			}
			if ( isset( $a['columnwidth'] ) ) {
				$this->error( "Legend definition invalid.\n"
					. "Attribute 'columnwidth' and 'orientation:horizontal' are mutually exclusive." );
				return;
			}
		}
		if ( self::num( $a['columns'] ?? null ) > 1 && isset( $a['left'] ) && !isset( $a['columnwidth'] ) ) {
			$this->error( "Legend attribute 'columnwidth' not defined.\nThis is needed when attribute 'left' is "
				. "specified." );
			return;
		}
		if ( !isset( $a['orientation'] ) ) {
			$a['orientation'] = 'ver';
		}
		unset( $a );
		$this->legend = $this->attributes;
	}

	/**
	 * @throws CompileError
	 */
	private function parsePeriod(): void {
		if ( !$this->validAttributes( 'Period' ) ) {
			return;
		}
		foreach ( $this->attributes as $attribute => $attrValue ) {
			$attrValue = self::str( $attrValue );
			if ( $this->dateFormat === 'yyyy' ) {
				if ( !Regex::match( '/^\-?\d+$/', $attrValue ) ) {
					$this->error( "Period definition invalid.\nInvalid year '$attrValue' specified for "
						. "attribute '$attribute'." );
					return;
				}
			} elseif ( $this->dateFormat === 'x.y' ) {
				if ( !Regex::match( '/^\-?\d+(?:\.\d+)?$/', $attrValue ) ) {
					$this->error( "Period definition invalid.\nInvalid year '$attrValue' specified for "
						. "attribute '$attribute'." );
					return;
				}
			} else {
				if ( Regex::match( '/^\d+$/', $attrValue ) && self::num( $attrValue ) >= 1800
					&& self::num( $attrValue ) <= 2030
				) {
					if ( Regex::match( '/^From$/i', $attribute ) ) {
						$attrValue = $this->dateFormat === 'yyyy-mm-dd' ? "$attrValue-01-01" : "01/01/$attrValue";
					}
					if ( Regex::match( '/^Till$/i', $attribute ) ) {
						if ( $this->dateFormat === 'yyyy-mm-dd' ) {
							$attrValue .= '-12-31';
						} elseif ( $this->dateFormat === 'dd/mm/yyyy' ) {
							$attrValue = "31/12/$attrValue";
						} else {
							$attrValue = "12/31/$attrValue";
						}
					}
				}
				if ( !$this->validDateFormat( $attrValue ) ) {
					$this->error( "Period attribute '$attribute' invalid.\n"
						. "Date does not conform to specified DateFormat '{$this->dateFormat}'." );
					return;
				}
				$year = $this->dateFormat === 'yyyy-mm-dd' ? substr( $attrValue, 0, 4 ) : substr( $attrValue, 6, 4 );
				if ( self::num( $year ) < 1800 ) {
					$this->error( "Period attribute '$attribute' invalid. Specify year >= 1800." );
					return;
				}
				$this->attributes[$attribute] = $attrValue;
			}
		}
		$this->period = $this->attributes;
	}

	/**
	 * @throws CompileError
	 */
	private function parsePlotArea(): void {
		if ( !$this->validAttributes( 'PlotArea' ) ) {
			return;
		}
		// The values are not validated: the Perl original checked an always empty list.
		$this->checkPreset( 'PlotArea' );
		[ $top, $height, $right, $width ] = array_map( fn ( $k ) => self::str( $this->attributes[$k] ?? null ),
			[ 'top', 'height', 'right', 'width' ] );
		if ( $top !== '' && $height !== '' ) {
			$this->error( "PlotArea attributes 'top' and 'height' are mutually exclusive. Specify only one of them." );
			return;
		}
		if ( $right !== '' && $width !== '' ) {
			$this->error( "PlotArea attributes 'right' and 'width' are mutually exclusive. Specify only one of them." );
			return;
		}
		if ( $top === '' && $height === '' ) {
			$this->error( "PlotArea definition incomplete. Either attribute 'top' (advised) or 'height' should "
				. "be specified" );
			return;
		}
		if ( $right === '' && $width === '' ) {
			$this->error( "PlotArea definition incomplete. Either attribute 'right' (advised) or 'width' should "
				. "be specified" );
			return;
		}
		$this->plotArea = $this->attributes;
	}

	/**
	 * @throws CompileError
	 */
	private function parsePlotData(): void {
		$barsCommandFound = count( $this->bars ) > 0;
		$prevbar = '';
		$barndx = 0;

		if ( self::str( $this->period['from'] ?? null ) === '' || self::str( $this->axis['time'] ?? null ) === '' ) {
			if ( self::str( $this->period['from'] ?? null ) === '' ) {
				$this->error( "PlotData invalid. No (valid) command 'Period' specified in previous lines." );
			} else {
				$this->error( "PlotData invalid. No (valid) command 'TimeAxis' specified in previous lines." );
			}
			$this->getData();
			while ( !$this->inputParsed && !$this->noData ) {
				$this->getData();
			}
			return;
		}

		$this->getData();
		if ( $this->noData ) {
			$this->error( "Data expected for command 'PlotData', but line is not indented.\n" );
			return;
		}

		$this->plotDefs['anchor'] = 'middle';
		while ( !$this->inputParsed && !$this->noData ) {
			if ( !$this->validAttributes( 'PlotData' ) ) {
				$this->getData();
				continue;
			}
			$at = '';
			$from = '';
			$till = '';
			$text = '';
			$link = '';
			$hint = '';
			$shift = '';
			$this->checkPreset( 'PlotData' );
			$d = $this->plotDefs;
			$bar = $d['bar'] ?? '';
			$color = $d['color'] ?? 'barcoldefault';
			$textcolor = $d['textcolor'] ?? 'black';
			$fontsize = $d['fontsize'] ?? 'S';
			$width = $d['width'] ?? '0.25';
			$anchor = $d['anchor'] ?? '';
			$align = $d['align'] ?? 'left';
			$shiftx = $d['shiftx'] ?? '';
			$shifty = $d['shifty'] ?? '';
			$mark = $d['mark'] ?? '';
			$markcolor = $d['markcolor'] ?? '';

			foreach ( $this->attributes as $attribute => $attrValue ) {
				$attrValue = self::str( $attrValue );
				if ( Regex::match( '/^Bar$/i', $attribute ) ) {
					if ( !self::isValidId( $attrValue ) ) {
						$this->error( "PlotData attribute '$attribute' invalid.\n"
							. "Use only characters 'a'-'z', 'A'-'Z', '0'-'9', '_'. "
							. "The id must not start with '#' or contain ','.\n" );
						$this->getData();
						continue 2;
					}
					if ( $barsCommandFound ) {
						if ( !$this->barDefined( $attrValue ) ) {
							$this->error( "PlotData invalid. Bar '$attrValue' not (properly) defined." );
							$this->getData();
							continue 2;
						}
					} elseif ( !$this->barDefined( $attrValue ) ) {
						$this->addBar( $attrValue );
					}
					$bar = $attrValue;
					$prevbar = $bar;
				} elseif ( Regex::match( '/^BarSet$/i', $attribute ) ) {
					if ( !self::isValidId( $attrValue ) ) {
						$this->error( "PlotData attribute '$attribute' invalid.\n"
							. "Use only characters 'a'-'z', 'A'-'Z', '0'-'9', '_'. "
							. "The id must not start with '#' or contain ','.\n" );
						$this->getData();
						continue 2;
					}
					if ( Regex::match( '/break/i', $attrValue ) ) {
						$barndx = 0;
					} elseif ( Regex::match( '/skip/i', $attrValue ) ) {
						$barndx++;
						$this->barDefined( $prevbar . '#' . $barndx );
					} else {
						if ( $barsCommandFound && !$this->barDefined( $attrValue . '#1' ) ) {
							$this->error( "PlotData invalid. BarSet '$attrValue' not (properly) defined with "
								. "command BarData." );
							$this->getData();
							continue 2;
						}
						$bar = $attrValue;
						if ( $bar !== $prevbar ) {
							$barndx = 0;
						}
						$prevbar = $bar;
					}
				} elseif ( Regex::match( '/^(?:At|From|Till)$/i', $attribute ) ) {
					if ( Regex::match( '/^Start$/i', $attrValue ) ) {
						$attrValue = self::str( $this->period['from'] );
					}
					if ( Regex::match( '/^End$/i', $attrValue ) ) {
						$attrValue = self::str( $this->period['till'] ?? null );
					}
					if ( !$this->validDateFormat( $attrValue ) ) {
						$this->error( "PlotData attribute '$attribute' invalid.\n"
							. "Date '$attrValue' does not conform to specified DateFormat {$this->dateFormat}." );
						$this->getData();
						continue 2;
					}
					if ( !$this->validDateRange( $attrValue ) ) {
						$this->error( "Plotdata attribute '$attribute' invalid.\n"
							. "Date '$attrValue' not within range as specified by command Period." );
						$this->getData();
						continue 2;
					}
					if ( Regex::match( '/^At$/i', $attribute ) ) {
						$at = $attrValue;
					} elseif ( Regex::match( '/^From$/i', $attribute ) ) {
						$from = $attrValue;
					} else {
						$till = $attrValue;
					}
				} elseif ( Regex::match( '/^(?:Color|TextColor)$/i', $attribute ) ) {
					if ( !$this->colorPredefined( $attrValue ) && !isset( $this->colors[strtolower( $attrValue )] ) ) {
						$this->error( "PlotData invalid. Attribute '$attribute' "
							. ( Regex::match( '/^Color$/i', $attribute ) ? 'has' : 'contains' )
							. " unknown color '$attrValue'.\n  Specify command 'Color' before this command." );
						$this->getData();
						continue 2;
					}
					if ( Regex::match( '/^Color$/i', $attribute ) ) {
						$color = $attrValue;
					} else {
						$textcolor = $this->colors[strtolower( $attrValue )] ?? strtolower( $attrValue );
					}
				} elseif ( Regex::match( '/^Width$/i', $attribute ) ) {
					$width = self::normalize( $attrValue );
					if ( self::num( $width ) > self::num( $this->maxBarWidth ) ) {
						$this->maxBarWidth = $width;
					}
				} elseif ( Regex::match( '/^FontSize$/i', $attribute ) ) {
					if ( !Regex::match( '/\d+(?:\.\d)?/', $attrValue )
						&& !Regex::match( '/xs|s|m|l|xl/i', $attrValue )
					) {
						$this->error( "PlotData invalid. Specify for attribute '$attribute' a number of XS,S,M,L,XL." );
						$this->getData();
						continue 2;
					}
					$fontsize = $attrValue;
				} elseif ( Regex::match( '/^Anchor$/i', $attribute ) ) {
					if ( !Regex::match( '/^(?:from|till|middle)$/i', $attrValue ) ) {
						$this->error( "PlotData value '$attribute' invalid. Specify 'from', 'till' or 'middle'." );
						$this->getData();
						continue 2;
					}
					$anchor = strtolower( $attrValue );
				} elseif ( Regex::match( '/^Align$/i', $attribute ) ) {
					if ( !Regex::match( '/^(?:left|right|center)$/i', $attrValue ) ) {
						$this->error( "PlotData value '$attribute' invalid. Specify 'left', 'right' or 'center'." );
						$this->getData();
						continue 2;
					}
					$align = strtolower( $attrValue );
				} elseif ( Regex::match( '/^Shift$/i', $attribute ) ) {
					$shift = Regex::replace( '/%28%(.*?)%29%/', '$1', $attrValue, 1 );
					$shift = Regex::replace( '/\s/', '', $shift );
					$xy = self::split( '/,/', $shift );
					if ( ( $xy[0] ?? '' ) !== '' ) {
						$shiftx = self::normalize( $xy[0] );
					}
					if ( ( $xy[1] ?? '' ) !== '' ) {
						$shifty = self::normalize( $xy[1] );
					}
					if ( self::num( $shiftx ) < -10 || self::num( $shiftx ) > 10
						|| self::num( $shifty ) < -10 || self::num( $shifty ) > 10
					) {
						$this->error( "PlotData invalid. Attribute '$shift', specify value(s) between -1000 and "
							. "1000 pixels"
							. ' = -10 and 10 inch.' );
						$this->getData();
						continue 2;
					}
				} elseif ( Regex::match( '/^Text$/i', $attribute ) ) {
					$text = str_replace( '\\n', "\n", self::parseText( $attrValue ) );
					$text = Regex::replaceCallback( '/(\[\[? [^\]]* \n [^\]]* \]?\])/x',
						static fn ( $m ) => self::normalizeWikiLink( $m[1] ), $text );
				} elseif ( Regex::match( '/^Link$/i', $attribute ) ) {
					$link = self::encodeUrl( self::normalizeUrl( self::parseText( $attrValue ) ) );
				} elseif ( Regex::match( '/^Mark$/i', $attribute ) ) {
					$sub = self::split( '/,/', Regex::replace( '/%28% (.*) %29%/x', '$1', $attrValue, 1 ) );
					$mark = $sub[0] ?? '';
					if ( !Regex::match( '/^(?:Line|None)$/i', $mark ) ) {
						$this->error( "PlotData invalid. Value '$mark' for attribute 'mark' unknown." );
						$this->getData();
						continue 2;
					}
					if ( isset( $sub[1] ) ) {
						$markcolor = $sub[1];
						if ( !$this->colorPredefined( $markcolor )
							&& !isset( $this->colors[strtolower( $markcolor )] )
						) {
							$this->error( "PlotData invalid. Attribute 'mark': unknown color '$markcolor'.\n"
								. "  Specify command 'Color' before this command." );
							$this->getData();
							continue 2;
						}
						$markcolor = strtolower( $markcolor );
					} else {
						$markcolor = 'black';
					}
				} else {
					$this->error( "PlotData invalid. Unknown attribute '$attribute' found." );
					$this->getData();
					continue 2;
				}
			}

			$shift = self::str( $shiftx ) . ',' . self::str( $shifty );
			if ( self::str( $this->maxBarWidth ) === '' ) {
				$this->maxBarWidth = self::num( $width ) - 0.001;
			}
			if ( $bar !== '' ) {
				$this->barLegend[strtolower( $bar )] ??= $bar;
				$this->barWidths[$bar] ??= $width;
			}

			// update defaults
			if ( $at === '' && $from === '' && $till === '' ) {
				if ( $bar !== '' ) {
					$this->plotDefs['bar'] = $bar;
				}
				foreach ( [ 'color' => $color, 'textcolor' => $textcolor, 'fontsize' => $fontsize,
					'width' => $width, 'anchor' => $anchor, 'align' => $align, 'shiftx' => $shiftx,
					'shifty' => $shifty, 'mark' => $mark, 'markcolor' => $markcolor ] as $k => $v
				) {
					if ( self::str( $v ) !== '' ) {
						$this->plotDefs[$k] = $v;
					}
				}
				$this->getData();
				continue;
			}

			if ( $bar === '' ) {
				if ( $prevbar !== '' ) {
					$bar = $prevbar;
				} else {
					if ( count( $this->bars ) > 1 ) {
						$this->error( "PlotData invalid. Specify attribute 'bar'." );
						$this->getData();
						continue;
					}
					$bar = count( $this->bars ) === 1 ? $this->bars[0] : '1';
					$prevbar = $bar;
				}
			}

			// bar is actually a bar set
			if ( $this->barDefined( $bar . '#1' ) && ( $from !== '' || $at !== '' || $text === ' ' ) ) {
				$barndx++;
				if ( !$this->barDefined( $bar . '#' . $barndx ) ) {
					$barndx = 1;
				}
				$bar = $bar . '#' . $barndx;
			}

			if ( $at !== '' && ( $from !== '' || $till !== '' ) ) {
				$this->error( "PlotData invalid. Attributes 'at' and 'from/till' are mutually exclusive." );
				$this->getData();
				continue;
			}
			if ( ( $from === '' && $till !== '' ) || ( $from !== '' && $till === '' ) ) {
				$this->error( "PlotData invalid. Specify attribute 'at' or 'from' + 'till'." );
				$this->getData();
				continue;
			}
			if ( $at === '' ) {
				if ( $text !== '' && $anchor === '' ) {
					$this->error( "PlotData invalid. Attribute 'anchor' missing." );
					$this->getData();
					continue;
				}
				if ( $color === '' ) {
					$this->error( "PlotData invalid. Attribute 'color' missing." );
					$this->getData();
					continue;
				}
				if ( self::str( $width ) === '' ) {
					$this->error( "PlotData invalid. Attribute 'width' missing." );
					$this->getData();
					continue;
				}
			}

			if ( $from !== '' ) {
				if ( $link !== '' && $hint === '' ) {
					$hint = self::externalLinkToHint( $link );
				}
				if ( $link !== '' || $hint !== '' ) {
					$this->mapPng = true;
				}
				if ( $link !== '' ) {
					$this->mapSvg = true;
				}
				$this->plotBars[] = Printf::f( self::num( $width ), '6.3' ) . ",$bar,$from,$till,"
					. strtolower( $color )
					. ",$link,$hint,\n";
				if ( self::num( $width ) > self::num( $this->barWidths[$bar] ?? null ) ) {
					$this->barWidths[$bar] = $width;
				}
				if ( $text !== '' ) {
					if ( $anchor === 'from' ) {
						$at = $from;
					} elseif ( $anchor === 'till' ) {
						$at = $till;
					} else {
						$at = $this->dateMedium( $from, $till );
					}
				}
				if ( $mark !== '' && !Regex::match( '/none/i', $mark ) ) {
					$mc = strtolower( $markcolor );
					$this->plotLines[] = "$bar,$from,$from,$mc,,,\n";
					$this->plotLines[] = "$bar,$till,$till,$mc,,,\n";
					$mark = '';
				}
			}

			if ( $at !== '' ) {
				if ( $mark !== '' && !Regex::match( '/none/i', $mark ) ) {
					$this->plotLines[] = "$bar,$at,$at," . strtolower( $markcolor ) . ",,,\n";
				}
				if ( $text !== '' ) {
					if ( $link !== '' ) {
						if ( Regex::match( '/\[.*\]/', $text ) ) {
							$text = Regex::replace( '/\[+ (?:[^\|]* \|)? ([^\]]*) \]+/x', '$1', $text );
						}
						if ( $hint === '' ) {
							$hint = self::externalLinkToHint( $link );
						}
					}
					if ( $align === '' ) {
						$align = 'center';
					}
					$esc = static fn ( $s ) => str_replace( ',', '#%$', self::str( $s ) );
					$this->plotText[] = implode( ',', [ $at, $bar, $esc( $text ), $esc( $textcolor ),
						self::str( $fontsize ), $align, $esc( $shift ), $esc( $link ), $esc( $hint ) ] );
				}
			}
			$this->getData();
		}

		$maxwidth = 0;
		foreach ( $this->barWidths as $w ) {
			if ( self::num( $w ) != 0 && self::num( $w ) > self::num( $maxwidth ) ) {
				$maxwidth = $w;
			}
		}
		foreach ( $this->barWidths as $b => $w ) {
			if ( self::num( $w ) == 0 ) {
				$this->barWidths[$b] = $maxwidth;
			}
		}
	}

	/**
	 * @throws CompileError
	 */
	private function parsePreset(): void {
		if ( !$this->firstCmd ) {
			$this->error( "Specify 'Preset' command before any other commands, if desired at all.\n" );
			return;
		}
		$preset = self::str( $this->attributes['single'] ?? null );
		if ( !Regex::match( '/^(?:TimeVertical_OneBar_UnitYear|TimeHorizontal_AutoPlaceBars_UnitYear)$/i', $preset ) ) {
			$this->error( "Preset value invalid.\n"
				. "  Two presets are available:\n"
				. "  TimeVertical_OneBar_UnitYear and TimeHorizontal_AutoPlaceBars_UnitYear\n"
				. "  See documentation at https://www.mediawiki.org/wiki/Extension:EasyTimeline/syntax#Presets" );
			return;
		}
		$this->preset = $preset;
		$bro = self::H_BRO;
		$brc = self::H_BRC;
		if ( Regex::match( '/^TimeVertical_OneBar_UnitYear/i', $preset ) ) {
			$this->dateFormat = 'yyyy';
			$this->alignBars = 'early';
			$this->axis['format'] = 'yyyy';
			$this->axis['time'] = 'y';
			$this->plotArea = [ 'left' => 45, 'right' => 10, 'top' => 10, 'bottom' => 10 ] + $this->plotArea;
			array_push( $this->presetList,
				'PlotArea|+|left|45', 'PlotArea|+|right|10', 'PlotArea|+|top|10', 'PlotArea|+|bottom|10',
				'PlotArea|-|width', 'PlotArea|-|height', 'Dateformat|-||yyyy', 'TimeAxis|=|format|yyyy',
				'TimeAxis|=|orientation|vertical', 'ScaleMajor|=|unit|year', 'ScaleMinor|=|unit|year',
				'AlignBars|=||early', "PlotData|+|mark|{$bro}line,white{$brc}", 'PlotData|+|align|left',
				'PlotData|+|fontsize|S', 'PlotData|+|width|20', "PlotData|+|shift|{$bro}20,0{$brc}" );
		} else {
			$this->dateFormat = 'yyyy';
			$this->alignBars = 'justify';
			$this->axis['format'] = 'yyyy';
			$this->axis['time'] = 'x';
			$this->plotArea = [ 'left' => 25, 'right' => 25, 'top' => 15, 'bottom' => 30 ] + $this->plotArea;
			$this->image['height'] = 'auto';
			$this->image['barinc'] = 20;
			$this->backgroundColors['canvas'] = 'gray(0.7)';
			$this->legend['orientation'] = 'ver';
			$this->legend['left'] = 35;
			$this->legend['top'] = 130;
			$this->storeColor( 'canvas', self::encodeInput( 'gray(0.7)' ), '' );
			$this->storeColor( 'grid1', self::encodeInput( 'gray(0.4)' ), '' );
			$this->storeColor( 'grid2', self::encodeInput( 'gray(0.2)' ), '' );
			array_push( $this->presetList,
				'ImageSize|=|height|auto', 'ImageSize|+|barincrement|20',
				'PlotArea|+|left|25', 'PlotArea|+|right|25', 'PlotArea|+|top|15', 'PlotArea|+|bottom|30',
				'PlotArea|-|width', 'PlotArea|-|height', 'Dateformat|-||yyyy', 'TimeAxis|=|format|yyyy',
				'TimeAxis|=|orientation|horizontal', 'ScaleMajor|=|unit|year', 'ScaleMajor|+|grid|grid1',
				'ScaleMinor|=|unit|year', 'AlignBars|=||justify', 'Legend|+|orientation|ver',
				'Legend|+|left|35', 'Legend|+|top|130', 'PlotData|+|align|left', 'PlotData|+|anchor|from',
				'PlotData|+|fontsize|M', 'PlotData|+|width|15', 'PlotData|+|textcolor|black',
				"PlotData|+|shift|{$bro}4,-6{$brc}" );
		}
	}

	/**
	 * @throws CompileError
	 */
	private function parseScale(): void {
		$scale = Regex::match( '/ScaleMajor/i', $this->command ) ? 'Major' : 'Minor';
		if ( !$this->validAttributes( 'Scale' . $scale ) ) {
			return;
		}
		$this->checkPreset( 'Scale' . $scale );
		$this->scales[$scale] = true;

		// Perl visited the attributes in random hash order and read values that an earlier
		// step may have deleted. A preset 'grid' visited after 'gridcolor' made Perl fail, so
		// existing images were rendered with 'grid' first.
		$keys = array_keys( $this->attributes );
		usort( $keys, static fn ( $a, $b ) => ( $b === 'grid' ) <=> ( $a === 'grid' ) );
		foreach ( $keys as $attribute ) {
			$attrValue = self::str( $this->attributes[$attribute] ?? null );
			if ( Regex::match( '/Grid/i', $attribute ) ) {
				if ( !$this->colorPredefined( $attrValue ) && !isset( $this->colors[strtolower( $attrValue )] ) ) {
					$this->error( "Scale attribute '$attribute' invalid. Unknown color '$attrValue'.\n"
						. "  Specify command 'Color' before this command." );
					return;
				}
				$this->attributes["$scale grid"] = $attrValue;
				unset( $this->attributes['grid'] );
			} elseif ( Regex::match( '/Text/i', $attribute ) ) {
				$attrValue = str_replace( '~', '\\n', self::newlinesToTildes( $attrValue ) );
				$attrValue = Regex::replace( '/^"/', '', $attrValue );
				$this->attributes["$scale stubs"] = Regex::replace( '/"$/', '', $attrValue );
			} elseif ( Regex::match( '/Unit/i', $attribute ) ) {
				if ( $this->dateFormat === 'yyyy' ) {
					if ( !Regex::match( '/^(?:year|years)$/i', $attrValue ) ) {
						$this->error( "Scale attribute '$attribute' invalid. DateFormat 'yyyy' implies 'unit:year'." );
						return;
					}
				} elseif ( !Regex::match( '/^(?:year|month|day)s?$/i', $attrValue ) ) {
					$this->error( "Scale attribute '$attribute' invalid. Specify year, month or day." );
					return;
				}
				$this->attributes["$scale unit"] = Regex::replace( '/s$/', '', $attrValue, 1 );
				unset( $this->attributes['unit'] );
			} elseif ( Regex::match( '/Increment/i', $attribute ) ) {
				if ( !Regex::match( '/^\d+$/i', $attrValue ) || self::num( $attrValue ) == 0 ) {
					$this->error( "Scale attribute '$attribute' invalid. Specify positive integer." );
					return;
				}
				$this->attributes["$scale inc"] = $attrValue;
				unset( $this->attributes['increment'] );
			} elseif ( Regex::match( '/Start/i', $attribute ) ) {
				if ( in_array( $this->dateFormat, [ 'dd/mm/yyyy', 'mm/dd/yyyy', 'yyyy-mm-dd' ], true )
					&& Regex::match( '/^\d+$/', $attrValue ) && self::num( $attrValue ) >= 1800
					&& self::num( $attrValue ) <= 2030
				) {
					$attrValue = $this->dateFormat === 'yyyy-mm-dd' ? "$attrValue-01-01" : "01/01/$attrValue";
				}
				if ( !$this->validDateFormat( $attrValue ) ) {
					$this->error( "Scale attribute '$attribute' invalid.\n"
						. "Date does not conform to specified DateFormat '{$this->dateFormat}'." );
					return;
				}
				if ( !$this->validDateRange( $attrValue ) ) {
					$this->error( "Scale attribute '$attribute' invalid.\n"
						. "Date '$attrValue' not within range as specified by command Period." );
					return;
				}
				$this->attributes["$scale start"] = $attrValue;
				unset( $this->attributes['start'] );
			}
			if ( $this->dateFormat === 'yyyy' ) {
				$this->attributes["$scale unit"] = 'year';
			}
		}
		foreach ( $this->attributes as $k => $v ) {
			$this->scales[$k] = $v;
		}
	}

	/**
	 * @throws CompileError
	 */
	private function parseTextData(): void {
		$posx = null;
		$posy = null;
		$this->getData();
		if ( $this->noData ) {
			$this->error( "Data expected for command 'TextData', but line is not indented.\n" );
			return;
		}

		while ( !$this->inputParsed && !$this->noData ) {
			if ( !$this->validAttributes( 'TextData' ) ) {
				$this->getData();
				continue;
			}
			$this->checkPreset( 'TextData' );
			$pos = '';
			$link = '';
			$hint = '';
			$text = '';
			$tabs = $this->textDefs['tabs'] ?? '';
			$fontsize = $this->textDefs['fontsize'] ?? '';
			$lineheight = $this->textDefs['lineheight'] ?? '';
			$textcolor = $this->textDefs['textcolor'] ?? '';

			foreach ( $this->attributes as $attribute => $attrValue ) {
				$attrValue = self::str( $attrValue );
				if ( Regex::match( '/^FontSize$/i', $attribute ) ) {
					if ( !Regex::match( '/\d+(?:\.\d)?/', $attrValue )
						&& !Regex::match( '/^(?:xs|s|m|l|xl)$/i', $attrValue )
					) {
						$this->error( "TextData invalid. Attribute '$attribute': specify number of XS,S,M,L,XL." );
						$this->getData();
						continue 2;
					}
					$fontsize = $attrValue;
					if ( !Regex::match( '/^(?:xs|s|m|l|xl)$/i', $fontsize ) ) {
						if ( self::num( $fontsize ) < 6 ) {
							$fontsize = 6;
						}
						if ( self::num( $fontsize ) > 30 ) {
							$fontsize = 30;
						}
					}
				} elseif ( Regex::match( '/^LineHeight$/i', $attribute ) ) {
					$lineheight = self::normalize( $attrValue );
					if ( self::num( $lineheight ) < -0.4 || self::num( $lineheight ) > 0.4 ) {
						$this->error( "TextData attribute 'lineheight' invalid.\n"
							. "Specify value up to 40 pixels = 0.4 inch\n"
							. "Run with option -b (bypass checks) when this is correct.\n" );
					}
				} elseif ( Regex::match( '/^Pos$/i', $attribute ) ) {
					$xy = self::split( '/,/', Regex::replace( '/\s*%28%(.*)%29%\s*/', '$1', $attrValue, 1 ) );
					$posx = self::normalize( $xy[0] ?? '' );
					$posy = self::normalize( $xy[1] ?? '' );
					$pos = self::str( $posx ) . ',' . self::str( $posy );
				} elseif ( Regex::match( '/^Tabs$/i', $attribute ) ) {
					$tabs = $attrValue;
				} elseif ( Regex::match( '/^(?:Color|TextColor)$/i', $attribute ) ) {
					if ( !$this->colorPredefined( $attrValue ) && !isset( $this->colors[strtolower( $attrValue )] ) ) {
						$this->error( "TextData invalid. Attribute '$attribute' contains unknown color '$attrValue'.\n"
							. "  Specify command 'Color' before this command." );
						$this->getData();
						continue 2;
					}
					$textcolor = $this->colors[strtolower( $attrValue )] ?? strtolower( $attrValue );
				} elseif ( Regex::match( '/^Text$/i', $attribute ) ) {
					$text = self::newlinesToTildes( $attrValue );
				} elseif ( Regex::match( '/^Link$/i', $attribute ) ) {
					$link = self::encodeUrl( self::normalizeUrl( self::parseText( $attrValue ) ) );
				}
			}

			if ( self::str( $fontsize ) === '' ) {
				$fontsize = 'S';
			}
			if ( self::str( $lineheight ) === '' ) {
				$lineheight = self::defaultLineHeight( $fontsize, [ 0.11, 0.13, 0.155, 0.19, 0.24 ] );
			}
			if ( $textcolor === '' ) {
				$textcolor = 'black';
			}
			if ( $pos === '' ) {
				$xy = self::split( '/,/', $this->textDefs['pos'] ?? null );
				$posx = $xy[0] ?? null;
				$posy = self::num( $xy[1] ?? null ) - self::num( $lineheight );
				if ( $posy < 0 ) {
					$posy = 0;
				}
				$pos = self::str( $posx ) . ',' . self::str( $posy );
				$this->textDefs['pos'] = $pos;
			}

			// update defaults
			if ( $text === '' ) {
				foreach ( [ 'pos' => $pos, 'tabs' => $tabs, 'fontsize' => $fontsize, 'textcolor' => $textcolor,
					'lineheight' => $lineheight ] as $k => $v
				) {
					if ( self::str( $v ) !== '' ) {
						$this->textDefs[$k] = $v;
					}
				}
				$this->getData();
				continue;
			}

			if ( $link !== '' ) {
				if ( Regex::match( '/\[.*\]/', $text ) ) {
					$text = Regex::replace( '/\[+ (?:[^\|]* \|)? ([^\]]*) \]+/x', '$1', $text );
				}
				$hint = self::externalLinkToHint( $link );
			}
			// tab inside an implicit link is ignored
			$text = Regex::replaceCallback( '/(\[+ [^\]]* \^ [^\]]* \]+)/x',
				static fn ( $m ) => str_replace( '^', ' ', $m[1] ), $text );

			if ( self::str( $tabs ) !== '' ) {
				$tabs = Regex::replace( '/^\s*%28%(.*)%29%\s*$/', '$1', $tabs, 1 );
				$tabList = self::split( '/,/', $tabs );
				foreach ( $tabList as $tab ) {
					$tab = Regex::replace( '/^\s*(.*)\s*$/', '$1', $tab );
					if ( !Regex::match( '/\d+\-(?:center|left|right)$/', $tab ) ) {
						$this->error( "Specify attribute 'tabs' as 'n-a,n-a,n-a,.. where n = numeric value, "
							. "a = left|right|center." );
						while ( !$this->inputParsed && !$this->noData ) {
							$this->getData();
						}
						return;
					}
				}
				$parts = self::split( '/\^/', $text );
				if ( count( $parts ) - 1 > count( $tabList ) ) {
					$this->error( 'TextData invalid. ' . ( count( $parts ) - 1 ) . " tab characters ('^') in "
						. "text, only "
						. count( $tabList ) . ' tab(s) defined.' );
					$this->getData();
					continue;
				}
			}

			$this->writeText( '^', '', 0, $posx, $posy, $text, $textcolor, $fontsize, 'left', $link, $hint, $tabs );
			$this->getData();
		}
	}

	/**
	 * Line height (TextData) or line distance (PlotData) for a font size.
	 *
	 * @param mixed $fontsize
	 * @param float[] $named values for XS, S, M, L, XL
	 * @return mixed
	 */
	private static function defaultLineHeight( mixed $fontsize, array $named ): mixed {
		$fs = self::str( $fontsize );
		if ( Regex::match( '/^(?:XS|S|M|L|XL)$/i', $fs ) ) {
			if ( Regex::match( '/XS/i', $fs ) ) {
				return $named[0];
			} elseif ( Regex::match( '/S/i', $fs ) ) {
				return $named[1];
			} elseif ( Regex::match( '/M/i', $fs ) ) {
				return $named[2];
			} elseif ( Regex::match( '/XL/i', $fs ) ) {
				return $named[4];
			}
			return $named[3];
		}
		$h = Printf::f( ( self::num( $fs ) * 1.2 ) / 100, '.2' );
		if ( self::num( $h ) < self::num( $fs ) / 100 + 0.02 ) {
			return self::num( $fs ) / 100 + 0.02;
		}
		return $h;
	}

	/**
	 * @throws CompileError
	 */
	private function parseTimeAxis(): void {
		if ( !$this->validAttributes( 'TimeAxis' ) ) {
			return;
		}
		$this->checkPreset( 'TimeAxis' );

		foreach ( $this->attributes as $attribute => $attrValue ) {
			$attrValue = self::str( $attrValue );
			if ( Regex::match( '/Format/i', $attribute ) ) {
				if ( Regex::match( '/^yy$/i', $attrValue ) ) {
					$this->error( "TimeAxis attribute '$attribute' valid but not available, waiting for bug fix.\n"
						. "Please specify 'format:yyyy' instead of 'format:yy'." );
					return;
				}
				if ( $this->dateFormat === 'yyyy' && !Regex::match( '/^(?:yy|yyyy)$/i', $attrValue ) ) {
					$this->error( "TimeAxis attribute '$attribute' invalid.\n"
						. "DateFormat 'yyyy' implies 'format:yy' or 'format:yyyy'." );
					return;
				}
			} elseif ( Regex::match( '/Order/i', $attribute ) ) {
				if ( !Regex::match( '/^(?:normal|reverse)$/i', $attrValue ) ) {
					$this->error( "TimeAxis attribute '$attribute' invalid.\n"
						. "  Specify 'order:normal' (default) or 'order:reverse'\n"
						. "  normal =\n"
						. "  vertical axis: highest date on top,\n"
						. "  horizontal axis: highest date at right side\n" );
					return;
				}
				if ( Regex::match( '/reverse/i', $attrValue ) && $this->dateFormat !== 'yyyy' ) {
					$this->error( "TimeAxis attribute '$attribute' invalid.\n"
						. "  'order:reverse' is only possible with DateFormat=yyyy (sorry)\n" );
					return;
				}
				$this->attributes['order'] = strtolower( $attrValue );
			} elseif ( Regex::match( '/Orientation/i', $attribute ) ) {
				if ( Regex::match( '/^hor(?:izontal)?$/i', $attrValue ) ) {
					$this->attributes['time'] = 'x';
				} elseif ( Regex::match( '/^ver(?:tical)?$/i', $attrValue ) ) {
					$this->attributes['time'] = 'y';
				} else {
					$this->error( "TimeAxis attribute '$attribute' invalid.\nSpecify hor[izontal] or ver[tical]" );
					return;
				}
				unset( $this->attributes['orientation'] );
			}
		}
		$this->attributes['format'] ??= 'yyyy';
		$this->axis = $this->attributes;
	}

	private function detectMissingCommands(): void {
		if ( !$this->image ) {
			$this->error2( 'Command ImageSize missing or invalid' );
		}
		if ( !$this->plotArea ) {
			$this->error2( 'Command PlotArea missing or invalid' );
		}
		$time = self::str( $this->axis['time'] ?? null );
		// Perl truth: '' and '0' are false
		if ( !$time ) {
			$this->error2( 'Command TimeAxis missing or invalid' );
		}
		if ( Regex::match( '/auto/i', self::str( $this->image['width'] ?? null ) ) && Regex::match( '/x/i', $time ) ) {
			$this->error2( "ImageSize value 'width:auto' only allowed with TimeAxis value 'orientation:vertical'" );
		}
		if ( Regex::match( '/auto/i', self::str( $this->image['height'] ?? null ) ) && Regex::match( '/y/i', $time ) ) {
			$this->error2( "ImageSize value 'height:auto' only allowed with TimeAxis value 'orientation:horizontal'" );
		}
	}

	/**
	 * Convert a dimension (pixels, in, cm, %) to inches, formatted as %.3f.
	 */
	private static function normalize( mixed $number, mixed $reference = null ): mixed {
		$s = self::str( $number );
		if ( $s === '' || Regex::match( '/auto/i', $s ) ) {
			return $number;
		}
		$val = self::num( Regex::replace( '/[^\d\.\-].*$/s', '', $s ) );
		$dim = Regex::replace( '/\d/', '', $s );
		if ( Regex::match( '/in/i', $dim ) ) {
			$n = $val;
		} elseif ( Regex::match( '/cm/i', $dim ) ) {
			$n = $val / 2.54;
		} elseif ( str_contains( $dim, '%' ) ) {
			$n = self::num( $reference ) * $val / 100;
		} else {
			$n = $val / 100;
		}
		return Printf::f( $n, '.3' );
	}

	private function validateAndNormalizeDimensions(): void {
		$img = &$this->image;
		$pa = &$this->plotArea;
		$s = static fn ( $v ) => self::str( $v );
		$n = static fn ( $v ) => self::num( $v );

		if ( Regex::match( '/auto/i', $s( $img['width'] ?? null ) ) ) {
			foreach ( [ 'width', 'left', 'right' ] as $attribute ) {
				if ( str_contains( $s( $pa[$attribute] ?? null ), '%' ) ) {
					$this->error2( "You specified 'ImageSize = width:auto'.\n"
						. "  This implies absolute values in PlotArea attributes 'left', 'right' and/or 'width' "
							. "(no %).\n" );
					return;
				}
			}
			if ( $s( $pa['width'] ?? null ) !== '' || $s( $pa['left'] ?? null ) === ''
				|| $s( $pa['right'] ?? null ) === ''
			) {
				$this->error2( "You specified 'ImageSize = width:auto'.\n"
					. "  This implies  'PlotArea  = width:auto'.\n"
					. "  Instead of 'width' specify plot margins with PlotArea attributes 'left' and 'right'.\n" );
				return;
			}
		}
		if ( Regex::match( '/auto/i', $s( $img['height'] ?? null ) ) ) {
			foreach ( [ 'height', 'top', 'bottom' ] as $attribute ) {
				if ( str_contains( $s( $pa[$attribute] ?? null ), '%' ) ) {
					$this->error2( "You specified 'ImageSize = height:auto'.\n"
						. "  This implies absolute values in PlotArea attributes 'top', 'bottom' and/or "
							. "'height' (no %).\n" );
					return;
				}
			}
			if ( $s( $pa['height'] ?? null ) !== '' || $s( $pa['top'] ?? null ) === ''
				|| $s( $pa['bottom'] ?? null ) === ''
			) {
				$this->error2( "You specified 'ImageSize = height:auto'.\n"
					. "  This implies  'PlotArea  = height:auto'.\n"
					. "  Instead of 'height' specify plot margins with PlotArea attributes 'top' and 'bottom'.\n" );
				return;
			}
		}

		$img['width'] = self::normalize( $img['width'] ?? null );
		$img['height'] = self::normalize( $img['height'] ?? null );
		$img['barinc'] = self::normalize( $img['barinc'] ?? null );
		$pa['width'] = self::normalize( $pa['width'] ?? null, $img['width'] );
		$pa['height'] = self::normalize( $pa['height'] ?? null, $img['height'] );
		$pa['left'] = self::normalize( $pa['left'] ?? null, $img['width'] );
		$pa['right'] = self::normalize( $pa['right'] ?? null, $img['width'] );
		$pa['bottom'] = self::normalize( $pa['bottom'] ?? null, $img['height'] );
		$pa['top'] = self::normalize( $pa['top'] ?? null, $img['height'] );

		$barsMinus1 = count( $this->bars ) - 1;
		if ( Regex::match( '/auto/i', $s( $img['width'] ) ) ) {
			$pa['width'] = $barsMinus1 * $n( $img['barinc'] );
			$img['width'] = $n( $pa['left'] ) + $pa['width'] + $n( $pa['right'] );
		} elseif ( Regex::match( '/auto/i', $s( $img['height'] ) ) ) {
			$pa['height'] = $barsMinus1 * $n( $img['barinc'] );
			$img['height'] = $n( $pa['top'] ) + $pa['height'] + $n( $pa['bottom'] );
		}
		if ( $s( $pa['right'] ) !== '' ) {
			$pa['width'] = $n( $img['width'] ) - $n( $pa['left'] ) - $n( $pa['right'] );
		}
		if ( $s( $pa['top'] ) !== '' ) {
			$pa['height'] = $n( $img['height'] ) - $n( $pa['top'] ) - $n( $pa['bottom'] );
		}

		if ( $n( $img['width'] ) > 16 || $n( $img['height'] ) > 20 ) {
			$this->error2( "Maximum image size is 1600x2000 pixels = 16x20 inch\n"
				. "  Run with option -b (bypass checks) when this is correct.\n" );
			return;
		}
		if ( $n( $img['width'] ) < 0.25 || $n( $img['height'] ) < 0.25 ) {
			$this->error2( "Minimum image size is 25x25 pixels = 0.25x0.25 inch\n" );
			return;
		}
		if ( $n( $pa['width'] ) > $n( $img['width'] ) ) {
			$this->error2( "Plot width larger than image width. Please adjust.\n" );
			return;
		}
		if ( $n( $pa['width'] ) < 0.2 ) {
			$this->error2( "Plot width less than 20 pixels = 0.2 inch. Please adjust.\n" );
			return;
		}
		if ( $n( $pa['height'] ) > $n( $img['height'] ) ) {
			$this->error2( "Plot height larger than image height. Please adjust.\n" );
			return;
		}
		if ( $n( $pa['height'] ) < 0.2 ) {
			$this->error2( "Plot height less than 20 pixels = 0.2 inch. Please adjust.\n" );
			return;
		}
		if ( $n( $pa['left'] ) + $n( $pa['width'] ) > $n( $img['width'] ) ) {
			$this->error2( "Plot width + margins larger than image width. Please adjust.\n" );
			return;
		}
		if ( $n( $pa['left'] ) < 0 ) {
			$pa['left'] = 0;
		}
		if ( $n( $pa['bottom'] ) + $n( $pa['height'] ) > $n( $img['height'] ) ) {
			$this->error2( "Plot height + margins larger than image height. Please adjust.\n" );
			return;
		}
		if ( $n( $pa['bottom'] ) < 0 ) {
			$pa['bottom'] = 0;
		}

		if ( isset( $this->scales['Major'] ) || isset( $this->scales['Minor'] ) ) {
			$margin = isset( $this->scales['Major'] ) ? 0.2 : 0.05;
			$px = self::str( 100 * $margin );
			$ms = self::str( $margin );
			if ( ( $this->axis['time'] ?? '' ) === 'x' ) {
				if ( $n( $pa['bottom'] ) < $margin ) {
					$this->error2( "Not enough space below plot area for plotting time axis\n"
						. " Specify 'PlotArea = bottom:x', where x is at least $px pixels = $ms inch\n" );
					return;
				}
			} elseif ( $n( $pa['left'] ) < $margin ) {
				$this->error2( "Not enough space outside plot area for plotting time axis\n"
					. " Specify 'PlotArea = left:x', where x is at least $px pixels = $ms inch\n" );
				return;
			}
		}

		$lg = &$this->legend;
		if ( isset( $lg['orientation'] ) ) {
			if ( isset( $lg['left'] ) ) {
				$lg['left'] = self::normalize( $lg['left'], $img['width'] );
			}
			if ( isset( $lg['top'] ) ) {
				$lg['top'] = self::normalize( $lg['top'], $img['height'] );
			}
			if ( isset( $lg['columnwidth'] ) ) {
				$lg['columnwidth'] = self::normalize( $lg['columnwidth'], $img['width'] );
			}
			$position = $s( $lg['position'] ?? null );
			if ( !isset( $lg['columns'] ) ) {
				$lg['columns'] = 1;
				if ( Regex::match( '/ver/i', $s( $lg['orientation'] ) )
					&& Regex::match( '/^(?:top|bottom)$/i', $position )
				) {
					$cnt = count( $this->legendData ) - 1;
					if ( $cnt > 10 ) {
						$lg['columns'] = 3;
					} elseif ( $cnt > 5 ) {
						$lg['columns'] = 2;
					}
				}
			}
			$colWidth = static fn () => sprintf( '%02f',
				( $n( $pa['left'] ) + $n( $pa['width'] ) - 0.2 ) / $n( $lg['columns'] ) );
			if ( Regex::match( '/top/i', $position ) ) {
				$lg['left'] ??= $pa['left'];
				$lg['top'] ??= $n( $img['height'] ) - 0.2;
				if ( !isset( $lg['columnwidth'] ) && $n( $lg['columns'] ) > 1 ) {
					$lg['columnwidth'] = $colWidth();
				}
			} elseif ( Regex::match( '/bottom/i', $position ) ) {
				$lg['left'] ??= $pa['left'];
				$lg['top'] ??= $n( $pa['bottom'] ) - 0.4;
				if ( !isset( $lg['columnwidth'] ) && $n( $lg['columns'] ) > 1 ) {
					$lg['columnwidth'] = $colWidth();
				}
			} elseif ( Regex::match( '/right/i', $position ) ) {
				$lg['left'] ??= $n( $pa['left'] ) + $n( $pa['width'] ) + 0.2;
				$lg['top'] ??= $n( $pa['bottom'] ) + $n( $pa['height'] ) - 0.2;
			}
		}
		$this->axis['order'] ??= 'normal';
	}

	// ---------------------------------------------------------------- script generation

	/**
	 * Generate the ploticus scripts for the SVG and the PNG pass.
	 */
	private function writePlotFile(): ?array {
		$this->writeTexts();
		$this->script = '';
		$s = static fn ( $v ) => self::str( $v );
		$n = static fn ( $v ) => self::num( $v );
		$axisTime = $s( $this->axis['time'] ?? null );
		$axisBars = $axisTime === 'x' ? 'y' : 'x';
		$reverse = (bool)Regex::match( '/reverse/i', $s( $this->axis['order'] ?? null ) );
		$pa = &$this->plotArea;
		$canvas = $this->backgroundColors['canvas'] ?? null;

		$this->script .= "#proc page\n  dopagebox: no\n  pagesize: " . $s( $this->image['width'] ) . ' '
			. $s( $this->image['height'] ) . "\n";
		if ( $canvas !== null ) {
			$this->script .= '  backgroundcolor: ' . $s( $canvas ) . "\n";
		}
		$this->script .= "\n";

		$this->alignBars ??= 'early';
		$extent = $axisTime === 'x' ? 'height' : 'width';
		if ( $n( $this->maxBarWidth ) > $n( $pa[$extent] ) ) {
			$this->error2( "Maximum bar width exceeds plotarea $extent." );
			return null;
		}
		if ( $n( $this->maxBarWidth ) == $n( $pa[$extent] ) ) {
			$pa[$extent] = $n( $pa[$extent] ) + 0.01;
		}
		$barsMinus1 = count( $this->bars ) - 1;
		$p = $n( $pa[$extent] );
		$mbw = $n( $this->maxBarWidth );
		if ( $this->alignBars === 'justify' ) {
			if ( $barsMinus1 > 0 ) {
				$u = -( $p * $barsMinus1 ) / ( $mbw - $p );
				$x = ( $u - $barsMinus1 ) / 2;
				$from = 1 - $x;
				$till = 1 + $barsMinus1 + $x;
			} else {
				$from = 0.5;
				$till = 1.5;
			}
		} elseif ( $this->alignBars === 'early' ) {
			$u = max( $barsMinus1 + 1, 1 );
			$x = ( ( $mbw / 2 ) * $u ) / $p;
			$from = 1 - $x;
			$till = $from + $u;
		} else {
			$u = $barsMinus1 + 1;
			$x = ( ( $mbw / 2 ) * $u ) / $p;
			$till = $u + $x;
			$from = $till - $u;
		}
		if ( $s( $from ) === $s( $till ) ) {
			$till = $from + 1;
		}

		$this->script .= "#proc areadef\n  rectangle: " . $s( $pa['left'] ) . ' ' . $s( $pa['bottom'] ) . ' '
			. Printf::f( $n( $pa['left'] ) + $n( $pa['width'] ), '.2' ) . ' '
			. Printf::f( $n( $pa['bottom'] ) + $n( $pa['height'] ), '.2' ) . "\n";
		if ( $this->dateFormat === 'yyyy' || $this->dateFormat === 'x.y' ) {
			$this->script .= "  {$axisTime}scaletype: linear\n";
		} else {
			$this->script .= "  {$axisTime}scaletype: date {$this->dateFormat}\n";
		}
		if ( !$reverse ) {
			$this->script .= "  {$axisTime}range: " . $s( $this->period['from'] ?? null ) . ' '
				. '' . $s( $this->period['till'] ?? null ) . "\n";
		} else {
			$this->script .= "  {$axisTime}range: " . $s( self::neg( $this->period['till'] ?? null ) ) . ' '
				. $s( self::neg( $this->period['from'] ?? null ) ) . "\n";
		}
		$this->script .= "  {$axisBars}scaletype: linear\n";
		$this->script .= "  {$axisBars}range: " . Printf::f( $from - 0.001, '.3' ) . ' '
			. Printf::f( $till, '.3' ) . "\n";
		$this->script .= "  #saveas: A\n\n";

		$colors = $this->colors;
		ksort( $colors, SORT_STRING );
		foreach ( $colors as $color => $value ) {
			$this->script .= "#proc legendentry\n  sampletype: color\n";
			if ( ( $this->colorLabels[$color] ?? '' ) !== '' ) {
				$this->script .= '  label: ' . $this->colorLabels[$color] . "\n";
			}
			$this->script .= "  details: $value\n  tag: $color\n\n";
		}

		if ( isset( $this->backgroundColors['bars'] ) ) {
			$this->script .= "#proc getdata\n  delim: comma\n  data:\n";
			$maxwidth = 0;
			foreach ( $this->plotBars as $entry ) {
				$width = self::split( '/,/', $entry )[0] ?? null;
				if ( $n( $width ) > $n( $maxwidth ) ) {
					$maxwidth = $width;
				}
			}
			foreach ( $this->bars as $i => $unused ) {
				$this->script .= ( $i + 1 ) . ',' . $s( $this->period['from'] ?? null ) . ','
					. $s( $this->period['till'] ?? null ) . ','
					. $s( $this->backgroundColors['bars'] ) . "\n";
			}
			$this->script .= "\n#proc bars\n  axis: $axisTime\n  barwidth: " . $s( $maxwidth ) . "\n  outline: no\n";
			if ( $axisTime === 'x' ) {
				$this->script .= "  horizontalbars: yes\n";
			}
			$this->script .= "  locfield: 1\n  segmentfields: 2 3\n  colorfield: 4\n\n";
		}

		if ( isset( $this->scales['Minor grid'] ) ) {
			$this->plotScale( 'Minor', true );
		}
		if ( isset( $this->scales['Major grid'] ) ) {
			$this->plotScale( 'Major', true );
		}
		$this->plotLinesLayer( 'back' );

		$this->plotBarsNow = $this->plotBars;
		$this->plotBarsScript();
		$this->script .= "\n([inc3])\n\n";

		foreach ( $this->plotLines as $i => $entry ) {
			$bar = Regex::replace( '/\#.*/', '', self::split( '/,/', $entry )[0] ?? '' );
			$this->plotLines[$i] = Printf::f( $n( $this->barWidths[$bar] ?? null ), '6.3' ) . ',' . $entry;
		}
		$this->plotBarsNow = $this->plotLines;
		$this->plotBarsScript();

		$scriptPng1 = '';
		$scriptPng2 = '';
		$scriptPng3 = '';
		$scriptSvg1 = '';
		$scriptSvg2 = '';

		if ( $barsMinus1 > 0 ) {
			$adjust = $axisBars === 'x' ? 'adjust=0,0.09' : 'adjust=0.09,0';
			$slide = $axisBars === 'y' ? '  stubslide: -' . Printf::f( $mbw / 2, '.2' ) . "\n" : '';
			$head = "#proc {$axisBars}axis\n  stubdetails: $adjust\n  tics: none\n  stubrange: 1\n{$slide}  "
				. "stubs: text\n";
			$scriptPng2 = $head;
			$scriptSvg2 = $head;
			$bars2 = $axisBars === 'y' ? $this->bars : array_reverse( $this->bars );

			foreach ( $bars2 as $bar ) {
				$hint = '';
				$text = self::str( $this->barLegend[strtolower( $bar )] ?? null );
				if ( Regex::match( '/^\s*$/', $text ) ) {
					$text = '\\';
				}
				$link = $this->barLink[strtolower( $bar )] ?? null;
				if ( $link === null && Regex::match( '/\[.*\]/', $text ) ) {
					[ $text, $link, $hint ] = $this->processWikiLink( $text, '', $hint );
				}
				$text = Regex::replace( '/\[+([^\]]*)\]+/', '$1', $text, 1 );
				$scriptPng2 .= self::dataLine( $text ) . "\n";
				if ( $link !== null ) {
					// fixSvg() writes the link into the SVG, after the script is decoded
					$this->linksSvg[] = self::decodeInput( $link );
					$lcnt = count( $this->linksSvg ) - 1;
					$scriptSvg2 .= "[$lcnt" . "[$text]$lcnt]\n";
				} else {
					$scriptSvg2 .= self::dataLine( $text ) . "\n";
				}
			}
			$scriptPng2 .= "\n";
			$scriptSvg2 .= "\n";

			$scriptPng2 .= "#proc {$axisBars}axis\n  stubdetails: $adjust color=" . self::LINK_COLOR
				. "\n  tics: none\n  stubrange: 1\n{$slide}  stubs: text\n";
			$barcnt = $barsMinus1 + 1;
			foreach ( $bars2 as $bar ) {
				$hint = '';
				$text = self::str( $this->barLegend[strtolower( $bar )] ?? null );
				if ( Regex::match( '/^\s*$/', $text ) ) {
					$text = '\\';
				}
				$link = $this->barLink[strtolower( $bar )] ?? null;
				if ( $link === null && Regex::match( '/\[.*\]/', $text ) ) {
					[ $text, $link, $hint ] = $this->processWikiLink( $text, '', $hint );
				}
				if ( $link === null || $link === '' ) {
					$text = '\\';
				} else {
					$scriptPng3 .= "#proc rect\n  rectangle: 0 $barcnt(s)+0.05 " . $s( $pa['left'] ) . " "
						. "$barcnt(s)-0.05\n"
						. '  color: ' . $s( $canvas ) . "\n  clickmapurl: $link\n";
					if ( $hint !== '' ) {
						$scriptPng3 .= "  clickmaplabel: $hint\n";
					}
					$text = Regex::replace( '/\[+([^\]]*)\]+/', '$1', $text, 1 );
				}
				$scriptPng2 .= self::dataLine( $text ) . "\n";
				$barcnt--;
			}
			$scriptPng2 .= "\n";
		}

		$this->plotLinesLayer( 'front' );
		$this->script .= "\n([inc1])\n\n\n([inc2])\n\n";

		$fixLocation = function ( string $cmd ) use ( $barsMinus1 ): string {
			if ( Regex::match( '/^\s*location/', $cmd ) ) {
				$cmd = Regex::replaceCallback( '/(.*)\[(.*)\](.*)/',
					static fn ( $m ) => $m[1] . self::str( $barsMinus1 - self::num( $m[2] ) + 2 ) . $m[3], $cmd, 1 );
			}
			return $cmd;
		};
		if ( $this->plotTextsPng ) {
			$scriptPng1 = implode( '', array_map( $fixLocation, $this->plotTextsPng ) ) . "\n";
		}
		if ( $this->plotTextsSvg ) {
			$scriptSvg1 = implode( '', array_map( $fixLocation, $this->plotTextsSvg ) ) . "\n";
		}

		if ( isset( $this->scales['Minor'] ) ) {
			$this->plotScale( 'Minor', false );
		}
		if ( isset( $this->scales['Major'] ) ) {
			$this->plotScale( 'Major', false );
		}

		if ( isset( $this->legend['orientation'] ) ) {
			if ( !$this->legendData && $this->preset === '' ) {
				$this->error2( "Command 'Legend' found, but no entries for the legend were specified.\n"
					. "  Please remove or disable command (disable = put # before the command)\n"
					. "  or specify entries for the legend with command 'Colors', attribute 'legend'\n" );
				return null;
			}
			$lg = &$this->legend;
			$perColumn = 999;
			if ( Regex::match( '/ver/i', $s( $lg['orientation'] ) ) && $n( $lg['columns'] ) > 1 ) {
				$perColumn = 0;
				while ( $n( $lg['columns'] ) * $perColumn < count( $this->legendData ) ) {
					$perColumn++;
				}
			}
			for ( $i = 1; $i <= $n( $lg['columns'] ); $i++ ) {
				$this->script .= "#proc legend\n  noclear: yes\n";
				$this->script .= Regex::match( '/ver/i', $s( $lg['orientation'] ) )
					? "  format: multiline\n" : "  format: singleline\n";
				$this->script .= "  seglen: 0.2\n  swatchsize: 0.12\n  textdetails: size=S\n";
				$this->script .= '  location: ' . $s( $n( $lg['left'] ) + 0.2 ) . ' ' . $s( $lg['top'] ) . "\n";
				$this->script .= "  specifyorder:\n";
				for ( $j = 1; $j <= $perColumn; $j++ ) {
					$category = array_shift( $this->legendData );
					if ( $category !== null ) {
						$this->script .= self::dataLine( $category ) . "\n";
					}
				}
				$this->script .= "\n";
				$lg['left'] = $n( $lg['left'] ) + $n( $lg['columnwidth'] ?? null );
			}
		}
		$this->script .= "#endproc\n";

		$svg = str_replace( '([inc1])', $scriptSvg1, $this->script );
		$svg = str_replace( '([inc2])', $scriptSvg2, $svg );
		$svg = str_replace( '([inc3])', '', $svg );
		$svg = self::replaceSizes( $svg, [ '7', '8.9', '10.5', '13', '17' ] );
		$svg = Regex::replaceCallback( '/(\n  location:.*)/', fn ( $m ) => $this->shiftOnePixelForSvg( $m[1] ), $svg );

		$png = Regex::replace( '/dopagebox: no/', 'dopagebox: yes', $this->script, 1 );
		$png = str_replace( '([inc1])', $scriptPng1, $png );
		$png = str_replace( '([inc2])', $scriptPng2, $png );
		$png = str_replace( '([inc3])', $scriptPng3, $png );
		$png = self::replaceSizes( $png, [ '6', '8', '10', '14', '18' ] );

		return [
			'svg' => self::decodeScript( $svg ),
			'png' => self::decodeScript( $png ),
			'svgMap' => $this->mapSvg,
			'pngMap' => $this->mapPng,
		];
	}

	/**
	 * Replace the font size names XS, S, M, L, XL by point sizes.
	 *
	 * @param string $script
	 * @param string[] $sizes
	 * @return string
	 */
	private static function replaceSizes( string $script, array $sizes ): string {
		foreach ( [ 'textsize ', 'size=' ] as $prefix ) {
			foreach ( [ 'XS', 'S', 'M', 'L', 'XL' ] as $i => $name ) {
				$script = Regex::replace( '/' . $prefix . $name . '/i', $prefix . $sizes[$i], $script );
			}
		}
		return $script;
	}

	private function writeTexts(): void {
		foreach ( $this->plotText as $line ) {
			$f = array_pad( explode( ',', $line, 9 ), 9, '' );
			[ $at, $bar, $text, $textcolor, $fontsize, $align, $shift, $link, $hint ] =
				array_map( static fn ( $v ) => str_replace( '#%$', ',', $v ), $f );
			$barcnt = 0;
			foreach ( $this->bars as $i => $b ) {
				if ( strtolower( $b ) === strtolower( $bar ) ) {
					$barcnt = $i + 1;
					break;
				}
			}
			if ( ( $this->axis['time'] ?? '' ) === 'x' ) {
				$xpos = "$at(s)";
				$ypos = "[$barcnt](s)";
			} else {
				$ypos = "$at(s)";
				$xpos = "[$barcnt](s)";
			}
			$shiftx = null;
			if ( $shift !== '' ) {
				$xy = self::split( '/,/', $shift );
				$shiftx = $xy[0] ?? null;
				$shifty = $xy[1] ?? null;
				if ( self::num( $shiftx ) > 0 ) {
					$xpos .= '+' . self::str( $shiftx );
				}
				if ( self::num( $shiftx ) < 0 ) {
					$xpos .= self::str( $shiftx );
				}
				if ( self::num( $shifty ) > 0 ) {
					$ypos .= '+' . self::str( $shifty );
				}
				if ( self::num( $shifty ) < 0 ) {
					$ypos .= self::str( $shifty );
				}
			}
			$this->writeText( '~', $bar, $shiftx, $xpos, $ypos, $text, $textcolor, $fontsize, $align, $link, $hint );
		}
	}

	/**
	 * @throws CompileError
	 */
	private function writeText(
		string $mode, string $bar, mixed $shiftx, mixed $posx, mixed $posy, string $text, string $textcolor,
		mixed $fontsize, string $align, string $link, string $hint, mixed $tabs = null
	): void {
		if ( Regex::match( '/reverse/i', self::str( $this->axis['order'] ?? null ) ) ) {
			$negate = static fn ( $v ) => Regex::replaceCallback( '/(.*)(\(s\))/',
				static fn ( $m ) => self::str( self::neg( $m[1] ) ) . $m[2], self::str( $v ), 1 );
			if ( ( $this->axis['time'] ?? '' ) === 'y' ) {
				$posy = $negate( $posy );
			} else {
				$posx = $negate( $posx );
			}
		}

		$outside = false;
		foreach ( [ [ $posx, 'width' ], [ $posy, 'height' ] ] as [ $pos, $dim ] ) {
			if ( !str_contains( self::str( $pos ), '(s)' ) ) {
				if ( self::num( $pos ) < 0 ) {
					$outside = true;
				}
				$size = self::str( $this->image[$dim] ?? null );
				if ( !Regex::match( '/auto/i', $size ) && self::num( $pos ) > self::num( $size ) / 100 ) {
					$outside = true;
				}
			}
		}
		if ( $outside ) {
			if ( $this->warnTextOutsideArea++ < 5 ) {
				$text = str_replace( "\n", '~', $text );
				$this->error( "Text segment '$text' falls outside image area. Text ignored." );
			}
			return;
		}

		$tabList = array_map( static fn ( $t ) => Regex::replace( '/^\s*(.*)\s*$/', '$1', $t ),
			self::split( '/,/', $tabs ) );
		$posx0 = $posx;
		$dy = 0;
		if ( Regex::match( '/\[\[.*\]\]/', $text ) ) {
			$link = '';
			$hint = '';
		}
		if ( $mode === '^' ) {
			$items = self::split( '/\^/', $text );
		} else {
			$items = self::split( '/\n/', $text );
			$dy = self::defaultLineHeight( $fontsize, [ 0.09, 0.11, 0.135, 0.16, 0.21 ] );
		}

		foreach ( $items as $item ) {
			if ( !Regex::match( '/^[\n\s]*$/', $item ) ) {
				[ $item, $link2, $hint2 ] = $this->processWikiLink( $item, '', '' );
				if ( $link2 === '' ) {
					$link2 = $link;
					if ( $link !== '' && !Regex::match( '/\[\[.*\]\]/', $item ) ) {
						$item = "[[$item]]";
					}
				}
				if ( $hint2 === '' ) {
					$hint2 = $hint;
				}
				$this->writeProcAnnotate( $bar, $shiftx, $posx, $posy, $item, $textcolor, $fontsize, $align,
					$link2, $hint2 );
			}
			if ( $tabList ) {
				$tab = array_shift( $tabList );
				$parts = explode( '-', $tab );
				$align = $parts[1] ?? '';
				$posx = self::num( $posx0 ) + self::num( self::normalize( $parts[0] ) );
			}
			$posy = $this->shiftY( $posy, $dy );
		}
	}

	/**
	 * Add $delta to the offset part of a location like "1950(s)+0.1".
	 */
	private function shiftY( mixed $posy, mixed $delta, bool $add = false ): string {
		$posy = self::str( $posy );
		if ( str_contains( $posy, '+' ) ) {
			$parts = explode( '+', $posy );
			$this->posy1 = $parts[0];
			$this->posy2 = $parts[1] ?? null;
		} elseif ( Regex::match( '/.+\-/', $posy ) ) {
			$parts = explode( '-', $posy );
			if ( str_starts_with( $posy, '-' ) ) {
				$this->posy1 = '-' . ( $parts[1] ?? '' );
				$this->posy2 = self::neg( $parts[2] ?? null );
			} else {
				$this->posy1 = $parts[0];
				$this->posy2 = self::neg( $parts[1] ?? null );
			}
		} else {
			$this->posy1 = $posy;
			$this->posy2 = 0;
		}
		$p2 = self::num( $this->posy2 );
		$p2 = $add ? $p2 + self::num( $delta ) : $p2 - self::num( $delta );
		if ( $p2 == 0 ) {
			return self::str( $this->posy1 );
		}
		return self::str( $this->posy1 ) . ( $p2 < 0 ? '' : '+' ) . self::str( $p2 );
	}

	/**
	 * @throws CompileError
	 */
	private function writeProcAnnotate(
		string $bar, mixed $shiftx, mixed $xpos, mixed $ypos, string $text, string $textcolor,
		mixed $fontsize, string $align, string $link, string $hint
	): void {
		if ( strlen( $text ) > 250 ) {
			$this->error( 'Text segments can be up to 250 characters long. This segment is ' . strlen( $text )
				. " chars.\n"
				. "  You can either shorten the text or\n"
				. "  - PlotData: insert line breaks (~)\n"
				. "  - TextData: insert tabs (~) to produce columns\n" );
			return;
		}
		if ( $textcolor === '' ) {
			$textcolor = 'black';
		}
		$textdetails = "  textdetails: align=$align size=" . self::str( $fontsize ) . " color=$textcolor";
		$location = '  location: ' . self::str( $xpos ) . ' ' . self::str( $ypos ) . "\n";
		$textLine = static fn ( $t ) => Regex::match( '/^\s/', $t ) ? "  text: \n\\$t\n\n" : "  text: $t\n\n";

		array_push( $this->plotTextsPng, "#proc annotate\n", $location, "$textdetails\n" );
		array_push( $this->plotTextsSvg, "#proc annotate\n", $location, "$textdetails\n" );
		$this->plotTextsPng[] = $textLine( str_replace( [ '[[', ']]' ], '', $text ) );

		$text2 = $text;
		if ( $link !== '' ) {
			// placeholder, replaced by the real link after SVG generation
			$this->linksSvg[] = self::decodeInput( $link );
			$lcnt = count( $this->linksSvg ) - 1;
			$wrap = static fn ( $m ) => "[$lcnt" . "[$m[1]]$lcnt]";
			$text2 = Regex::replaceCallback( '/\[\[([^\]]+)\]\]/', $wrap, $text2, 1 );
			$text2 = Regex::replaceCallback( '/\[\[([^\]]+)$/', $wrap, $text2, 1 );
			$text2 = Regex::replaceCallback( '/^([^\[]+)\]\]/', $wrap, $text2, 1 );
		}
		$text3 = self::encodeHtml( $text2 );
		if ( $text2 !== $text3 ) {
			// placeholder, replaced by the real text after SVG generation
			// encodeInput() hides "&", so encodeHtml() did not see it. Encode each "&" that does
			// not start a character reference, which a text can have (&#32; or &amp;).
			$this->textsSvg[] = Regex::replace(
				'/&(?!#[0-9]+;|#x[0-9a-fA-F]+;|(?:amp|lt|gt|quot|apos);)/', '&#38;', self::decodeInput( $text3 ) );
			$text3 = self::PLACEHOLDER . ( count( $this->textsSvg ) - 1 ) . self::PLACEHOLDER;
			$text3 .= str_repeat( 'x', max( 0, strlen( $text2 ) - strlen( $text3 ) ) );
		}
		$this->plotTextsSvg[] = $textLine( $text3 );

		if ( $link === '' ) {
			return;
		}
		$this->mapPng = true;
		$this->plotTextsPng[] = "#proc annotate\n";
		$this->plotTextsPng[] = $location;
		if ( $align !== 'right' ) {
			$this->plotTextsPng[] = "  clickmapurl: $link\n";
		} elseif ( $bar === '' ) {
			// links on right aligned texts are only supported in SVG
			return;
		} else {
			$this->plotTextsPng[] = "  clickmapurl: $link&&" . self::str( $shiftx ) . "\n";
		}
		if ( $hint !== '' ) {
			$this->plotTextsPng[] = "  clickmaplabel: $hint\n";
		}
		$this->plotTextsPng[] = Regex::replace( '/color=[^\s]+/', 'color=' . self::LINK_COLOR, $textdetails, 1 ) . "\n";

		$text = self::decodeInput( $text );
		if ( Regex::match( '/^[^\[]+\]\]/', $text ) ) {
			$text = "[[$text";
		}
		if ( Regex::match( '/\[\[[^\]]+$/', $text ) ) {
			$text .= ']]';
		}
		$pos1 = strpos( $text, '[[' );
		$pos2 = strpos( $text, ']]' );
		$pos2 = $pos2 === false ? 0 : $pos2 + 1;
		if ( $pos1 !== false ) {
			$len = strlen( $text );
			for ( $i = 0; $i < $len; $i++ ) {
				if ( $text[$i] !== "\n" && ( $i < $pos1 || $i > $pos2 ) ) {
					$text[$i] = ' ';
				}
			}
		}
		$text = Regex::replace( '/\[\[(.*?)\]\]/s', '$1', $text, 1 );
		// writePlotFile() decodes the whole script once more: keep a "%" of the text a "%"
		$text = str_replace( '%', self::H_PERC, $text );
		$this->plotTextsPng[] = $textLine( $text );
	}

	private function plotBarsScript(): void {
		$axisTime = self::str( $this->axis['time'] ?? null );
		$reverse = (bool)Regex::match( '/reverse/i', self::str( $this->axis['order'] ?? null ) );
		while ( $this->plotBarsNow ) {
			$later = [];
			$maxwidth = 0;
			foreach ( $this->plotBarsNow as $entry ) {
				$width = self::split( '/,/', $entry )[0] ?? null;
				if ( self::num( $width ) > self::num( $maxwidth ) ) {
					$maxwidth = $width;
				}
			}
			$this->script .= "#proc getdata\n  delim: comma\n  data:\n";
			foreach ( $this->plotBarsNow as $entry ) {
				$f = self::split( '/,/', $entry );
				[ $width, $bar, $from, $till, $color, $link, $hint ] = array_pad( $f, 7, '' );
				if ( self::num( $width ) < self::num( $maxwidth ) ) {
					$later[] = $entry;
					continue;
				}
				$cnt = count( $this->bars );
				foreach ( $this->bars as $i => $b ) {
					if ( strtolower( $b ) === strtolower( $bar ) ) {
						$bar = (string)( $cnt - 1 - ( $i - 1 ) );
						break;
					}
				}
				if ( !$reverse ) {
					$this->script .= "$bar,$from,$till,$color,$link,$hint,\n";
				} else {
					$this->script .= "$bar," . self::str( self::neg( $till ) ) . ',' . self::str( self::neg( $from ) )
						. ",$color,$link,$hint,\n";
				}
			}
			if ( count( $later ) === count( $this->plotBarsNow ) ) {
				// Only negative widths left: the Perl original looped forever here.
				$this->fail( 'Bar widths must not be negative.' );
			}
			$this->script .= "\n#proc bars\n  axis: $axisTime\n  barwidth: " . self::str( $maxwidth ) . "\n  "
				. "outline: no\n";
			if ( $axisTime === 'x' ) {
				$this->script .= "  horizontalbars: yes\n";
			}
			$this->script .= "  locfield: 1\n  segmentfields: 2 3\n  colorfield: 4\n"
				. "  clickmapurl: @@5\n  clickmaplabel: @@6\n\n";
			$this->plotBarsNow = $later;
		}
	}

	private function plotScale( string $scale, bool $grid ): void {
		$s = static fn ( $v ) => self::str( $v );
		$axisTime = $s( $this->axis['time'] ?? null );
		$reverse = (bool)Regex::match( '/reverse/i', $s( $this->axis['order'] ?? null ) );
		$from = $this->dateToFloat( $this->period['from'] );
		$till = $this->dateToFloat( $this->period['till'] ?? null );

		$this->script .= "#proc areadef\n  #clone: A\n  {$axisTime}scaletype: linear\n";
		if ( !$reverse ) {
			$this->script .= "  {$axisTime}range: " . $s( $from ) . ' ' . $s( $till ) . "\n";
		} else {
			$this->script .= "  {$axisTime}range: " . $s( self::neg( $till ) ) . ' ' . $s( self::neg( $from ) ) . "\n";
		}
		$this->script .= "\n#proc {$axisTime}axis\n";

		if ( $scale === 'Major' && !$grid ) {
			if ( $s( $this->scales['Major stubs'] ?? null ) === '' ) {
				$this->script .= '  stubs: incremental ' . $s( $this->scales['Major inc'] ?? null ) . "\n";
			} else {
				$this->script .= '  stubs: list ' . $s( $this->scales['Major stubs'] ) . "\n";
			}
		} else {
			$this->script .= "  stubs: none\n";
		}

		$inc = $s( $this->scales["$scale inc"] ?? null );
		if ( $this->dateFormat === 'yyyy' || $this->dateFormat === 'x.y' ) {
			$this->script .= "  ticincrement: $inc\n";
		} else {
			$unitName = $s( $this->scales["$scale unit"] ?? null );
			$unit = 1;
			if ( Regex::match( '/month/i', $unitName ) ) {
				$unit = 1 / 12;
			}
			if ( Regex::match( '/day/i', $unitName ) ) {
				$unit = 1 / 365;
			}
			$this->script .= "  ticincrement: $inc " . $s( $unit ) . "\n";
		}

		if ( isset( $this->scales["$scale start"] ) ) {
			$start = $this->dateToFloat( $this->scales["$scale start"] );
			if ( $reverse ) {
				$loop = 0;
				$start = self::neg( $start );
				$incN = self::num( $inc );
				while ( $start - $incN >= self::num( self::neg( $this->period['till'] ?? null ) ) ) {
					$start -= $incN;
					if ( ++$loop > 1000 ) {
						break;
					}
				}
			}
			$this->script .= '  stubrange: ' . $s( $start ) . "\n";
		}

		if ( $scale === 'Major' ) {
			$this->script .= "  ticlen: 0.05\n";
			if ( $axisTime === 'y' ) {
				$this->script .= "  stubdetails: adjust=0.05,0\n";
			}
			if ( $reverse ) {
				$this->script .= "  signreverse: yes\n";
			}
		} else {
			$this->script .= "  ticlen: 0.02\n";
		}

		$color = $s( $this->scales["$scale grid"] ?? null );
		if ( isset( $this->colors[$color] ) ) {
			$color = $this->colors[$color];
		}
		if ( $grid ) {
			$this->script .= "  grid: color=$color\n";
		}
		$this->script .= "\n";
		if ( $grid ) {
			$this->script .= "#proc areadef\n  #clone: A\n\n";
		}
	}

	private function plotLinesLayer( string $layer ): void {
		$s = static fn ( $v ) => self::str( $v );
		$n = static fn ( $v ) => self::num( $v );
		$axisTime = $s( $this->axis['time'] ?? null );
		$reverse = (bool)Regex::match( '/reverse/i', $s( $this->axis['order'] ?? null ) );
		$pa = $this->plotArea;
		$img = $this->image;

		foreach ( $this->drawLines as $entry ) {
			if ( !str_contains( $entry, "|$layer\n" ) ) {
				continue;
			}
			$entry = substr( $entry, 0, -1 );
			$f = explode( '|', $entry );
			$this->script .= "#proc line\n";
			$at = $from = $till = $points = '';
			if ( Regex::match( '/^[12]/', $entry ) ) {
				[ $mode, $at, $from, $till, $color, $width ] = $f;
			} else {
				[ $mode, $points, $color, $width ] = $f;
			}
			$this->script .= "  linedetails: width=$width color=$color style=0\n";

			if ( $mode === '1' ) {
				if ( $reverse ) {
					$at = $s( self::neg( $at ) );
				}
				[ $lo, $size, $imgSize ] = $axisTime === 'x'
					? [ 'bottom', 'height', 'height' ]
					: [ 'left', 'width', 'width' ];
				$from = $from === '' ? $pa[$lo] : $from;
				$till = $till === '' ? $n( $pa[$lo] ) + $n( $pa[$size] ) : $till;
				$from = $n( $from ) + $n( $width ) / 200;
				$till = $n( $till ) - $n( $width ) / 200;
				if ( $from > $n( $img[$imgSize] ) ) {
					$from = $img[$imgSize];
				}
				if ( $till > $n( $img[$imgSize] ) ) {
					$till = $img[$imgSize];
				}
				if ( $axisTime === 'x' ) {
					$this->script .= "  points: $at(s) " . $s( $from ) . " $at(s) " . $s( $till ) . "\n";
				} else {
					$this->script .= '  points: ' . $s( $from ) . " $at(s) " . $s( $till ) . " $at(s)\n";
				}
			} elseif ( $mode === '2' ) {
				if ( $reverse ) {
					$from = $s( self::neg( $from ) );
					$till = $s( self::neg( $till ) );
				}
				$from .= '(s)+' . $s( $n( $width ) / 200 );
				$till .= '(s)-' . $s( $n( $width ) / 200 );
				[ $lo, $imgSize ] = $axisTime === 'x' ? [ 'bottom', 'height' ] : [ 'left', 'width' ];
				if ( $at === '' ) {
					$at = $pa[$lo];
				}
				if ( $n( $at ) > $n( $img[$imgSize] ) ) {
					$at = $img[$imgSize];
				}
				$at = $s( $at );
				if ( $axisTime === 'x' ) {
					$this->script .= "  points: $from $at $till $at\n";
				} else {
					$this->script .= "  points: $at $from $at $till\n";
				}
			} else {
				$pts = array_map( static fn ( $p ) => self::normalize( $p ), explode( ',', $points ) );
				if ( $n( $pts[0] ) > $n( $img['width'] ) || $n( $pts[1] ) > $n( $img['height'] )
					|| $n( $pts[2] ) > $n( $img['width'] ) || $n( $pts[3] ) > $n( $img['height'] )
				) {
					$this->error2( "Linedata attribute 'points' invalid.\n"
						. sprintf( '(%d,%d)(%d,%d)', $n( $pts[0] ) * 100, $n( $pts[1] ) * 100, $n( $pts[2] ) * 100,
							$n( $pts[3] ) * 100 )
						. " does not fit in image\n" );
					return;
				}
				$this->script .= "  points: $pts[0] $pts[1] $pts[2] $pts[3]\n";
			}
			$this->script .= "\n";
		}
	}

	private function shiftOnePixelForSvg( string $line ): string {
		$line = Regex::replace( '/location:\s*/', '', $line, 1 );
		$xy = self::split( ' ', $line );
		$posx = $xy[0] ?? '';
		$posy = $xy[1] ?? '';
		$this->shiftY( $posy, 0 );
		if ( !Regex::match( '/(s)/', self::str( $this->posy1 ) ) ) {
			$posy = self::str( self::num( $posy ) + 0.01 );
		} else {
			$posy = $this->shiftY( $posy, 0.01, true );
		}
		return "\n  location: $posx $posy";
	}

	// ---------------------------------------------------------------- output post-processing

	/**
	 * Correct click coordinates of right aligned texts (ploticus bug).
	 */
	public static function fixMap( string $map ): string {
		$out = '';
		foreach ( self::split( '/(?<=\n)/', $map ) as $line ) {
			$line = rtrim( $line, "\n" );
			if ( str_contains( $line, '&&' ) ) {
				$coords = Regex::replace( '/^.*coords\="([^"]*)".*$/', '$1', $line );
				$line = Regex::replace( '/\&\&[^"]*/', '', $line, 1 );
				$c = self::split( '/,/', $coords );
				$len = self::num( $c[2] ?? null ) - self::num( $c[0] ?? null );
				$c[0] = self::num( $c[0] ?? null ) - 2 * ( $len - 25 );
				$c[2] = $c[0] + $len;
				ksort( $c );
				$line = Regex::replace( '/' . $coords . '/', implode( ',', array_map( [ self::class, 'str' ], $c ) ),
					$line, 1 );
			}
			$out .= "$line\n";
		}
		return $out;
	}

	/**
	 * Add size and links to the SVG written by ploticus.
	 */
	public function fixSvg( string $svg ): string {
		if ( !Regex::match( '/<svg[^>]+?(width|height)/', $svg ) ) {
			Regex::match( '/<svg[^>]+?viewBox="0 0 ([\d.]+) ([\d.]+)"/', $svg, $m );
			$width = self::str( self::num( $m[1] ?? null ) * 100 / 72 );
			$height = self::str( self::num( $m[2] ?? null ) * 100 / 72 );
			$svg = Regex::replaceCallback( '/<svg[^>]+/', static fn ( $m ) => "$m[0] width=\"$width\" "
				. "height=\"$height\"",
				$svg, 1 );
		}
		// Only the placeholders of the compiler: the text of the timeline cannot have the character
		$svg = Regex::replaceCallback( '/' . self::PLACEHOLDER . '(\d+)' . self::PLACEHOLDER . 'x*/',
			fn ( $m ) => $this->textsSvg[(int)$m[1]] ?? '', $svg );
		if ( $this->svgOnly ) {
			return Regex::replace( '/(<text.*?)>\[(\d+)\[(.*?)\]\d+\]/', '$1 style="fill:blue;">$3', $svg );
		}
		// The URL of an inline link [url text] is not encoded: escape it for the attribute
		return Regex::replaceCallback( '/\[(\d+)\[(.*?)\]\d+\]/', fn ( $m ) => '<a style="fill:blue;" xlink:href="'
			. htmlspecialchars( $this->normalizeUrlForSvg( $this->linksSvg[(int)$m[1]] ?? '' ),
				ENT_QUOTES | ENT_XML1, 'UTF-8', false )
			. '">' . $m[2] . '</a>', $svg );
	}

	// ---------------------------------------------------------------- validation helpers

	private function colorPredefined( string $color ): bool {
		if ( Regex::match( '/^(?:black|white|tan1|tan2|red|magenta|claret|coral|pink|orange|redorange|lightorange|'
			. 'yellow|yellow2|dullyellow|yelloworange|brightgreen|green|kelleygreen|teal|drabgreen|yellowgreen|'
			. 'limegreen|brightblue|darkblue|blue|oceanblue|skyblue|purple|lavender|lightpurple|powderblue|'
			. 'powderblue2)$/i', $color )
		) {
			if ( !isset( $this->colors[strtolower( $color )] ) ) {
				$this->storeColor( $color, $color, '' );
			}
			return true;
		}
		return false;
	}

	private static function validAbs( string $value ): bool {
		return (bool)Regex::match( '/^\d+\.?\d*(?:px|in|cm)?$/i', $value );
	}

	private static function validAbsRel( string $value ): bool {
		return (bool)Regex::match( '/^\d+\.?\d*(?:px|in|cm|%25%)?$/i', $value );
	}

	/**
	 * @return string[] day, month, year of a date in the current DateFormat
	 */
	private function dateParts( string $date ): array {
		if ( $this->dateFormat === 'yyyy-mm-dd' ) {
			return [ substr( $date, 8, 2 ), substr( $date, 5, 2 ), substr( $date, 0, 4 ) ];
		}
		if ( $this->dateFormat === 'dd/mm/yyyy' ) {
			return [ substr( $date, 0, 2 ), substr( $date, 3, 2 ), substr( $date, 6, 4 ) ];
		}
		return [ substr( $date, 3, 2 ), substr( $date, 0, 2 ), substr( $date, 6, 4 ) ];
	}

	private function validDateFormat( string $date ): bool {
		if ( $this->dateFormat === 'yyyy' ) {
			return (bool)Regex::match( '/^\-?\d+$/', $date );
		}
		if ( $this->dateFormat === 'x.y' ) {
			return (bool)Regex::match( '/^\-?\d+(?:\.\d+)?$/', $date );
		}
		$re = $this->dateFormat === 'yyyy-mm-dd' ? '/^\d\d\d\d-\d\d-\d\d$/' : '/^\d\d\/\d\d\/\d\d\d\d$/';
		if ( !Regex::match( $re, $date ) ) {
			return false;
		}
		[ $day, $month, $year ] = array_map( 'intval', $this->dateParts( $date ) );
		if ( in_array( $month, [ 1, 3, 5, 7, 8, 10, 12 ], true ) ) {
			return $day <= 31;
		}
		if ( in_array( $month, [ 4, 6, 9, 11 ], true ) ) {
			return $day <= 30;
		}
		if ( $month === 2 ) {
			return $day <= ( ( $year % 4 === 0 && $year % 100 !== 0 ) ? 29 : 28 );
		}
		return false;
	}

	private function validDateRange( string $date ): bool {
		$from = self::str( $this->period['from'] ?? null );
		$till = self::str( $this->period['till'] ?? null );
		if ( $this->dateFormat === 'yyyy' || $this->dateFormat === 'x.y' ) {
			return !( self::num( $date ) < self::num( $from ) || self::num( $date ) > self::num( $till ) );
		}
		$d = array_map( [ self::class, 'num' ], $this->dateParts( $date ) );
		$f = array_map( [ self::class, 'num' ], $this->dateParts( $from ) );
		$t = array_map( [ self::class, 'num' ], $this->dateParts( $till ) );
		if ( $d[2] < $f[2] || ( $d[2] == $f[2] && ( $d[1] < $f[1] || ( $d[1] == $f[1] && $d[0] < $f[0] ) ) ) ) {
			return false;
		}
		return !( $d[2] > $t[2] || ( $d[2] == $t[2] && ( $d[1] > $t[1] || ( $d[1] == $t[1] && $d[0] > $t[0] ) ) ) );
	}

	private function dateMedium( string $from, string $till ): string {
		if ( $this->dateFormat === 'yyyy' || $this->dateFormat === 'x.y' ) {
			return Printf::f( ( self::num( $from ) + self::num( $till ) ) / 2, '.3' );
		}
		return $this->dateFrom1800( (int)( ( self::num( $this->daysFrom1800( $from ) )
			+ self::num( $this->daysFrom1800( $till ) ) ) / 2 ) );
	}

	private function daysFrom1800( string $date ): ?int {
		$mmm = [ 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 ];
		[ $day, $month, $year ] = array_map( [ self::class, 'num' ], $this->dateParts( $date ) );
		if ( $year < 1800 ) {
			$this->error2( "Function 'DaysFrom1800' expects year >= 1800, not "
				. "'" . $this->dateParts( $date )[2] . "'." );
			return null;
		}
		$days = ( $year - 1800 ) * 365;
		$days += (int)( ( $year - 1 - 1800 ) / 4 );
		$days -= (int)( ( $year - 1 - 1800 ) / 100 );
		for ( $m = $month - 2; $m >= 0; $m-- ) {
			$days += $mmm[$m] ?? 0;
			// Perl % works on integers
			if ( $m === 1 && (int)$year % 4 === 0 && (int)$year % 100 !== 0 ) {
				$days++;
			}
		}
		return $days + $day;
	}

	private function dateToFloat( mixed $date ): mixed {
		$date = self::str( $date );
		if ( !str_contains( $this->dateFormat, '/' ) ) {
			return $date;
		}
		$year = Regex::replace( '/.*\//', '', $date );
		$fraction = ( self::num( $this->daysFrom1800( $date ) ) - self::num( $this->daysFrom1800( "01/01/$year" ) ) )
			/ 365.25;
		return self::num( $year ) + $fraction;
	}

	private function dateFrom1800( int $days ): string {
		$mmm = [ 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 ];
		$year = 1800;
		while ( $days > 365 + ( $year % 4 === 0 ? 1 : 0 ) ) {
			$days -= ( $year % 4 === 0 && $year % 100 !== 0 ) ? 366 : 365;
			$year++;
		}
		$month = 0;
		// The Perl original loops forever past December; stop there instead
		while ( $month < 12 && $days > $mmm[$month] ) {
			$days -= $mmm[$month];
			if ( $month === 1 && $year % 4 === 0 && $year % 100 !== 0 ) {
				$days--;
			}
			$month++;
		}
		$month++;
		if ( $this->dateFormat === 'yyyy-mm-dd' ) {
			return sprintf( '%04d-%02d-%02d', $year, $month, $days );
		}
		if ( $this->dateFormat === 'dd/mm/yyyy' ) {
			return sprintf( '%02d/%02d/%04d', $days, $month, $year );
		}
		return sprintf( '%02d/%02d/%04d', $month, $days, $year );
	}

	/**
	 * Split the text attribute off an attribute line.
	 *
	 * @return string[] [ remaining line, text ]
	 * @throws CompileError
	 */
	private function extractText( string $data ): array {
		$text = '';
		// special case: allow embedded spaces when 'text' is last attribute
		if ( Regex::match( '/text\:[^\:]+$/', $data ) ) {
			$text = Regex::replace( '/^.*?text\:/', '', $data, 1 );
			$text = Regex::replace( '/^(.*?)\s*$/', '$1', $text, 1 );
			$text = str_replace( '\\n', "\n", $text );
			$text = str_replace( '""', '@#$', $text );
			$text = str_replace( '"', '', $text );
			$text = str_replace( '@#$', '"', $text );
			$data = Regex::replace( '/text\:.*$/', '', $data, 1 );
		}
		// extract text between double quotes
		$data = str_replace( '""', '@#$', $data );
		if ( Regex::match( '/text\:\s*"/', $data ) ) {
			$text = Regex::replace( '/^.*?text\:\s*"/', '', $data, 1 );
			if ( !str_contains( $text, '"' ) ) {
				$this->error( "PlotData invalid. Attribute 'text': no closing \" found." );
				return [ 'x', 'x' ];
			}
			$text = Regex::replace( '/".*$/', '', $text, 1 );
			$text = str_replace( '@#$', '"', $text );
			$text = str_replace( '\\n', "\n", $text );
		}
		$data = Regex::replace( '/text\:\s*"[^"]*"/', '', $data, 1 );
		return [ str_replace( '@#$', '"', $data ), $text ];
	}

	/**
	 * Line breaks would end a ploticus attribute, replace them.
	 */
	private static function newlinesToTildes( string $text ): string {
		return Regex::replace( '/(?:\r\n|[\r\n]|\\\\n)/', '~', $text );
	}

	private static function parseText( string $text ): string {
		$text = str_replace( '_', ' ', str_replace( '__', '@#$', $text ) );
		$text = str_replace( '@#$', '_', $text );
		$text = str_replace( '~', '\\n', str_replace( '~~', '@#$', $text ) );
		return str_replace( '@#$', '~', $text );
	}

	/**
	 * Whether a bar exists. A missing next bar of a bar set ("set#3") is inserted
	 * behind its predecessor.
	 */
	private function barDefined( string $bar ): bool {
		foreach ( $this->bars as $b ) {
			if ( strtolower( $b ) === strtolower( $bar ) ) {
				return true;
			}
		}
		if ( !Regex::match( '/^(.*\#)(\d+)$/s', $bar, $m ) ) {
			return false;
		}
		$prev = strtolower( $m[1] . self::str( self::num( $m[2] ) - 1 ) );
		foreach ( $this->bars as $i => $b ) {
			if ( strtolower( $b ) === $prev ) {
				array_splice( $this->bars, $i + 1, 0, [ strtolower( $bar ) ] );
				$this->barLegend[strtolower( $bar )] = ' ';
				return true;
			}
		}
		return false;
	}

	/**
	 * @throws CompileError
	 */
	private function validAttributes( string $command ): bool {
		$lists = [
			'BackgroundColors' => [ '', 'canvas,bars' ],
			'BarData' => [ '', 'bar,barset,link,text' ],
			'Colors' => [ 'id,value', 'legend' ],
			'ImageSize' => [ '', 'width,height,barincrement' ],
			'Legend' => [ '', 'columns,columnwidth,orientation,position,left,top' ],
			'LineData' => [ '', 'at,from,till,atpos,frompos,untilpos,tillpos,points,color,layer,width' ],
			'Period' => [ 'from,till', '' ],
			'PlotArea' => [ '', 'left,bottom,width,height,right,top' ],
			'PlotData' => [ '',
				'align,anchor,at,bar,barset,color,fontsize,from,link,mark,shift,text,textcolor,till,width' ],
			'ScaleMajor' => [ 'increment,start', 'unit,grid,gridcolor,text' ],
			'ScaleMinor' => [ 'increment,start', 'unit,grid,gridcolor,text' ],
			'TextData' => [ '', 'fontsize,lineheight,link,pos,tabs,text,textcolor' ],
			'TimeAxis' => [ '', 'orientation,format,order' ],
		];
		[ $required, $allowed ] = array_map( static fn ( $l ) => self::split( '/,/', $l ), $lists[$command] );

		$hint = "\nSyntax: '$command =";
		foreach ( $required as $a ) {
			$hint .= " $a:..";
		}
		foreach ( $allowed as $a ) {
			$hint .= " [$a:..]";
		}
		$hint .= "'";

		$rest = $this->attributes;
		foreach ( $required as $a ) {
			if ( self::str( $this->attributes[$a] ?? null ) === '' ) {
				$this->error( "$command definition incomplete. $hint" );
				return false;
			}
			unset( $rest[$a] );
		}
		foreach ( $allowed as $a ) {
			unset( $rest[$a] );
		}
		if ( $rest ) {
			$first = array_key_first( $rest );
			if ( $first === 'single' ) {
				$this->error( "$command definition invalid. Specify all attributes as name:value pairs." );
			} else {
				$this->error( "$command definition invalid. Invalid attribute '$first' found. $hint" );
			}
			return false;
		}
		return true;
	}

	/**
	 * @throws CompileError
	 */
	private function checkPreset( string $command ): void {
		$addValue = true;
		if ( strtolower( $command ) === strtolower( $this->prevPresetCommand )
			&& Regex::match( '/^(?:DrawLines|PlotData|TextData)$/i', $command )
		) {
			$addValue = false;
		}
		$this->prevPresetCommand = $command;

		foreach ( $this->presetList as $preset ) {
			if ( stripos( $preset, "$command|" ) !== 0 ) {
				continue;
			}
			[ , $action, $attrName, $attrPreset ] = array_pad( explode( '|', $preset ), 4, '' );
			if ( $attrName === '' ) {
				$attrName = 'single';
			}
			$attrValue = self::str( $this->attributes[$attrName] ?? null );

			if ( $action === '-' && $attrValue !== '' ) {
				if ( $attrName === 'single' ) {
					$this->error( "Chosen preset makes this command redundant.\n  Please remove this command." );
				} else {
					$this->error( "Chosen preset conflicts with '$attrName:...'.\n  Please remove this attribute." );
				}
				$this->attributes[$attrName] = '';
			}
			if ( $action === '+' && $attrValue === '' && $addValue ) {
				$this->attributes[$attrName] = $attrPreset;
			}
			if ( $action === '=' && $attrValue === '' ) {
				$this->attributes[$attrName] = $attrPreset;
			}
			if ( $action === '=' && $attrValue !== '' && !Regex::match( "/$attrPreset/i", $attrValue ) ) {
				if ( $attrName === 'single' ) {
					$this->error( "Conflicting settings.\nPreset defines '$attrPreset'." );
				} else {
					$this->error( "Conflicting settings.\nPreset defines '$attrName:$attrPreset'." );
				}
				$this->attributes[$attrName] = $attrPreset;
			}
		}
	}

	// ---------------------------------------------------------------- links and encoding

	private static function normalizeUrl( string $url ): string {
		$url = Regex::replace( '/(https?)\:?\/?\/?/', '$1://', $url, 1 );
		// Not a bare "%20": with the "%" of a following %XX% it would be decoded as one
		return str_replace( ' ', self::H_PERC . '20', $url );
	}

	/**
	 * Relative URLs become full URLs, as upload verification does not accept relative URLs.
	 */
	private function normalizeUrlForSvg( string $url ): string {
		if ( !Regex::match( '/^https?:\/\//', $url ) ) {
			$url = Regex::replace( '/\$1/', str_replace( [ '\\', '$' ], [ '\\\\', '\\$' ], $url ),
				$this->articlePath, 1 );
		}
		return $url;
	}

	/**
	 * A wiki link with line breaks becomes one link per line.
	 */
	private static function normalizeWikiLink( string $text ): string {
		$double = (bool)Regex::match( '/\[\[.*\]\]/', $text );
		$text = Regex::replace( '/\[\[?/', '', $text, 1 );
		$text = Regex::replace( '/\]?\]/', '', $text, 1 );
		$parts = explode( '|', $text );
		$hide = $parts[0];
		$show = $parts[1] ?? '';
		if ( $show === '' ) {
			$show = $hide;
		}
		$hide = Regex::replace( '/\s*\n\s*/', ' ', $hide );
		$lines = self::split( '/\n/', $show );
		foreach ( $lines as &$part ) {
			$part = $double ? "[[$hide|$part]]" : "[$hide|$part]";
		}
		return implode( "\n", $lines );
	}

	/**
	 * Find the first link in a text segment.
	 *
	 * @return string[] [ text, link, hint ]
	 */
	private function processWikiLink( string $text, string $link, string $hint ): array {
		$wikilink = false;
		$chomp = static fn ( $s ) => str_ends_with( $s, "\n" ) ? substr( $s, 0, -1 ) : $s;
		$text = $chomp( $text );
		$link = $chomp( $link );
		$hint = $chomp( $hint );

		if ( $link !== '' ) {
			$text = Regex::replace( '/\[\[[^\|]+\|(.*)\]\]/', '$1', $text );
			$text = Regex::replace( '/\[\[[^\:]+\:(.*)\]\]/', '$1', $text );
		} elseif ( Regex::match( '/\[.+\]/', $text ) ) {
			// keep first link in text segment, remove others
			$link = str_replace( "\n", '', $text );
			$link = Regex::replace( '/^[^\[\]]*\[/', '[', $link, 1 );
			$wikilink = str_starts_with( $link, '[[' );
			$link = Regex::replace( '/^[^\[]*\[+([^\[\]]*)\].*$/', '$1', $link, 1 );
			$link = Regex::replace( '/\|.*$/', '', $link, 1 );
			if ( $wikilink ) {
				$link = "[[$link]]";
			}
			$text = Regex::replace( '/(\[+)[^\|\]]+\|([^\]]*)(\]+)/', '$1$2$3', $text );
			$text = Regex::replace( '/(https?)\:/', '$1colon', $text );
			$text = Regex::replace( '/\[+([^\]]+)\]+/', '{{{$1}}}', $text, 1 );
			$text = Regex::replace( '/\[+([^\]]+)\]+/', '$1', $text );
			$text = Regex::replace( '/\{\{\{([^\}]*)\}\}\}/', '[[$1]]', $text, 1 );
		}

		if ( $wikilink ) {
			$title = Regex::replace( '/^\[\[(.*)\]\]$/', '$1', $link, 1 );
			$title = str_replace( ' ', '_', $title );
			$urlpart = self::encodeUrl( $title );
			$link = Regex::replace( '/\$1/', str_replace( [ '\\', '$' ], [ '\\\\', '\\$' ], $urlpart ),
				$this->articlePath, 1 );
			if ( $hint === '' && $title !== '' ) {
				$hint = $title;
			}
			$hint = str_replace( '_', ' ', $hint );
		} elseif ( $link !== '' ) {
			$hint = self::externalLinkToHint( $link );
		}

		if ( $link !== '' && !str_contains( $text, '[[' ) && !str_contains( $text, ']]' ) ) {
			$text = "[[$text]]";
		}
		return [ $text, $link, self::encodeHtml( $hint ) ];
	}

	private static function externalLinkToHint( string $hint ): string {
		$hint = Regex::replace( '/^https?\:?\/?\/?/', '', $hint, 1 );
		$hint = Regex::replace( '/\/.*$/', '', $hint, 1 );
		return self::encodeHtml( "$hint/.." );
	}

	/**
	 * Hide characters with a special meaning in the syntax as %XX%.
	 */
	private static function encodeInput( string $text ): string {
		// revert encoding of '<' & '>' by MediaWiki
		$text = str_replace( [ '&lt;', '&gt;' ], [ '<', '>' ], $text );
		return Regex::replaceCallback( '/([\`\{\}\%\&\@\$\(\)\;\=])/',
			static fn ( $m ) => '%' . sprintf( '%X', ord( $m[1] ) ) . '%', $text );
	}

	/**
	 * A line of a multiline value in the plot script, from the timeline. The Plotter takes each
	 * line whose first word starts with "#" as a directive and each one that starts with "//" as
	 * a comment, also after spaces, so such a line gets a "\\" in front. The Plotter removes that
	 * "\\" from a line of a multiline value. An empty line ends the value, so it becomes "\\".
	 */
	private static function dataLine( string $text ): string {
		if ( trim( $text ) === '' ) {
			return '\\';
		}
		return Regex::replace( '/^(\s*)(?=#|\/\/)/', '$1\\\\', $text );
	}

	/**
	 * Decode the complete plot script. The Plotter replaces "@NAME" by the value of a variable
	 * in each line, and "@@" by "@": write the "@" of the timeline as "@@".
	 *
	 * In the same pass as the other characters: a "%40%" that is typed in the timeline is
	 * "%25%40%25%" here, and its "%40%" is not an "@".
	 */
	private static function decodeScript( string $script ): string {
		return self::decodeInput( $script, '@@' );
	}

	/**
	 * Whether the id of a bar or bar set can be used. The id is written at the start of a line
	 * of the plot script, where a "#" starts a directive.
	 *
	 * The pattern for the allowed characters is not anchored, as in the Perl script, because
	 * existing timelines have ids with other characters.
	 */
	private static function isValidId( string $id ): bool {
		if ( Regex::match( '/^\s*#/', $id ) ) {
			return false;
		}
		// The id is also a field of records that are joined with commas
		if ( str_contains( $id, ',' ) ) {
			return false;
		}
		return Regex::match( '/[a-zA-Z0-9\_]+/', $id ) === 1;
	}

	/**
	 * @param string $text
	 * @param string $at What an encoded "@" becomes, see decodeScript()
	 */
	private static function decodeInput( string $text, string $at = '@' ): string {
		// Never the placeholder character: compile() removes it from the source, and it must
		// not come back from an encoded form
		// Also lower case: the value of an attribute is made lower case in some places, with
		// the encoded characters in it. Without this, the "%" of such a form stays, and is
		// decoded with the characters of the timeline next to it.
		return Regex::replaceCallback( '/\%([0-9A-Fa-f]{2})\%/',
			static fn ( $m ) => match ( $m[1] ) {
				'01' => '',
				'40' => $at,
				default => chr( hexdec( $m[1] ) ),
			}, $text );
	}

	private static function encodeHtml( string $text ): string {
		$text = Regex::replaceCallback( '/([\<\>\&\'"])/', static fn ( $m ) => '&#' . ord( $m[1] ) . ';', $text );
		return str_replace( "\n", '<br>', $text );
	}

	private static function encodeUrl( string $url ): string {
		return Regex::replaceCallback( '/([^0-9a-zA-Z\%\:\/\._])/',
			static fn ( $m ) => self::H_PERC . sprintf( '%02X', ord( $m[1] ) ), $url );
	}

	// ---------------------------------------------------------------- errors

	/**
	 * @throws CompileError
	 */
	private function error( string $msg ): void {
		$msg = Regex::replace( '/\n\s*/', "\n  ", self::decodeInput( $msg ) );
		$this->cntErrors++;
		$this->errors[] = "Line {$this->lineNo}: " . self::decodeInput( $this->line ) . "\n";
		$this->errors[] = "- $msg\n\n";
		if ( $this->cntErrors > 10 ) {
			$this->abort( 'More than 10 errors found' );
		}
	}

	private function error2( string $msg ): void {
		$msg = Regex::replace( '/\n\s*/', "\n  ", self::decodeInput( $msg ) );
		$this->cntErrors++;
		$this->errors[] = "- $msg\n";
	}

	/**
	 * @throws CompileError
	 */
	private function abort( string $msg ): never {
		$html = '<p>EasyTimeline ' . self::VERSION . '</p><p><b>Timeline generation failed: '
			. self::encodeHtml( self::decodeInput( $msg ) ) . "</b></p>\n";
		foreach ( $this->errors as $line ) {
			$html .= self::encodeHtml( $line ) . "\n";
		}
		throw new CompileError( $html );
	}

	// ---------------------------------------------------------------- Perl scalar semantics

	/**
	 * Numeric value of a scalar, like Perl numification of a string.
	 */
	private static function num( mixed $v ): int|float {
		if ( $v === null || $v === false ) {
			return 0;
		}
		if ( is_int( $v ) || is_float( $v ) ) {
			return $v;
		}
		if ( $v === true ) {
			return 1;
		}
		if ( !Regex::match(
			'/^[ \t\n\r\f\x0B]*([+-]?)(?:(\d+)(\.\d*)?([eE][+-]?\d+)?|(\.\d+)([eE][+-]?\d+)?|(inf(?:inity)?|nan))/i',
			$v, $m
		) ) {
			return 0;
		}
		if ( ( $m[7] ?? '' ) !== '' ) {
			$x = strtolower( $m[7] ) === 'nan' ? NAN : INF;
			return $m[1] === '-' ? -$x : $x;
		}
		$isInt = ( $m[2] ?? '' ) !== '' && ( $m[3] ?? '' ) === '' && ( $m[4] ?? '' ) === '';
		$numStr = substr( $m[0], strspn( $m[0], " \t\n\r\f\v" ) );
		if ( $isInt && strlen( $m[2] ) < 19 ) {
			return (int)$numStr;
		}
		return (float)$numStr;
	}

	/**
	 * String value of a scalar, like Perl stringification (floats as %.15g).
	 */
	private static function str( mixed $v ): string {
		if ( $v === null || $v === false ) {
			return '';
		}
		if ( $v === true ) {
			return '1';
		}
		if ( is_string( $v ) ) {
			return $v;
		}
		if ( is_int( $v ) ) {
			return (string)$v;
		}
		if ( is_nan( $v ) ) {
			return 'NaN';
		}
		if ( is_infinite( $v ) ) {
			return $v > 0 ? 'Inf' : '-Inf';
		}
		return $v == 0.0 ? '0' : Printf::g( $v, 15 );
	}

	/**
	 * Perl split: ' ' is awk mode, trailing empty fields are removed.
	 *
	 * @param string $re PCRE pattern, or ' ' for awk mode
	 * @param mixed $s
	 * @return string[]
	 */
	private static function split( string $re, mixed $s ): array {
		$s = self::str( $s );
		if ( $re === ' ' ) {
			$s = ltrim( $s, " \t\n\r\f\v" );
			$re = '/[ \t\n\r\f\x0B]+/';
		}
		if ( $s === '' ) {
			return [];
		}
		$parts = Regex::split( $re, $s );
		while ( $parts && end( $parts ) === '' ) {
			array_pop( $parts );
		}
		return $parts;
	}

	/**
	 * Unary minus on a scalar.
	 */
	private static function neg( mixed $v ): int|float|string {
		if ( is_string( $v ) && Regex::match( '/^[A-Za-z_]/', $v ) ) {
			return '-' . $v;
		}
		$n = self::num( $v );
		return $n == 0 ? 0 : -$n;
	}
}
