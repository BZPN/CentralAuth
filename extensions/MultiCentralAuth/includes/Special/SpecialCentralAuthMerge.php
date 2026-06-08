<?php

namespace MediaWiki\Extension\MultiCentralAuth\Special;

use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserNameUtils;
use Wikimedia\Rdbms\IConnectionProvider;
use MediaWiki\Html\Html;

class SpecialCentralAuthMerge extends SpecialPage {

	private IConnectionProvider $dbProvider;
	private UserNameUtils $userNameUtils;

	public function __construct(
		IConnectionProvider $dbProvider,
		UserNameUtils $userNameUtils
	) {
		parent::__construct( 'CentralAuthMerge', 'ca-merge' );
		$this->dbProvider = $dbProvider;
		$this->userNameUtils = $userNameUtils;
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
				'default' => $requestId ? "Merging... per request [[Special:CAMergeRequestQueue/view/$requestId|$requestId]]" : '',
				'readonly' => (bool)$requestId,
			],
		];

		// Handle other dynamic farms if passed via query params
		$params = $this->getRequest()->getValues();
		foreach ( $params as $key => $val ) {
			if ( str_ends_with( $key, 'User' ) && $key !== 'targetUser' && $key !== 'wmUser' && $key !== 'mhUser' ) {
				$farmId = substr( $key, 0, -4 );
				$formDescriptor[$key] = [
					'type' => 'text',
					'name' => $key,
					'label' => ucfirst( $farmId ) . " username",
					'default' => $val,
				];
			}
		}

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setSubmitCallback( [ $this, 'onSubmit' ] );
		$htmlForm->show();
	}

	public function onSubmit( array $formData ) {
		$localUserName = $formData['local_user'];
		$wmUserName = $formData['wm_user'] ?: null;
		$mhUserName = $formData['mh_user'] ?: null;
		$comment = $formData['comment'];

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

		$dbw->upsert(
			'mca_external_ids',
			[
				'mei_user_id' => $localUserId,
				'mei_wm_username' => $wmUserName,
				'mei_mh_username' => $mhUserName,
			],
			[ 'mei_user_id' ],
			[
				'mei_wm_username' => $wmUserName,
				'mei_mh_username' => $mhUserName,
			],
			__METHOD__
		);

		// Log action
		$targetUser = \MediaWiki\User\User::newFromName( $localUserName );
		if ( $targetUser ) {
			$logEntry = new \LogPage( 'mca-log' );
			$logEntry->addEntry(
				'merge',
				$targetUser->getUserPage(),
				$comment,
				[ $wmUserName ?? '', $mhUserName ?? '', $this->getUser()->getName() ],
				$this->getUser()
			);
		}

		$this->getOutput()->addHTML( Html::successBox( $this->msg( 'mca-merge-success', $localUserName )->parse() ) );
		return true;
	}
}
