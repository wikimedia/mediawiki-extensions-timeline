<?php

# Timeline extension
# To use, include this file from your LocalSettings.php
# To configure, set members of $wgTimelineSettings after the inclusion

class TimelineSettings {
	var $ploticusCommand, $perlCommand;
};
$wgTimelineSettings = new TimelineSettings;
$wgTimelineSettings->ploticusCommand = "/usr/bin/ploticus";
$wgTimelineSettings->perlCommand = "/usr/bin/perl";

if ( defined( 'MW_SUPPORTS_PARSERFIRSTCALLINIT' ) ) {
	$wgHooks['ParserFirstCallInit'][] = 'wfTimelineExtension';
} else {
	$wgExtensionFunctions[] = 'wfTimelineExtension';
}

$wgExtensionCredits['parserhook'][] = array(
	'name'           => 'EasyTimeline',
	'author'         => 'Erik Zachte',
	'url'            => 'http://www.mediawiki.org/wiki/Extension:EasyTimeline',
	'svn-date' => '$LastChangedDate$',
	'svn-revision' => '$LastChangedRevision$',
	'description'    => 'Timeline extension',
	'descriptionmsg' => 'timeline-desc',
);
$wgExtensionMessagesFiles['Timeline'] = dirname(__FILE__) . '/Timeline.i18n.php';

function wfTimelineExtension() {
	global $wgParser;
	$wgParser->setHook( "timeline", "renderTimeline" );
	return true;
}

function renderTimeline( $timelinesrc )
{
	global $wgUploadDirectory, $wgUploadPath, $IP, $wgTimelineSettings, $wgArticlePath, $wgTmpDirectory;
	$hash = md5( $timelinesrc );
	$dest = $wgUploadDirectory."/timeline/";
	wfLoadExtensionMessages('Timeline');
	if ( ! is_dir( $dest ) ) { wfMkdirParents( $dest, 0777 ); }
	if ( ! is_dir( $wgTmpDirectory ) ) { wfMkdirParents( $wgTmpDirectory, 0777 ); }

	$fname = $dest . $hash;
	if ( ! ( file_exists( $fname.".png" ) || file_exists( $fname.".err" ) ) )
	{
		$handle = fopen($fname, "w");
		fwrite($handle, $timelinesrc);
		fclose($handle);

		$cmdline = wfEscapeShellArg( $wgTimelineSettings->perlCommand, $IP . "/extensions/timeline/EasyTimeline.pl" ) .
		  " -i " . wfEscapeShellArg( $fname ) . " -m -P " . wfEscapeShellArg( $wgTimelineSettings->ploticusCommand ) .
		  " -T " . wfEscapeShellArg( $wgTmpDirectory ) . " -A " . wfEscapeShellArg( $wgArticlePath );

		$ret = `{$cmdline}`;

		unlink($fname);

		if ( $ret == "" ) {
			$error = htmlspecialchars( wfMsg( 'timeline-install-error', $cmdline ) );
			return "<div id=\"toc\"><tt>$error</tt></div>";
		}

	}

	@$err=file_get_contents( $fname.".err" );

	if ( $err != "" ) {
		$txt = "<div id=\"toc\"><tt>$err</tt></div>";
	} else {
		@$map = file_get_contents( $fname.".map" );

		if (substr(php_uname(), 0, 7) == "Windows") {
			$ext = "gif";
		} else {
			$ext = "png";
		}

		$txt  = "<map id=\"timeline_$hash\">{$map}</map>".
		        "<img usemap=\"#timeline_{$hash}\" src=\"{$wgUploadPath}/timeline/{$hash}.{$ext}\" />";
	}
	return $txt;
}
