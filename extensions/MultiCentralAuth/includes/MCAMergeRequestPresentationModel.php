<?php

namespace MediaWiki\Extension\MultiCentralAuth;

class MCAMergeRequestPresentationModel extends ResilientPresentationModel {

	public function getIconType() {
		return 'mca-icon';
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
			return $this->getMessageWithAgent( 'mca-notification-header-submitted' );
		}
		$msg = $this->getMessageWithAgent( 'mca-notification-header-resolved' );
		$msg->params( $this->event->getExtraParam( 'status' ) );
		return $msg;
	}

	public function getBodyMessage() {
		$requestId = $this->event->getExtraParam( 'request-id' );
		if ( $requestId ) {
			return $this->msg( 'mca-notification-body', $requestId );
		}
		return false;
	}
}
