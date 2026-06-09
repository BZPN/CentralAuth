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
			'farm' => [
				'type' => 'radio',
				'name' => 'farm',
				'label-message' => 'mca-unmerge-farm',
				'options' => [
					'Wikimedia' => 'wm',
					'Miraheze' => 'mh',
					'All' => 'all',
				],
				'default' => 'all',
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
		$farm = $formData['farm'];

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

		if ( $farm === 'all' ) {
			$dbw->delete(
				'mca_external_userids',
				[ 'meu_user_id' => $localUserId ],
				__METHOD__
			);
			if ( $dbw->tableExists( 'mca_external_ids' ) ) {
				$dbw->delete(
					'mca_external_ids',
					[ 'mei_user_id' => $localUserId ],
					__METHOD__
				);
			}
		} else {
			$dbw->delete(
				'mca_external_userids',
				[
					'meu_user_id' => $localUserId,
					'meu_farm_id' => $farm,
				],
				__METHOD__
			);
		}

		// Log action
		$targetUser = \MediaWiki\MediaWikiServices::getInstance()->getUserFactory()->newFromName( $localUserName );
		if ( $targetUser ) {
			$logEntry = new \ManualLogEntry( 'mca-log', 'unmerge' );
			$logEntry->setPerformer( $this->getUser() );
			$logEntry->setTarget( $targetUser->getUserPage() );
			$logEntry->setComment( $comment );
			$logEntry->setParameters( [
				'4::systems' => $formData['farm'] ?? 'All'
			] );
			$logEntry->insert();
			$logEntry->publish( $logEntry->insert() );
		}

		$this->getOutput()->addHTML( Html::successBox( $this->msg( 'mca-unmerge-success', $localUserName )->parse() ) );
		return true;
	}
}
