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

		$parts = explode( '/', $subpage );
		if ( isset( $parts[0] ) && $parts[0] === 'view' && isset( $parts[1] ) ) {
			$this->showRequest( (int)$parts[1] );
			return;
		}

		$this->showQueue();
	}

	private function showQueue() {
		if ( !$this->getAuthority()->isAllowed( 'ca-merge' ) ) {
			$this->checkPermissions();
		}
		$dbr = $this->dbProvider->getReplicaDatabase();
		// Status order: open first, then others.
		$rows = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'mca_merge_requests' )
			->orderBy( "CASE WHEN mmr_status = 'open' THEN 0 ELSE 1 END", 'ASC' )
			->orderBy( 'mmr_user_id', 'ASC' )
			->orderBy( 'mmr_timestamp', 'DESC' )
			->fetchResultSet();

		$table = Html::openElement( 'table', [ 'class' => 'wikitable sortable', 'style' => 'width: 100%;' ] );
		$table .= Html::openElement( 'tr' ) .
			Html::element( 'th', [], 'ID' ) .
			Html::element( 'th', [], 'User' ) .
			Html::element( 'th', [], 'Status' ) .
			Html::element( 'th', [], 'Date' ) .
			Html::element( 'th', [], 'Action' ) .
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

		$extDataHtml = '';
		foreach ( $extData as $farmId => $data ) {
			$extDataHtml .= Html::element( 'p', [], "Farm $farmId User: " . ( $data['user'] ?? '' ) );
			$extDataHtml .= Html::element( 'p', [], "Farm $farmId Diff: " . ( $data['diff'] ?? '' ) );
		}

		$this->getOutput()->addHTML( Html::rawElement( 'div', [ 'class' => 'mca-request-details' ],
			Html::element( 'p', [], "Request ID: {$row->mmr_id}" ) .
			Html::element( 'p', [], "User: " . ( $user ? $user->getName() : 'Unknown' ) ) .
			Html::element( 'p', [], "Farm Selection: {$row->mmr_farm}" ) .
			$extDataHtml .
			Html::element( 'p', [], "Comment: {$row->mmr_comment}" ) .
			Html::element( 'p', [], "Status: {$row->mmr_status}" )
		) );

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
