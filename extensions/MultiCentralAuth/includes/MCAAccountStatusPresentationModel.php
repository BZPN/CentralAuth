<?php

namespace MediaWiki\Extension\MultiCentralAuth;

class MCAAccountStatusPresentationModel extends ResilientPresentationModel {

	public function canRender() {
		return $this->event->getExtraParam( 'systems' ) !== null;
	}

	public function getIconType() {
		return 'mca-icon';
	}

	public function getPrimaryLink() {
		return $this->getPageLink(
			\MediaWiki\SpecialPage\SpecialPage::getTitleFor( 'CentralAuth' ),
			'',
			true
		);
	}

	public function getSecondaryLinks() {
		return [ $this->getAgentLink() ];
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
			return $this->msg( 'mca-notification-body-plain' )->plaintextParams( $systems );
		}
		return false;
	}
}
