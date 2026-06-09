<?php

namespace MediaWiki\Extension\MultiCentralAuth;

use MediaWiki\Linter\LogFormatter as LinterLogFormatter;
use MediaWiki\Log\LogFormatter;
use MediaWiki\Html\Html;

class MCALogFormatter extends LogFormatter {

	/**
	 * @return array
	 */
	protected function getMessageParameters() {
		$params = parent::getMessageParameters();

		// $params[2] is the target user
		// We want to format it as a simple username link without "User:" prefix if possible,
		// but standard LogFormatter uses $3 for formatted target.

		return $params;
	}

	/**
	 * @return array
	 */
	public function getPreloadTitles() {
		return parent::getPreloadTitles();
	}

	/**
	 * Custom formatting for the target
	 * @return string
	 */
	protected function formatTarget() {
		$target = $this->entry->getTarget();
		if ( $target->getNamespace() === NS_USER ) {
			return Html::element( 'a', [
				'href' => $target->getFullURL(),
				'title' => $target->getPrefixedText(),
			], $target->getText() );
		}
		return parent::formatTarget();
	}

	/**
	 * Overwrite to use our custom target formatting in messages
	 * @return array
	 */
	protected function getMessageKeyAndParams() {
		$res = parent::getMessageKeyAndParams();
		$key = $res[0];
		$params = $res[1];

		// In MediaWiki log messages:
		// $1 is performer
		// $2 is performer GENDER
		// $3 is target

		$params[2] = $this->formatTarget();

		return [ $key, $params ];
	}
}
