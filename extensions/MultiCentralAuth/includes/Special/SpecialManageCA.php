<?php

namespace MediaWiki\Extension\MultiCentralAuth\Special;

use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use Wikimedia\Rdbms\IConnectionProvider;
use MediaWiki\Html\Html;

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
		$this->getOutput()->addModuleStyles( [
			'mediawiki.codex.messagebox.styles',
			'oojs-ui-core.styles',
			'oojs-ui-widgets.styles',
			'ext.multicentralauth.styles'
		] );

		$user = $this->getUser();
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$removeWiki = $this->getRequest()->getVal( 'remove' );
		if ( $removeWiki ) {
			$dbw->delete(
				'mca_local_attachments',
				[
					'mla_user_id' => $user->getId(),
					'mla_wiki_id' => $removeWiki,
				],
				__METHOD__
			);
			$this->getOutput()->addHTML( Html::successBox( $this->msg( 'mca-manage-success', $removeWiki )->parse() ) );
		}

		$attachedWikis = $dbw->newSelectQueryBuilder()
			->select( 'mla_wiki_id' )
			->from( 'mca_local_attachments' )
			->where( [ 'mla_user_id' => $user->getId() ] )
			->fetchFieldValues();

		// Instructions
		$this->getOutput()->addHTML( $this->getFramedFieldsetLayout(
			Html::element( 'p', [], $this->msg( 'mca-manage-instructions' )->text() ),
			'manageca'
		) );

		// Current wikis list
		$listHtml = '';
		if ( $attachedWikis ) {
			$listHtml = Html::openElement( 'ul' );
			foreach ( $attachedWikis as $wiki ) {
				$removeLink = Html::element( 'a', [
					'href' => $this->getPageTitle()->getLocalURL( [ 'remove' => $wiki ] ),
					'style' => 'color: #d33; margin-left: 1em;'
				], $this->msg( 'mca-manage-remove' )->text() );

				$listHtml .= Html::rawElement( 'li', [], htmlspecialchars( $wiki ) . $removeLink );
			}
			$listHtml .= Html::closeElement( 'ul' );
		} else {
			$listHtml = Html::element( 'p', [], $this->msg( 'mca-manage-no-wikis' )->text() );
		}

		$this->getOutput()->addHTML( $this->getFramedFieldsetLayout( $listHtml, 'mca-manage-current-wikis' ) );

		$formDescriptor = [
			'wiki_id' => [
				'type' => 'text',
				'name' => 'wiki_id',
				'label-message' => 'mca-manage-wiki-id',
				'required' => true,
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setSubmitCallback( [ $this, 'onSubmit' ] );
		$htmlForm->setSubmitTextMsg( 'mca-manage-action-add' );

		// Capture form output
		$htmlForm->prepareForm();
		$status = $htmlForm->tryAuthorizedSubmit();
		$this->getOutput()->addHTML( $this->getFramedFieldsetLayout( $htmlForm->getHTML( $status ), 'mca-manage-action-add' ) );
	}

	public function onSubmit( array $formData ) {
		$wikiId = $formData['wiki_id'];
		$user = $this->getUser();
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->insert(
			'mca_local_attachments',
			[
				'mla_user_id' => $user->getId(),
				'mla_wiki_id' => $wikiId,
			],
			__METHOD__,
			[ 'IGNORE' ]
		);

		$this->getOutput()->addHTML( Html::successBox( $this->msg( 'mca-manage-success', $wikiId )->parse() ) );
		return true;
	}

	private function getFramedFieldsetLayout( $html, $legendMsg ): string {
		$label = $this->msg( $legendMsg )->text();
		return Html::rawElement( 'div', [ 'class' => 'mw-htmlform-ooui-wrapper oo-ui-panelLayout-framed oo-ui-panelLayout-padded' ],
			Html::rawElement( 'fieldset', [ 'class' => 'oo-ui-fieldsetLayout' ],
				Html::element( 'legend', [ 'class' => 'oo-ui-fieldsetLayout-header' ], $label ) .
				Html::rawElement( 'div', [ 'class' => 'oo-ui-fieldsetLayout-group' ], $html )
			)
		);
	}
}
