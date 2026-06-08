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
		$farms = $this->externalCAProvider->getFarms();

		$formDescriptor = [
			'local_user' => [
				'type' => 'user',
				'name' => 'local_user',
				'label-message' => 'mca-merge-local-user',
				'required' => true,
				'default' => $targetUser,
				'readonly' => (bool)$requestId,
			],
		];

		foreach ( $farms as $farm ) {
			$id = $farm['id'];
			$formDescriptor["{$id}_user"] = [
				'type' => 'text',
				'name' => "{$id}_user",
				'label' => "{$farm['name']} username",
				'default' => $this->getRequest()->getText( "{$id}User" ),
			];
		}

		$formDescriptor['comment'] = [
			'type' => 'text',
			'name' => 'comment',
			'label-message' => 'mca-comment',
			'required' => true,
			'default' => $requestId ? "Merging... per request [[Special:CAMergeRequestQueue/view/$requestId|$requestId]]" : '',
			'readonly' => (bool)$requestId,
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setSubmitCallback( [ $this, 'onSubmit' ] );
		$htmlForm->show();
	}

	public function onSubmit( array $formData ) {
		$localUserName = $formData['local_user'];
		$comment = $formData['comment'];
		$farms = $this->externalCAProvider->getFarms();

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

		$logData = [];
		foreach ( $farms as $farm ) {
			$id = $farm['id'];
			$extUser = $formData["{$id}_user"] ?? null;
			if ( $extUser ) {
				$dbw->upsert(
					'mca_external_userids',
					[
						'meu_user_id' => $localUserId,
						'meu_farm_id' => $id,
						'meu_external_username' => $extUser,
					],
					[ 'meu_user_id', 'meu_farm_id' ],
					[ 'meu_external_username' => $extUser ],
					__METHOD__
				);
				$logData[] = "$id: $extUser";
			}
		}

		// Log action
		$targetUser = \MediaWiki\MediaWikiServices::getInstance()->getUserFactory()->newFromName( $localUserName );
		if ( $targetUser ) {
			$logEntry = new \LogPage( 'mca-log' );
			$logEntry->addEntry(
				'merge',
				$targetUser->getUserPage(),
				$comment,
				[ implode( ', ', $logData ), $this->getUser()->getName() ],
				$this->getUser()
			);
		}

		$this->getOutput()->addHTML( Html::successBox( $this->msg( 'mca-merge-success', $localUserName )->parse() ) );
		return true;
	}
}
