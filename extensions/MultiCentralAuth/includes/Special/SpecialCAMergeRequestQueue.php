<?php

namespace MediaWiki\Extension\MultiCentralAuth\Special;

use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Html\Html;
use MediaWiki\User\UserFactory;
use Wikimedia\Rdbms\IConnectionProvider;

class SpecialCAMergeRequestQueue extends SpecialPage {

	private IConnectionProvider $dbProvider;
	private UserFactory $userFactory;

	public function __construct( IConnectionProvider $dbProvider, UserFactory $userFactory ) {
		parent::__construct( 'CAMergeRequestQueue', 'ca-request-view' );
		$this->dbProvider = $dbProvider;
		$this->userFactory = $userFactory;
	}

	public function execute( $subpage ) {
		$this->setHeaders();
		$this->getOutput()->addModuleStyles( [ 'mediawiki.ui.input', 'mediawiki.ui.button', 'mediawiki.ui.vform' ] );

		$parts = explode( '/', $subpage );
		if ( isset( $parts[0] ) && $parts[0] === 'view' && isset( $parts[1] ) ) {
			$this->showRequest( (int)$parts[1] );
			return;
		}

		$this->showQueue();
	}

	private function showQueue() {
		$searchUser = $this->getRequest()->getText( 'mca_user' );

		// Search form
		$form = Html::openElement( 'form', [ 'method' => 'get', 'action' => $this->getPageTitle()->getLocalURL() ] );
		$form .= Html::rawElement( 'div', [ 'class' => 'mw-ui-vform' ],
			Html::rawElement( 'div', [ 'class' => 'mw-ui-field' ],
				Html::element( 'label', [], "Search by user:" ) .
				Html::element( 'input', [ 'name' => 'mca_user', 'class' => 'mw-ui-input', 'value' => $searchUser ] )
			) .
			Html::submitButton( "Search", [ 'class' => 'mw-ui-button mw-ui-progressive' ] )
		);
		$form .= Html::closeElement( 'form' );
		$this->getOutput()->addHTML( $form );

		$dbr = $this->dbProvider->getReplicaDatabase();
		$queryBuilder = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'mca_merge_requests' );

		// Security: non-admins only see their own requests
		if ( !$this->getAuthority()->isAllowed( 'ca-merge' ) ) {
			$queryBuilder->where( [ 'mmr_user_id' => $this->getUser()->getId() ] );
		}

		if ( $searchUser ) {
			$user = $this->userFactory->newFromName( $searchUser );
			if ( $user && $user->isRegistered() ) {
				$queryBuilder->where( [ 'mmr_user_id' => $user->getId() ] );
			} else {
				$queryBuilder->where( [ 'mmr_user_id' => 0 ] ); // Return nothing
			}
		}

		// Status order: open first, then others.
		$rows = $queryBuilder
			->orderBy( "CASE WHEN mmr_status = 'open' THEN 0 ELSE 1 END", 'ASC' )
			->orderBy( 'mmr_timestamp', 'DESC' )
			->fetchResultSet();

		$table = Html::openElement( 'table', [ 'class' => 'wikitable sortable', 'style' => 'width: 100%; margin-top: 1em;' ] );
		$table .= Html::openElement( 'tr' ) .
			Html::element( 'th', [], $this->msg( 'mca-request-id' )->text() ) .
			Html::element( 'th', [], $this->msg( 'mca-request-user' )->text() ) .
			Html::element( 'th', [], $this->msg( 'mca-request-status' )->text() ) .
			Html::element( 'th', [], $this->msg( 'mca-request-date' )->text() ) .
			Html::element( 'th', [], $this->msg( 'mca-request-action' )->text() ) .
			Html::closeElement( 'tr' );

		foreach ( $rows as $row ) {
			$user = $this->userFactory->newFromId( $row->mmr_user_id );
			$table .= Html::openElement( 'tr' ) .
				Html::element( 'td', [], $row->mmr_id ) .
				Html::element( 'td', [], $user ? $user->getName() : 'Unknown' ) .
				Html::element( 'td', [], $row->mmr_status ) .
				Html::element( 'td', [], $this->getLanguage()->userTimeAndDate( $row->mmr_timestamp, $this->getUser() ) ) .
				Html::rawElement( 'td', [], Html::element( 'a', [
					'href' => $this->getPageTitle( "view/{$row->mmr_id}" )->getFullURL()
				], 'View' ) ) .
				Html::closeElement( 'tr' );
		}
		$table .= Html::closeElement( 'table' );

		$this->getOutput()->addHTML( $table );
	}

	private function showRequest( int $id ) {
		$dbr = $this->dbProvider->getReplicaDatabase();
		$row = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'mca_merge_requests' )
			->where( [ 'mmr_id' => $id ] )
			->fetchRow();

		if ( !$row ) {
			$this->getOutput()->addHTML( Html::errorBox( 'Request not found.' ) );
			return;
		}

		// Security: requester can only see their own requests if not admin
		if ( !$this->getAuthority()->isAllowed( 'ca-merge' ) && $row->mmr_user_id !== $this->getUser()->getId() ) {
			$this->checkPermissions();
			return;
		}

		$user = $this->userFactory->newFromId( $row->mmr_user_id );
		$extData = json_decode( $row->mmr_external_data, true ) ?: [];

		$fields = [
			'Request ID' => $row->mmr_id,
			'User' => $user ? $user->getName() : 'Unknown',
			'Farm Selection' => $row->mmr_farm,
			'Status' => $row->mmr_status,
			'Comment' => $row->mmr_comment,
		];

		foreach ( $extData as $farmId => $data ) {
			$fields["Farm $farmId User"] = $data['user'] ?? '';
			$fields["Farm $farmId Diff"] = $data['diff'] ?? '';
		}

		$html = Html::openElement( 'div', [ 'class' => 'mw-htmlform-ooui-wrapper oo-ui-panelLayout-framed oo-ui-panelLayout-padded' ] );
		$html .= Html::element( 'h2', [ 'class' => 'mca-box-header' ], $this->msg( 'mca-request-details-title' )->text() );
		$html .= Html::openElement( 'ul' );
		foreach ( $fields as $label => $val ) {
			$html .= Html::rawElement( 'li', [], "'''$label''': " . htmlspecialchars( $val ) );
		}
		$html .= Html::closeElement( 'ul' );
		$html .= Html::closeElement( 'div' );

		$this->getOutput()->addHTML( $html );

		if ( $row->mmr_status === 'open' && $this->getAuthority()->isAllowed( 'ca-merge' ) ) {
			$this->showActionForm( $row );
		}
	}

	private function showActionForm( $row ) {
		$formDescriptor = [
			'status' => [
				'type' => 'select',
				'label' => 'Action',
				'options' => [
					'Done' => 'done',
					'Rejected' => 'rejected',
				],
			],
			'comment' => [
				'type' => 'textarea',
				'label' => 'Comment',
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setSubmitCallback( function( $data ) use ( $row ) {
			$dbw = $this->dbProvider->getPrimaryDatabase();
			$dbw->update( 'mca_merge_requests', [
				'mmr_status' => $data['status'],
				'mmr_closed_by' => $this->getUser()->getId(),
				'mmr_closed_comment' => $data['comment'],
				'mmr_closed_timestamp' => $dbw->timestamp(),
			], [ 'mmr_id' => $row->mmr_id ], __METHOD__ );

			$requester = $this->userFactory->newFromId( $row->mmr_user_id );
			\MediaWiki\MediaWikiServices::getInstance()->getHookContainer()->run( 'MCAMergeRequestResolved', [ $row->mmr_id, $requester, $data['status'] ] );

			if ( $data['status'] === 'done' ) {
				$user = $this->userFactory->newFromId( $row->mmr_user_id );
				$extData = json_decode( $row->mmr_external_data, true ) ?: [];

				$params = [
					'requestId' => $row->mmr_id,
					'targetUser' => $user ? $user->getName() : '',
				];
				foreach ( $extData as $farmId => $data ) {
					$params["{$farmId}User"] = $data['user'];
				}

				$this->getOutput()->redirect( SpecialPage::getTitleFor( 'CentralAuthMerge' )->getFullURL( $params ) );
			} else {
				$this->getOutput()->redirect( $this->getPageTitle()->getFullURL() );
			}
			return true;
		} );
		$htmlForm->show();
	}
}
