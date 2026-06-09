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
}
