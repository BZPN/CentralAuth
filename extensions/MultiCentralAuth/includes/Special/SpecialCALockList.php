<?php

namespace MediaWiki\Extension\MultiCentralAuth\Special;

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

		$dbr = $this->dbProvider->getReplicaDatabase();
		$res = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'mca_locks' )
			->where( 'mcl_expiry > ' . $dbr->addQuotes( $dbr->timestamp() ) . ' OR mcl_expiry = ' . $dbr->addQuotes( 'infinity' ) )
			->orderBy( 'mcl_timestamp', 'DESC' )
			->fetchResultSet();

		$html = Html::openElement( 'table', [ 'class' => 'wikitable sortable', 'style' => 'width: 100%;' ] );
		$html .= Html::openElement( 'thead' ) . Html::openElement( 'tr' );
		foreach ( [ 'id', 'user', 'reason', 'expiry', 'by', 'timestamp', 'actions' ] as $col ) {
			$html .= Html::element( 'th', [], $this->msg( "mca-lock-list-$col" )->text() );
		}
		$html .= Html::closeElement( 'tr' ) . Html::closeElement( 'thead' );
		$html .= Html::openElement( 'tbody' );

		foreach ( $res as $row ) {
			$user = $this->userFactory->newFromId( $row->mcl_user_id );
			$performer = $this->userFactory->newFromId( $row->mcl_by );

			$html .= Html::openElement( 'tr' );
			$html .= Html::element( 'td', [], $row->mcl_id );
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

			$actions = Html::element( 'a', [
				'href' => SpecialPage::getTitleFor( 'UnlockCAAccount', $user ? $user->getName() : '' )->getFullURL()
			], $this->msg( 'unlockcaaccount' )->text() );
			$html .= Html::rawElement( 'td', [], $actions );

			$html .= Html::closeElement( 'tr' );
		}

		$html .= Html::closeElement( 'tbody' ) . Html::closeElement( 'table' );

		$this->getOutput()->addHTML( $html );
	}
}
