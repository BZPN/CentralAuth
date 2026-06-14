<?php

namespace MediaWiki\Extension\MultiCentralAuth;

class MCAMergeRequestPresentationModel extends ResilientPresentationModel {

	public function canRender() {
		return (bool)$this->event->getTitle();
	}

	public function getIconType() {
		$type = $this->event->getType();
		if ( $type === 'mca-merge-request-submitted' ) {
			return 'mca-icon-new';
		}
		$status = $this->event->getExtraParam( 'status' );
		if ( $status === 'done' ) {
			return 'mca-icon-approved';
		}
		return 'mca-icon-rejected';
	}

	public function getPrimaryLink() {
		return $this->getPageLink(
			$this->event->getTitle(),
			'',
			true
		);
	}

	public function getSecondaryLinks() {
		return [ $this->getAgentLink() ];
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
			$msg = $this->msg( 'mca-notification-body' );
			$msg->params( $requestId );
			return $msg;
		}
		return false;
	}
}
