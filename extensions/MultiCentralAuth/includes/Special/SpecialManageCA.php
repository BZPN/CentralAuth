<?php

namespace MediaWiki\Extension\MultiCentralAuth\Special;

use MediaWiki\Block\BlockManager;
use MediaWiki\Extension\MultiCentralAuth\ExternalCAProvider;
use MediaWiki\User\UserFactory;
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
	private UserFactory $userFactory;

	public function __construct(
		IConnectionProvider $dbProvider,
		ExternalCAProvider $externalCAProvider,
		UserGroupManager $userGroupManager,
		BlockManager $blockManager,
		UserFactory $userFactory
	) {
		parent::__construct( 'ManageCA', 'ca-manage' );
		$this->dbProvider = $dbProvider;
		$this->externalCAProvider = $externalCAProvider;
		$this->userGroupManager = $userGroupManager;
		$this->blockManager = $blockManager;
		$this->userFactory = $userFactory;
	}

	public function execute( $subpage ) {
		$this->checkPermissions();
		$this->setHeaders();

		$targetName = $this->getRequest()->getText( 'target' );
		$user = $this->getUser();

		if ( $this->getAuthority()->isAllowed( 'ca-merge' ) && $targetName ) {
			$user = $this->userFactory->newFromName( $targetName );
			if ( !$user || !$user->isRegistered() ) {
				$this->getOutput()->addHTML( Html::errorBox( $this->msg( 'mca-error-user-not-found' )->parse() ) );
				return;
			}
		}

		$this->getOutput()->addModuleStyles( [
			'mediawiki.codex.messagebox.styles',
			'oojs-ui-core.styles',
			'oojs-ui-widgets.styles',
			'mediawiki.ui.input',
			'mediawiki.ui.button',
			'mediawiki.ui.vform',
			'ext.multicentralauth.styles'
		] );
		$this->getOutput()->addModules( 'ext.multicentralauth.js' );
		$this->getOutput()->addJsConfigVars( 'mcaFarmWikis', $farmWikis );

		$dbw = $this->dbProvider->getPrimaryDatabase();

		if ( $this->getAuthority()->isAllowed( 'ca-merge' ) ) {
			$this->showTargetForm( $targetName );
		}

		$externalUsernames = $this->externalCAProvider->getExternalUsernames( $user->getId() );
		$hasLinkedAccounts = (bool)( $externalUsernames['wm'] || $externalUsernames['mh'] );

		if ( !$hasLinkedAccounts ) {
			$this->getOutput()->addHTML( Html::warningBox( $this->msg( 'mca-manage-need-link' )->parse() ) );
		}

		$manualWikis = $this->externalCAProvider->getLocalAttachedWikis( $user->getId(), true );
		$suppressedWikis = $this->externalCAProvider->getSuppressedWikis( $user->getId(), true );

		// Bulk removal logic
		$removeWikis = $this->getRequest()->getArray( 'remove_wikis' );
		$comment = $this->getRequest()->getText( 'wpComment' );
		if ( $this->getRequest()->wasPosted() && $removeWikis && $this->getUser()->matchEditToken( $this->getRequest()->getVal( 'wpEditToken' ) ) ) {
			if ( !$comment ) {
				$this->getOutput()->addHTML( Html::errorBox( $this->msg( 'mca-error-comment-required' )->parse() ) );
			} else {
				$dbw->delete(
					'mca_local_attachments',
					[
						'mla_user_id' => $user->getId(),
						'mla_wiki_id' => $removeWikis,
					],
					__METHOD__
				);

				$insertSuppression = [];
				foreach ( $removeWikis as $wikiId ) {
					$insertSuppression[] = [
						'msw_user_id' => $user->getId(),
						'msw_wiki_id' => $wikiId,
					];
				}
				$dbw->insert(
					'mca_suppressed_wikis',
					$insertSuppression,
					__METHOD__,
					[ 'IGNORE' ]
				);

				// Group wikis by farm for auto-unmerge
				$wikisByFarm = [];
				foreach ( $removeWikis as $wikiId ) {
					$farmId = $this->externalCAProvider->categorizeWiki( $wikiId );
					$wikisByFarm[$farmId][] = $wikiId;
				}

				// Log actions and check for auto-unmerge
				foreach ( $removeWikis as $wikiId ) {
					$logEntry = new \ManualLogEntry( 'mca-log', 'manage-remove' );
					$logEntry->setPerformer( $this->getUser() );
					$logEntry->setTarget( $user->getUserPage() );
					$logEntry->setComment( $comment );
					$logEntry->setParameters( [ '4::wiki' => $wikiId ] );
					$logEntry->insert();
					$logEntry->publish( $logEntry->insert() );
				}

				// Auto-unmerge logic: if all wikis of a farm are removed, remove the external ID
				$externalUsernames = $this->externalCAProvider->getExternalUsernames( $user->getId() );
				foreach ( $wikisByFarm as $farmId => $wikis ) {
					if ( isset( $externalUsernames[$farmId] ) && $externalUsernames[$farmId] ) {
						// Check if any wikis of this farm still exist for this user
						$remainingWikis = $this->externalCAProvider->getLocalAttachedWikis( $user->getId(), true );
						$farmStillHasWikis = false;
						foreach ( $remainingWikis as $remWiki ) {
							if ( $this->externalCAProvider->categorizeWiki( $remWiki ) === $farmId ) {
								$farmStillHasWikis = true;
								break;
							}
						}

						if ( !$farmStillHasWikis ) {
							$dbw->delete( 'mca_external_userids', [ 'meu_user_id' => $user->getId(), 'meu_farm_id' => $farmId ], __METHOD__ );
						}
					}
				}

				$this->getOutput()->addHTML( Html::successBox( $this->msg( 'mca-manage-success' )->parse() ) );
				$this->getOutput()->redirect( $this->getPageTitle()->getFullURL( [ 'target' => $user->getName() ] ) );
				return;
			}
		}

		// 1. Instructions
		$this->getOutput()->addHTML( $this->getFramedFieldsetLayout(
			Html::element( 'p', [], $this->msg( 'mca-manage-instructions' )->text() ),
			'manageca',
			'mca-header-type-list'
		) );

		// 2. Add form
		$farms = $this->externalCAProvider->getFarms();
		$farmOptions = [ 'Manual entry' => 'manual' ];
		foreach ( $farms as $farm ) {
			$farmOptions[$farm['name']] = $farm['id'];
		}

		$farmWikis = [];
		foreach ( $farms as $farm ) {
			$wikis = $this->externalCAProvider->getFarmWikis( $farm['id'] );
			foreach ( $wikis as $w ) {
				$farmWikis[$farm['id']][$w] = $w;
			}
		}

		$formDescriptor = [
			'farm' => [
				'type' => 'select',
				'name' => 'farm',
				'label-message' => 'mca-manage-farm',
				'options' => $farmOptions,
				'default' => 'manual',
				'id' => 'mca-farm-select',
			],
			'dynamic_wiki' => [
				'type' => 'select',
				'name' => 'dynamic_wiki',
				'label-message' => 'mca-manage-wiki-selection',
				'options' => [],
				'cssclass' => 'mca-dynamic-wiki-field',
				'id' => 'mca-wiki-select',
			],
			'subdomain' => [
				'type' => 'text',
				'name' => 'subdomain',
				'label-message' => 'mca-manage-subdomain',
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
			],
		];

		// Populate dynamic wikis if a farm is selected (this would ideally be JS, but we'll handle it in onSubmit)

		if ( !$hasLinkedAccounts ) {
			foreach ( $formDescriptor as &$field ) {
				$field['disabled'] = true;
			}
		}

		$formDescriptor['comment'] = [
			'type' => 'text',
			'name' => 'comment',
			'label-message' => 'mca-comment',
			'required' => true,
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setSubmitCallback( [ $this, 'onSubmit' ] );
		$htmlForm->setSubmitTextMsg( 'mca-manage-action-add' );
		$htmlForm->addHiddenField( 'target', $user->getName() );

		$htmlForm->prepareForm();
		$status = $htmlForm->tryAuthorizedSubmit();
		$this->getOutput()->addHTML( $this->getFramedFieldsetLayout( $htmlForm->getHTML( $status ), 'mca-manage-action-add', 'mca-header-type-view' ) );

		// 3. Tables with checkboxes
		$this->getOutput()->addHTML( Html::openElement( 'form', [ 'method' => 'post', 'action' => $this->getPageTitle()->getLocalURL() ] ) );
		$this->getOutput()->addHTML( Html::hidden( 'wpEditToken', $this->getUser()->getEditToken() ) );
		$this->getOutput()->addHTML( Html::hidden( 'target', $user->getName() ) );

		$farms = $this->externalCAProvider->getFarms();
		$farmManual = [];
		foreach ( $farms as $farm ) {
			$farmManual[$farm['id']] = [];
		}
		$otherManual = [];

		foreach ( $manualWikis as $wiki ) {
			$cat = $this->externalCAProvider->categorizeWiki( $wiki );
			if ( isset( $farmManual[$cat] ) ) {
				$farmManual[$cat][] = $wiki;
			} else {
				$otherManual[] = $wiki;
			}
		}

		foreach ( $farms as $farm ) {
			$farmId = $farm['id'];
			$extUsername = $externalUsernames[$farmId] ?? null;
			$manual = $farmManual[$farmId];

			if ( $extUsername || $manual ) {
				if ( $farm['is_centralauth'] && $farm['api_url'] ) {
					$data = $this->externalCAProvider->fetchGlobalUserInfo( $farm['api_url'], $extUsername ?? '' );
					$this->showExternalData( $data, $extUsername ?? $user->getName(), $farm['name'], $farm['header_msg'] ?? 'mca-header-list-generic', $manual, $suppressedWikis );
				} else {
					$this->showOtherManualData( $user, $manual, $farm['name'], $farm['header_msg'] ?? 'mca-header-list-generic' );
				}
			}
		}

		if ( $otherManual ) {
			$this->showOtherManualData( $user, $otherManual, 'Other', 'mca-manage-current-wikis' );
		}

		if ( $externalUsernames || $manualWikis ) {
			$deleteContent = Html::element( 'p', [], $this->msg( 'mca-manage-delete-help' )->text() );
			$deleteContent .= Html::rawElement( 'div', [ 'class' => 'mw-ui-vform' ],
				Html::rawElement( 'div', [ 'class' => 'mw-ui-field' ],
					Html::element( 'label', [], $this->msg( 'mca-comment' )->text() ) .
					Html::element( 'input', [ 'name' => 'wpComment', 'class' => 'mw-ui-input', 'required' => true ] )
				)
			);
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
		$farm = $formData['farm'];
		$comment = $formData['comment'];
		if ( $farm === 'manual' ) {
			$subdomain = $formData['subdomain'];
			$domain = $formData['domain'];
			$hostname = strtolower( $subdomain . $domain );
		} else {
			$hostname = $formData['dynamic_wiki'] ?: $formData['subdomain']; // Fallback to subdomain if dynamic selection was empty
		}

		if ( !$hostname ) {
			return $this->msg( 'mca-error-no-wiki-provided' );
		}
		$targetName = $this->getRequest()->getText( 'target' );

		if ( !$this->externalCAProvider->isValidWiki( $hostname ) ) {
			return $this->msg( 'mca-manage-error-wiki-not-found', $hostname );
		}

		$user = $this->getUser();
		if ( $this->getAuthority()->isAllowed( 'ca-merge' ) && $targetName ) {
			$user = $this->userFactory->newFromName( $targetName );
		}

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

		$dbw->delete(
			'mca_suppressed_wikis',
			[
				'msw_user_id' => $user->getId(),
				'msw_wiki_id' => $hostname,
			],
			__METHOD__
		);

		// Log action
		$logEntry = new \ManualLogEntry( 'mca-log', 'manage-add' );
		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setTarget( $user->getUserPage() );
		$logEntry->setComment( $comment );
		$logEntry->setParameters( [ '4::wiki' => $hostname ] );
		$logEntry->insert();
		$logEntry->publish( $logEntry->insert() );

		$this->getOutput()->addHTML( Html::successBox( $this->msg( 'mca-manage-merged-success', $hostname )->parse() ) );
		$this->getOutput()->redirect( $this->getPageTitle()->getFullURL( [ 'target' => $user->getName() ] ) );
		return true;
	}

	private function showTargetForm( $default = '' ) {
		$formDescriptor = [
			'target' => [
				'type' => 'user',
				'name' => 'target',
				'label-message' => 'mca-target-label',
				'default' => $default,
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setMethod( 'get' );
		$htmlForm->setSubmitTextMsg( 'mca-view-user-info' );
		$htmlForm->prepareForm();

		$this->getOutput()->addHTML( $this->getFramedFieldsetLayout( $htmlForm->getHTML( false ), 'mca-header-view', 'mca-header-type-view' ) );
	}

	private function showExternalData( ?array $data, string $username, string $sourceName, string $tableHeaderMsg, array $manualWikis, array $suppressedWikis ) {
		$merged = $data['merged'] ?? [];
		$rows = [];
		$attachedWikis = [];

		foreach ( $merged as $m ) {
			$parsedUrl = parse_url( $m['url'] );
			$host = isset( $parsedUrl['host'] ) ? strtolower( $parsedUrl['host'] ) : null;

			if ( $host && in_array( $host, $suppressedWikis ) ) {
				continue;
			}

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

	private function showOtherManualData( $user, array $manualWikis, string $farmName, string $headerMsg ) {
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
			$this->getOutput()->addHTML( $this->getFramedFieldsetLayout( $table, $headerMsg, 'mca-header-type-list' ) );
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
