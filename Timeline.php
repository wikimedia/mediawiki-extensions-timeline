<?php
/**
 * EasyTimeline - Timeline extension
 * To use, include this file from your LocalSettings.php
 * To configure, set members of $wgTimelineSettings after the inclusion
 *
 * @file
 * @ingroup Extensions
 * @author Erik Zachte <xxx@chello.nl (nospam: xxx=epzachte)>
 * @license GNU General Public License version 2
 * @link http://www.mediawiki.org/wiki/Extension:EasyTimeline Documentation
 */

$wgExtensionCredits['parserhook'][] = array(
	'path' => __FILE__,
	'name' => 'EasyTimeline',
	'author' => 'Erik Zachte',
	'url' => 'https://www.mediawiki.org/wiki/Extension:EasyTimeline',
	'descriptionmsg' => 'timeline-desc',
);

class TimelineSettings {
	public $ploticusCommand, $perlCommand;

	// Update this timestamp to force older rendered timelines
	// to be generated when the page next gets rendered.
	// Can help to resolve old image-generation bugs.
	public $epochTimestamp = '20120101000000';

	// Path to the EasyTimeline.pl perl file, which is used to actually generate the timelines.
	public $timelineFile;

	// Font name.
	// Documentation on how Ploticus handles fonts is available at
	// http://ploticus.sourceforge.net/doc/fonts.html section "What fonts are available?"
	// and below. If using a TrueType font, the file with .ttf extension
	// must be available in path specified by environment variable $GDFONTPATH;
	// some other font types are available (see the docs linked above).
	//
	// Use the fontname 'ascii' to use the internal Ploticus font that does not require
	// an external font file. Defaults to FreeSans for backwards compatibility.
	//
	// Note: according to Ploticus docs, font names with a space may be problematic.
	public $fontFile = 'FreeSans';

	// The name of the FileBackend to use for timeline (see $wgFileBackends)
	public $fileBackend = '';
}
$wgTimelineSettings = new TimelineSettings;
$wgTimelineSettings->ploticusCommand = "/usr/bin/ploticus";
$wgTimelineSettings->perlCommand = "/usr/bin/perl";
$wgTimelineSettings->timelineFile = __DIR__ . "/EasyTimeline.pl";

$wgHooks['ParserFirstCallInit'][] = 'wfTimelineExtension';
$wgMessagesDirs['Timeline'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['Timeline'] = __DIR__ . '/Timeline.i18n.php';

/**
 * @param $parser Parser
 * @return bool
 */
function wfTimelineExtension( &$parser ) {
	$parser->setHook( 'timeline', 'wfRenderTimeline' );
	return true;
}

/**
 * @param $timelinesrc string
 * @param $args array
 * @throws Exception
 * @return string HTML
 */
function wfRenderTimeline( $timelinesrc, array $args ) {
	global $wgUploadDirectory, $wgUploadPath, $wgArticlePath, $wgTmpDirectory, $wgRenderHashAppend;
	global $wgTimelineSettings;

	$method = isset( $args['method'] ) ? $args['method'] : 'ploticusOnly';
	$svg2png = ( $method == 'svg2png' );

	// Get the backend to store plot data and pngs
	if ( $wgTimelineSettings->fileBackend != '' ) {
		$backend = FileBackendGroup::singleton()->get( $wgTimelineSettings->fileBackend );
	} else {
		$backend = new FSFileBackend( array(
			'name'           => 'timeline-backend',
			'wikiId'	 => wfWikiId(),
			'lockManager'    => new NullLockManager( array() ),
			'containerPaths' => array( 'timeline-render' => "{$wgUploadDirectory}/timeline" ),
			'fileMode'       => 0777
		) );
	}

	// Get a hash of the plot data.
	// $args must be checked, because the same source text may be used with
	// with different args.
	$hash = md5( $timelinesrc . implode( '', $args ) );
	if ( $wgRenderHashAppend != '' ) {
		$hash = md5( $hash . $wgRenderHashAppend );
	}

	// Storage destination path (excluding file extension)
	$fname = 'mwstore://' . $backend->getName() . "/timeline-render/$hash";

	$previouslyFailed = $backend->fileExists( array( 'src' => "{$fname}.err" ) );
	$previouslyRendered = $backend->fileExists( array( 'src' => "{$fname}.png" ) );
	if ( $previouslyRendered ) {
		$timestamp = $backend->getFileTimestamp( array( 'src' => "{$fname}.png" ) );
		$expired = ( $timestamp < $wgTimelineSettings->epochTimestamp );
	} else {
		$expired = false;
	}

	// Create a new .map, .png (or .gif), and .err file as needed...
	if ( $expired || ( !$previouslyRendered && !$previouslyFailed ) ) {
		if ( !is_dir( $wgTmpDirectory ) ) {
			mkdir( $wgTmpDirectory, 0777 );
		}
		$tmpFile = TempFSFile::factory( 'timeline_' );
		if ( $tmpFile ) {
			$tmpPath = $tmpFile->getPath();
			file_put_contents( $tmpPath, $timelinesrc ); // store plot data to file

			$filesCollect = array(); // temp files to clean up
			foreach ( array( 'map', 'png', 'svg', 'err' ) as $ext ) {
				$fileCollect = new TempFSFile( "{$tmpPath}.{$ext}" );
				$fileCollect->autocollect(); // clean this up
				$filesCollect[] = $fileCollect;
			}

			// Get command for ploticus to read the user input and output an error,
			// map, and rendering (png or gif) file under the same dir as the temp file.
			$cmdline = wfEscapeShellArg( $wgTimelineSettings->perlCommand, $wgTimelineSettings->timelineFile ) .
			($svg2png ? " -s " : "") .
			" -i " . wfEscapeShellArg( $tmpPath ) .
			" -m -P " . wfEscapeShellArg( $wgTimelineSettings->ploticusCommand ) .
			" -T " . wfEscapeShellArg( $wgTmpDirectory ) .
			" -A " . wfEscapeShellArg( $wgArticlePath ) .
			" -f " . wfEscapeShellArg( $wgTimelineSettings->fontFile );

			// Actually run the command...
			wfDebug( "Timeline cmd: $cmdline\n" );
			$retVal = null;
			$ret = wfShellExec( $cmdline, $retVal );

			// If running in svg2png mode, create the PNG file from the SVG
			if ( $svg2png ) {
				// Read the default timeline image size from the DVG file
				$svgFilename = "{$tmpPath}.svg";
				wfSuppressWarnings();
				$svgHandle = fopen( $svgFilename, "r" );
				wfRestoreWarnings();
				if ( !$svgHandle ) {
					throw new Exception( "Unable to open file $svgFilename for reading the timeline size" );
				}
				while ( !feof( $svgHandle ) ) {
					$line = fgets( $svgHandle );
					if ( preg_match( '/width="([0-9.]+)" height="([0-9.]+)"/', $line, $matches ) ) {
						$svgWidth = $matches[1];
						$svgHeight = $matches[2];
						break;
					}
				}
				fclose( $svgHandle );

				$svgHandler = new SvgHandler();
				wfDebug( "Rasterizing PNG timeline from SVG $svgFilename, size $svgWidth x $svgHeight\n" );
				$rasterizeResult = $svgHandler->rasterize( $svgFilename, "{$tmpPath}.png", $svgWidth, $svgHeight );
				if ( $rasterizeResult !== true ) {
					return "<div class=\"error\" dir=\"ltr\">FAIL: " . $rasterizeResult->toText() . "</div>";
				}
			}

			// Copy the output files into storage...
			// @TODO: store error files in another container or not at all?
			$ops = array();
			$backend->prepare( array( 'dir' => dirname( $fname ) ) );
			foreach ( array( 'map', 'png', 'err' ) as $ext ) {
				if ( file_exists( "{$tmpPath}.{$ext}" ) ) {
					$ops[] = array( 'op' => 'store',
						'src' => "{$tmpPath}.{$ext}", 'dst' => "{$fname}.{$ext}" );
				}
			}
			if ( !$backend->doQuickOperations( $ops )->isOK() ) {
				return "<div class=\"error\" dir=\"ltr\"><tt>Timeline error. " .
					"Could not store output files</tt></div>"; // ugh
			}
		} else {
			return "<div class=\"error\" dir=\"ltr\"><tt>Timeline error. " .
				"Could not create temp file</tt></div>"; // ugh
		}

		if ( $ret == "" || $retVal > 0 ) {
			// Message not localized, only relevant during install
			return "<div class=\"error\" dir=\"ltr\"><tt>Timeline error. " .
				"Command line was: " . htmlspecialchars( $cmdline ) . "</tt></div>";
		}
	}

	$err = $backend->getFileContents( array( 'src' => "{$fname}.err" ) );

	if ( $err != "" ) {
		// Convert the error from poorly-sanitized HTML to plain text
		$err = strtr( $err, array(
			'</p><p>' => "\n\n",
			'<p>' => '',
			'</p>' => '',
			'<b>' => '',
			'</b>' => '',
			'<br>' => "\n" ) );
		$err = Sanitizer::decodeCharReferences( $err );

		// Now convert back to HTML again
		$encErr = nl2br( htmlspecialchars( $err ) );
		$txt = "<div class=\"error\" dir=\"ltr\"><tt>$encErr</tt></div>";
	} else {
		$map = $backend->getFileContents( array( 'src' => "{$fname}.map" ) );

		$map = str_replace( ' >', ' />', $map );
		$map = "<map name=\"timeline_" . htmlspecialchars( $hash ) . "\">{$map}</map>";
		$map = easyTimelineFixMap( $map );

		$url = "{$wgUploadPath}/timeline/{$hash}.png";
		$txt = $map .
			"<img usemap=\"#timeline_" . htmlspecialchars( $hash ) . "\" " .
			"src=\"" . htmlspecialchars( $url ) . "\">";

		if( $expired ) {
			// Replacing an older file, we may need to purge the old one.
			global $wgUseSquid;
			if( $wgUseSquid ) {
				$u = new SquidUpdate( array( $url ) );
				$u->doUpdate();
			}
		}
	}

	return $txt;
}

/**
 * Do a security check on the image map HTML
 * @param $html string
 * @return string HTML
 */
function easyTimelineFixMap( $html ) {
	global $wgUrlProtocols;
	$doc = new DOMDocument( '1.0', 'UTF-8' );
	wfSuppressWarnings();
	$status = $doc->loadXML( $html );
	wfRestoreWarnings();
	if ( !$status ) {
		 // Load messages only if error occurs
		return '<div class="error">' . wfMessage( 'timeline-invalidmap' )->text() . '</div>';
	}

	$map = $doc->firstChild;
	if ( strtolower( $map->nodeName ) !== 'map' ) {
		 // Load messages only if error occurs
		return '<div class="error">' . wfMessage( 'timeline-invalidmap' )->text() . '</div>';
	}
	$name = $map->attributes->getNamedItem( 'name' )->value;
	$html = Xml::openElement( 'map', array( 'name' => $name ) );

	$allowedAttribs = array( 'shape', 'coords', 'href', 'nohref', 'alt',
		'tabindex', 'title' );
	foreach ( $map->childNodes as $node ) {
		if ( strtolower( $node->nodeName ) !== 'area' ) {
			continue;
		}
		$ok = true;
		$attributes = array();
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
			$html .= "<!-- illegal element removed -->\n";
			continue;
		}

		$html .= Xml::element( 'area', $attributes );
	}
	$html .= '</map>';
	return $html;
}
