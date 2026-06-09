<?php

namespace MediaWiki\Extension\MultiCentralAuth;

use MediaWiki\Logging\LogFormatter;
use MediaWiki\Html\Html;

class MCALogFormatter extends LogFormatter {

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
