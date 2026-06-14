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
		$status = $this->event->getExtraParam( 'status' );
		// $1 agent name, $2 agent gender, $3 status
		$msg->params( $this->msg( "mca-request-status-$status" ) );
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
