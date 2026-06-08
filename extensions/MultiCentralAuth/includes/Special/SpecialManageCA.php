<?php

namespace MediaWiki\Extension\MultiCentralAuth\Special;

use MediaWiki\Block\BlockManager;
use MediaWiki\Extension\MultiCentralAuth\ExternalCAProvider;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\MainConfigNames;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\User\UserGroupManager;
use MediaWiki\Utils\MWTimestamp;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Rdbms\IConnectionProvider;
use MediaWiki\Html\Html;

class SpecialManageCA extends SpecialPage {

	private IConnectionProvider $dbProvider;
	private ExternalCAProvider $externalCAProvider;
	private UserGroupManager $userGroupManager;
	private BlockManager $blockManager;

	public function __construct(
		IConnectionProvider $dbProvider,
		ExternalCAProvider $externalCAProvider,
		UserGroupManager $userGroupManager,
		BlockManager $blockManager
	) {
		parent::__construct( 'ManageCA', 'ca-manage' );
		$this->dbProvider = $dbProvider;
		$this->externalCAProvider = $externalCAProvider;
		$this->userGroupManager = $userGroupManager;
		$this->blockManager = $blockManager;
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

		$externalUsernames = $this->externalCAProvider->getExternalUsernames( $user->getId() );
		if ( !$externalUsernames['wm'] && !$externalUsernames['mh'] ) {
			$this->getOutput()->addHTML( Html::errorBox( $this->msg( 'mca-manage-need-link' )->parse() ) );
		}

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

		$manualWikis = $this->externalCAProvider->getLocalAttachedWikis( $user->getId() );

		// 1. Local Wiki Data
		$localManual = [];
		foreach ( $manualWikis as $wiki ) {
			if ( $this->externalCAProvider->categorizeWiki( $wiki ) === 'local' ) {
				$localManual[] = $wiki;
			}
		}
		$this->showLocalData( $user, $localManual );

		// 2. Wikimedia Data
		$wmManual = [];
		foreach ( $manualWikis as $wiki ) {
			if ( $this->externalCAProvider->categorizeWiki( $wiki ) === 'wm' ) {
				$wmManual[] = $wiki;
			}
		}
		if ( $externalUsernames['wm'] || $wmManual ) {
			$wmData = $this->externalCAProvider->fetchGlobalUserInfo( 'https://meta.wikimedia.org/w/api.php', $externalUsernames['wm'] ?? '' );
			$this->showExternalData( $wmData, $externalUsernames['wm'] ?? $user->getName(), 'Wikimedia', 'mca-header-list-wm', $wmManual );
		}

		// 3. Miraheze Data
		$mhManual = [];
		foreach ( $manualWikis as $wiki ) {
			if ( $this->externalCAProvider->categorizeWiki( $wiki ) === 'mh' ) {
				$mhManual[] = $wiki;
			}
		}
		if ( $externalUsernames['mh'] || $mhManual ) {
			$mhData = $this->externalCAProvider->fetchGlobalUserInfo( 'https://meta.miraheze.org/w/api.php', $externalUsernames['mh'] ?? '' );
			$this->showExternalData( $mhData, $externalUsernames['mh'] ?? $user->getName(), 'Miraheze', 'mca-header-list-mh', $mhManual );
		}

		// Instructions
		$this->getOutput()->addHTML( $this->getFramedFieldsetLayout(
			Html::element( 'p', [], $this->msg( 'mca-manage-instructions' )->text() ),
			'manageca'
		) );

		$formDescriptor = [
			'subdomain' => [
				'type' => 'text',
				'name' => 'subdomain',
				'label-message' => 'mca-manage-subdomain',
				'required' => true,
			],
			'domain' => [
				'type' => 'select',
				'name' => 'domain',
				'label-message' => 'mca-manage-domain',
				'options' => [
					'.wikipedia.org' => '.wikipedia.org',
					'.wikimedia.org' => '.wikimedia.org',
					'.miraheze.org' => '.miraheze.org',
					'Other' => 'other',
				],
				'required' => true,
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setSubmitCallback( [ $this, 'onSubmit' ] );
		$htmlForm->setSubmitTextMsg( 'mca-manage-action-add' );

		$htmlForm->prepareForm();
		$status = $htmlForm->tryAuthorizedSubmit();
		$this->getOutput()->addHTML( $this->getFramedFieldsetLayout( $htmlForm->getHTML( $status ), 'mca-manage-action-add' ) );
	}

	public function onSubmit( array $formData ) {
		$subdomain = $formData['subdomain'];
		$domain = $formData['domain'];
		$hostname = ( $domain === 'other' ) ? $subdomain : $subdomain . $domain;

		if ( !$this->externalCAProvider->isValidWiki( $hostname ) ) {
			return $this->msg( 'mca-manage-error-wiki-not-found', $hostname );
		}

		$user = $this->getUser();
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->insert(
			'mca_local_attachments',
			[
				'mla_user_id' => $user->getId(),
				'mla_wiki_id' => $hostname,
			],
			__METHOD__,
			[ 'IGNORE' ]
		);

		$this->getOutput()->addHTML( Html::successBox( $this->msg( 'mca-manage-merged-success', $hostname )->parse() ) );
		return true;
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

		$rows = [];
		$rows[] = [
			'wiki' => $currentWikiId,
			'attachedMethod' => 'home',
			'editCount' => $editCount,
			'attachedTimestamp' => $reg,
			'groups' => $this->userGroupManager->getUserGroups( $user ),
			'blocked' => (bool)$this->blockManager->getBlock( $user, null ),
			'manual' => false,
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
					'manual' => true,
				];
			}
		}

		$table = $this->renderTable( $rows, $user->getName() );
		$this->getOutput()->addHTML( $this->getFramedFieldsetLayout( $table, [ 'mca-header-info', $localWikiName ] ) );
	}

	private function showExternalData( ?array $data, string $username, string $sourceName, string $tableHeaderMsg, array $manualWikis ) {
		$merged = $data['merged'] ?? [];
		$rows = [];
		$attachedWikis = [];

		foreach ( $merged as $m ) {
			$rows[] = [
				'wiki' => $m['wiki'],
				'url' => $m['url'],
				'attachedMethod' => $m['method'],
				'editCount' => $m['editcount'],
				'attachedTimestamp' => $m['timestamp'],
				'groups' => $m['groups'] ?? [],
				'blocked' => isset( $m['blocked'] ),
				'manual' => false,
			];
			$parsedUrl = parse_url( $m['url'] );
			if ( isset( $parsedUrl['host'] ) ) {
				$attachedWikis[] = $parsedUrl['host'];
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
					'manual' => true,
				];
			}
		}

		$table = $this->renderTable( $rows, $username );
		$this->getOutput()->addHTML( $this->getFramedFieldsetLayout( $table, $tableHeaderMsg ) );
	}

	private function renderTable( array $rows, $username ) {
		$lang = $this->getLanguage();
		$html = Html::openElement( 'table', [ 'class' => 'wikitable sortable mw-centralauth-wikislist', 'style' => 'width: 100%;' ] );
		$html .= Html::openElement( 'thead' ) . Html::openElement( 'tr' );
		foreach ( [ 'localwiki', 'attached-on', 'method', 'blocked', 'editcount', 'groups' ] as $col ) {
			$html .= Html::element( 'th', [], $this->msg( "centralauth-admin-list-$col" )->text() );
		}
		$html .= Html::element( 'th', [], '' ); // Action column
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

			// Action
			$action = '';
			if ( $row['manual'] ) {
				$action = Html::element( 'a', [
					'href' => $this->getPageTitle()->getLocalURL( [ 'remove' => $wikiId ] ),
					'style' => 'color: #d33;'
				], $this->msg( 'mca-manage-remove' )->text() );
			}
			$html .= Html::rawElement( 'td', [], $action );

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
