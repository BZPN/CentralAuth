<?php

namespace MediaWiki\Extension\MultiCentralAuth;

use Wikimedia\Rdbms\Database\DatabaseSqlite;

class Hooks {
	public static function onLoadExtensionSchemaUpdates( $updater ) {
		$dbType = $updater->getDB()->getType();
		$sqlFile = __DIR__ . '/../sql/tables.sql';
		$updater->addExtensionTable( 'mca_external_ids', $sqlFile );
		$updater->addExtensionTable( 'mca_local_attachments', $sqlFile );
		$updater->addExtensionTable( 'mca_suppressed_wikis', __DIR__ . '/../sql/mca_suppressed_wikis.sql' );
	}
}
