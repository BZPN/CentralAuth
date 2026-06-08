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
use MediaWiki\Html\Xml;

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
			'mediawiki.ui.input',
			'mediawiki.ui.button',
			'mediawiki.ui.vform',
			'ext.multicentralauth.styles'
		] );

		$user = $this->getUser();
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$externalUsernames = $this->externalCAProvider->getExternalUsernames( $user->getId() );
		if ( !$externalUsernames['wm'] && !$externalUsernames['mh'] ) {
			$this->getOutput()->addHTML( Html::errorBox( $this->msg( 'mca-manage-need-link' )->parse() ) );
		}

		$manualWikis = $this->externalCAProvider->getLocalAttachedWikis( $user->getId() );

		// Bulk removal logic
		$removeWikis = $this->getRequest()->getArray( 'remove_wikis' );
		if ( $this->getRequest()->wasPosted() && $removeWikis && $this->getUser()->matchEditToken( $this->getRequest()->getVal( 'wpEditToken' ) ) ) {
			$dbw->delete(
				'mca_local_attachments',
				[
					'mla_user_id' => $user->getId(),
					'mla_wiki_id' => $removeWikis,
				],
				__METHOD__
			);
			$this->getOutput()->addHTML( Html::successBox( $this->msg( 'mca-manage-success' )->parse() ) );
			$this->getOutput()->redirect( $this->getPageTitle()->getFullURL() );
			return;
		}

		$this->getOutput()->addHTML( Html::openElement( 'form', [ 'method' => 'post', 'action' => $this->getPageTitle()->getLocalURL() ] ) );
		$this->getOutput()->addHTML( Html::hidden( 'wpEditToken', $this->getUser()->getEditToken() ) );

		// 1. Wikimedia Data
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

		// 2. Miraheze Data
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

		if ( $wmManual || $mhManual ) {
			$this->getOutput()->addHTML( Html::submitButton( $this->msg( 'mca-manage-delete-selected' )->text(), [
				'name' => 'delete_selected',
				'class' => 'mw-ui-button mw-ui-destructive',
				'onclick' => "return confirm('" . Xml::escapeJsString( $this->msg( 'mca-manage-confirm-delete' )->text() ) . "');"
			] ) );
		}

		$this->getOutput()->addHTML( Html::closeElement( 'form' ) );

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
		$this->getOutput()->redirect( $this->getPageTitle()->getFullURL() );
		return true;
	}

	private function showExternalData( ?array $data, string $username, string $sourceName, string $tableHeaderMsg, array $manualWikis ) {
		$merged = $data['merged'] ?? [];
		$rows = [];
		$attachedWikis = [];

		foreach ( $merged as $m ) {
			$parsedUrl = parse_url( $m['url'] );
			$host = $parsedUrl['host'] ?? null;
			$isManual = $host && in_array( $host, $manualWikis );

			$rows[] = [
				'wiki' => $m['wiki'],
				'url' => $m['url'],
				'attachedMethod' => $m['method'],
				'editCount' => $m['editcount'],
				'attachedTimestamp' => $m['timestamp'],
				'groups' => $m['groups'] ?? [],
				'blocked' => isset( $m['blocked'] ),
				'manual' => $isManual,
			];
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
		foreach ( [ 'localwiki', 'attached-on', 'method' ] as $col ) {
			$html .= Html::element( 'th', [], $this->msg( "centralauth-admin-list-$col" )->text() );
		}
		$html .= Html::element( 'th', [], '' ); // Checkbox column
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

			// Checkbox
			$checkbox = '';
			if ( $row['manual'] ) {
				$checkbox = Html::check( 'remove_wikis[]', false, [ 'value' => $wikiId ] );
			}
			$html .= Html::rawElement( 'td', [ 'style' => 'text-align: center;' ], $checkbox );

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
			Html::rawElement( 'h2', [ 'class' => 'oo-ui-fieldsetLayout-header', 'style' => 'margin-top: 0; font-size: 1.2em; font-weight: bold; border-bottom: 1px solid #a2a9b1; padding-bottom: 0.3em; margin-bottom: 0.5em;' ], $label ) .
			Html::rawElement( 'div', [ 'class' => 'oo-ui-fieldsetLayout-group' ], $html )
		);
	}
}
