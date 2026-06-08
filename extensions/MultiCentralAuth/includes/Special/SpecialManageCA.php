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
			'mediawiki.ui.input',
			'mediawiki.ui.button',
			'mediawiki.ui.vform',
			'ext.multicentralauth.styles'
		] );

		$user = $this->getUser();
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$externalUsernames = $this->externalCAProvider->getExternalUsernames( $user->getId() );
		$hasLinkedAccounts = (bool)( $externalUsernames['wm'] || $externalUsernames['mh'] );

		if ( !$hasLinkedAccounts ) {
			$this->getOutput()->addHTML( Html::warningBox( $this->msg( 'mca-manage-need-link' )->parse() ) );
		}

		$manualWikis = $this->externalCAProvider->getLocalAttachedWikis( $user->getId(), true );

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

		// 1. Instructions
		$this->getOutput()->addHTML( $this->getFramedFieldsetLayout(
			Html::element( 'p', [], $this->msg( 'mca-manage-instructions' )->text() ),
			'manageca',
			'mca-header-type-list'
		) );

		// 2. Add form
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
					'.wiktionary.org' => '.wiktionary.org',
					'.wikibooks.org' => '.wikibooks.org',
					'.wikisource.org' => '.wikisource.org',
					'.wikiquote.org' => '.wikiquote.org',
					'.wikinews.org' => '.wikinews.org',
					'.miraheze.org' => '.miraheze.org',
				],
				'required' => true,
			],
		];

		if ( !$hasLinkedAccounts ) {
			foreach ( $formDescriptor as &$field ) {
				$field['disabled'] = true;
			}
		}

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setSubmitCallback( [ $this, 'onSubmit' ] );
		$htmlForm->setSubmitTextMsg( 'mca-manage-action-add' );

		$htmlForm->prepareForm();
		$status = $htmlForm->tryAuthorizedSubmit();
		$this->getOutput()->addHTML( $this->getFramedFieldsetLayout( $htmlForm->getHTML( $status ), 'mca-manage-action-add', 'mca-header-type-view' ) );

		// 3. Tables with checkboxes
		$this->getOutput()->addHTML( Html::openElement( 'form', [ 'method' => 'post', 'action' => $this->getPageTitle()->getLocalURL() ] ) );
		$this->getOutput()->addHTML( Html::hidden( 'wpEditToken', $this->getUser()->getEditToken() ) );

		$wmManual = [];
		$mhManual = [];
		$otherManual = [];
		foreach ( $manualWikis as $wiki ) {
			$cat = $this->externalCAProvider->categorizeWiki( $wiki );
			if ( $cat === 'wm' ) {
				$wmManual[] = $wiki;
			} elseif ( $cat === 'mh' ) {
				$mhManual[] = $wiki;
			} else {
				$otherManual[] = $wiki;
			}
		}

		if ( $externalUsernames['wm'] || $wmManual ) {
			$wmData = $this->externalCAProvider->fetchGlobalUserInfo( 'https://meta.wikimedia.org/w/api.php', $externalUsernames['wm'] ?? '' );
			$this->showExternalData( $wmData, $externalUsernames['wm'] ?? $user->getName(), 'Wikimedia', 'mca-header-list-wm', $wmManual );
		}

		if ( $externalUsernames['mh'] || $mhManual ) {
			$mhData = $this->externalCAProvider->fetchGlobalUserInfo( 'https://meta.miraheze.org/w/api.php', $externalUsernames['mh'] ?? '' );
			$this->showExternalData( $mhData, $externalUsernames['mh'] ?? $user->getName(), 'Miraheze', 'mca-header-list-mh', $mhManual );
		}

		if ( $otherManual ) {
			$this->showOtherManualData( $user, $otherManual );
		}

		if ( $externalUsernames['wm'] || $externalUsernames['mh'] || $manualWikis ) {
			$deleteContent = Html::element( 'p', [], $this->msg( 'mca-manage-delete-help' )->text() );
			$deleteContent .= Html::submitButton( $this->msg( 'mca-manage-delete-selected' )->text(), [
				'name' => 'delete_selected',
				'class' => 'mw-ui-button mw-ui-destructive',
				'onclick' => "return confirm(" . json_encode( $this->msg( 'mca-manage-confirm-delete' )->text() ) . ");"
			] );
			$this->getOutput()->addHTML( $this->getFramedFieldsetLayout( $deleteContent, 'mca-manage-delete-selected', 'mca-header-type-delete' ) );
		}

		$this->getOutput()->addHTML( Html::closeElement( 'form' ) );
	}

	public function onSubmit( array $formData ) {
		$subdomain = $formData['subdomain'];
		$domain = $formData['domain'];
		$hostname = strtolower( ( $domain === 'other' ) ? $subdomain : $subdomain . $domain );

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
			$host = isset( $parsedUrl['host'] ) ? strtolower( $parsedUrl['host'] ) : null;
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
				'host' => $host,
			];
			if ( $host ) {
				$attachedWikis[] = $host;
			}
		}

		foreach ( $manualWikis as $wikiHost ) {
			if ( in_array( $wikiHost, $attachedWikis ) ) {
				continue;
			}
			$metadata = $this->externalCAProvider->fetchUserMetadata( $wikiHost, $username ) ?? [];
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

		$table = $this->renderTable( $rows, $username );
		$this->getOutput()->addHTML( $this->getFramedFieldsetLayout( $table, $tableHeaderMsg, 'mca-header-type-list' ) );
	}

	private function showOtherManualData( $user, array $manualWikis ) {
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
			$table = $this->renderTable( $rows, $user->getName() );
			$this->getOutput()->addHTML( $this->getFramedFieldsetLayout( $table, 'mca-manage-current-wikis', 'mca-header-type-list' ) );
		}
	}

	private function renderTable( array $rows, $username ) {
		$lang = $this->getLanguage();
		$html = Html::openElement( 'table', [ 'class' => 'wikitable sortable mw-centralauth-wikislist', 'style' => 'width: 100%;' ] );
		$html .= Html::openElement( 'thead' ) . Html::openElement( 'tr' );
		$html .= Html::rawElement( 'th', [ 'class' => 'unsortable' ], '&nbsp;' ); // Checkbox column first
		foreach ( [ 'localwiki', 'attached-on', 'method' ] as $col ) {
			$html .= Html::element( 'th', [], $this->msg( "centralauth-admin-list-$col" )->text() );
		}
		$html .= Html::closeElement( 'tr' ) . Html::closeElement( 'thead' );
		$html .= Html::openElement( 'tbody' );

		foreach ( $rows as $row ) {
			$html .= Html::openElement( 'tr' );

			// Checkbox
			$checkbox = '';
			if ( isset( $row['host'] ) ) {
				$checkbox = Html::element( 'input', [
					'type' => 'checkbox',
					'name' => 'remove_wikis[]',
					'value' => $row['host']
				] );
			}
			$html .= Html::rawElement( 'td', [ 'style' => 'text-align: center;' ], $checkbox );

			// Wiki hostname/id
			$wikiId = $row['wiki'];
			$url = $row['url'] ?? null;
			$wikiDisplayId = $wikiId;

			if ( $url ) {
				$parsedUrl = parse_url( $url );
				$wikiDisplayId = $parsedUrl['host'] ?? $wikiId;
			}

			// Wiki Link
			if ( $url ) {
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

			$html .= Html::closeElement( 'tr' );
		}

		$html .= Html::closeElement( 'tbody' ) . Html::closeElement( 'table' );
		return $html;
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
