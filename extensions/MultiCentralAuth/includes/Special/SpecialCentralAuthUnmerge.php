<?php

namespace MediaWiki\Extension\MultiCentralAuth\Special;

use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserNameUtils;
use Wikimedia\Rdbms\IConnectionProvider;
use MediaWiki\Html\Html;

class SpecialCentralAuthUnmerge extends SpecialPage {

	private IConnectionProvider $dbProvider;
	private UserNameUtils $userNameUtils;

	public function __construct(
		IConnectionProvider $dbProvider,
		UserNameUtils $userNameUtils
	) {
		parent::__construct( 'CentralAuthUnmerge', 'ca-merge' );
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
				'label-message' => 'mca-unmerge-local-user',
				'required' => true,
			],
			'comment' => [
				'type' => 'text',
				'name' => 'comment',
				'label-message' => 'mca-comment',
				'required' => true,
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setSubmitCallback( [ $this, 'onSubmit' ] );
		$htmlForm->show();
	}

	public function onSubmit( array $formData ) {
		$localUserName = $formData['local_user'];
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

		$dbw->delete(
			'mca_external_ids',
			[ 'mei_user_id' => $localUserId ],
			__METHOD__
		);

		// Log action
		$targetUser = \MediaWiki\MediaWikiServices::getInstance()->getUserFactory()->newFromName( $localUserName );
		if ( $targetUser ) {
			$logEntry = new \LogPage( 'mca-log' );
			$logEntry->addEntry(
				'unmerge',
				$targetUser->getUserPage(),
				$comment,
				[ $this->getUser()->getName() ],
				$this->getUser()
			);
		}

		$this->getOutput()->addHTML( Html::successBox( $this->msg( 'mca-unmerge-success', $localUserName )->parse() ) );
		return true;
	}
}
