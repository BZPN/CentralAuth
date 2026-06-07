<?php

use MediaWiki\Extension\MultiCentralAuth\ExternalCAProvider;
use MediaWiki\MediaWikiServices;

return [
	'MultiCentralAuth.ExternalCAProvider' => function ( MediaWikiServices $services ): ExternalCAProvider {
		return new ExternalCAProvider(
			$services->getHttpRequestFactory(),
			$services->getConnectionProvider()
		);
	},
];
