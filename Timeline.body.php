<?php


class Timeline {

	/**
	 * @param $parser Parser
	 * @return bool
	 */
	public static function onParserFirstCallInit( &$parser ) {
		$parser->setHook( 'timeline', 'Timeline::renderTimeline' );

		return true;
	}

	/**
	 * Render timeline and save to file backend
	 *
	 * Files are saved to the file backend $wgTimelineFileBackend if set. Else
	 * default to FSFileBackend named 'timeline-backend'.
	 *
	 * The rendered timeline is saved in the file backend using @see hash() and
	 * will be reused if the hash match. You can invalidate the cache by
	 * setting the global variable $wgRenderHashAppend (default: '').
	 *
	 * @param $timelinesrc string
	 * @param $args array
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @throws Exception
	 * @return string HTML
	 */
	public static function renderTimeline( $timelinesrc, array $args, $parser, $frame ) {
		global $wgUploadDirectory, $wgUploadPath, $wgArticlePath, $wgTmpDirectory;
		global $wgTimelineFileBackend, $wgTimelineEpochTimestamp, $wgTimelinePerlCommand, $wgTimelineFile;
		global $wgTimelineFontFile, $wgTimelinePloticusCommand;

		$parser->getOutput()->addModuleStyles( 'ext.timeline.styles' );

		$method = isset( $args['method'] ) ? $args['method'] : 'ploticusOnly';
		$svg2png = ( $method == 'svg2png' );

		// Get the backend to store plot data and pngs
		if ( $wgTimelineFileBackend != '' ) {
			$backend = FileBackendGroup::singleton()->get( $wgTimelineFileBackend );
		} else {
			$backend = new FSFileBackend(
				[
					'name' => 'timeline-backend',
					'wikiId' => wfWikiID(),
					'lockManager' => new NullLockManager( [] ),
					'containerPaths' => [ 'timeline-render' => "{$wgUploadDirectory}/timeline" ],
					'fileMode' => 0777,
					'obResetFunc' => 'wfResetOutputBuffers',
					'streamMimeFunc' => [ 'StreamFile', 'contentTypeFromPath' ]
				]
			);
		}

		$hash = self::hash( $timelinesrc, $args );

		// Storage destination path (excluding file extension)
		$fname = 'mwstore://' . $backend->getName() . "/timeline-render/$hash";

		$previouslyFailed = $backend->fileExists( [ 'src' => "{$fname}.err" ] );
		$previouslyRendered = $backend->fileExists( [ 'src' => "{$fname}.png" ] );
		if ( $previouslyRendered ) {
			$timestamp = $backend->getFileTimestamp( [ 'src' => "{$fname}.png" ] );
			$expired = ( $timestamp < $wgTimelineEpochTimestamp );
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
				// store plot data to file
				file_put_contents( $tmpPath, $timelinesrc );

				// temp files to clean up
				$filesCollect = [];
				foreach ( [ 'map', 'png', 'svg', 'err' ] as $ext ) {
					$fileCollect = new TempFSFile( "{$tmpPath}.{$ext}" );
					// clean this up
					$fileCollect->autocollect();
					$filesCollect[] = $fileCollect;
				}

				// Get command for ploticus to read the user input and output an error,
				// map, and rendering (png or gif) file under the same dir as the temp file.
				$cmdline = wfEscapeShellArg( $wgTimelinePerlCommand, $wgTimelineFile )
					. ( $svg2png ? " -s " : "" )
					. " -i " . wfEscapeShellArg( $tmpPath )
					. " -m -P " . wfEscapeShellArg( $wgTimelinePloticusCommand )
					. " -T " . wfEscapeShellArg( $wgTmpDirectory )
					. " -A " . wfEscapeShellArg( $wgArticlePath )
					. " -f " . wfEscapeShellArg( $wgTimelineFontFile );

				// Actually run the command...
				wfDebug( "Timeline cmd: $cmdline\n" );
				$retVal = null;
				$ret = wfShellExec( $cmdline, $retVal );

				// If running in svg2png mode, create the PNG file from the SVG
				if ( $svg2png ) {
					// Read the default timeline image size from the DVG file
					$svgFilename = "{$tmpPath}.svg";
					MediaWiki\suppressWarnings();
					$svgHandle = fopen( $svgFilename, "r" );
					MediaWiki\restoreWarnings();
					if ( !$svgHandle ) {
						throw new Exception( "Unable to open file $svgFilename for reading the timeline size" );
					}
					$svgWidth = '';
					$svgHeight = '';
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
					$rasterizeResult = $svgHandler->rasterize(
						$svgFilename,
						"{$tmpPath}.png",
						$svgWidth,
						$svgHeight
					);
					if ( $rasterizeResult !== true ) {
						return "<div class=\"error\" dir=\"ltr\">FAIL: " . $rasterizeResult->toText() . "</div>";
					}
				}

				// Copy the output files into storage...
				// @TODO: store error files in another container or not at all?
				$ops = [];
				$backend->prepare( [ 'dir' => dirname( $fname ) ] );
				foreach ( [ 'map', 'png', 'err' ] as $ext ) {
					if ( file_exists( "{$tmpPath}.{$ext}" ) ) {
						$ops[] = [ 'op' => 'store', 'src' => "{$tmpPath}.{$ext}", 'dst' => "{$fname}.{$ext}" ];
					}
				}
				if ( !$backend->doQuickOperations( $ops )->isOK() ) {
					// FIXME Hard coded english text
					return "<div class=\"error\" dir=\"ltr\"><tt>Timeline error. "
						. "Could not store output files</tt></div>";
				}
			} else {
				// FIXME Hard coded english text
				return "<div class=\"error\" dir=\"ltr\"><tt>Timeline error. "
					. "Could not create temp file</tt></div>";
			}

			if ( $ret == "" || $retVal > 0 ) {
				// Message not localized, only relevant during install
				return "<div class=\"error\" dir=\"ltr\"><tt>Timeline error. "
					. "Command line was: " . htmlspecialchars( $cmdline ) . "</tt></div>";
			}
		}

		$err = $backend->getFileContents( [ 'src' => "{$fname}.err" ] );

		if ( $err != "" ) {
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
			$txt = "<div class=\"error\" dir=\"ltr\"><tt>$encErr</tt></div>";
		} else {
			$map = $backend->getFileContents( [ 'src' => "{$fname}.map" ] );

			$map = str_replace( ' >', ' />', $map );
			$map = "<map name=\"timeline_" . htmlspecialchars( $hash ) . "\">{$map}</map>";
			$map = self::fixMap( $map );

			$url = "{$wgUploadPath}/timeline/{$hash}.png";
			$txt = "<div class=\"timeline-wrapper\">" . $map
				. "<img usemap=\"#timeline_" . htmlspecialchars( $hash )
				. "\" " . "src=\"" . htmlspecialchars( $url ) . "\">" . "</div>";

			if ( $expired ) {
				// Replacing an older file, we may need to purge the old one.
				global $wgUseSquid;
				if ( $wgUseSquid ) {
					$u = new CdnCacheUpdate( [ $url ] );
					$u->doUpdate();
				}
			}
		}

		return $txt;
	}

	/**
	 * Generate a hash of the plot data
	 *
	 * $args must be checked, because the same source text may be used with
	 * different arguments.
	 *
	 * Uses global $wgRenderHashAppend to salt / vary the hash. Will invalidate
	 * the cache as a side effect though old files will be left in the file
	 * backend.
	 *
	 * @param $timelinesrc string
	 * @param $args array
	 * @return string hash
	 */
	public static function hash( $timelinesrc, array $args ) {
		global $wgRenderHashAppend;

		$hash = md5( $timelinesrc . implode( '', $args ) );
		if ( $wgRenderHashAppend != '' ) {
			$hash = md5( $hash . $wgRenderHashAppend );
		}

		return $hash;
	}

	/**
	 * Do a security check on the image map HTML
	 * @param $html string
	 * @return string HTML
	 */
	private static function fixMap( $html ) {
		global $wgUrlProtocols;
		$doc = new DOMDocument( '1.0', 'UTF-8' );
		MediaWiki\suppressWarnings();
		$status = $doc->loadXML( $html );
		MediaWiki\restoreWarnings();
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
		$html = Xml::openElement( 'map', [ 'name' => $name ] );

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
				$html .= "<!-- illegal element removed -->\n";
				continue;
			}

			$html .= Xml::element( 'area', $attributes );
		}
		$html .= '</map>';

		return $html;
	}
}
