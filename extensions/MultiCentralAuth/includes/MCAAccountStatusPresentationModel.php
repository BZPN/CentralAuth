<?php

namespace MediaWiki\Extension\MultiCentralAuth;

if ( class_exists( '\MediaWiki\Extension\Echo\PresentationModel\EchoEventPresentationModel' ) ) {
	abstract class ResilientPresentationModel extends \MediaWiki\Extension\Echo\PresentationModel\EchoEventPresentationModel {}
} else {
	abstract class ResilientPresentationModel extends \EchoEventPresentationModel {}
}

class MCAAccountStatusPresentationModel extends ResilientPresentationModel {

	public function getIconType() {
		return 'mca-icon';
	}

	public function getPrimaryLink() {
		return [
			'url' => \MediaWiki\SpecialPage\SpecialPage::getTitleFor( 'CentralAuth' )->getFullURL(),
			'label' => $this->msg( 'mca-notification-link' )->text(),
		];
	}

	public function getHeaderMessage() {
		$type = $this->event->getType();
		$systems = $this->event->getExtraParam( 'systems' );
		if ( $type === 'mca-account-merged' ) {
			$msg = $this->getMessageWithAgent( 'mca-notification-header-merged' );
		} else {
			$msg = $this->getMessageWithAgent( 'mca-notification-header-unmerged' );
		}
		$msg->params( $systems );
		return $msg;
	}

	public function getBodyMessage() {
		$systems = $this->event->getExtraParam( 'systems' );
		if ( $systems ) {
			$msg = new \MediaWiki\Message\RawMessage( '$1' );
			$msg->plaintextParams( $systems );
			return $msg;
		}
		return false;
	}
}
