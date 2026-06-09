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
		$this->getOutput()->addModuleStyles( [
			'oojs-ui-core.styles',
			'oojs-ui-widgets.styles',
			'oojs-ui.styles.icons-editing-styling',
			'oojs-ui.styles.icons-moderation',
			'mediawiki.widgets.styles',
			'mediawiki.widgets.DateInputWidget.styles',
			'ext.multicentralauth.styles'
		] );

		$target = $this->getRequest()->getText( 'target', $subpage );

		$this->getOutput()->addHTML( Html::element( 'p', [], $this->msg( 'mca-lock-intro' )->text() ) );

		if ( $this->getRequest()->getVal( 'success' ) ) {
			$this->getOutput()->addHTML( Html::successBox( $this->msg( 'mca-lock-success', $this->getRequest()->getVal( 'success' ) )->parse() ) );
		}

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
				'default' => 'infinite',
				'options' => [
					$this->msg( 'mca-lock-expiry-1hour' )->text() => '1 hour',
					$this->msg( 'mca-lock-expiry-2hours' )->text() => '2 hours',
					$this->msg( 'mca-lock-expiry-1day' )->text() => '1 day',
					$this->msg( 'mca-lock-expiry-3days' )->text() => '3 days',
					$this->msg( 'mca-lock-expiry-1week' )->text() => '1 week',
					$this->msg( 'mca-lock-expiry-1month' )->text() => '1 month',
					$this->msg( 'mca-lock-expiry-3months' )->text() => '3 months',
					$this->msg( 'mca-lock-expiry-1year' )->text() => '1 year',
					$this->msg( 'mca-lock-expiry-2years' )->text() => '2 years',
					$this->msg( 'infiniteblock' )->text() => 'infinite',
				],
				'required' => true,
			],
			'reason' => [
				'type' => 'selectandother',
				'name' => 'reason',
				'label-message' => 'mca-lock-reason',
				'options-message' => 'mca-lock-reason-dropdown',
				'cssclass' => 'mca-mobile-full-width',
				'maxlength' => 255,
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
		$htmlForm->setSubmitDestructive( true );
		$htmlForm->prepareForm();

		$status = $htmlForm->tryAuthorizedSubmit();
		if ( $status === true || ( $status instanceof \StatusValue && $status->isGood() ) ) {
			return;
		}

		$this->getOutput()->addHTML( $this->getFramedFieldsetLayout( $htmlForm->getHTML( $status ), 'lockcaaccount', 'mca-header-type-view' ) );
	}

	public function onSubmit( array $formData ) {
		$targetName = $formData['target'];
		$expiry = $formData['expiry'];
		$reason = $formData['reason'];

		if ( is_array( $reason ) ) {
			$selected = $reason[0] ?? '';
			$other = $reason[1] ?? '';
			if ( $selected === 'other' || $selected === '' ) {
				$reason = $other;
			} else {
				if ( $other !== '' && $other !== $selected ) {
					$reason = $selected . ': ' . $other;
				} else {
					$reason = $selected;
				}
			}
		}

		if ( !$reason ) {
			$reason = 'No reason provided';
		}

		$unmerge = $formData['unmerge'];
		$blockips = $formData['blockips'];

		$user = $this->userFactory->newFromName( $targetName );
		if ( !$user || !$user->isRegistered() ) {
			return \Status::newFatal( 'mca-error-user-not-found' );
		}

		if ( $user->getId() === $this->getUser()->getId() ) {
			return \Status::newFatal( 'mca-lock-error-self' );
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
			return \Status::newFatal( 'mca-lock-already-locked' );
		}

		// Parse expiry
		if ( $expiry === 'infinite' || $expiry === 'indefinite' || $expiry === 'infinity' ) {
			$expiryMW = 'infinity';
		} else {
			$ts = strtotime( $expiry );
			if ( !$ts ) {
				// Try parsing as MW timestamp if it's from the date picker
				try {
					$expiryMW = $dbw->timestamp( $expiry );
				} catch ( \Exception $e ) {
					return \Status::newFatal( 'mca-lock-error-invalid-expiry' );
				}
			} else {
				$expiryMW = $dbw->timestamp( $ts );
			}
		}

		$dbw->insert(
			'mca_locks',
			[
				'mcl_user_id' => $user->getId(),
				'mcl_reason' => $reason,
				'mcl_expiry' => $expiryMW,
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
		$logEntry = new \ManualLogEntry( 'mca-lock-log', 'lock' );
		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setTarget( $user->getUserPage() );
		$logEntry->setComment( $reason );
		$logEntry->setParameters( [
			'4::expiry' => $expiry,
		] );
		$logId = $logEntry->insert();
		$logEntry->publish( $logId );

		$this->getOutput()->redirect( $this->getPageTitle()->getFullURL( [ 'success' => $targetName ] ) );
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

	private function getFramedFieldsetLayout( $html, $legendMsg, $headerClass = '' ): string {
		if ( is_array( $legendMsg ) ) {
			$label = $this->msg( ...$legendMsg )->text();
		} else {
			$label = $this->msg( $legendMsg )->text();
		}

		return Html::rawElement( 'div', [ 'class' => 'mw-htmlform-ooui-wrapper oo-ui-panelLayout-framed oo-ui-panelLayout-padded', 'style' => 'margin-bottom: 1em;' ],
			Html::rawElement( 'h2', [ 'class' => 'mca-box-header ' . $headerClass ], $label ) .
			Html::rawElement( 'div', [ 'class' => 'oo-ui-fieldsetLayout-group' ], $html )
		);
	}
}
