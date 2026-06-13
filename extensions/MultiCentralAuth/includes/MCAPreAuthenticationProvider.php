<?php

namespace MediaWiki\Extension\MultiCentralAuth;

use MediaWiki\Auth\AbstractPreAuthenticationProvider;
use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\MediaWikiServices;

class MCAPreAuthenticationProvider extends AbstractPreAuthenticationProvider {

	/**
	 * @param array $reqs
	 * @return \StatusValue
	 */
	public function testForAuthentication( array $reqs ) {
		$username = AuthenticationRequest::getUsernameFromRequests( $reqs );

		if ( $username === null ) {
			return \StatusValue::newGood();
		}

		$dbProvider = MediaWikiServices::getInstance()->getConnectionProvider();
		$dbr = $dbProvider->getReplicaDatabase();

		$userId = $dbr->newSelectQueryBuilder()
			->select( 'user_id' )
			->from( 'user' )
			->where( [ 'user_name' => $username ] )
			->fetchField();

		if ( !$userId ) {
			return \StatusValue::newGood();
		}

		$lock = $dbr->newSelectQueryBuilder()
			->select( [ 'mcl_reason', 'mcl_expiry' ] )
			->from( 'mca_locks' )
			->where( [ 'mcl_user_id' => $userId ] )
			->andWhere( $dbr->expr( 'mcl_expiry', '>', $dbr->timestamp() )
				->or( 'mcl_expiry', '=', 'infinity' )
			)
			->fetchRow();

		if ( $lock ) {
			$expiry = $lock->mcl_expiry;
			$context = \MediaWiki\Context\RequestContext::getMain();
			$lang = MediaWikiServices::getInstance()->getLanguageFactory()->getLanguage(
				$context->getLanguage()->getCode()
			);
			if ( $expiry === 'infinity' ) {
				$formattedExpiry = wfMessage( 'infiniteblock' )->inLanguage( $lang )->text();
			} else {
				$formattedExpiry = $lang->userTimeAndDate( $expiry, $context->getUser() );
			}

			return \StatusValue::newFatal(
				wfMessage( 'centralauth-lock-message', $lock->mcl_reason, $formattedExpiry )
			);
		}

		return \StatusValue::newGood();
	}
}
