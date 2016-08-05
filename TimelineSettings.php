<?php

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
