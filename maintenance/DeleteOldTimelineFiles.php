<?php
/**
 * @license GPL-2.0-or-later
 * @file
 * @ingroup Maintenance
 */

namespace MediaWiki\Extension\Timeline;

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Status\Status;

if ( getenv( 'MW_INSTALL_PATH' ) ) {
	$IP = getenv( 'MW_INSTALL_PATH' );
} else {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

/**
 * Maintenance script that deletes old timeline files from storage
 *
 * @ingroup Maintenance
 */
class DeleteOldTimelineFiles extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( "Deletes old score files from storage" );
		$this->addOption(
			'date',
			'Delete EasyTimeline files that were created before this date (e.g. 20170101000000)',
			true,
			true
		);
		$this->addOption( 'dry-run', 'Do not actually delete files' );
		$this->requireExtension( "EasyTimeline" );
	}

	public function execute() {
		$backend = Timeline::getBackend();
		$dir = $backend->getContainerStoragePath( 'timeline-render' );

		$dryRun = $this->hasOption( 'dry-run' );

		$filesToDelete = [];
		$deleteDate = $this->getOption( 'date' );
		foreach (
			$backend->getFileList( [ 'dir' => $dir, 'adviseStat' => true ] ) as $file
		) {
			$fullPath = $dir . '/' . $file;
			$timestamp = $backend->getFileTimestamp( [ 'src' => $fullPath ] );
			if ( $timestamp < $deleteDate ) {
				if ( $dryRun ) {
					$this->output(
						"{$fullPath} {$timestamp}\n"
					);
				}
				$filesToDelete[] = [ 'op' => 'delete', 'src' => $fullPath, ];
			}
		}

		$count = count( $filesToDelete );

		if ( !$count ) {
			$this->output( "No old EasyTimeline files to delete!\n" );
			return;
		}

		$this->output( "$count old EasyTimeline files to be deleted.\n" );
		if ( $dryRun ) {
			$this->output( "Dry run, not deleting files.\n" );
			return;
		}

		$deletedCount = 0;
		foreach ( array_chunk( $filesToDelete, 1000 ) as $chunk ) {
			$ret = $backend->doQuickOperations( $chunk );

			if ( $ret->isOK() ) {
				$deletedCount += count( $chunk );
				$this->output( "$deletedCount...\n" );
			} else {
				$status = Status::wrap( $ret );
				$this->output( "Deleting old EasyTimeline files errored.\n" );
				$this->error( $status->getWikiText( false, false, 'en' ) );
			}
		}

		$this->output( "$deletedCount old EasyTimeline files deleted.\n" );
	}
}

$maintClass = DeleteOldTimelineFiles::class;
require_once RUN_MAINTENANCE_IF_MAIN;
