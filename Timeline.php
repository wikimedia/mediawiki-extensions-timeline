<?php

function renderTimeline( $timelinesrc )
{
	global $wgUploadDirectory, $IP, $wgPloticusCommand, $wgScript;
	$hash = md5( $timelinesrc );
	$fname = $wgUploadDirectory."/timeline/".$hash;

	if ( ! ( file_exists( $fname.".png" ) || file_exists( $fname.".err" ) ) )
	{
		$handle = fopen($fname, "w");
		fwrite($handle, $timelinesrc);
		fclose($handle);

		if ( $wgPloticusCommand == "" )
		{
			$wgPloticusCommand = "/usr/bin/ploticus";
		}
		$ret = `{$IP}/extensions/timeline/EasyTimeline.pl -i {$fname} -m -P {$wgPloticusCommand} -T /tmp -A {$wgScript}`;

		unlink($fname);

		if ( $ret == "" ) {
			// Message not localized, only relevant during install
			return "<div id=\"toc\"><tt>Timeline error: Executable not found</tt></div>";
		}

	}

	@$err=file_get_contents( $fname.".err" );
	if ( $err != "" ) {
		$txt = "<div id=\"toc\"><tt>$err</tt></div>";
	} else {
		@$map = file_get_contents( $fname.".map" );
		$txt  = "<map name=\"$hash\">{$map}</map>".
		        "<img usemap=\"#{$hash}\" src=\"/images/timeline/{$hash}.png\">";
	}
	return $txt;
}

?>
