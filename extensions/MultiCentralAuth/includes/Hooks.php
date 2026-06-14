<?php

namespace MediaWiki\Extension\MultiCentralAuth;

use MediaWiki\Html\Html;
use Wikimedia\Rdbms\Database\DatabaseSqlite;

class Hooks {
	public static function onLoadExtensionSchemaUpdates( $updater ) {
		$dbType = $updater->getDB()->getType();
		$sqlFile = __DIR__ . '/../sql/tables.sql';
		$updater->addExtensionTable( 'mca_external_ids', $sqlFile );
		$updater->addExtensionTable( 'mca_local_attachments', $sqlFile );
		$updater->addExtensionTable( 'mca_suppressed_wikis', __DIR__ . '/../sql/mca_suppressed_wikis.sql' );
		$updater->addExtensionTable( 'mca_merge_requests', __DIR__ . '/../sql/mca_merge_requests.sql' );
		$updater->addExtensionTable( 'mca_farms', __DIR__ . '/../sql/mca_farms.sql' );
		$updater->addExtensionTable( 'mca_farm_wikis', __DIR__ . '/../sql/mca_farms.sql' );
		$updater->addExtensionTable( 'mca_external_userids', __DIR__ . '/../sql/mca_external_userids.sql' );
		$updater->addExtensionTable( 'mca_locks', __DIR__ . '/../sql/mca_locks.sql' );
	}

	public static function onContributionsToolLinks( $id, $user, &$toolLinks ) {
		// In some versions $user is a Title, in others a User.
		$target = ( $user instanceof \MediaWiki\User\User ) ? $user->getName() : $user->getText();
		$toolLinks[] = Html::element( 'a', [
			'href' => \MediaWiki\SpecialPage\SpecialPage::getTitleFor( 'CentralAuth', $target )->getFullURL(),
			'class' => 'mw-contributions-link-centralauth'
		], wfMessage( 'mca-global-account-info' )->text() );
	}

	public static function onEchoGetDefaultNotifiedUsers( $event, &$users ) {
		$extra = $event->getExtra();
		if ( isset( $extra['recipient-ids'] ) && is_array( $extra['recipient-ids'] ) ) {
			$userFactory = \MediaWiki\MediaWikiServices::getInstance()->getUserFactory();
			foreach ( $extra['recipient-ids'] as $id ) {
				$u = $userFactory->newFromId( $id );
				if ( $u && $u->isRegistered() ) {
					$users[$id] = $u;
				}
			}
		}
		return true;
	}

	public static function onMCAMergeRequestSubmitted( $requestId, $user ) {
		if ( !class_exists( '\EchoEvent' ) && !class_exists( '\MediaWiki\Extension\Echo\Model\Event' ) ) {
			return;
		}

		$services = \MediaWiki\MediaWikiServices::getInstance();
		// Find users with ca-merge permission
		$dbr = $services->getConnectionProvider()->getReplicaDatabase();

		// Find all groups that have the 'ca-merge' permission
		$groupsWithPermission = [];
		$config = $services->getMainConfig();
		$groupPermissions = $config->get( \MediaWiki\MainConfigNames::GroupPermissions );
		foreach ( $groupPermissions as $group => $permissions ) {
			if ( isset( $permissions['ca-merge'] ) && $permissions['ca-merge'] ) {
				$groupsWithPermission[] = $group;
			}
		}

		if ( !$groupsWithPermission ) {
			$groupsWithPermission = [ 'sysop' ];
		}

		$res = $dbr->newSelectQueryBuilder()
			->select( 'ug_user' )
			->from( 'user_groups' )
			->where( [ 'ug_group' => $groupsWithPermission ] )
			->fetchResultSet();

		$targetIds = [];
		foreach ( $res as $row ) {
			$targetIds[] = (int)$row->ug_user;
		}

		$targetIds[] = $user->getId();

		self::createEchoEvent( [
			'type' => 'mca-merge-request-submitted',
			'title' => \MediaWiki\SpecialPage\SpecialPage::getTitleFor( 'CAMergeRequestQueue', "view/$requestId" ),
			'extra' => [
				'request-id' => $requestId,
				'user-id' => $user->getId(),
				'recipient-ids' => array_unique( $targetIds ),
			],
			'agent' => $user,
		] );
	}

	public static function onMCAMergeRequestResolved( $requestId, $user, $status, $performer = null ) {
		if ( !$user ) {
			return;
		}

		$services = \MediaWiki\MediaWikiServices::getInstance();

		self::createEchoEvent( [
			'type' => 'mca-merge-request-resolved',
			'title' => \MediaWiki\SpecialPage\SpecialPage::getTitleFor( 'CAMergeRequestQueue', "view/$requestId" ),
			'extra' => [
				'request-id' => $requestId,
				'status' => $status,
				'recipient-ids' => [ $user->getId() ],
			],
			'agent' => $performer ?? $services->getUserFactory()->newAnonymous(),
		] );
	}

	public static function onMCAAccountMerged( $user, $performer, $systems ) {
		if ( !$user ) {
			return;
		}

		self::createEchoEvent( [
			'type' => 'mca-account-merged',
			'title' => \MediaWiki\SpecialPage\SpecialPage::getTitleFor( 'CentralAuth' ),
			'extra' => [
				'systems' => $systems,
				'recipient-ids' => [ $user->getId() ],
			],
			'agent' => $performer,
		] );
	}

	public static function onMCAAccountUnmerged( $user, $performer, $systems ) {
		if ( !$user ) {
			return;
		}

		self::createEchoEvent( [
			'type' => 'mca-account-unmerged',
			'title' => \MediaWiki\SpecialPage\SpecialPage::getTitleFor( 'CentralAuth' ),
			'extra' => [
				'systems' => $systems,
				'recipient-ids' => [ $user->getId() ],
			],
			'agent' => $performer,
		] );
	}

	private static function createEchoEvent( array $params ) {
		if ( class_exists( '\EchoEvent' ) ) {
			\EchoEvent::create( $params );
		} elseif ( class_exists( '\MediaWiki\Extension\Echo\Model\Event' ) ) {
			\MediaWiki\Extension\Echo\Model\Event::create( $params );
		}
	}

	public static function onSpecialContributionsBeforeMainOutput( $id, $user, $sp ) {
		// $user can be a Title or a User object depending on version
		$targetName = ( $user instanceof \MediaWiki\User\User ) ? $user->getName() : $user->getText();
		$targetUser = \MediaWiki\MediaWikiServices::getInstance()->getUserFactory()->newFromName( $targetName );

		if ( !$targetUser || !$targetUser->isRegistered() ) {
			return;
		}

		$dbProvider = \MediaWiki\MediaWikiServices::getInstance()->getConnectionProvider();
		$dbr = $dbProvider->getReplicaDatabase();

		$lock = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'mca_locks' )
			->where( [ 'mcl_user_id' => $targetUser->getId() ] )
			->andWhere( $dbr->expr( 'mcl_expiry', '>', $dbr->timestamp() )
				->or( 'mcl_expiry', '=', 'infinity' )
			)
			->fetchRow();

		if ( $lock ) {
			$out = $sp->getOutput();
			$out->addModuleStyles( [
				'oojs-ui.styles.icons-moderation',
				'ext.multicentralauth.styles',
				'mediawiki.ui.button'
			] );

			$dbr = \MediaWiki\MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
			$logId = $dbr->newSelectQueryBuilder()
				->select( 'log_id' )
				->from( 'logging' )
				->where( [
					'log_type' => 'mca-lock-log',
					'log_action' => 'lock',
					'log_namespace' => NS_USER,
					'log_title' => $targetUser->getUserPage()->getDBkey()
				] )
				->orderBy( 'log_timestamp', 'DESC' )
				->fetchField();

			$msg = wfMessage( 'mca-lock-notice-header' )->parse();
			$logEntryHtml = '';

			if ( $logId ) {
				$logEntry = \MediaWiki\Logging\DatabaseLogEntry::newFromId( $logId, $dbr );
				if ( $logEntry ) {
					$formatter = \MediaWiki\Logging\LogFormatter::newFromEntry( $logEntry );
					$formatter->setContext( $sp->getContext() );
					$lang = $sp->getLanguage();
					$tsLink = Html::element( 'a', [
						'href' => \MediaWiki\SpecialPage\SpecialPage::getTitleFor( 'Log', 'mca-lock-log' )->getFullURL( [
							'logid' => $logId
						] )
					], $lang->userTimeAndDate( $logEntry->getTimestamp(), $sp->getUser() ) );

					$logEntryHtml = Html::rawElement( 'ul', [],
						Html::rawElement( 'li', [], $tsLink . ' ' . $formatter->getActionText() . ' ' . $formatter->getComment() )
					);
				}
			}

			$logLink = \MediaWiki\SpecialPage\SpecialPage::getTitleFor( 'Log', 'mca-lock-log' )->getFullURL( [
				'page' => $targetUser->getUserPage()->getPrefixedText()
			] );

			$link = Html::element( 'a', [
				'href' => $logLink,
			], wfMessage( 'mca-view-full-log' )->text() );

			$out->addHTML( Html::rawElement( 'div', [ 'class' => 'mw-message-box mca-global-lock-notice-box-green' ],
				Html::element( 'span', [ 'class' => 'mw-message-box-icon oo-ui-icon-error' ] ) .
				Html::rawElement( 'div', [ 'class' => 'mw-message-box-content' ],
					Html::rawElement( 'div', [], $msg ) .
					$logEntryHtml .
					Html::rawElement( 'div', [ 'style' => 'margin-top: 0.5em;' ], $link )
				)
			) );
		}
	}
}
