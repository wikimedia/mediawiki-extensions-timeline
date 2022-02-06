<?php

namespace MediaWiki\Extension\Timeline;

use Exception;
use Html;
use Title;

/**
 * Timeline exception
 */
class TimelineException extends Exception {

	/** @var array */
	private $args;

	/**
	 * @param string $message Message key
	 * @param array $args message arguments (optional)
	 */
	public function __construct( $message, array $args = [] ) {
		parent::__construct( $message );
		$this->args = $args;
	}

	/**
	 * Key for use in statsd metrics
	 *
	 * @return string
	 */
	public function getStatsdKey(): string {
		// Normalize message key into _ for statsd
		return str_replace( '-', '_', $this->getMessage() );
	}

	/**
	 * Get the exception as localized HTML
	 *
	 * TODO: inject context?
	 *
	 * @return string
	 */
	public function getHtml() {
		return Html::rawElement(
			'div',
			[ 'class' => [ 'error', 'timeline-error' ] ],
			wfMessage( $this->message, ...$this->args )
				->inContentLanguage()
				->title( Title::makeTitle( NS_SPECIAL, 'Badtitle' ) )
				->parse()
		);
	}
}
