<?php

namespace MediaWiki\Extension\MultiCentralAuth;

use MediaWiki\Http\HttpRequestFactory;
use Wikimedia\Rdbms\IConnectionProvider;

class ExternalCAProvider {

	private HttpRequestFactory $requestFactory;
	private IConnectionProvider $dbProvider;

	public function __construct(
		HttpRequestFactory $requestFactory,
		IConnectionProvider $dbProvider
	) {
		$this->requestFactory = $requestFactory;
		$this->dbProvider = $dbProvider;
	}

	public function getExternalUsernames( int $userId ): array {
		$dbr = $this->dbProvider->getReplicaDatabase();
		$row = $dbr->newSelectQueryBuilder()
			->select( [ 'mei_wm_username', 'mei_mh_username' ] )
			->from( 'mca_external_ids' )
			->where( [ 'mei_user_id' => $userId ] )
			->fetchRow();

		if ( !$row ) {
			return [ 'wm' => null, 'mh' => null ];
		}

		return [
			'wm' => $row->mei_wm_username,
			'mh' => $row->mei_mh_username,
		];
	}

	public function fetchGlobalUserInfo( string $apiUrl, string $username ): ?array {
		if ( !$username ) {
			return null;
		}

		$url = $apiUrl . '?' . http_build_query( [
			'action' => 'query',
			'meta' => 'globaluserinfo',
			'guiuser' => $username,
			'guiprop' => 'merged|groups|editcount',
			'format' => 'json',
			'formatversion' => 2,
		] );

		$options = [ 'method' => 'GET' ];
		$request = $this->requestFactory->create( $url, $options, __METHOD__ );
		$status = $request->execute();

		if ( !$status->isOK() ) {
			return null;
		}

		$data = json_decode( $request->getContent(), true );
		if ( isset( $data['query']['globaluserinfo']['missing'] ) ) {
			return null;
		}

		return $data['query']['globaluserinfo'] ?? null;
	}

	public function getLocalAttachedWikis( int $userId ): array {
		$dbr = $this->dbProvider->getReplicaDatabase();
		return $dbr->newSelectQueryBuilder()
			->select( 'mla_wiki_id' )
			->from( 'mca_local_attachments' )
			->where( [ 'mla_user_id' => $userId ] )
			->fetchFieldValues();
	}
}
