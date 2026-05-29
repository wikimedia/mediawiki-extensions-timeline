<?php

namespace MediaWiki\Extension\Timeline;

use DOMDocument;
use MediaWiki\Context\RequestContext;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Html\Html;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Upload\UploadBase;
use MediaWiki\Upload\UploadFromFile;
use MediaWiki\WikiMap\WikiMap;
use NullLockManager;
use Shellbox\Command\BoxedCommand;
use Wikimedia\FileBackend\FileBackend;
use Wikimedia\FileBackend\FSFileBackend;
use Wikimedia\ScopedCallback;

class Timeline implements ParserFirstCallInitHook {

	/**
	 * Bump when some change requires re-rendering all timelines
	 */
	private const CACHE_VERSION = 3;

	private static ?FileBackend $backend = null;

	/** @inheritDoc */
	public function onParserFirstCallInit( $parser ) {
		$parser->setHook( 'timeline', [ self::class, 'onTagHook' ] );
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
	 */
	public static function onTagHook( ?string $timelinesrc, array $args, Parser $parser ): string {
		global $wgUploadPath;

		$pOutput = $parser->getOutput();
		$pOutput->addModuleStyles( [ 'ext.timeline.styles' ] );

		$parser->addTrackingCategory( 'timeline-tracking-category' );

		$createSvg = ( $args['method'] ?? null ) === 'svg2png';
		$options = [
			'createSvg' => $createSvg,
			'font' => self::determineFont( $args['font'] ?? '' ),
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
		$pathPrefix = $backend->getContainerStoragePath( 'timeline-render' ) . "/$hash";

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

		$map = Html::rawElement(
			'map',
			[ 'name' => "timeline_{$hash}" ],
			str_replace(
				' >',
				' />',
				$backend->getFileContents( [ 'src' => "{$pathPrefix}.map" ] )
			)
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
	 *
	 * @throws TimelineException
	 */
	private static function renderTimeline( string $timelinesrc, array $options ): void {
		global $wgArticlePath, $wgTmpDirectory, $wgTimelinePerlCommand,
			$wgTimelinePloticusCommand, $wgShellboxShell, $wgTimelineRsvgCommand,
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
				$wgShellboxShell,
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
				'ET_ARTICLEPATH' => MediaWikiServices::getInstance()->getUrlUtils()->expand( $wgArticlePath ) ?? '',
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
			self::throwCompileException( $stdout );
		}

		$svgFile = "{$factoryDirectory}/file.svg";

		$request = new FauxRequest( [], true );
		$request->setUpload( 'file', [
			'name' => 'file.svg',
			'type' => 'image/svg',
			'tmp_name' => $svgFile,
			'size' => filesize( $svgFile ),
			'error' => '',
		] );

		$upload = new UploadFromFile();
		$upload->initialize(
			'file.svg',
			$request->getUpload( 'file' )
		);

		$verification = $upload->verifyUpload();

		if ( $verification['status'] !== UploadBase::OK ) {
			LoggerFactory::getInstance( 'timeline' )->info(
				"File verification svg generated by timeline failed for {user} because {result}",
				[
					'user' => RequestContext::getMain()->getUser()->getName(),
					'resultCode' => $verification['status'],
					'result' => $upload->getVerificationErrorCode( $verification['status'] ),
					'details' => $verification['details'] ?? ''
				]
			);
			throw new TimelineException( 'timeline-error-storage' );
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

	private static function boxedCommand(): BoxedCommand {
		return MediaWikiServices::getInstance()->getShellCommandFactory()
			->createBoxed( 'easytimeline' )
			->disableNetwork()
			->firejailDefaultSeccomp();
	}

	/**
	 * Add an input file from the scripts directory
	 */
	private static function addScript( BoxedCommand $command, string $script ): void {
		$command->inputFileFromFile( "scripts/$script",
			__DIR__ . "/../scripts/$script" );
	}

	/**
	 * Creates the specified local directory if it does not exist yet.
	 * Otherwise, it does nothing.
	 *
	 * @param string $path Local path to directory to be created.
	 * @param int|null $mode Chmod value of the new directory.
	 *
	 * @throws TimelineException
	 */
	private static function createDirectory( string $path, ?int $mode = null ): void {
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
	 */
	private static function eraseDirectory( string $dir ): void {
		if ( file_exists( $dir ) ) {
			// @phan-suppress-next-line PhanPluginUseReturnValueInternalKnown
			array_map( 'unlink', glob( "$dir/*", GLOB_NOSORT ) );
			$rc = rmdir( $dir );
			if ( !$rc ) {
				wfDebug( __METHOD__ . ": Unable to remove directory $dir\n." );
			}
		}
	}

	/**
	 * Given a user's input of font, identify the font
	 * directory and font path that should be set
	 *
	 * @param string $input
	 * @return array with 'dir', 'file' keys. Note that 'dir' might be false.
	 */
	private static function determineFont( string $input ): array {
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
	 */
	public static function getBackend(): FileBackend {
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
	 * Only the well-formed error envelope emitted by EasyTimeline.pl's
	 * Abort() routine is reflected back to the requester. Anything else
	 * in file.err is treated as attacker-influenced (e.g. via a future
	 * ploticus injection bug) and is logged server-side only, with a
	 * generic error surfaced to the user.
	 *
	 * @throws TimelineException
	 */
	private static function throwRawException( string $err ): never {
		if ( !str_starts_with( $err, '<p>EasyTimeline ' ) ) {
			LoggerFactory::getInstance( 'timeline' )->warning(
				'Unexpected EasyTimeline file.err contents',
				[ 'error' => $err ]
			);
			throw new TimelineException( 'timeline-compilererr', [ '' ] );
		}

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
	 * @throws TimelineException
	 */
	private static function throwCompileException( string $stdout ): never {
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
	 */
	private static function extractMessage( string &$stdout ): ?array {
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
	 *
	 * @throws TimelineException
	 */
	private static function fixMap( string $html ): string {
		global $wgUrlProtocols;
		$doc = new DOMDocument( '1.0', 'UTF-8' );
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		$status = @$doc->loadXML( $html );
		if ( !$status ) {
			throw new TimelineException( 'timeline-invalidmap' );
		}

		$map = $doc->firstChild;
		if ( strtolower( $map->nodeName ) !== 'map' ) {
			throw new TimelineException( 'timeline-invalidmap' );
		}
		/** @phan-suppress-next-line PhanUndeclaredProperty */
		$name = $map->attributes->getNamedItem( 'name' )->value;
		$res = Html::openElement( 'map', [ 'name' => $name ] );

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
				if ( $lcName == 'href' && !str_starts_with( $value, '/' ) ) {
					$ok = false;
					foreach ( $wgUrlProtocols as $protocol ) {
						if ( str_starts_with( $value, $protocol ) ) {
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

			$res .= Html::element( 'area', $attributes );
		}
		$res .= Html::closeElement( 'map' );

		return $res;
	}

	/**
	 * Track how often we do each type of shellout in statsd
	 */
	private static function recordShellout( string $type ): void {
		MediaWikiServices::getInstance()->getStatsFactory()
			->getCounter( 'timeline_shell_total' )
			->setLabel( 'type', $type )
			->copyToStatsdAt( "timeline_shell.$type" )
			->increment();
	}

	/**
	 * Track how often each error is received in statsd
	 */
	private static function recordError( TimelineException $ex ): void {
		MediaWikiServices::getInstance()->getStatsFactory()
			->getCounter( 'timeline_error_total' )
			->setLabel( 'exception', $ex->getStatsdKey() )
			->copyToStatsdAt( "timeline_error.{$ex->getStatsdKey()}" )
			->increment();
	}
}
