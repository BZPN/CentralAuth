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
	}

	public static function onContributionsToolLinks( $id, $title, &$toolLinks ) {
		$toolLinks[] = Html::element( 'a', [
			'href' => \MediaWiki\SpecialPage\SpecialPage::getTitleFor( 'CentralAuth', $title->getText() )->getFullURL(),
			'class' => 'mw-contributions-link-centralauth'
		], \MediaWiki\MediaWikiServices::getInstance()->getMessageCache()->get( 'mca-global-account-info' ) );
	}

	public static function onMCAMergeRequestSubmitted( $requestId, $user ) {
		if ( !class_exists( '\EchoEvent' ) ) {
			return;
		}

		// Find users with ca-merge permission
		$dbr = \MediaWiki\MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
		$res = $dbr->newSelectQueryBuilder()
			->select( 'ug_user' )
			->from( 'user_groups' )
			->where( [ 'ug_group' => [ 'sysop' ] ] ) // Simplification: assume sysops have it as per extension.json
			->fetchResultSet();

		$targets = [];
		foreach ( $res as $row ) {
			$targets[] = \MediaWiki\User\User::newFromId( $row->ug_user );
		}

		if ( $targets ) {
			\EchoEvent::create( [
				'type' => 'mca-merge-request-submitted',
				'title' => \MediaWiki\Title\Title::newFromText( "Special:CAMergeRequestQueue/view/$requestId" ),
				'extra' => [
					'request-id' => $requestId,
					'user-id' => $user->getId(),
				],
				'agent' => $user,
			] );
		}
	}

	public static function onMCAMergeRequestResolved( $requestId, $user, $status ) {
		if ( !class_exists( '\EchoEvent' ) ) {
			return;
		}

		\EchoEvent::create( [
			'type' => 'mca-merge-request-resolved',
			'title' => \MediaWiki\Title\Title::newFromText( "Special:CAMergeRequestQueue/view/$requestId" ),
			'extra' => [
				'request-id' => $requestId,
				'status' => $status,
			],
			'agent' => \MediaWiki\MediaWikiServices::getInstance()->getUserFactory()->newUnknown(),
		] );
	}
}
