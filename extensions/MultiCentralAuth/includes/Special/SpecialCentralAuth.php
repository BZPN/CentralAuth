<?php

namespace MediaWiki\Extension\MultiCentralAuth\Special;

use MediaWiki\Block\BlockManager;
use MediaWiki\Extension\MultiCentralAuth\ExternalCAProvider;
use MediaWiki\Html\Html;
use MediaWiki\MainConfigNames;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserNameUtils;
use MediaWiki\Utils\MWTimestamp;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Rdbms\IConnectionProvider;

class SpecialCentralAuth extends SpecialPage {

	private IConnectionProvider $dbProvider;
	private UserFactory $userFactory;
	private UserNameUtils $userNameUtils;
	private ExternalCAProvider $externalCAProvider;
	private UserGroupManager $userGroupManager;
	private BlockManager $blockManager;

	public function __construct(
		IConnectionProvider $dbProvider,
		UserFactory $userFactory,
		UserNameUtils $userNameUtils,
		ExternalCAProvider $externalCAProvider,
		UserGroupManager $userGroupManager,
		BlockManager $blockManager
	) {
		parent::__construct( 'CentralAuth' );
		$this->dbProvider = $dbProvider;
		$this->userFactory = $userFactory;
		$this->userNameUtils = $userNameUtils;
		$this->externalCAProvider = $externalCAProvider;
		$this->userGroupManager = $userGroupManager;
		$this->blockManager = $blockManager;
	}

	public function execute( $subpage ) {
		$this->setHeaders();
		$this->getOutput()->addModuleStyles( [
			'mediawiki.codex.messagebox.styles',
			'oojs-ui-core.styles',
			'oojs-ui-widgets.styles',
			'ext.multicentralauth.styles'
		] );
		$this->getOutput()->addModules( 'ext.multicentralauth.js' );

		$target = $this->getRequest()->getText( 'target', $subpage );

		$this->showUsernameForm( $target );

		if ( !$target ) {
			return;
		}

		$user = $this->userFactory->newFromName( $target );
		if ( !$user || !$user->isRegistered() ) {
			$this->getOutput()->addHTML( Html::errorBox( $this->msg( 'mca-error-user-not-found' )->parse() ) );
			return;
		}

		// 1. Local Wiki Data
		$this->showLocalData( $user );

		$externalUsernames = $this->externalCAProvider->getExternalUsernames( $user->getId() );

		// 2. Wikimedia Data
		if ( $externalUsernames['wm'] ) {
			$wmData = $this->externalCAProvider->fetchGlobalUserInfo( 'https://meta.wikimedia.org/w/api.php', $externalUsernames['wm'] );
			if ( $wmData ) {
				$this->showExternalData( $wmData, 'Wikimedia', 'mca-header-list-wm' );
			}
		}

		// 3. Miraheze Data
		if ( $externalUsernames['mh'] ) {
			$mhData = $this->externalCAProvider->fetchGlobalUserInfo( 'https://meta.miraheze.org/w/api.php', $externalUsernames['mh'] );
			if ( $mhData ) {
				$this->showExternalData( $mhData, 'Miraheze', 'mca-header-list-mh' );
			}
		}
	}

	private function showUsernameForm( $default = '' ) {
		$form = Html::rawElement( 'form', [ 'action' => $this->getPageTitle()->getLocalURL(), 'method' => 'get' ],
			Html::rawElement( 'div', [],
				Html::element( 'label', [ 'for' => 'mca-target' ], $this->msg( 'mca-target-label' )->text() ) . ' ' .
				Html::input( 'target', $default, 'text', [ 'id' => 'mca-target', 'class' => 'mw-ui-input mw-ui-input-inline', 'size' => 40 ] ) . ' ' .
				Html::submitButton( $this->msg( 'mca-header-view' )->text(), [ 'id' => 'mca-submit', 'class' => 'mw-ui-button mw-ui-progressive' ] )
			)
		);

		$this->getOutput()->addHTML( $this->getFramedFieldsetLayout( $form, 'mca-header-view' ) );
	}

	private function showLocalData( $user ) {
		$localWikiName = $this->getConfig()->get( MainConfigNames::Sitename );
		$currentWikiId = WikiMap::getCurrentWikiId();

		$attachedWikis = $this->externalCAProvider->getLocalAttachedWikis( $user->getId() );
		$wikisToShow = array_unique( array_merge( [ $currentWikiId ], $attachedWikis ) );

		$dbr = $this->dbProvider->getReplicaDatabase();
		$userData = $dbr->newSelectQueryBuilder()
			->select( [ 'user_editcount', 'user_registration' ] )
			->from( 'user' )
			->where( [ 'user_id' => $user->getId() ] )
			->fetchRow();

		$reg = $userData ? $userData->user_registration : '';
		$editCount = $userData ? (int)$userData->user_editcount : 0;

		$info = $this->formatUserInfo( $user->getName(), $reg, $editCount );
		$this->getOutput()->addHTML( $this->getFramedFieldsetLayout( $info, [ 'mca-header-info', $localWikiName ] ) );

		$rows = [];
		foreach ( $wikisToShow as $wikiId ) {
			if ( $wikiId === $currentWikiId ) {
				$rows[] = [
					'wiki' => $wikiId,
					'attachedMethod' => 'home',
					'editCount' => $editCount,
					'attachedTimestamp' => $reg,
					'groups' => $this->userGroupManager->getUserGroups( $user ),
					'blocked' => (bool)$this->blockManager->getBlock( $user, null ),
				];
			} else {
				$rows[] = [
					'wiki' => $wikiId,
					'attachedMethod' => 'local',
					'editCount' => 0,
					'attachedTimestamp' => '',
					'groups' => [],
					'blocked' => false,
				];
			}
		}

		$table = $this->renderTable( $rows, $user->getName() );
		$this->getOutput()->addHTML( $this->getFramedFieldsetLayout( $table, [ 'mca-header-info', $localWikiName ] ) );
	}

	private function showExternalData( array $data, string $sourceName, string $tableHeaderMsg ) {
		$username = $data['name'];
		$reg = $data['registration'] ?? '';
		$editCount = $data['editcount'] ?? 0;
		$globalGroups = $data['groups'] ?? [];

		$sumEditCount = 0;
		foreach ( $data['merged'] as $m ) {
			$sumEditCount += $m['editcount'];
		}

		$info = $this->formatUserInfo( $username, $reg, $editCount, $sumEditCount, count( $data['merged'] ), $globalGroups );
		$this->getOutput()->addHTML( $this->getFramedFieldsetLayout( $info, [ 'mca-header-info', $sourceName ] ) );

		$rows = [];
		foreach ( $data['merged'] as $merged ) {
			$rows[] = [
				'wiki' => $merged['wiki'],
				'url' => $merged['url'],
				'attachedMethod' => $merged['method'],
				'editCount' => $merged['editcount'],
				'attachedTimestamp' => $merged['timestamp'],
				'groups' => $merged['groups'] ?? [],
				'blocked' => isset( $merged['blocked'] ),
			];
		}

		$table = $this->renderTable( $rows, $username );
		$this->getOutput()->addHTML( $this->getFramedFieldsetLayout( $table, $tableHeaderMsg ) );
	}

	private function formatUserInfo( $username, $reg, $editCount, $sumEditCount = null, $attachedCount = null, $globalGroups = [] ) {
		$lang = $this->getLanguage();
		$prettyReg = '';
		if ( $reg ) {
			$ts = new MWTimestamp( $reg );
			$prettyReg = $lang->userTimeAndDate( $ts->getTimestamp(), $this->getUser() ) . ' (' . $lang->getHumanTimestamp( $ts ) . ')';
		}

		$html = Html::openElement( 'div', [ 'class' => 'mca-info-box' ] );
		$html .= Html::openElement( 'ul' );
		$html .= Html::rawElement( 'li', [], $this->msg( 'mca-username', $username )->parse() );
		if ( $prettyReg ) {
			$html .= Html::rawElement( 'li', [], $this->msg( 'mca-registered', $prettyReg )->parse() );
		}
		$html .= Html::rawElement( 'li', [], $this->msg( 'mca-editcount', $lang->formatNum( $editCount ) )->parse() );

		if ( $sumEditCount !== null ) {
			$html .= Html::rawElement( 'li', [], $this->msg( 'mca-editcount-sum', $lang->formatNum( $sumEditCount ) )->parse() );
		}
		if ( $attachedCount !== null ) {
			$html .= Html::rawElement( 'li', [], $this->msg( 'mca-attached-count', $lang->formatNum( $attachedCount ) )->parse() );
		}
		if ( $globalGroups ) {
			$html .= Html::rawElement( 'li', [], $this->msg( 'mca-global-groups', $lang->commaList( $globalGroups ) )->parse() );
		}

		$html .= Html::closeElement( 'ul' );
		$html .= Html::closeElement( 'div' );
		return $html;
	}

	private function renderTable( array $rows, $username ) {
		$lang = $this->getLanguage();
		$html = Html::openElement( 'table', [ 'class' => 'wikitable sortable mw-centralauth-wikislist', 'style' => 'width: 100%;' ] );
		$html .= Html::openElement( 'thead' ) . Html::openElement( 'tr' );
		foreach ( [ 'localwiki', 'attached-on', 'method', 'blocked', 'editcount', 'groups' ] as $col ) {
			$html .= Html::element( 'th', [], $this->msg( "centralauth-admin-list-$col" )->text() );
		}
		$html .= Html::closeElement( 'tr' ) . Html::closeElement( 'thead' );
		$html .= Html::openElement( 'tbody' );

		foreach ( $rows as $row ) {
			$html .= Html::openElement( 'tr' );

			// Wiki
			$wikiId = $row['wiki'];
			$url = $row['url'] ?? null;
			$wikiDisplayId = $wikiId;

			if ( $url ) {
				$parsedUrl = parse_url( $url );
				$wikiDisplayId = $parsedUrl['host'] ?? $wikiId;
				$userPageUrl = rtrim( $url, '/' ) . '/wiki/User:' . urlencode( $username );
				$wikiDisplay = Html::element( 'a', [ 'href' => $userPageUrl ], $wikiDisplayId );
			} else {
				// Local wiki or attached local wiki
				$localUserPage = Title::makeTitle( NS_USER, $username );
				$wikiDisplay = Html::element( 'a', [ 'href' => $localUserPage->getFullURL() ], $wikiDisplayId );
			}
			$html .= Html::rawElement( 'td', [], $wikiDisplay );

			// Attached on
			$reg = $row['attachedTimestamp'];
			if ( $reg ) {
				$ts = new MWTimestamp( $reg );
				$formattedReg = $lang->userTimeAndDate( $ts->getTimestamp(), $this->getUser() );
			} else {
				$formattedReg = '';
			}
			$html .= Html::element( 'td', [], $formattedReg );

			// Method
			$method = $row['attachedMethod'];
			if ( $method === 'email' ) {
				$method = 'mail';
			}
			$iconName = $method === 'home' ? 'primary' : $method;
			$iconPath = $this->getConfig()->get( MainConfigNames::ExtensionAssetsPath ) . "/MultiCentralAuth/resources/icons/merged-$iconName.png";

			$brief = $this->msg( "centralauth-merge-method-$iconName" )->text();
			$icon = Html::element( 'img', [
				'src' => $iconPath,
				'alt' => $brief,
				'title' => $brief,
			] ) . Html::element( 'span', [
				'class' => 'merge-method-help',
				'title' => $brief,
				'data-centralauth-mergemethod' => $iconName,
				'style' => 'cursor: help;'
			], $this->msg( 'centralauth-merge-method-questionmark' )->text() );

			$html .= Html::rawElement( 'td', [ 'class' => 'mw-centralauth-wikislist-method' ], $icon );

			// Blocked
			if ( $row['blocked'] ) {
				$blockLogUrl = $url ? rtrim( $url, '/' ) . '/wiki/Special:Log/block?page=User:' . urlencode( $username ) :
					SpecialPage::getTitleFor( 'Log', 'block' )->getFullURL( [ 'page' => 'User:' . $username ] );
				$blockedDisplay = Html::element( 'a', [ 'href' => $blockLogUrl ], $this->msg( 'centralauth-admin-yes' )->text() );
			} else {
				$blockedDisplay = $this->msg( 'centralauth-admin-notblocked' )->text();
			}
			$html .= Html::rawElement( 'td', [], $blockedDisplay );

			// Editcount
			$contribsUrl = $url ? rtrim( $url, '/' ) . '/wiki/Special:Contributions/' . urlencode( $username ) :
				SpecialPage::getTitleFor( 'Contributions', $username )->getFullURL();
			$editCountDisplay = Html::element( 'a', [ 'href' => $contribsUrl ], $lang->formatNum( $row['editCount'] ) );
			$html .= Html::rawElement( 'td', [ 'class' => 'mw-centralauth-wikislist-editcount' ], $editCountDisplay );

			// Groups
			$html .= Html::element( 'td', [], $lang->commaList( $row['groups'] ) );

			$html .= Html::closeElement( 'tr' );
		}

		$html .= Html::closeElement( 'tbody' ) . Html::closeElement( 'table' );
		return $html;
	}

	private function getFramedFieldsetLayout( $html, $legendMsg ): string {
		if ( is_array( $legendMsg ) ) {
			$label = $this->msg( ...$legendMsg )->text();
		} else {
			$label = $this->msg( $legendMsg )->text();
		}

		return Html::rawElement( 'div', [ 'class' => 'mw-htmlform-ooui-wrapper oo-ui-panelLayout-framed oo-ui-panelLayout-padded', 'style' => 'margin-bottom: 1em;' ],
			Html::rawElement( 'fieldset', [ 'class' => 'oo-ui-fieldsetLayout' ],
				Html::element( 'legend', [ 'class' => 'oo-ui-fieldsetLayout-header' ], $label ) .
				Html::rawElement( 'div', [ 'class' => 'oo-ui-fieldsetLayout-group' ], $html )
			)
		);
	}
}
