<?php

namespace MediaWiki\Extension\MultiCentralAuth\Special;

use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use Wikimedia\Rdbms\IConnectionProvider;
use MediaWiki\Html\Html;
use MediaWiki\Utils\MWTimestamp;

class SpecialCALockList extends SpecialPage {

	private IConnectionProvider $dbProvider;
	private UserFactory $userFactory;

	public function __construct(
		IConnectionProvider $dbProvider,
		UserFactory $userFactory
	) {
		parent::__construct( 'CALockList' );
		$this->dbProvider = $dbProvider;
		$this->userFactory = $userFactory;
	}

	public function execute( $subpage ) {
		$this->setHeaders();
		$this->getOutput()->addModuleStyles( [
			'oojs-ui-core.styles',
			'oojs-ui-widgets.styles',
			'mediawiki.widgets.styles',
			'ext.multicentralauth.styles'
		] );

		if ( $subpage && strpos( $subpage, 'view/' ) === 0 ) {
			$this->showDetails( (int)substr( $subpage, 5 ) );
			return;
		}

		$this->showSearchForm();

		$userTarget = $this->getRequest()->getText( 'wpTarget' );
		$sort = $this->getRequest()->getText( 'sort', 'timestamp' );
		$order = $this->getRequest()->getText( 'order', 'DESC' );

		$dbr = $this->dbProvider->getReplicaDatabase();
		$queryBuilder = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'mca_locks' );

		if ( $userTarget ) {
			$targetUser = $this->userFactory->newFromName( $userTarget );
			if ( $targetUser && $targetUser->isRegistered() ) {
				$queryBuilder->where( [ 'mcl_user_id' => $targetUser->getId() ] );
			} else {
				$queryBuilder->where( [ 'mcl_user_id' => 0 ] ); // Force empty result if user not found
			}
		}

		$sortMap = [
			'timestamp' => 'mcl_timestamp',
			'expiry' => 'mcl_expiry',
			'id' => 'mcl_id',
		];
		$orderBy = $sortMap[$sort] ?? 'mcl_timestamp';
		$queryBuilder->orderBy( $orderBy, $order );

		$res = $queryBuilder->fetchResultSet();

		$html = Html::openElement( 'table', [ 'class' => 'wikitable sortable', 'style' => 'width: 100%;' ] );
		$html .= Html::openElement( 'thead' ) . Html::openElement( 'tr' );
		foreach ( [ 'id', 'user', 'reason', 'expiry', 'by', 'timestamp', 'status', 'actions' ] as $col ) {
			$label = $this->msg( "mca-lock-list-$col" )->text();
			if ( isset( $sortMap[$col] ) ) {
				$newOrder = ( $sort === $col && $order === 'DESC' ) ? 'ASC' : 'DESC';
				$label = Html::element( 'a', [
					'href' => $this->getPageTitle()->getFullURL( [
						'wpTarget' => $userTarget,
						'sort' => $col,
						'order' => $newOrder
					] )
				], $label );
			}
			$html .= Html::rawElement( 'th', [], $label );
		}
		$html .= Html::closeElement( 'tr' ) . Html::closeElement( 'thead' );
		$html .= Html::openElement( 'tbody' );

		foreach ( $res as $row ) {
			$user = $this->userFactory->newFromId( $row->mcl_user_id );
			$performer = $this->userFactory->newFromId( $row->mcl_by );

			$isExpired = $row->mcl_expiry !== 'infinity' && $row->mcl_expiry < $dbr->timestamp();
			$status = $isExpired ? $this->msg( 'mca-lock-status-expired' )->text() : $this->msg( 'mca-lock-status-active' )->text();

			$html .= Html::openElement( 'tr' );
			$html .= Html::rawElement( 'td', [], Html::element( 'a', [
				'href' => $this->getPageTitle( "view/{$row->mcl_id}" )->getFullURL()
			], $row->mcl_id ) );
			$html .= Html::element( 'td', [], $user ? $user->getName() : $row->mcl_user_id );
			$html .= Html::element( 'td', [], $row->mcl_reason );

			$expiry = $row->mcl_expiry;
			if ( $expiry === 'infinity' ) {
				$formattedExpiry = $this->msg( 'infiniteblock' )->text();
			} else {
				$formattedExpiry = $this->getLanguage()->userTimeAndDate( $expiry, $this->getUser() );
			}
			$html .= Html::element( 'td', [], $formattedExpiry );
			$html .= Html::element( 'td', [], $performer ? $performer->getName() : $row->mcl_by );
			$html .= Html::element( 'td', [], $this->getLanguage()->userTimeAndDate( $row->mcl_timestamp, $this->getUser() ) );
			$html .= Html::element( 'td', [], $status );

			$actions = Html::element( 'a', [
				'href' => SpecialPage::getTitleFor( 'UnlockCAAccount', $user ? $user->getName() : '' )->getFullURL()
			], $this->msg( 'unlockcaaccount' )->text() );
			$html .= Html::rawElement( 'td', [], $actions );

			$html .= Html::closeElement( 'tr' );
		}

		$html .= Html::closeElement( 'tbody' ) . Html::closeElement( 'table' );

		$this->getOutput()->addHTML( $html );
	}

	private function showSearchForm() {
		$formDescriptor = [
			'wpTarget' => [
				'type' => 'user',
				'name' => 'wpTarget',
				'label-message' => 'mca-target-label',
				'default' => $this->getRequest()->getText( 'wpTarget' ),
			],
			'sort' => [
				'type' => 'select',
				'name' => 'sort',
				'label-message' => 'mca-lock-list-sort',
				'options' => [
					$this->msg( 'mca-lock-list-timestamp' )->text() => 'timestamp',
					$this->msg( 'mca-lock-list-expiry' )->text() => 'expiry',
					$this->msg( 'mca-lock-list-id' )->text() => 'id',
				],
				'default' => $this->getRequest()->getText( 'sort', 'timestamp' ),
			],
			'order' => [
				'type' => 'select',
				'name' => 'order',
				'label-message' => 'mca-lock-list-order',
				'options' => [
					'Descending' => 'DESC',
					'Ascending' => 'ASC',
				],
				'default' => $this->getRequest()->getText( 'order', 'DESC' ),
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setMethod( 'get' );
		$htmlForm->setSubmitTextMsg( 'mca-submit' );
		$htmlForm->prepareForm();

		$this->getOutput()->addHTML( $this->getFramedFieldsetLayout( $htmlForm->getHTML( false ), 'calocklist', 'mca-header-type-view' ) );
	}

	private function showDetails( int $id ) {
		$dbr = $this->dbProvider->getReplicaDatabase();
		$row = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'mca_locks' )
			->where( [ 'mcl_id' => $id ] )
			->fetchRow();

		if ( !$row ) {
			$this->getOutput()->addHTML( Html::errorBox( $this->msg( 'mca-error-lock-not-found' )->text() ) );
			return;
		}

		$user = $this->userFactory->newFromId( $row->mcl_user_id );
		$performer = $this->userFactory->newFromId( $row->mcl_by );

		$this->getOutput()->setPageTitle( $this->msg( 'mca-lock-details-title', $id ) );

		$html = Html::openElement( 'div', [ 'class' => 'mca-info-box' ] );
		$html .= Html::openElement( 'ul' );
		$html .= Html::rawElement( 'li', [], '[[USERNAME]]' ); // Placeholder, actual username link handled by message or logic
		$html .= Html::rawElement( 'li', [], $this->msg( 'mca-lock-list-id' )->text() . ': ' . $row->mcl_id );
		$html .= Html::rawElement( 'li', [], $this->msg( 'mca-lock-list-user' )->text() . ': ' . ( $user ? $user->getName() : $row->mcl_user_id ) );
		$html .= Html::rawElement( 'li', [], $this->msg( 'mca-lock-list-reason' )->text() . ': ' . htmlspecialchars( $row->mcl_reason ) );

		$expiry = $row->mcl_expiry;
		if ( $expiry === 'infinity' ) {
			$formattedExpiry = $this->msg( 'infiniteblock' )->text();
		} else {
			$formattedExpiry = $this->getLanguage()->userTimeAndDate( $expiry, $this->getUser() );
		}
		$html .= Html::rawElement( 'li', [], $this->msg( 'mca-lock-list-expiry' )->text() . ': ' . $formattedExpiry );
		$html .= Html::rawElement( 'li', [], $this->msg( 'mca-lock-list-by' )->text() . ': ' . ( $performer ? $performer->getName() : $row->mcl_by ) );
		$html .= Html::rawElement( 'li', [], $this->msg( 'mca-lock-list-timestamp' )->text() . ': ' . $this->getLanguage()->userTimeAndDate( $row->mcl_timestamp, $this->getUser() ) );

		$isExpired = $row->mcl_expiry !== 'infinity' && $row->mcl_expiry < $dbr->timestamp();
		$status = $isExpired ? $this->msg( 'mca-lock-status-expired' )->text() : $this->msg( 'mca-lock-status-active' )->text();
		$html .= Html::rawElement( 'li', [], $this->msg( 'mca-lock-list-status' )->text() . ': ' . $status );

		$html .= Html::closeElement( 'ul' );
		$html .= Html::closeElement( 'div' );

		// Fix placeholder
		$username = $user ? $user->getName() : (string)$row->mcl_user_id;
		$html = str_replace( '[[USERNAME]]', $this->msg( 'mca-username', htmlspecialchars( $username ) )->parse(), $html );

		$this->getOutput()->addHTML( $this->getFramedFieldsetLayout( $html, [ 'mca-lock-details-title', $id ], 'mca-header-type-info' ) );

		$unlockUrl = SpecialPage::getTitleFor( 'UnlockCAAccount', $user ? $user->getName() : '' )->getFullURL();
		$this->getOutput()->addHTML( Html::rawElement( 'div', [ 'style' => 'margin-top: 1em;' ],
			Html::element( 'a', [ 'href' => $unlockUrl, 'class' => 'mw-ui-button mw-ui-progressive' ], $this->msg( 'unlockcaaccount' )->text() ) . ' ' .
			Html::element( 'a', [ 'href' => $this->getPageTitle()->getFullURL(), 'class' => 'mw-ui-button' ], $this->msg( 'mca-back-to-list' )->text() )
		) );
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
