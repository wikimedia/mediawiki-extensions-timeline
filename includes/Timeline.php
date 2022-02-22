<?php

namespace MediaWiki\Extension\Timeline;

use DOMDocument;
use FileBackend;
use FSFileBackend;
use Html;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use NullLockManager;
use Parser;
use Sanitizer;
use Shellbox\Command\BoxedCommand;
use WikiMap;
use Wikimedia\AtEase\AtEase;
use Wikimedia\ScopedCallback;
use Xml;

class Timeline {

	/**
	 * Bump when some change requires re-rendering all timelines
	 */
	private const CACHE_VERSION = 2;

	/** @var FileBackend|null instance cache */
	private static $backend;

	/**
	 * @param Parser $parser
	 * @return bool
	 */
	public static function onParserFirstCallInit( $parser ) {
		$parser->setHook( 'timeline', [ self::class, 'onTagHook' ] );

		return true;
	}

	/**
	 * Render timeline if necessary and display to users
	 *
	 * Based on the user's input, calculate a unique hash for the requested
	 * timeline. If it already exists in the FileBackend, serve it to the
	 * user. Otherwise call renderTimeline().
	 *
	 * Specially catch any TimelineExceptions and display a nice error for
	 * users and record it in stats.
	 *
	 * @param string|null $timelinesrc
	 * @param array $args
	 * @param Parser $parser
	 * @return string HTML
	 */
	public static function onTagHook( ?string $timelinesrc, array $args, Parser $parser ) {
		global $wgUploadPath;

		$pOutput = $parser->getOutput();
		$pOutput->addModuleStyles( [ 'ext.timeline.styles' ] );

		$parser->addTrackingCategory( 'timeline-tracking-category' );

		$createSvg = ( $args['method'] ?? null ) === 'svg2png';
		$options = [
			'createSvg' => $createSvg,
			'font' => self::determineFont( $args['font'] ?? null ),
		];
		if ( $timelinesrc === null ) {
			$timelinesrc = '';
		}

		// Input for cache key
		$cacheOptions = [
			'code' => $timelinesrc,
			'options' => $options,
			'ExtVersion' => self::CACHE_VERSION,
			// TODO: ploticus version? Given that it's
			// dead upstream, unlikely to ever change.
		];
		$hash = \Wikimedia\base_convert( sha1( serialize( $cacheOptions ) ), 16, 36, 31 );
		$backend = self::getBackend();
		// Storage destination path (excluding file extension)
		// TODO: Implement $wgHashedUploadDirectory layout
		$pathPrefix = 'mwstore://' . $backend->getName() . "/timeline-render/$hash";

		$options += [
			'pathPrefix' => $pathPrefix,
		];

		$exists = $backend->fileExists( [ 'src' => "{$pathPrefix}.png" ] );
		if ( !$exists ) {
			try {
				self::renderTimeline( $timelinesrc, $options );
			} catch ( TimelineException $e ) {
				// TODO: add error tracking category
				self::recordError( $e );
				return $e->getHtml();
			}
		}

		$map = $backend->getFileContents( [ 'src' => "{$pathPrefix}.map" ] );

		$map = str_replace( ' >', ' />', $map );
		$map = Html::rawElement(
			'map',
			[ 'name' => "timeline_{$hash}" ],
			$map
		);
		try {
			$map = self::fixMap( $map );
		} catch ( TimelineException $e ) {
			// TODO: add error tracking category
			self::recordError( $e );
			return $e->getHtml();
		}

		$img = Html::element(
			'img',
			[
				'usemap' => "#timeline_{$hash}",
				'src' => "{$wgUploadPath}/timeline/{$hash}.png",
			]
		);
		return Html::rawElement(
			'div',
			[ 'class' => 'timeline-wrapper' ],
			$map . $img
		);
	}

	/**
	 * Render timeline and save to file backend
	 *
	 * A temporary factory directory is created to store things while they're
	 * being made. The renderTimeline.sh script is invoked via BoxedCommand
	 * which calls EasyTimeline.pl which calls ploticus.
	 *
	 * The rendered timeline is saved in the file backend using the provided
	 * 'pathPrefix'.
	 *
	 * @param string $timelinesrc
	 * @param array $options
	 * @throws TimelineException
	 */
	private static function renderTimeline( string $timelinesrc, array $options ) {
		global $wgArticlePath, $wgTmpDirectory, $wgTimelinePerlCommand,
			$wgTimelinePloticusCommand, $wgTimelineShell, $wgTimelineRsvgCommand,
			$wgPhpCli;

		/* temporary working directory to use */
		$fuzz = md5( (string)mt_rand() );
		// @phan-suppress-next-line PhanTypeSuspiciousStringExpression
		$factoryDirectory = $wgTmpDirectory . "/ET.$fuzz";
		self::createDirectory( $factoryDirectory, 0700 );
		// Make sure we clean up the directory at the end of this function
		$teardown = new ScopedCallback( function () use ( $factoryDirectory ) {
			self::eraseDirectory( $factoryDirectory );
		} );

		$env = [];
		// Set font directory if configured
		if ( $options['font']['dir'] !== false ) {
			$env['GDFONTPATH'] = $options['font']['dir'];
		}

		$command = self::boxedCommand()
			->routeName( 'easytimeline' )
			->params(
				$wgTimelineShell,
				'scripts/renderTimeline.sh'
			)
			->inputFileFromString( 'file', $timelinesrc )
			// Save these as files
			->outputFileToFile( 'file.png', "$factoryDirectory/file.png" )
			->outputFileToFile( 'file.svg', "$factoryDirectory/file.svg" )
			->outputFileToFile( 'file.map', "$factoryDirectory/file.map" )
			// Save error text in memory
			->outputFileToString( 'file.err' )
			->includeStderr()
			->environment( [
				'ET_ARTICLEPATH' => $wgArticlePath,
				'ET_FONTFILE' => $options['font']['file'],
				'ET_PERL' => $wgTimelinePerlCommand,
				'ET_PLOTICUS' => $wgTimelinePloticusCommand,
				'ET_PHP' => $wgPhpCli,
				'ET_RSVG' => $wgTimelineRsvgCommand,
				'ET_SVG' => $options['createSvg'] ? 'yes' : 'no',
			] + $env );
		self::addScript( $command, 'renderTimeline.sh' );
		self::addScript( $command, 'EasyTimeline.pl' );
		self::addScript( $command, 'extractSVGSize.php' );

		$result = $command->execute();
		self::recordShellout( 'render_timeline' );

		if ( $result->wasReceived( 'file.err' ) ) {
			$error = $result->getFileContents( 'file.err' );
			self::throwRawException( $error );
		}

		$stdout = $result->getStdout();
		if ( $result->getExitCode() != 0 || !strlen( $stdout ) ) {
			self::throwCompileException( $stdout, $options );
		}

		// Copy the output files into storage...
		$pathPrefix = $options['pathPrefix'];
		$ops = [];
		$backend = self::getBackend();
		$backend->prepare( [ 'dir' => dirname( $pathPrefix ) ] );
		// Save .map, .png, .svg files
		foreach ( [ 'map', 'png', 'svg' ] as $ext ) {
			if ( $result->wasReceived( "file.$ext" ) ) {
				$ops[] = [
					'op' => 'store',
					'src' => "{$factoryDirectory}/file.{$ext}",
					'dst' => "{$pathPrefix}.{$ext}"
				];
			}
		}
		if ( !$backend->doQuickOperations( $ops )->isOK() ) {
			throw new TimelineException( 'timeline-error-storage' );
		}
	}

	/**
	 * Return a BoxedCommand object
	 *
	 * @return BoxedCommand
	 */
	private static function boxedCommand() {
		return MediaWikiServices::getInstance()->getShellCommandFactory()
			->createBoxed( 'easytimeline' )
			->disableNetwork()
			->firejailDefaultSeccomp();
	}

	/**
	 * Add an input file from the scripts directory
	 *
	 * @param BoxedCommand $command
	 * @param string $script
	 */
	private static function addScript( BoxedCommand $command, string $script ) {
		$command->inputFileFromFile( "scripts/$script",
			__DIR__ . "/../scripts/$script" );
	}

	/**
	 * Creates the specified local directory if it does not exist yet.
	 * Otherwise does nothing.
	 *
	 * @param string $path Local path to directory to be created.
	 * @param int|null $mode Chmod value of the new directory.
	 *
	 * @throws TimelineException if the directory does not exist and could not
	 * 	be created.
	 */
	private static function createDirectory( $path, $mode = null ) {
		if ( !is_dir( $path ) ) {
			$rc = wfMkdirParents( $path, $mode, __METHOD__ );
			if ( !$rc ) {
				throw new TimelineException( 'timeline-error-temp', [ $path ] );
			}
		}
	}

	/**
	 * Deletes a local directory with no subdirectories with all files in it.
	 *
	 * @param string $dir Local path to the directory that is to be deleted.
	 *
	 * @return bool true on success, false on error
	 */
	private static function eraseDirectory( $dir ) {
		if ( file_exists( $dir ) ) {
			// @phan-suppress-next-line PhanPluginUseReturnValueInternalKnown
			array_map( 'unlink', glob( "$dir/*", GLOB_NOSORT ) );
			$rc = rmdir( $dir );
			if ( !$rc ) {
				wfDebug( __METHOD__ . ": Unable to remove directory $dir\n." );
			}
			return $rc;
		}

		/* Nothing to do */
		return true;
	}

	/**
	 * Given a user's input of font, identify the font
	 * directory and font path that should be set
	 *
	 * @param ?string $input
	 * @return array with 'dir', 'file' keys. Note that 'dir' might be false.
	 */
	private static function determineFont( $input ) {
		global $wgTimelineFonts, $wgTimelineFontFile, $wgTimelineFontDirectory;
		// Try the user-specified font, if invalid, use "default"
		$fullPath = $wgTimelineFonts[$input] ?? $wgTimelineFonts['default'] ?? false;
		if ( $fullPath !== false ) {
			return [
				'dir' => dirname( $fullPath ),
				'file' => basename( $fullPath ),
			];
		}
		// Try using deprecated globals
		return [
			'dir' => $wgTimelineFontDirectory,
			'file' => $wgTimelineFontFile,
		];
	}

	/**
	 * Files are saved to the file backend $wgTimelineFileBackend if set. Else
	 * default to FSFileBackend named 'timeline-backend'.
	 *
	 * @return FileBackend
	 */
	private static function getBackend(): FileBackend {
		global $wgTimelineFileBackend, $wgUploadDirectory;

		if ( $wgTimelineFileBackend ) {
			return MediaWikiServices::getInstance()->getFileBackendGroup()
				->get( $wgTimelineFileBackend );
		}

		if ( !self::$backend ) {
			self::$backend = new FSFileBackend(
				[
					'name' => 'timeline-backend',
					'wikiId' => WikiMap::getCurrentWikiId(),
					'lockManager' => new NullLockManager( [] ),
					'containerPaths' => [ 'timeline-render' => "{$wgUploadDirectory}/timeline" ],
					'fileMode' => 0777,
					'obResetFunc' => 'wfResetOutputBuffers',
					'streamMimeFunc' => [ 'StreamFile', 'contentTypeFromPath' ],
					'logger' => LoggerFactory::getInstance( 'timeline' ),
				]
			);
		}

		return self::$backend;
	}

	/**
	 * Cleanup and throw errors from EasyTimeline.pl
	 *
	 * @param string $err
	 * @throws TimelineException
	 * @return never
	 */
	private static function throwRawException( $err ) {
		// Convert the error from poorly-sanitized HTML to plain text
		$err = strtr( $err, [
			'</p><p>' => "\n\n",
			'<p>' => '',
			'</p>' => '',
			'<b>' => '',
			'</b>' => '',
			'<br>' => "\n"
		] );
		$err = Sanitizer::decodeCharReferences( $err );

		// Now convert back to HTML again
		$encErr = nl2br( htmlspecialchars( $err ) );
		$params = [ Html::rawElement(
			'div',
			[
				'class' => [ 'error', 'timeline-error' ],
				'lang' => 'en',
				'dir' => 'ltr',
			],
			$encErr
		) ];
		throw new TimelineException( 'timeline-compilererr', $params );
	}

	/**
	 * Get error information from the output returned by scripts/renderTimeline.sh
	 * and throw a relevant error.
	 *
	 * @param string $stdout
	 * @param array $options
	 * @throws TimelineException
	 * @return never
	 */
	private static function throwCompileException( $stdout, $options ) {
		$extracted = self::extractMessage( $stdout );
		if ( $extracted ) {
			$message = $extracted[0];
			$params = $extracted[1];
		} else {
			$message = 'timeline-compilererr';
			$params = [];
		}
		// Add stdout (always in English) as a param, wrapped in <pre>
		$params[] = Html::element(
			'pre',
			[ 'lang' => 'en', 'dir' => 'ltr' ],
			$stdout
		);
		throw new TimelineException( $message, $params );
	}

	/**
	 * Parse the script return value and extract any mw-msg lines. Modify the
	 * text to remove the lines. Return the first mw-msg line as a Message
	 * object. If there was no mw-msg line, return null.
	 *
	 * @param string &$stdout
	 * @return array|null
	 */
	private static function extractMessage( &$stdout ) {
		$filteredStdout = '';
		$messageParams = [];
		foreach ( explode( "\n", $stdout ) as $line ) {
			if ( preg_match( '/^mw-msg:\t/', $line ) ) {
				if ( !$messageParams ) {
					$messageParams = array_slice( explode( "\t", $line ), 1 );
				}
			} else {
				if ( $filteredStdout !== '' ) {
					$filteredStdout .= "\n";
				}
				$filteredStdout .= $line;
			}
		}
		$stdout = $filteredStdout;
		if ( $messageParams ) {
			$messageName = array_shift( $messageParams );
			// Used messages:
			// - timeline-readerr
			// - timeline-scripterr
			// - timeline-perlnotexecutable
			// - timeline-ploticusnotexecutable
			// - timeline-compilererr
			// - timeline-rsvg-error
			return [ $messageName, $messageParams ];
		} else {
			return null;
		}
	}

	/**
	 * Do a security check on the image map HTML
	 * @param string $html
	 * @return string HTML
	 */
	private static function fixMap( $html ) {
		global $wgUrlProtocols;
		$doc = new DOMDocument( '1.0', 'UTF-8' );
		AtEase::suppressWarnings();
		$status = $doc->loadXML( $html );
		AtEase::restoreWarnings();
		if ( !$status ) {
			throw new TimelineException( 'timeline-invalidmap' );
		}

		$map = $doc->firstChild;
		if ( strtolower( $map->nodeName ) !== 'map' ) {
			throw new TimelineException( 'timeline-invalidmap' );
		}
		/** @phan-suppress-next-line PhanUndeclaredProperty */
		$name = $map->attributes->getNamedItem( 'name' )->value;
		$res = Xml::openElement( 'map', [ 'name' => $name ] );

		$allowedAttribs = [ 'shape', 'coords', 'href', 'nohref', 'alt', 'tabindex', 'title' ];
		foreach ( $map->childNodes as $node ) {
			if ( strtolower( $node->nodeName ) !== 'area' ) {
				continue;
			}
			$ok = true;
			$attributes = [];
			foreach ( $node->attributes as $name => $value ) {
				$value = $value->value;
				$lcName = strtolower( $name );
				if ( !in_array( $lcName, $allowedAttribs ) ) {
					$ok = false;
					break;
				}
				if ( $lcName == 'href' && substr( $value, 0, 1 ) !== '/' ) {
					$ok = false;
					foreach ( $wgUrlProtocols as $protocol ) {
						if ( substr( $value, 0, strlen( $protocol ) ) == $protocol ) {
							$ok = true;
							break;
						}
					}
					if ( !$ok ) {
						break;
					}
				}
				$attributes[$name] = $value;
			}
			if ( !$ok ) {
				$res .= "<!-- illegal element removed -->\n";
				continue;
			}

			$res .= Xml::element( 'area', $attributes );
		}
		$res .= '</map>';

		return $res;
	}

	/**
	 * Track how often we do each type of shellout in statsd
	 *
	 * @param string $type Type of shellout
	 */
	private static function recordShellout( $type ) {
		$statsd = MediaWikiServices::getInstance()->getStatsdDataFactory();
		$statsd->increment( "timeline_shell.$type" );
	}

	/**
	 * Track how often each error is received in statsd
	 *
	 * @param TimelineException $ex
	 */
	private static function recordError( TimelineException $ex ) {
		$statsd = MediaWikiServices::getInstance()->getStatsdDataFactory();
		$statsd->increment( "timeline_error.{$ex->getStatsdKey()}" );
	}
}
