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

	public static function onMCAMergeRequestSubmitted( $requestId, $user ) {
		if ( !class_exists( '\EchoEvent' ) ) {
			return;
		}

		// Find users with ca-merge permission
		$dbr = \MediaWiki\MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();

		// Find all groups that have the 'ca-merge' permission
		$groupsWithPermission = [];
		$config = \MediaWiki\MediaWikiServices::getInstance()->getMainConfig();
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

		$targets = [];
		$userFactory = \MediaWiki\MediaWikiServices::getInstance()->getUserFactory();
		foreach ( $res as $row ) {
			$targets[] = $userFactory->newFromId( $row->ug_user );
		}

		if ( $targets ) {
			\EchoEvent::create( [
				'type' => 'mca-merge-request-submitted',
				'title' => \MediaWiki\SpecialPage\SpecialPage::getTitleFor( 'CAMergeRequestQueue', "view/$requestId" ),
				'extra' => [
					'request-id' => $requestId,
					'user-id' => $user->getId(),
				],
				'agent' => $user,
				'targets' => array_merge( $targets, [ $user ] ),
			] );
		}
	}

	public static function onMCAMergeRequestResolved( $requestId, $user, $status ) {
		if ( !class_exists( '\EchoEvent' ) || !$user ) {
			return;
		}

		$gender = \MediaWiki\MediaWikiServices::getInstance()
			->getUserOptionsLookup()
			->getOption( $user, 'gender' );

		\EchoEvent::create( [
			'type' => 'mca-merge-request-resolved',
			'title' => \MediaWiki\SpecialPage\SpecialPage::getTitleFor( 'CAMergeRequestQueue', "view/$requestId" ),
			'extra' => [
				'request-id' => $requestId,
				'status' => $status,
				'gender' => $gender,
			],
			'agent' => \MediaWiki\MediaWikiServices::getInstance()->getUserFactory()->newAnonymous(),
			'targets' => [ $user ],
		] );
	}

	public static function onCheckCanLogin( $user, $authBackend, &$canLogin ) {
		// $user is UserIdentity here.
		if ( !$user->isRegistered() ) {
			return;
		}

		$dbProvider = \MediaWiki\MediaWikiServices::getInstance()->getConnectionProvider();
		$dbr = $dbProvider->getReplicaDatabase();

		$lock = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'mca_locks' )
			->where( [ 'mcl_user_id' => $user->getId() ] )
			->andWhere( 'mcl_expiry > ' . $dbr->addQuotes( $dbr->timestamp() ) . ' OR mcl_expiry = ' . $dbr->addQuotes( 'infinity' ) )
			->fetchRow();

		if ( $lock ) {
			$expiry = $lock->mcl_expiry;
			$context = \MediaWiki\Context\RequestContext::getMain();
			$lang = \MediaWiki\MediaWikiServices::getInstance()->getLanguageFactory()->getLanguage(
				$context->getLanguage()->getCode()
			);
			if ( $expiry === 'infinity' ) {
				$formattedExpiry = wfMessage( 'infiniteblock' )->inLanguage( $lang )->text();
			} else {
				$formattedExpiry = $lang->userTimeAndDate( $expiry, $context->getUser() );
			}

			$canLogin = \Status::newFatal( 'mca-lock-blocked-login', $lock->mcl_reason, $formattedExpiry );
			return false;
		}
	}

	public static function onSpecialContributionsBeforeMainOutput( $id, $user, $out ) {
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
			->andWhere( 'mcl_expiry > ' . $dbr->addQuotes( $dbr->timestamp() ) . ' OR mcl_expiry = ' . $dbr->addQuotes( 'infinity' ) )
			->fetchRow();

		if ( $lock ) {
			$performer = \MediaWiki\MediaWikiServices::getInstance()->getUserFactory()->newFromId( $lock->mcl_by );
			if ( $performer && $performer->isRegistered() ) {
				$blockerLink = Html::element( 'a', [
					'href' => \MediaWiki\SpecialPage\SpecialPage::getTitleFor( 'CentralAuth', $performer->getName() )->getFullURL(),
				], $performer->getName() );
			} else {
				$blockerLink = $lock->mcl_by;
			}

			$expiry = $lock->mcl_expiry;
			$lang = $out->getLanguage();
			if ( $expiry === 'infinity' ) {
				$formattedExpiry = wfMessage( 'infiniteblock' )->inLanguage( $lang )->text();
			} else {
				$formattedExpiry = $lang->userTimeAndDate( $expiry, $out->getUser() );
			}
			$reason = $lock->mcl_reason;
			$timestamp = $lang->userTimeAndDate( $lock->mcl_timestamp, $out->getUser() );

			$msg = wfMessage( 'mca-global-lock-notice' )
				->rawParams( $blockerLink )
				->params( $formattedExpiry, $reason, $timestamp )
				->parse();

			$out->addModuleStyles( 'oojs-ui.styles.icons-moderation' );
			$out->addHTML( Html::rawElement( 'div', [ 'class' => 'mw-message-box mw-message-box-error mca-global-lock-notice-box' ],
				Html::element( 'span', [ 'class' => 'mw-message-box-icon oo-ui-icon-lock' ] ) .
				Html::rawElement( 'div', [], $msg )
			) );
		}
	}
}
