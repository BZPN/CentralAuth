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
			Html::element( 'th', [], 'ID' ) .
			Html::element( 'th', [], 'Name' ) .
			Html::element( 'th', [], 'API URL' ) .
			Html::element( 'th', [], 'CentralAuth' ) .
			Html::element( 'th', [], 'Actions' ) .
			Html::closeElement( 'tr' );

		foreach ( $farms as $farm ) {
			$table .= Html::openElement( 'tr' ) .
				Html::element( 'td', [], $farm->mf_id ) .
				Html::element( 'td', [], $farm->mf_display_name ) .
				Html::element( 'td', [], $farm->mf_api_url ) .
				Html::element( 'td', [], $farm->mf_is_centralauth ? 'Yes' : 'No' ) .
				Html::rawElement( 'td', [], Html::element( 'a', [
					'href' => $this->getPageTitle( 'delete' )->getFullURL( [ 'id' => $farm->mf_id ] ),
					'class' => 'mw-ui-button mw-ui-destructive'
				], 'Delete' ) ) .
				Html::closeElement( 'tr' );
		}
		$table .= Html::closeElement( 'table' );

		$this->getOutput()->addHTML( $table );
	}

	private function showAddForm() {
		$formDescriptor = [
			'id' => [
				'type' => 'text',
				'label' => 'Farm ID (e.g. fandom)',
				'required' => true,
			],
			'name' => [
				'type' => 'text',
				'label' => 'Display Name',
				'required' => true,
			],
			'api_url' => [
				'type' => 'text',
				'label' => 'API URL',
			],
			'is_centralauth' => [
				'type' => 'check',
				'label' => 'Is CentralAuth enabled?',
			],
			'header_msg' => [
				'type' => 'text',
				'label' => 'Header message key',
			],
			'wikis' => [
				'type' => 'textarea',
				'label' => 'Wikis (one per line, hostnames)',
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
