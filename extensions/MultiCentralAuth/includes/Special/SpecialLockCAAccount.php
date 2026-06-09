<?php

namespace MediaWiki\Extension\MultiCentralAuth\Special;

use MediaWiki\Block\BlockManager;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserNameUtils;
use MediaWiki\User\UserFactory;
use Wikimedia\Rdbms\IConnectionProvider;
use MediaWiki\Html\Html;
use MediaWiki\Utils\MWTimestamp;
use MediaWiki\MainConfigNames;

class SpecialLockCAAccount extends SpecialPage {

	private IConnectionProvider $dbProvider;
	private UserNameUtils $userNameUtils;
	private BlockManager $blockManager;
	private UserFactory $userFactory;

	public function __construct(
		IConnectionProvider $dbProvider,
		UserNameUtils $userNameUtils,
		BlockManager $blockManager,
		UserFactory $userFactory
	) {
		parent::__construct( 'LockCAAccount', 'ca-lock' );
		$this->dbProvider = $dbProvider;
		$this->userNameUtils = $userNameUtils;
		$this->blockManager = $blockManager;
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
			'expiry' => [
				'type' => 'expiry',
				'name' => 'expiry',
				'label-message' => 'mca-lock-expiry',
				'required' => true,
			],
			'reason' => [
				'type' => 'selectandother',
				'name' => 'reason',
				'label-message' => 'mca-lock-reason',
				'options-message' => 'mca-lock-reason-dropdown',
			],
			'unmerge' => [
				'type' => 'check',
				'name' => 'unmerge',
				'label-message' => 'mca-lock-unmerge',
				'default' => false,
			],
			'blockips' => [
				'type' => 'check',
				'name' => 'blockips',
				'label-message' => 'mca-lock-blockips',
				'default' => false,
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setSubmitCallback( [ $this, 'onSubmit' ] );
		$htmlForm->setSubmitTextMsg( 'lockcaaccount' );
		$htmlForm->show();
	}

	public function onSubmit( array $formData ) {
		$targetName = $formData['target'];
		$expiry = $formData['expiry'];
		$reason = $formData['reason'];
		$unmerge = $formData['unmerge'];
		$blockips = $formData['blockips'];

		$user = $this->userFactory->newFromName( $targetName );
		if ( !$user || !$user->isRegistered() ) {
			return [ 'mca-error-user-not-found' ];
		}

		$dbw = $this->dbProvider->getPrimaryDatabase();

		// Check if already locked
		$exists = $dbw->newSelectQueryBuilder()
			->select( 'mcl_id' )
			->from( 'mca_locks' )
			->where( [ 'mcl_user_id' => $user->getId() ] )
			->andWhere( 'mcl_expiry > ' . $dbw->addQuotes( $dbw->timestamp() ) . ' OR mcl_expiry = ' . $dbw->addQuotes( 'infinity' ) )
			->fetchField();

		if ( $exists ) {
			$dbw->delete( 'mca_locks', [ 'mcl_user_id' => $user->getId() ], __METHOD__ );
		}

		$dbw->insert(
			'mca_locks',
			[
				'mcl_user_id' => $user->getId(),
				'mcl_reason' => $reason,
				'mcl_expiry' => $dbw->encodeExpiry( $expiry ),
				'mcl_by' => $this->getUser()->getId(),
				'mcl_timestamp' => $dbw->timestamp(),
			],
			__METHOD__
		);

		if ( $unmerge ) {
			$dbw->delete( 'mca_external_userids', [ 'meu_user_id' => $user->getId() ], __METHOD__ );
			if ( $dbw->tableExists( 'mca_external_ids' ) ) {
				$dbw->delete( 'mca_external_ids', [ 'mei_user_id' => $user->getId() ], __METHOD__ );
			}
		}

		if ( $blockips ) {
			$this->blockUserIPs( $user, $reason );
		}

		// Log action
		$logEntry = new \ManualLogEntry( 'mca-log', 'lock' );
		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setTarget( $user->getUserPage() );
		$logEntry->setComment( $reason );
		$logEntry->setParameters( [
			'4::expiry' => $expiry,
		] );
		$logId = $logEntry->insert();
		$logEntry->publish( $logId );

		$this->getOutput()->addHTML( Html::successBox( $this->msg( 'mca-lock-success', $targetName )->parse() ) );
		return true;
	}

	private function blockUserIPs( $user, $reason ) {
		$dbr = $this->dbProvider->getReplicaDatabase();
		$res = $dbr->newSelectQueryBuilder()
			->select( 'rc_ip' )
			->from( 'recentchanges' )
			->where( [ 'rc_user' => $user->getId() ] )
			->groupBy( 'rc_ip' )
			->fetchResultSet();

		foreach ( $res as $row ) {
			$ip = $row->rc_ip;
			if ( !$ip ) continue;

			$block = new DatabaseBlock( [
				'address' => $ip,
				'by' => $this->getUser()->getId(),
				'reason' => $reason,
				'expiry' => 'infinity',
				'isHardblock' => true,
				'isCreateAccountBlocked' => true,
			] );

			$this->blockManager->placeBlock( $block );
		}
	}
}
