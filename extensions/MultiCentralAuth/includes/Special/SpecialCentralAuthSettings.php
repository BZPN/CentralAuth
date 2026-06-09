<?php

namespace MediaWiki\Extension\MultiCentralAuth\Special;

use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Html\Html;
use Wikimedia\Rdbms\IConnectionProvider;

class SpecialCentralAuthSettings extends SpecialPage {

	private IConnectionProvider $dbProvider;

	public function __construct( IConnectionProvider $dbProvider ) {
		parent::__construct( 'CentralAuthSettings', 'ca-admin' );
		$this->dbProvider = $dbProvider;
	}

	public function execute( $subpage ) {
		$this->checkPermissions();
		$this->setHeaders();
		$this->getOutput()->addModuleStyles( [ 'mediawiki.ui.button', 'mediawiki.ui.input', 'mediawiki.ui.vform' ] );

		if ( $subpage === 'add' ) {
			$this->showAddForm();
		} elseif ( $subpage === 'edit' ) {
			$this->showEditForm();
		} elseif ( $subpage === 'delete' ) {
			$this->showDeleteConfirm();
		} else {
			$this->showFarmList();
		}
	}

	private function showFarmList() {
		$dbr = $this->dbProvider->getReplicaDatabase();
		$farms = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'mca_farms' )
			->fetchResultSet();

		$this->getOutput()->addHTML( Html::element( 'a', [
			'href' => $this->getPageTitle( 'add' )->getFullURL(),
			'class' => 'mw-ui-button mw-ui-progressive'
		], $this->msg( 'mca-settings-add-farm' )->text() ) );

		$table = Html::openElement( 'table', [ 'class' => 'wikitable', 'style' => 'width: 100%; margin-top: 1em;' ] );
		$table .= Html::openElement( 'tr' ) .
			Html::element( 'th', [], $this->msg( 'mca-settings-id' )->text() ) .
			Html::element( 'th', [], $this->msg( 'mca-settings-name' )->text() ) .
			Html::element( 'th', [], $this->msg( 'mca-settings-api-url' )->text() ) .
			Html::element( 'th', [], $this->msg( 'mca-settings-centralauth' )->text() ) .
			Html::element( 'th', [], $this->msg( 'mca-settings-actions' )->text() ) .
			Html::closeElement( 'tr' );

		foreach ( $farms as $farm ) {
			$table .= Html::openElement( 'tr' ) .
				Html::element( 'td', [], $farm->mf_id ) .
				Html::element( 'td', [], $farm->mf_display_name ) .
				Html::element( 'td', [], $farm->mf_api_url ) .
				Html::element( 'td', [], $farm->mf_is_centralauth ? 'Yes' : 'No' ) .
				Html::rawElement( 'td', [],
					Html::element( 'a', [
						'href' => $this->getPageTitle( 'edit' )->getFullURL( [ 'id' => $farm->mf_id ] ),
						'class' => 'mw-ui-button mw-ui-progressive'
					], $this->msg( 'mca-settings-edit' )->text() ) .
					Html::element( 'a', [
						'href' => $this->getPageTitle( 'delete' )->getFullURL( [ 'id' => $farm->mf_id ] ),
						'class' => 'mw-ui-button mw-ui-destructive',
						'style' => 'margin-left: 0.5em;'
					], $this->msg( 'mca-settings-delete' )->text() )
				) .
				Html::closeElement( 'tr' );
		}
		$table .= Html::closeElement( 'table' );

		$this->getOutput()->addHTML( $table );
	}

	private function showAddForm() {
		$formDescriptor = [
			'id' => [
				'type' => 'text',
				'label-message' => 'mca-settings-farm-id',
				'required' => true,
			],
			'name' => [
				'type' => 'text',
				'label-message' => 'mca-settings-display-name',
				'required' => true,
			],
			'api_url' => [
				'type' => 'text',
				'label-message' => 'mca-settings-api-url-label',
			],
			'is_centralauth' => [
				'type' => 'check',
				'label-message' => 'mca-settings-centralauth-label',
			],
			'header_msg' => [
				'type' => 'text',
				'label-message' => 'mca-settings-header-msg-label',
			],
			'wikis' => [
				'type' => 'textarea',
				'label-message' => 'mca-settings-wikis-label',
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setSubmitCallback( [ $this, 'onAddSubmit' ] );
		$htmlForm->show();
	}

	public function onAddSubmit( array $data ) {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->insert( 'mca_farms', [
			'mf_id' => $data['id'],
			'mf_display_name' => $data['name'],
			'mf_api_url' => $data['api_url'],
			'mf_is_centralauth' => $data['is_centralauth'],
			'mf_header_msg' => $data['header_msg'],
		], __METHOD__, [ 'IGNORE' ] );

		$wikis = explode( "\n", str_replace( "\r", "", $data['wikis'] ) );
		$insertWikis = [];
		foreach ( $wikis as $wiki ) {
			$wiki = trim( $wiki );
			if ( $wiki ) {
				$insertWikis[] = [
					'mfw_farm_id' => $data['id'],
					'mfw_wiki_id' => strtolower( $wiki ),
				];
			}
		}

		if ( $insertWikis ) {
			$dbw->insert( 'mca_farm_wikis', $insertWikis, __METHOD__, [ 'IGNORE' ] );
		}

		$this->getOutput()->redirect( $this->getPageTitle()->getFullURL() );
		return true;
	}

	private function showEditForm() {
		$id = $this->getRequest()->getText( 'id' );
		$dbr = $this->dbProvider->getReplicaDatabase();
		$farm = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'mca_farms' )
			->where( [ 'mf_id' => $id ] )
			->fetchRow();

		if ( !$farm ) {
			$this->getOutput()->redirect( $this->getPageTitle()->getFullURL() );
			return;
		}

		$wikis = $dbr->newSelectQueryBuilder()
			->select( 'mfw_wiki_id' )
			->from( 'mca_farm_wikis' )
			->where( [ 'mfw_farm_id' => $id ] )
			->fetchFieldValues();

		$formDescriptor = [
			'id' => [
				'type' => 'info',
				'label-message' => 'mca-settings-farm-id',
				'default' => $id,
			],
			'hidden_id' => [
				'type' => 'hidden',
				'name' => 'id',
				'default' => $id,
			],
			'name' => [
				'type' => 'text',
				'label-message' => 'mca-settings-display-name',
				'default' => $farm->mf_display_name,
				'required' => true,
			],
			'api_url' => [
				'type' => 'text',
				'label-message' => 'mca-settings-api-url-label',
				'default' => $farm->mf_api_url,
			],
			'is_centralauth' => [
				'type' => 'check',
				'label-message' => 'mca-settings-centralauth-label',
				'default' => (bool)$farm->mf_is_centralauth,
			],
			'header_msg' => [
				'type' => 'text',
				'label-message' => 'mca-settings-header-msg-label',
				'default' => $farm->mf_header_msg,
			],
			'wikis' => [
				'type' => 'textarea',
				'label-message' => 'mca-settings-wikis-label',
				'default' => implode( "\n", $wikis ),
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setSubmitCallback( [ $this, 'onEditSubmit' ] );
		$htmlForm->setSubmitTextMsg( 'mca-settings-edit' );
		$htmlForm->show();
	}

	public function onEditSubmit( array $data ) {
		$id = $data['id'];
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->update( 'mca_farms', [
			'mf_display_name' => $data['name'],
			'mf_api_url' => $data['api_url'],
			'mf_is_centralauth' => $data['is_centralauth'],
			'mf_header_msg' => $data['header_msg'],
		], [ 'mf_id' => $id ], __METHOD__ );

		$dbw->delete( 'mca_farm_wikis', [ 'mfw_farm_id' => $id ], __METHOD__ );

		$wikis = explode( "\n", str_replace( "\r", "", $data['wikis'] ) );
		$insertWikis = [];
		foreach ( $wikis as $wiki ) {
			$wiki = trim( $wiki );
			if ( $wiki ) {
				$insertWikis[] = [
					'mfw_farm_id' => $id,
					'mfw_wiki_id' => strtolower( $wiki ),
				];
			}
		}

		if ( $insertWikis ) {
			$dbw->insert( 'mca_farm_wikis', $insertWikis, __METHOD__, [ 'IGNORE' ] );
		}

		$this->getOutput()->redirect( $this->getPageTitle()->getFullURL() );
		return true;
	}

	private function showDeleteConfirm() {
		$id = $this->getRequest()->getText( 'id' );
		if ( !$id ) {
			$this->getOutput()->redirect( $this->getPageTitle()->getFullURL() );
			return;
		}

		$formDescriptor = [
			'id' => [
				'type' => 'hidden',
				'default' => $id,
				'name' => 'id',
			],
			'confirm_msg' => [
				'type' => 'info',
				'default' => "Are you sure you want to delete farm '$id'?",
			]
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setSubmitText( 'Confirm Delete' );
		$htmlForm->setSubmitDestructive( true );
		$htmlForm->setSubmitCallback( [ $this, 'onDeleteSubmit' ] );
		$htmlForm->show();
	}

	public function onDeleteSubmit( array $data ) {
		$id = $data['id'];
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->delete( 'mca_farms', [ 'mf_id' => $id ], __METHOD__ );
		$dbw->delete( 'mca_farm_wikis', [ 'mfw_farm_id' => $id ], __METHOD__ );

		$this->getOutput()->redirect( $this->getPageTitle()->getFullURL() );
		return true;
	}
}
