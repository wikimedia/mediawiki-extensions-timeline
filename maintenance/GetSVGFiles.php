<?php

/**
 * @license GPL-2.0-or-later
 * @file
 * @ingroup Maintenance
 */

namespace MediaWiki\Extension\Timeline;

use MediaWiki\Maintenance\Maintenance;

if ( getenv( 'MW_INSTALL_PATH' ) ) {
	$IP = getenv( 'MW_INSTALL_PATH' );
} else {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

/**
 * @ingroup Maintenance
 */
class GetSVGFiles extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			"Gets a count of the number of SVG files and optionally writes them to disk"
		);
		$this->addOption(
			'date',
			'Get SVG files that were created after this date (e.g. 20170101000000)',
			true,
			true
		);

		$this->addOption(
			'outputdir',
			'Saves SVG files matching date to this directory',
			false,
			true
		);
		$this->requireExtension( 'EasyTimeline' );
	}

	public function execute() {
		$backend = Timeline::getBackend();
		$baseStoragePath = $backend->getContainerStoragePath( 'timeline-render' );

		$files = $backend->getFileList( [ 'dir' => $baseStoragePath, 'adviseStat' => true ] );
		$count = iterator_count( $files );
		$this->output( "Total files (all extensions): $count\n" );

		$date = $this->getOption( 'date' );

		$targetFiles = [];

		foreach ( $files as $file ) {
			$fullPath = $baseStoragePath . '/' . $file;

			if (
				pathinfo( $file, PATHINFO_EXTENSION ) === 'svg' &&
				$backend->getFileTimestamp( [ 'src' => $fullPath ] ) >= $date
			) {
				$targetFiles[] = $fullPath;
			}
		}

		$targetFileCount = count( $targetFiles );

		$this->output( "{$targetFileCount} svg files created on or after {$date}\n" );

		if ( $this->hasOption( 'outputdir' ) ) {
			$outputDir = $this->getOption( 'outputdir' );
			$this->output( "Outputting svg files to {$outputDir}:\n" );

			$count = 0;
			foreach ( array_chunk( $targetFiles, 1000 ) as $chunk ) {
				$fileContents = $backend->getFileContentsMulti(
					[
						'srcs' => $chunk,
						'parallelize' => true,
					]
				);

				foreach ( $fileContents as $path => $contents ) {
					$pathNoPrefix = str_replace( $baseStoragePath . '/', '', $path );
					wfMkdirParents( $outputDir . '/' . dirname( $pathNoPrefix ) );
					file_put_contents( $outputDir . '/' . $pathNoPrefix, $contents );
				}
				$count += count( $chunk );
				$this->output( "$count...\n" );
			}

			$this->output( "Done!\n" );
		}
	}

}

$maintClass = GetSVGFiles::class;
require_once RUN_MAINTENANCE_IF_MAIN;
