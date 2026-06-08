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

		$farms = $this->getFarms();
		$result = [];
		foreach ( $farms as $farm ) {
			$result[$farm['id']] = null;
		}

		$rows = $dbr->newSelectQueryBuilder()
			->select( [ 'meu_farm_id', 'meu_external_username' ] )
			->from( 'mca_external_userids' )
			->where( [ 'meu_user_id' => $userId ] )
			->fetchResultSet();

		foreach ( $rows as $row ) {
			$result[$row->meu_farm_id] = $row->meu_external_username;
		}

		// Fallback for legacy table
		if ( $dbr->tableExists( 'mca_external_ids' ) ) {
			$legacy = $dbr->newSelectQueryBuilder()
				->select( [ 'mei_wm_username', 'mei_mh_username' ] )
				->from( 'mca_external_ids' )
				->where( [ 'mei_user_id' => $userId ] )
				->fetchRow();
			if ( $legacy ) {
				if ( isset( $result['wm'] ) && $result['wm'] === null ) $result['wm'] = $legacy->mei_wm_username;
				if ( isset( $result['mh'] ) && $result['mh'] === null ) $result['mh'] = $legacy->mei_mh_username;
			}
		}

		return $result;
	}

	public function getFarms(): array {
		$dbr = $this->dbProvider->getReplicaDatabase();
		$rows = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'mca_farms' )
			->fetchResultSet();

		$farms = [];
		foreach ( $rows as $row ) {
			$farms[] = [
				'id' => $row->mf_id,
				'name' => $row->mf_display_name,
				'api_url' => $row->mf_api_url,
				'is_centralauth' => (bool)$row->mf_is_centralauth,
				'header_msg' => $row->mf_header_msg,
			];
		}

		// Default farms if none configured
		if ( !$farms ) {
			$farms = [
				[
					'id' => 'wm',
					'name' => 'Wikimedia',
					'api_url' => 'https://meta.wikimedia.org/w/api.php',
					'is_centralauth' => true,
					'header_msg' => 'mca-header-list-wm',
				],
				[
					'id' => 'mh',
					'name' => 'Miraheze',
					'api_url' => 'https://meta.miraheze.org/w/api.php',
					'is_centralauth' => true,
					'header_msg' => 'mca-header-list-mh',
				]
			];
		}

		return $farms;
	}

	public function getFarmWikis( string $farmId ): array {
		$dbr = $this->dbProvider->getReplicaDatabase();
		return $dbr->newSelectQueryBuilder()
			->select( 'mfw_wiki_id' )
			->from( 'mca_farm_wikis' )
			->where( [ 'mfw_farm_id' => $farmId ] )
			->fetchFieldValues();
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

		$gui = $data['query']['globaluserinfo'] ?? null;
		if ( $gui && isset( $gui['merged'] ) ) {
			foreach ( $gui['merged'] as &$m ) {
				if ( isset( $m['groups'] ) ) {
					$m['groups'] = array_values( array_diff( $m['groups'], [ '*', 'user', 'autoconfirmed' ] ) );
				}
			}
		}

		return $gui;
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

	public function getSuppressedWikis( int $userId, bool $usePrimary = false ): array {
		$db = $usePrimary ? $this->dbProvider->getPrimaryDatabase() : $this->dbProvider->getReplicaDatabase();
		$wikis = $db->newSelectQueryBuilder()
			->select( 'msw_wiki_id' )
			->from( 'mca_suppressed_wikis' )
			->where( [ 'msw_user_id' => $userId ] )
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

		$groups = $user['groups'] ?? [];
		$filteredGroups = array_values( array_diff( $groups, [ '*', 'user', 'autoconfirmed' ] ) );

		return [
			'editcount' => $user['editcount'] ?? 0,
			'registration' => $user['registration'] ?? '',
			'groups' => $filteredGroups,
			'blocked' => isset( $user['blockid'] ),
		];
	}

	public function categorizeWiki( string $hostname ): string {
		$hostname = strtolower( $hostname );
		$farms = $this->getFarms();

		foreach ( $farms as $farm ) {
			$farmWikis = $this->getFarmWikis( $farm['id'] );
			if ( in_array( $hostname, $farmWikis ) ) {
				return $farm['id'];
			}

			// Heuristics for default farms if they are not explicitly in mca_farm_wikis
			if ( $farm['id'] === 'wm' ) {
				if ( str_ends_with( $hostname, '.wikipedia.org' ) || str_ends_with( $hostname, '.wikimedia.org' ) ) {
					return 'wm';
				}
			}
			if ( $farm['id'] === 'mh' ) {
				if ( str_ends_with( $hostname, '.miraheze.org' ) ) {
					return 'mh';
				}
			}
		}

		return 'local';
	}
}
