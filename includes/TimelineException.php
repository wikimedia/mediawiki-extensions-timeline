<?php

namespace MediaWiki\Extension\Timeline;

use Exception;
use MediaWiki\Html\Html;
use MediaWiki\Page\PageReferenceValue;

class TimelineException extends Exception {
	/**
	 * @param string $message Message key
	 * @param array $args message arguments (optional)
	 */
	public function __construct(
		$message,
		private readonly array $args = []
	) {
		parent::__construct( $message );
	}

	/**
	 * Key for use in statsd metrics
	 */
	public function getStatsdKey(): string {
		// Normalize message key into _ for statsd
		return str_replace( '-', '_', $this->getMessage() );
	}

	/**
	 * Get the exception as localized HTML
	 *
	 * TODO: inject context?
	 */
	public function getHtml(): string {
		return Html::rawElement(
			'div',
			[ 'class' => [ 'error', 'timeline-error' ] ],
			wfMessage( $this->message, ...$this->args )
				->inContentLanguage()
				->page( PageReferenceValue::localReference( NS_SPECIAL, 'Badtitle' ) )
				->parse()
		);
	}
}
