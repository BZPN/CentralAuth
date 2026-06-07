<?php

namespace MediaWiki\Extension\MultiCentralAuth\Special;

use MediaWiki\CommentFormatter\CommentFormatter;
use MediaWiki\Extension\MultiCentralAuth\ExternalCAProvider;
use MediaWiki\Html\Html;
use MediaWiki\MainConfigNames;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserNameUtils;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Rdbms\IConnectionProvider;

class SpecialCentralAuth extends SpecialPage {

	private CommentFormatter $commentFormatter;
	private IConnectionProvider $dbProvider;
	private NamespaceInfo $namespaceInfo;
	private UserFactory $userFactory;
	private UserNameUtils $userNameUtils;
	private ExternalCAProvider $externalCAProvider;

	public function __construct(
		CommentFormatter $commentFormatter,
		IConnectionProvider $dbProvider,
		NamespaceInfo $namespaceInfo,
		UserFactory $userFactory,
		UserNameUtils $userNameUtils,
		ExternalCAProvider $externalCAProvider
	) {
		parent::__construct( 'CentralAuth' );
		$this->commentFormatter = $commentFormatter;
		$this->dbProvider = $dbProvider;
		$this->namespaceInfo = $namespaceInfo;
		$this->userFactory = $userFactory;
		$this->userNameUtils = $userNameUtils;
		$this->externalCAProvider = $externalCAProvider;
	}

	public function execute( $subpage ) {
		$this->setHeaders();
		$this->getOutput()->addModuleStyles( 'ext.multicentralauth.styles' );

		$target = $this->getRequest()->getText( 'target', $subpage );
		if ( !$target ) {
			$this->showUsernameForm();
			return;
		}

		$user = $this->userFactory->newFromName( $target );
		if ( !$user || !$user->isRegistered() ) {
			$this->getOutput()->addWikiMsg( 'mca-error-user-not-found' );
			$this->showUsernameForm();
			return;
		}

		$this->showUsernameForm( $target );

		// 1. Local Wiki Data
		$this->showLocalData( $user );

		$externalUsernames = $this->externalCAProvider->getExternalUsernames( $user->getId() );

		// 2. Wikimedia Data
		if ( $externalUsernames['wm'] ) {
			$wmData = $this->externalCAProvider->fetchGlobalUserInfo( 'https://www.wikimedia.org/w/api.php', $externalUsernames['wm'] );
			if ( $wmData ) {
				$this->showExternalData( $wmData, 'Wikimedia' );
			}
		}

		// 3. Miraheze Data
		if ( $externalUsernames['mh'] ) {
			$mhData = $this->externalCAProvider->fetchGlobalUserInfo( 'https://meta.miraheze.org/w/api.php', $externalUsernames['mh'] );
			if ( $mhData ) {
				$this->showExternalData( $mhData, 'Miraheze' );
			}
		}
	}

	private function showUsernameForm( $default = '' ) {
		$this->getOutput()->addHTML(
			Html::rawElement( 'form', [ 'action' => $this->getPageTitle()->getLocalURL(), 'method' => 'get' ],
				Html::element( 'label', [ 'for' => 'mca-target' ], $this->msg( 'mca-target-label' )->text() ) .
				Html::input( 'target', $default, 'text', [ 'id' => 'mca-target', 'size' => 40 ] ) . ' ' .
				Html::submitButton( $this->msg( 'mca-submit' )->text(), [ 'id' => 'mca-submit' ] )
			)
		);
	}

	private function showLocalData( $user ) {
		$this->getOutput()->addHTML( '<h2>' . $this->msg( 'mca-header-local' )->escaped() . '</h2>' );

		$attachedWikis = $this->externalCAProvider->getLocalAttachedWikis( $user->getId() );
		$currentWiki = $this->getConfig()->get( MainConfigNames::DBname );

		// Always include current wiki if user is registered here (which they are if we got here)
		$wikisToShow = array_unique( array_merge( [ $currentWiki ], $attachedWikis ) );

		$rows = [];
		foreach ( $wikisToShow as $wikiId ) {
			// In a real multi-wiki setup we would fetch data from other DBs.
			// Here we can only reliably show data for the current wiki.
			if ( $wikiId === $currentWiki ) {
				$rows[] = [
					'wiki' => $wikiId,
					'attachedMethod' => 'home',
					'editCount' => $user->getEditCount(),
					'attachedTimestamp' => $user->getRegistration(),
					'groups' => $user->getGroups(),
					'blocked' => $user->isBlocked(),
				];
			} else {
				$rows[] = [
					'wiki' => $wikiId,
					'attachedMethod' => 'local', // Placeholder
					'editCount' => 0,
					'attachedTimestamp' => '',
					'groups' => [],
					'blocked' => false,
				];
			}
		}

		$this->renderTable( $rows );
	}

	private function showExternalData( array $data, string $sourceName ) {
		$this->getOutput()->addHTML( '<h2>' . $this->msg( 'mca-header-external', $sourceName )->escaped() . '</h2>' );

		$rows = [];
		foreach ( $data['merged'] as $merged ) {
			$rows[] = [
				'wiki' => $merged['wiki'],
				'url' => $merged['url'],
				'attachedMethod' => $merged['method'],
				'editCount' => $merged['editcount'],
				'attachedTimestamp' => $merged['timestamp'],
				'groups' => [], // API meta=globaluserinfo doesn't give per-wiki groups easily in 'merged'
				'blocked' => isset( $merged['blocked'] ),
			];
		}

		$this->renderTable( $rows );
	}

	private function renderTable( array $rows ) {
		$html = Html::openElement( 'table', [ 'class' => 'wikitable sortable mw-centralauth-wikislist' ] );
		$html .= Html::openElement( 'thead' ) . Html::openElement( 'tr' );
		foreach ( [ 'localwiki', 'attached-on', 'method', 'blocked', 'editcount', 'groups' ] as $col ) {
			$html .= Html::element( 'th', [], $this->msg( "centralauth-admin-list-$col" )->text() );
		}
		$html .= Html::closeElement( 'tr' ) . Html::closeElement( 'thead' );
		$html .= Html::openElement( 'tbody' );

		foreach ( $rows as $row ) {
			$html .= Html::openElement( 'tr' );

			// Wiki
			$wikiName = $row['wiki'];
			if ( isset( $row['url'] ) ) {
				$wikiDisplay = Html::element( 'a', [ 'href' => $row['url'] ], $wikiName );
			} else {
				$wikiDisplay = htmlspecialchars( $wikiName );
			}
			$html .= Html::rawElement( 'td', [], $wikiDisplay );

			// Attached on
			$html .= Html::element( 'td', [], $row['attachedTimestamp'] );

			// Method
			$method = $row['attachedMethod'];
			$icon = Html::element( 'img', [
				'src' => $this->getConfig()->get( MainConfigNames::ExtensionAssetsPath ) . "/MultiCentralAuth/resources/icons/merged-$method.png",
				'alt' => $method,
				'title' => $method,
			] );
			if ( !in_array( $method, [ 'primary', 'new', 'empty', 'password', 'mail', 'admin', 'login' ] ) ) {
				$icon = htmlspecialchars( $method );
			}
			$html .= Html::rawElement( 'td', [], $icon );

			// Blocked
			$html .= Html::element( 'td', [], $row['blocked'] ? $this->msg( 'centralauth-admin-yes' )->text() : $this->msg( 'centralauth-admin-notblocked' )->text() );

			// Editcount
			$html .= Html::element( 'td', [], $row['editCount'] );

			// Groups
			$html .= Html::element( 'td', [], implode( ', ', $row['groups'] ) );

			$html .= Html::closeElement( 'tr' );
		}

		$html .= Html::closeElement( 'tbody' ) . Html::closeElement( 'table' );
		$this->getOutput()->addHTML( $html );
	}
}
