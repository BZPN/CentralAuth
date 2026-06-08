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

	public function getLocalAttachedWikis( int $userId, bool $usePrimary = false ): array {
		$db = $usePrimary ? $this->dbProvider->getPrimaryDatabase() : $this->dbProvider->getReplicaDatabase();
		$wikis = $db->newSelectQueryBuilder()
			->select( 'mla_wiki_id' )
			->from( 'mca_local_attachments' )
			->where( [ 'mla_user_id' => $userId ] )
			->fetchFieldValues();
		return array_map( 'strtolower', $wikis );
	}

	public function isValidWiki( string $hostname ): bool {
		$url = "https://$hostname/w/api.php?" . http_build_query( [
			'action' => 'query',
			'meta' => 'siteinfo',
			'format' => 'json',
			'formatversion' => 2,
		] );

		$request = $this->requestFactory->create( $url, [ 'method' => 'GET' ], __METHOD__ );
		$status = $request->execute();

		return $status->isOK();
	}

	public function fetchUserMetadata( string $hostname, string $username ): ?array {
		$url = "https://$hostname/w/api.php?" . http_build_query( [
			'action' => 'query',
			'list' => 'users',
			'ususers' => $username,
			'usprop' => 'editcount|registration|groups|blockinfo',
			'format' => 'json',
			'formatversion' => 2,
		] );

		$request = $this->requestFactory->create( $url, [ 'method' => 'GET' ], __METHOD__ );
		$status = $request->execute();

		if ( !$status->isOK() ) {
			return null;
		}

		$data = json_decode( $request->getContent(), true );
		$user = $data['query']['users'][0] ?? null;

		if ( !$user || isset( $user['missing'] ) || isset( $user['invalid'] ) ) {
			return null;
		}

		return [
			'editcount' => $user['editcount'] ?? 0,
			'registration' => $user['registration'] ?? '',
			'groups' => $user['groups'] ?? [],
			'blocked' => isset( $user['blockid'] ),
		];
	}

	public function categorizeWiki( string $hostname ): string {
		$hostname = strtolower( $hostname );
		if ( str_ends_with( $hostname, '.wikipedia.org' ) || str_ends_with( $hostname, '.wikimedia.org' ) ) {
			return 'wm';
		}
		if ( str_ends_with( $hostname, '.miraheze.org' ) ) {
			return 'mh';
		}
		return 'local';
	}
}
