<?php

namespace MediaWiki\Extension\MultiCentralAuth\Special;

use MediaWiki\Block\BlockManager;
use MediaWiki\Extension\MultiCentralAuth\ExternalCAProvider;
use MediaWiki\Html\Html;
use MediaWiki\MainConfigNames;
use MediaWiki\HTMLForm\HTMLForm;
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
			'mediawiki.ui.input',
			'mediawiki.ui.button',
			'mediawiki.ui.vform',
			'mediawiki.widgets.styles',
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

		$this->showGlobalBlockInfo( $user );
		$this->showGlobalLockInfo( $user );

		$externalUsernames = $this->externalCAProvider->getExternalUsernames( $user->getId() );
		$manualWikis = $this->externalCAProvider->getLocalAttachedWikis( $user->getId() );
		$suppressedWikis = $this->externalCAProvider->getSuppressedWikis( $user->getId() );
		$farms = $this->externalCAProvider->getFarms();

		// 1. Local Wiki Data
		$localManual = [];
		foreach ( $manualWikis as $wiki ) {
			if ( $this->externalCAProvider->categorizeWiki( $wiki ) === 'local' ) {
				$localManual[] = $wiki;
			}
		}
		$this->showLocalData( $user, $localManual );

		// 2. Dynamic Farm Data
		foreach ( $farms as $farm ) {
			$farmId = $farm['id'];
			$farmManual = [];
			foreach ( $manualWikis as $wiki ) {
				if ( $this->externalCAProvider->categorizeWiki( $wiki ) === $farmId ) {
					$farmManual[] = $wiki;
				}
			}

			$extUsername = $externalUsernames[$farmId] ?? null;
			if ( $extUsername || $farmManual ) {
				$headerMsg = $farm['header_msg'] ?? 'mca-header-list-generic';
				if ( $farm['header_msg'] === null ) {
					$headerMsg = [ 'mca-header-list-generic', $farm['name'] ];
				}
				if ( $farm['is_centralauth'] && $farm['api_url'] ) {
					$farmData = $this->externalCAProvider->fetchGlobalUserInfo( $farm['api_url'], $extUsername ?? '' );
					$this->showExternalData(
						$farmData,
						$extUsername ?? $user->getName(),
						$farm['name'],
						[ $headerMsg, $farm['name'] ],
						$farmManual,
						$suppressedWikis
					);
				} else {
					// Direct API farm or no CentralAuth
					$this->showOtherManualData( $user, $farmManual, $farm['name'], $headerMsg );
				}
			}
		}
	}

	private function showOtherManualData( $user, array $manualWikis, string $farmName, $headerMsg ) {
		$rows = [];
		foreach ( $manualWikis as $wikiHost ) {
			$metadata = $this->externalCAProvider->fetchUserMetadata( $wikiHost, $user->getName() ) ?? [];
			$rows[] = [
				'wiki' => $wikiHost,
				'url' => "https://$wikiHost/",
				'attachedMethod' => 'primary',
				'editCount' => $metadata['editcount'] ?? 0,
				'attachedTimestamp' => $metadata['registration'] ?? '',
				'groups' => $metadata['groups'] ?? [],
				'blocked' => $metadata['blocked'] ?? false,
				'manual' => true,
				'host' => $wikiHost,
			];
		}

		if ( $rows ) {
			$info = $this->formatUserInfo( $user->getName(), '', 0, null, count( $rows ) );
			$this->getOutput()->addHTML( $this->getFramedFieldsetLayout( $info, [ 'mca-header-info', $farmName ], 'mca-header-type-info' ) );
			$table = $this->renderTable( $rows, $user->getName() );
			$this->getOutput()->addHTML( $this->getFramedFieldsetLayout( $table, $headerMsg, 'mca-header-type-list' ) );
		}
	}

	private function showUsernameForm( $default = '' ) {
		$formDescriptor = [
			'target' => [
				'type' => 'user',
				'name' => 'target',
				'label-message' => 'mca-target-label',
				'required' => true,
				'default' => $default,
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setMethod( 'get' );
		$htmlForm->setSubmitTextMsg( 'mca-view-user-info' );
		$htmlForm->prepareForm();

		$this->getOutput()->addHTML( $this->getFramedFieldsetLayout( $htmlForm->getHTML( false ), 'mca-header-view', 'mca-header-type-view' ) );
	}

	private function showLocalData( $user, array $manualWikis ) {
		$localWikiName = $this->getConfig()->get( MainConfigNames::Sitename );
		$currentWikiId = WikiMap::getCurrentWikiId();

		$dbr = $this->dbProvider->getReplicaDatabase();
		$userData = $dbr->newSelectQueryBuilder()
			->select( [ 'user_editcount', 'user_registration' ] )
			->from( 'user' )
			->where( [ 'user_id' => $user->getId() ] )
			->fetchRow();

		$reg = $userData ? $userData->user_registration : '';
		$editCount = $userData ? (int)$userData->user_editcount : 0;

		$info = $this->formatUserInfo( $user->getName(), $reg, $editCount );
		$this->getOutput()->addHTML( $this->getFramedFieldsetLayout( $info, [ 'mca-header-info', $localWikiName ], 'mca-header-type-info' ) );

		$rows = [];
		// Always show home wiki
		$groups = $this->userGroupManager->getUserGroups( $user );
		$filteredGroups = array_values( array_diff( $groups, [ '*', 'user', 'autoconfirmed' ] ) );
		$rows[] = [
			'wiki' => $currentWikiId,
			'attachedMethod' => 'home',
			'editCount' => $editCount,
			'attachedTimestamp' => $reg,
			'groups' => $filteredGroups,
			'blocked' => (bool)$this->blockManager->getBlock( $user, null ),
		];

		foreach ( $manualWikis as $wikiHost ) {
			if ( $wikiHost === $currentWikiId ) {
				continue;
			}
			$metadata = $this->externalCAProvider->fetchUserMetadata( $wikiHost, $user->getName() );
			if ( $metadata ) {
				$rows[] = [
					'wiki' => $wikiHost,
					'url' => "https://$wikiHost/",
					'attachedMethod' => 'local',
					'editCount' => $metadata['editcount'],
					'attachedTimestamp' => $metadata['registration'],
					'groups' => $metadata['groups'],
					'blocked' => $metadata['blocked'],
				];
			} else {
				$rows[] = [
					'wiki' => $wikiHost,
					'attachedMethod' => 'local',
					'editCount' => 0,
					'attachedTimestamp' => '',
					'groups' => [],
					'blocked' => false,
				];
			}
		}

		$table = $this->renderTable( $rows, $user->getName() );
		$this->getOutput()->addHTML( $this->getFramedFieldsetLayout( $table, 'mca-header-list-local', 'mca-header-type-list' ) );
	}

	private function showExternalData( ?array $data, string $username, string $sourceName, $tableHeaderMsg, array $manualWikis, array $suppressedWikis ) {
		$reg = $data['registration'] ?? '';
		$editCount = $data['editcount'] ?? 0;
		$globalGroups = $data['groups'] ?? [];

		$merged = $data['merged'] ?? [];
		$rows = [];
		$sumEditCount = 0;
		$attachedWikis = [];

		foreach ( $merged as $m ) {
			$parsedUrl = parse_url( $m['url'] );
			$host = isset( $parsedUrl['host'] ) ? strtolower( $parsedUrl['host'] ) : null;
			if ( $host && in_array( $host, $suppressedWikis ) ) {
				continue;
			}

			$rows[] = [
				'wiki' => $m['wiki'],
				'url' => $m['url'],
				'attachedMethod' => $m['method'],
				'editCount' => $m['editcount'],
				'attachedTimestamp' => $m['timestamp'],
				'groups' => $m['groups'] ?? [],
				'blocked' => isset( $m['blocked'] ),
			];
			$sumEditCount += $m['editcount'];
			if ( $host ) {
				$attachedWikis[] = $host;
			}
		}

		foreach ( $manualWikis as $wikiHost ) {
			if ( in_array( $wikiHost, $attachedWikis ) ) {
				continue;
			}
			$metadata = $this->externalCAProvider->fetchUserMetadata( $wikiHost, $username );
			if ( $metadata ) {
				$rows[] = [
					'wiki' => $wikiHost,
					'url' => "https://$wikiHost/",
					'attachedMethod' => 'local',
					'editCount' => $metadata['editcount'],
					'attachedTimestamp' => $metadata['registration'],
					'groups' => $metadata['groups'],
					'blocked' => $metadata['blocked'],
				];
				$sumEditCount += $metadata['editcount'];
			}
		}

		$info = $this->formatUserInfo( $username, $reg, $editCount, $sumEditCount, count( $rows ), $globalGroups );
		$this->getOutput()->addHTML( $this->getFramedFieldsetLayout( $info, [ 'mca-header-info', $sourceName ], 'mca-header-type-info' ) );

		$table = $this->renderTable( $rows, $username );
		$this->getOutput()->addHTML( $this->getFramedFieldsetLayout( $table, $tableHeaderMsg, 'mca-header-type-list' ) );
	}

	private function formatUserInfo( $username, $reg, $editCount, $sumEditCount = null, $attachedCount = null, $globalGroups = [] ) {
		$lang = $this->getLanguage();
		$prettyReg = '';
		if ( $reg ) {
			$ts = new MWTimestamp( $reg );
			$date = $lang->userTimeAndDate( $ts->getTimestamp(), $this->getUser() );
			$ago = $this->getSimplifiedRelativeTimestamp( $ts );
			$prettyReg = $date . ' (' . $ago . ')';
		}

		$html = Html::openElement( 'div', [ 'class' => 'mca-info-box' ] );
		$html .= Html::openElement( 'ul' );
		$html .= Html::rawElement( 'li', [], $this->msg( 'mca-username', htmlspecialchars( $username ) )->parse() );
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

	private function getSimplifiedRelativeTimestamp( MWTimestamp $ts ): string {
		$lang = $this->getLanguage();
		$diff = time() - $ts->getTimestamp();

		if ( $diff < 0 ) {
			return $lang->getHumanTimestamp( $ts, null, $this->getUser() );
		}

		if ( $diff < 60 ) {
			return $this->msg( 'ago-now' )->text();
		} elseif ( $diff < 3600 ) {
			$val = floor( $diff / 60 );
			return $this->msg( 'ago-minutes' )->numParams( $val )->text();
		} elseif ( $diff < 86400 ) {
			$val = floor( $diff / 3600 );
			return $this->msg( 'ago-hours' )->numParams( $val )->text();
		} elseif ( $diff < 2592000 ) {
			$val = floor( $diff / 86400 );
			return $this->msg( 'ago-days' )->numParams( $val )->text();
		} elseif ( $diff < 31536000 ) {
			$val = floor( $diff / 2592000 );
			return $this->msg( 'ago-months' )->numParams( $val )->text();
		} else {
			$val = floor( $diff / 31536000 );
			return $this->msg( 'ago-years' )->numParams( $val )->text();
		}
	}

	private function showGlobalBlockInfo( $user ) {
		$dbr = $this->dbProvider->getReplicaDatabase();
		if ( !$dbr->tableExists( 'globalblocks' ) ) {
			return;
		}

		$block = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'globalblocks' )
			->where( [ 'gb_target' => $user->getName() ] )
			->fetchRow();

		if ( $block ) {
			$blocker = $block->gb_by_text;
			$expiry = $block->gb_expiry;
			if ( $expiry === 'infinity' ) {
				$formattedExpiry = $this->msg( 'infiniteblock' )->text();
			} else {
				$formattedExpiry = $this->getLanguage()->userTimeAndDate( $expiry, $this->getUser() );
			}
			$reason = $block->gb_reason ?? '';
			$timestamp = $this->getLanguage()->userTimeAndDate( $block->gb_timestamp, $this->getUser() );

			$msg = $this->msg( 'mca-global-block-notice', $blocker, $formattedExpiry, $reason, $timestamp )->parse();
			$this->getOutput()->addHTML( Html::rawElement( 'div', [ 'class' => 'mw-message-box mw-message-box-error' ],
				Html::element( 'span', [ 'class' => 'mw-message-box-icon' ] ) .
				Html::rawElement( 'div', [], $msg )
			) );
		}
	}

	private function showGlobalLockInfo( $user ) {
		$dbr = $this->dbProvider->getReplicaDatabase();
		$lock = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'mca_locks' )
			->where( [ 'mcl_user_id' => $user->getId() ] )
			->andWhere( 'mcl_expiry > ' . $dbr->addQuotes( $dbr->timestamp() ) . ' OR mcl_expiry = ' . $dbr->addQuotes( 'infinity' ) )
			->fetchRow();

		if ( $lock ) {
			$performer = $this->userFactory->newFromId( $lock->mcl_by );
			if ( $performer && $performer->isRegistered() ) {
				$blockerLink = Html::element( 'a', [
					'href' => SpecialPage::getTitleFor( 'CentralAuth', $performer->getName() )->getFullURL(),
				], $performer->getName() );
			} else {
				$blockerLink = $lock->mcl_by;
			}

			$expiry = $lock->mcl_expiry;
			if ( $expiry === 'infinity' ) {
				$formattedExpiry = $this->msg( 'infiniteblock' )->text();
			} else {
				$formattedExpiry = $this->getLanguage()->userTimeAndDate( $expiry, $this->getUser() );
			}
			$reason = $lock->mcl_reason;
			$timestamp = $this->getLanguage()->userTimeAndDate( $lock->mcl_timestamp, $this->getUser() );

			$msg = $this->msg( 'mca-global-lock-notice' )
				->rawParams( $blockerLink )
				->params( $formattedExpiry, $reason, $timestamp )
				->parse();

			$this->getOutput()->addModuleStyles( 'oojs-ui.styles.icons-moderation' );
			$this->getOutput()->addHTML( Html::rawElement( 'div', [ 'class' => 'mw-message-box mw-message-box-warning mca-global-lock-notice-box' ],
				Html::element( 'span', [ 'class' => 'mw-message-box-icon oo-ui-icon-lock' ] ) .
				Html::rawElement( 'div', [], $msg )
			) );
		}
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
