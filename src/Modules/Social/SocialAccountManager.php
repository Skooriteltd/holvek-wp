<?php

namespace Holvek\WordPress\Modules\Social;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Holvek\WordPress\Core\Security\KeyVault;

class SocialAccountManager {

	private const OPTION_KEY = 'holvek_social_accounts';

	public function getAccounts(): array {
		$accounts = get_option( self::OPTION_KEY, array() );
		return is_array( $accounts ) ? $accounts : array();
	}

	public function getAccountsByNetwork( string $network ): array {
		return array_filter(
			$this->getAccounts(),
			function ( $account ) use ( $network ) {
				return $account['network'] === $network;
			}
		);
	}

	/**
	 * Store an account. The access token is written only as ciphertext.
	 *
	 * @return true|\WP_Error
	 */
	public function saveAccount( array $accountData ): true|\WP_Error {
		$accounts = $this->getAccounts();

		// Use a standard unique ID based on network and uuid
		$accountId         = $accountData['id'] ?? ( ( $accountData['network'] ?? 'account' ) . '_' . ( function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( '', true ) ) );
		$accountData['id'] = $accountId;

		if ( ! empty( $accountData['access_token'] ) && is_string( $accountData['access_token'] ) ) {
			$encrypted = KeyVault::encrypt( $accountData['access_token'] );
			if ( is_wp_error( $encrypted ) || ! is_string( $encrypted ) || ! KeyVault::isCiphertext( $encrypted ) ) {
				if ( is_wp_error( $encrypted ) ) {
					return $encrypted;
				}
				return new \WP_Error(
					'holvek_encryption_failed',
					__( 'Holvek could not encrypt this secret. The value was not saved.', 'holvek' ),
					array( 'status' => 500 )
				);
			}
			$accountData['access_token'] = $encrypted;
		}

		// Check if exists, update or append
		$exists = false;
		foreach ( $accounts as $index => $acc ) {
			if ( $acc['id'] === $accountId ) {
				$accounts[ $index ] = array_merge( $acc, $accountData );
				$exists             = true;
				break;
			}
		}

		if ( ! $exists ) {
			$accounts[] = $accountData;
		}

		update_option( self::OPTION_KEY, $accounts, false );
		return true;
	}

	public function deleteAccount( string $accountId ): bool {
		$accounts     = $this->getAccounts();
		$initialCount = count( $accounts );

		$accounts = array_filter(
			$accounts,
			function ( $account ) use ( $accountId ) {
				return $account['id'] !== $accountId;
			}
		);

		if ( count( $accounts ) !== $initialCount ) {
			update_option( self::OPTION_KEY, array_values( $accounts ), false );
			return true;
		}

		return false;
	}

	public function updateAccount( string $accountId, array $data ): bool {
		$accounts = $this->getAccounts();
		$updated  = false;

		foreach ( $accounts as $index => $acc ) {
			if ( $acc['id'] === $accountId ) {
				// Ensure we don't overwrite id, network, or access_token with empty values
				unset( $data['id'], $data['network'], $data['access_token'] );
				$accounts[ $index ] = array_merge( $acc, $data );
				$updated            = true;
				break;
			}
		}

		if ( $updated ) {
			update_option( self::OPTION_KEY, $accounts, false );
			return true;
		}

		return false;
	}
}
