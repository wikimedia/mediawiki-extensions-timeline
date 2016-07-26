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
	'license-name' => 'GPL-2.0',
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

$wgHooks['ParserFirstCallInit'][] = 'Timeline::onParserFirstCallInit';
$wgMessagesDirs['Timeline'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['Timeline'] = __DIR__ . '/Timeline.i18n.php';
$wgAutoloadClassp['Timeline'] = __DIR__ . '/Timeline.body.php';

$wgResourceModules['ext.timeline.styles'] = array(
	'localBasePath' => __DIR__,
	'remoteExtPath' => 'timeline',
	'styles' => array(
		'resources/ext.timeline.styles/timeline.css',
	),
	'position' => 'top',
	'targets' => array( 'mobile', 'desktop' ),
);

