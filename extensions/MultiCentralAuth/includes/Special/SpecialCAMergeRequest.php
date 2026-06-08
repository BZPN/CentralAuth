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
		$farms = $this->externalCAProvider->getFarms();

		$farmOptions = [];
		foreach ( $farms as $farm ) {
			$farmOptions[$farm['name']] = $farm['id'];
		}
		$farmOptions['Both'] = 'both';

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
				'options' => $farmOptions,
				'required' => true,
			],
		];

		foreach ( $farms as $farm ) {
			$id = $farm['id'];
			$name = $farm['name'];
			$formDescriptor["{$id}_user"] = [
				'type' => 'text',
				'label' => "$name username",
			];
			$formDescriptor["{$id}_diff"] = [
				'type' => 'text',
				'label' => "$name confirmation edit link",
				'help' => "From the account you want to link, perform an edit on any wiki of the $name farm with content confirming ownership of the account on this wiki in English and paste the diff here.",
			];
		}

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
		$farms = $this->externalCAProvider->getFarms();
		$externalData = [];

		if ( $selectedFarmId === 'both' ) {
			foreach ( $farms as $farm ) {
				$id = $farm['id'];
				if ( empty( $data["{$id}_user"] ) || empty( $data["{$id}_diff"] ) ) {
					return [ "mca-error-{$id}-required" ];
				}
				$externalData[$id] = [
					'user' => $data["{$id}_user"],
					'diff' => $data["{$id}_diff"]
				];
			}
		} else {
			if ( empty( $data["{$selectedFarmId}_user"] ) || empty( $data["{$selectedFarmId}_diff"] ) ) {
				return [ "mca-error-{$selectedFarmId}-required" ];
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
