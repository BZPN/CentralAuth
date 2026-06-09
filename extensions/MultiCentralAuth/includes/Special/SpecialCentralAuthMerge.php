<?php

namespace MediaWiki\Extension\MultiCentralAuth\Special;

use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserNameUtils;
use MediaWiki\Extension\MultiCentralAuth\ExternalCAProvider;
use Wikimedia\Rdbms\IConnectionProvider;
use MediaWiki\Html\Html;

class SpecialCentralAuthMerge extends SpecialPage {

	private IConnectionProvider $dbProvider;
	private UserNameUtils $userNameUtils;
	private ExternalCAProvider $externalCAProvider;

	public function __construct(
		IConnectionProvider $dbProvider,
		UserNameUtils $userNameUtils,
		ExternalCAProvider $externalCAProvider
	) {
		parent::__construct( 'CentralAuthMerge', 'ca-merge' );
		$this->dbProvider = $dbProvider;
		$this->userNameUtils = $userNameUtils;
		$this->externalCAProvider = $externalCAProvider;
	}

	public function execute( $subpage ) {
		$this->checkPermissions();
		$this->setHeaders();

		$requestId = $this->getRequest()->getText( 'requestId' );
		$targetUser = $this->getRequest()->getText( 'targetUser' );
		$wmUser = $this->getRequest()->getText( 'wmUser' );
		$mhUser = $this->getRequest()->getText( 'mhUser' );

		$formDescriptor = [
			'local_user' => [
				'type' => 'user',
				'name' => 'local_user',
				'label-message' => 'mca-merge-local-user',
				'required' => true,
				'default' => $targetUser,
				'readonly' => (bool)$requestId,
			],
			'wm_user' => [
				'type' => 'text',
				'name' => 'wm_user',
				'label-message' => 'mca-merge-wm-user',
				'help-message' => 'mca-merge-wm-user-help',
				'default' => $wmUser,
			],
			'mh_user' => [
				'type' => 'text',
				'name' => 'mh_user',
				'label-message' => 'mca-merge-mh-user',
				'help-message' => 'mca-merge-mh-user-help',
				'default' => $mhUser,
			],
			'comment' => [
				'type' => 'text',
				'name' => 'comment',
				'label-message' => 'mca-comment',
				'required' => true,
				'default' => $requestId ? $this->msg( 'mca-log-per-request', $requestId )->text() : '',
				'readonly' => (bool)$requestId,
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setSubmitCallback( [ $this, 'onSubmit' ] );
		$htmlForm->show();
	}

	public function onSubmit( array $formData ) {
		$localUserName = $formData['local_user'];
		$comment = $formData['comment'];
		$wmUser = $formData['wm_user'] ?: null;
		$mhUser = $formData['mh_user'] ?: null;

		$user = $this->userNameUtils->getCanonical( $localUserName );
		if ( !$user ) {
			return [ 'mca-error-invalid-user' ];
		}

		$dbw = $this->dbProvider->getPrimaryDatabase();
		$localUserId = $dbw->newSelectQueryBuilder()
			->select( 'user_id' )
			->from( 'user' )
			->where( [ 'user_name' => $localUserName ] )
			->fetchField();

		if ( !$localUserId ) {
			return [ 'mca-error-user-not-found' ];
		}

		$systems = [];
		if ( $wmUser ) {
			$this->saveExternalId( $localUserId, 'wm', $wmUser );
			$systems[] = 'Wikimedia';
		}
		if ( $mhUser ) {
			$this->saveExternalId( $localUserId, 'mh', $mhUser );
			$systems[] = 'Miraheze';
		}

		if ( !$systems ) {
			return [ 'mca-error-no-systems-selected' ];
		}

		// Log action
		$targetUser = \MediaWiki\MediaWikiServices::getInstance()->getUserFactory()->newFromName( $localUserName );
		if ( $targetUser ) {
			$logEntry = new \ManualLogEntry( 'mca-log', 'merge' );
			$logEntry->setPerformer( $this->getUser() );
			$logEntry->setTarget( $targetUser->getUserPage() );
			$logEntry->setComment( $comment );

			$systemList = implode( ' and ', $systems ) . " CentralAuth";
			$logEntry->setParameters( [
				'4::systems' => $systemList,
			] );
			$logId = $logEntry->insert();
			$logEntry->publish( $logId );
		}

		$this->getOutput()->addHTML( Html::successBox( $this->msg( 'mca-merge-success', $localUserName )->parse() ) );
		return true;
	}

	private function saveExternalId( int $userId, string $farmId, string $externalUser ) {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->delete( 'mca_external_userids', [ 'meu_user_id' => $userId, 'meu_farm_id' => $farmId ], __METHOD__ );
		$dbw->insert( 'mca_external_userids', [
			'meu_user_id' => $userId,
			'meu_farm_id' => $farmId,
			'meu_external_username' => $externalUser,
		], __METHOD__ );

		// Clear manual attachments for this farm as they are now redundant
		$wikis = $this->externalCAProvider->getLocalAttachedWikis( $userId, true );
		foreach ( $wikis as $wiki ) {
			if ( $this->externalCAProvider->categorizeWiki( $wiki ) === $farmId ) {
				$dbw->delete( 'mca_local_attachments', [ 'mla_user_id' => $userId, 'mla_wiki_id' => $wiki ], __METHOD__ );
			}
		}
	}
}
