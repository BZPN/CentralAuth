<?php

namespace MediaWiki\Extension\MultiCentralAuth\Special;

use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserNameUtils;
use MediaWiki\User\UserFactory;
use Wikimedia\Rdbms\IConnectionProvider;
use MediaWiki\Html\Html;

class SpecialUnlockCAAccount extends SpecialPage {

	private IConnectionProvider $dbProvider;
	private UserNameUtils $userNameUtils;
	private UserFactory $userFactory;

	public function __construct(
		IConnectionProvider $dbProvider,
		UserNameUtils $userNameUtils,
		UserFactory $userFactory
	) {
		parent::__construct( 'UnlockCAAccount', 'ca-lock' );
		$this->dbProvider = $dbProvider;
		$this->userNameUtils = $userNameUtils;
		$this->userFactory = $userFactory;
	}

	public function execute( $subpage ) {
		$this->checkPermissions();
		$this->setHeaders();

		$target = $this->getRequest()->getText( 'target', $subpage );

		$formDescriptor = [
			'target' => [
				'type' => 'user',
				'name' => 'target',
				'label-message' => 'mca-target-label',
				'required' => true,
				'default' => $target,
			],
			'reason' => [
				'type' => 'text',
				'name' => 'reason',
				'label-message' => 'mca-comment',
				'required' => true,
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setSubmitCallback( [ $this, 'onSubmit' ] );
		$htmlForm->setSubmitTextMsg( 'unlockcaaccount' );
		$htmlForm->show();
	}

	public function onSubmit( array $formData ) {
		$targetName = $formData['target'];
		$reason = $formData['reason'];

		$dbw = $this->dbProvider->getPrimaryDatabase();
		$userRow = $dbw->newSelectQueryBuilder()
			->select( 'user_id' )
			->from( 'user' )
			->where( [ 'user_name' => $targetName ] )
			->fetchRow();

		if ( !$userRow ) {
			return [ 'mca-error-user-not-found' ];
		}

		$dbw->delete(
			'mca_locks',
			[ 'mcl_user_id' => $userRow->user_id ],
			__METHOD__
		);

		// Log action
		$targetUser = $this->userFactory->newFromName( $targetName );
		if ( $targetUser ) {
			$logEntry = new \ManualLogEntry( 'mca-log', 'unlock' );
			$logEntry->setPerformer( $this->getUser() );
			$logEntry->setTarget( $targetUser->getUserPage() );
			$logEntry->setComment( $reason );
			$logId = $logEntry->insert();
			$logEntry->publish( $logId );
		}

		$this->getOutput()->addHTML( Html::successBox( $this->msg( 'mca-unlock-success', $targetName )->parse() ) );
		return true;
	}
}
