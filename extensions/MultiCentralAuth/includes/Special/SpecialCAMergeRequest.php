<?php

namespace MediaWiki\Extension\MultiCentralAuth\Special;

use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Html\Html;
use MediaWiki\Extension\MultiCentralAuth\ExternalCAProvider;
use Wikimedia\Rdbms\IConnectionProvider;

class SpecialCAMergeRequest extends SpecialPage {

	private IConnectionProvider $dbProvider;
	private ExternalCAProvider $externalCAProvider;

	public function __construct( IConnectionProvider $dbProvider, ExternalCAProvider $externalCAProvider ) {
		parent::__construct( 'CAMergeRequest', 'ca-request' );
		$this->dbProvider = $dbProvider;
		$this->externalCAProvider = $externalCAProvider;
	}

	public function execute( $subpage ) {
		$this->checkPermissions();
		$this->setHeaders();

		$user = $this->getUser();
		$formDescriptor = [
			'username' => [
				'type' => 'text',
				'label-message' => 'mca-request-username',
				'default' => $user->getName(),
				'readonly' => true,
			],
			'farm' => [
				'type' => 'radio',
				'label-message' => 'mca-request-farm',
				'options' => [
					'Wikimedia' => 'wm',
					'Miraheze' => 'mh',
					'Both' => 'both',
				],
				'required' => true,
			],
			'wm_user' => [
				'type' => 'text',
				'label' => $this->msg( 'mca-request-farm-user', 'Wikimedia' )->text(),
			],
			'wm_diff' => [
				'type' => 'text',
				'label' => $this->msg( 'mca-request-farm-diff', 'Wikimedia' )->text(),
				'help' => $this->msg( 'mca-request-farm-diff-help', 'Wikimedia' )->text(),
			],
			'mh_user' => [
				'type' => 'text',
				'label' => $this->msg( 'mca-request-farm-user', 'Miraheze' )->text(),
			],
			'mh_diff' => [
				'type' => 'text',
				'label' => $this->msg( 'mca-request-farm-diff', 'Miraheze' )->text(),
				'help' => $this->msg( 'mca-request-farm-diff-help', 'Miraheze' )->text(),
			],
		];

		$formDescriptor['comment'] = [
			'type' => 'textarea',
			'label-message' => 'mca-comment',
			'rows' => 3,
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setSubmitCallback( [ $this, 'onSubmit' ] );
		$htmlForm->show();
	}

	public function onSubmit( array $data ) {
		$selectedFarmId = $data['farm'];
		$externalData = [];

		if ( $selectedFarmId === 'both' ) {
			if ( empty( $data['wm_user'] ) || empty( $data['wm_diff'] ) ) {
				return [ $this->msg( 'mca-error-farm-required', 'Wikimedia' ) ];
			}
			if ( empty( $data['mh_user'] ) || empty( $data['mh_diff'] ) ) {
				return [ $this->msg( 'mca-error-farm-required', 'Miraheze' ) ];
			}
			$externalData['wm'] = [ 'user' => $data['wm_user'], 'diff' => $data['wm_diff'] ];
			$externalData['mh'] = [ 'user' => $data['mh_user'], 'diff' => $data['mh_diff'] ];
		} else {
			$farmName = $selectedFarmId === 'wm' ? 'Wikimedia' : 'Miraheze';
			if ( empty( $data["{$selectedFarmId}_user"] ) || empty( $data["{$selectedFarmId}_diff"] ) ) {
				return [ $this->msg( 'mca-error-farm-required', $farmName ) ];
			}
			$externalData[$selectedFarmId] = [
				'user' => $data["{$selectedFarmId}_user"],
				'diff' => $data["{$selectedFarmId}_diff"]
			];
		}

		$dbw = $this->dbProvider->getPrimaryDatabase();
		$id = $this->generateUniqueId( $dbw );

		$dbw->insert( 'mca_merge_requests', [
			'mmr_id' => $id,
			'mmr_user_id' => $this->getUser()->getId(),
			'mmr_farm' => $selectedFarmId,
			'mmr_external_data' => json_encode( $externalData ),
			'mmr_comment' => $data['comment'],
			'mmr_status' => 'open',
			'mmr_timestamp' => $dbw->timestamp(),
		], __METHOD__ );

		// Hook for notifications
		\MediaWiki\MediaWikiServices::getInstance()->getHookContainer()->run( 'MCAMergeRequestSubmitted', [ $id, $this->getUser() ] );

		$this->getOutput()->addHTML( Html::successBox( $this->msg( 'mca-request-success', $id )->parse() ) );
		return true;
	}

	private function generateUniqueId( $dbw ) {
		do {
			$id = mt_rand( 10000000, 99999999 );
			$exists = $dbw->newSelectQueryBuilder()
				->select( 'mmr_id' )
				->from( 'mca_merge_requests' )
				->where( [ 'mmr_id' => $id ] )
				->fetchField();
		} while ( $exists );
		return $id;
	}
}
