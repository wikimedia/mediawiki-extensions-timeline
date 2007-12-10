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

$wgExtensionFunctions[] = "wfTimelineExtension";
$wgExtensionCredits['parserhook'][] = array(
	'name' => 'EasyTimeline',
	'version'     => '1.1',
	'author' => 'Erik Zachte',
	'url' => 'http://meta.wikimedia.org/wiki/EasyTimeline',
	'description' => 'Timeline extension',
);

function wfTimelineExtension() {
	global $wgParser;
	$wgParser->setHook( "timeline", "renderTimeline" );
}

function renderTimeline( $timelinesrc )
{
	global $wgUploadDirectory, $wgUploadPath, $IP, $wgTimelineSettings, $wgArticlePath, $wgTmpDirectory;
	$hash = md5( $timelinesrc );
	$dest = $wgUploadDirectory."/timeline/";
	if ( ! is_dir( $dest ) ) { mkdir( $dest, 0777 ); }
	if ( ! is_dir( $wgTmpDirectory ) ) { mkdir( $wgTmpDirectory, 0777 ); }

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
			// Message not localized, only relevant during install
			return "<div id=\"toc\"><tt>Timeline error: Executable not found. Command line was: {$cmdline}</tt></div>";
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
		
		$txt  = "<map name=\"$hash\">{$map}</map>".
		        "<img usemap=\"#{$hash}\" src=\"{$wgUploadPath}/timeline/{$hash}.{$ext}\">";
	}
	return $txt;
}


