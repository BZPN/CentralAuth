<?php

namespace MediaWiki\Extension\MultiCentralAuth\Special;

use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use Wikimedia\Rdbms\IConnectionProvider;

class SpecialManageCA extends SpecialPage {

	private IConnectionProvider $dbProvider;

	public function __construct(
		IConnectionProvider $dbProvider
	) {
		parent::__construct( 'ManageCA', 'ca-manage' );
		$this->dbProvider = $dbProvider;
	}

	public function execute( $subpage ) {
		$this->checkPermissions();
		$this->setHeaders();

		$user = $this->getUser();
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$attachedWikis = $dbw->newSelectQueryBuilder()
			->select( 'mla_wiki_id' )
			->from( 'mca_local_attachments' )
			->where( [ 'mla_user_id' => $user->getId() ] )
			->fetchFieldValues();

		$formDescriptor = [
			'wiki_id' => [
				'type' => 'text',
				'name' => 'wiki_id',
				'label-message' => 'mca-manage-wiki-id',
				'required' => true,
			],
			'action' => [
				'type' => 'radio',
				'name' => 'action',
				'options' => [
					$this->msg( 'mca-manage-action-add' )->text() => 'add',
					$this->msg( 'mca-manage-action-remove' )->text() => 'remove',
				],
				'default' => 'add',
			],
		];

		$this->getOutput()->addHTML( '<h3>' . $this->msg( 'mca-manage-current-wikis' )->escaped() . '</h3>' );
		if ( $attachedWikis ) {
			$this->getOutput()->addHTML( '<ul><li>' . implode( '</li><li>', array_map( 'htmlspecialchars', $attachedWikis ) ) . '</li></ul>' );
		} else {
			$this->getOutput()->addWikiMsg( 'mca-manage-no-wikis' );
		}

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setSubmitCallback( [ $this, 'onSubmit' ] );
		$htmlForm->show();
	}

	public function onSubmit( array $formData ) {
		$wikiId = $formData['wiki_id'];
		$action = $formData['action'];
		$user = $this->getUser();
		$dbw = $this->dbProvider->getPrimaryDatabase();

		if ( $action === 'add' ) {
			$dbw->insert(
				'mca_local_attachments',
				[
					'mla_user_id' => $user->getId(),
					'mla_wiki_id' => $wikiId,
				],
				__METHOD__,
				[ 'IGNORE' ]
			);
		} else {
			$dbw->delete(
				'mca_local_attachments',
				[
					'mla_user_id' => $user->getId(),
					'mla_wiki_id' => $wikiId,
				],
				__METHOD__
			);
		}

		return true;
	}
}
