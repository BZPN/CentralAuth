<?php

namespace MediaWiki\Extension\MultiCentralAuth\Special;

use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserNameUtils;
use Wikimedia\Rdbms\IConnectionProvider;

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

		$formDescriptor = [
			'local_user' => [
				'type' => 'user',
				'name' => 'local_user',
				'label-message' => 'mca-merge-local-user',
				'required' => true,
			],
			'wm_user' => [
				'type' => 'text',
				'name' => 'wm_user',
				'label-message' => 'mca-merge-wm-user',
				'help-message' => 'mca-merge-wm-user-help',
			],
			'mh_user' => [
				'type' => 'text',
				'name' => 'mh_user',
				'label-message' => 'mca-merge-mh-user',
				'help-message' => 'mca-merge-mh-user-help',
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setSubmitCallback( [ $this, 'onSubmit' ] );
		$htmlForm->show();
	}

	public function onSubmit( array $formData ) {
		$localUserName = $formData['local_user'];
		$wmUserName = $formData['wm_user'] ?: null;
		$mhUserName = $formData['mh_user'] ?: null;

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

		return true;
	}
}
