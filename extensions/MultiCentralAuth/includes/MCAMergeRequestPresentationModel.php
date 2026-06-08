<?php

namespace MediaWiki\Extension\MultiCentralAuth;

use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;

class MCAMergeRequestPresentationModel extends EchoEventPresentationModel {

	public function getIconType() {
		return 'feedback';
	}

	public function getPrimaryLink() {
		return [
			'url' => $this->event->getTitle()->getFullURL(),
			'label' => $this->msg( 'mca-notification-link' )->text(),
		];
	}

	public function getHeaderMessage() {
		$type = $this->event->getType();
		if ( $type === 'mca-merge-request-submitted' ) {
			return $this->msg( 'mca-notification-header-submitted', $this->getViewingUserForMessage()->getName() );
		}
		return $this->msg( 'mca-notification-header-resolved', $this->event->getExtraParam( 'status' ) );
	}

	public function getBodyMessage() {
		return $this->msg( 'mca-notification-body', $this->event->getExtraParam( 'request-id' ) );
	}
}
