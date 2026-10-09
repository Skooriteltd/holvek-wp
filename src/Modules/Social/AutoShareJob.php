<?php

namespace Holvek\WordPress\Modules\Social;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Holvek\WordPress\Core\Queue\QueueManager;

class AutoShareJob {

	public const ACCOUNT_BATCH = 3;

	private array $data;

	public function __construct( array $data ) {
		$this->data = $data;
	}

	public function handle(): void {
		$postId    = $this->data['post_id'] ?? 0;
		$retries   = $this->data['retries'] ?? 0;
		$accountId = $this->data['account_id'] ?? null; // Optional: target specific account

		if ( ! $postId ) {
			return;
		}

		// Check if the post is actually published
		if ( get_post_status( $postId ) !== 'publish' ) {
			return;
		}

		// Check if social sharing is explicitly disabled for this post
		$disableShare = get_post_meta( $postId, '_holvek_disable_social_share', true );
		if ( $disableShare === '1' ) {
			return;
		}

		// Ensure we haven't already shared this post automatically to avoid spam loops
		// Unless it's a manual trigger (manual trigger can bypass this by setting 'manual' => true)
		$isManual = $this->data['manual'] ?? false;
		if ( ! $isManual ) {
			$alreadyShared = get_post_meta( $postId, '_holvek_auto_shared', true );
			if ( $alreadyShared === '1' ) {
				return;
			}
		}

		$accountManager = new SocialAccountManager();
		$apiGateway     = new SocialApiGateway();

		$accounts = $accountManager->getAccounts();
		if ( empty( $accounts ) ) {
			return; // No accounts connected
		}

		// Prepare payload
		$url   = get_permalink( $postId );
		$title = get_the_title( $postId );

		// Custom OG Title
		$ogTitle = get_post_meta( $postId, '_holvek_og_title', true );
		if ( ! empty( $ogTitle ) ) {
			$title = $ogTitle;
		}

		// Excerpt / OG Desc
		$description = get_post_meta( $postId, '_holvek_og_description', true );
		if ( empty( $description ) ) {
			$description = wp_trim_words( get_post_field( 'post_excerpt', $postId ), 25 );
		}

		// OG Image
		$image = get_post_meta( $postId, '_holvek_og_image', true );
		if ( empty( $image ) && has_post_thumbnail( $postId ) ) {
			$image = get_the_post_thumbnail_url( $postId, 'full' );
		}
		if ( empty( $image ) ) {
			$image = get_option( 'holvek_default_share_image', '' );
		}

		$payload = array(
			'url'         => $url,
			'title'       => $title,
			'description' => $description,
			'image'       => $image,
		);

		$hasFailures    = ! empty( $this->data['had_failures'] );
		$postCategories = wp_get_post_categories( $postId );
		$eligible       = array();

		foreach ( $accounts as $account ) {
			if ( $accountId && $account['id'] !== $accountId ) {
				continue;
			}

			$enabled = $account['auto_share'] ?? true;
			if ( ! $enabled && ! $isManual ) {
				continue;
			}

			$boundCategories = $account['bound_categories'] ?? array();
			if ( ! empty( $boundCategories ) ) {
				$intersection = array_intersect( $postCategories, $boundCategories );
				if ( empty( $intersection ) ) {
					continue;
				}
			}

			$eligible[] = $account;
		}

		$offset = $accountId ? 0 : max( 0, (int) ( $this->data['offset'] ?? 0 ) );
		$slice  = array_slice( $eligible, $offset, $accountId ? 1 : self::ACCOUNT_BATCH );

		foreach ( $slice as $account ) {
			$response = $apiGateway->share( $account, $payload );

			if ( ! $response['success'] ) {
				if ( $response['code'] === 429 && $retries < 3 ) {
					$hasFailures  = true;
					$queueManager = new QueueManager();
					$queueManager->push(
						self::class,
						array(
							'post_id'    => $postId,
							'account_id' => $account['id'],
							'retries'    => $retries + 1,
							'manual'     => $isManual,
						)
					);
				}
				continue;
			}

			$shareLogs = get_post_meta( $postId, '_holvek_share_logs', true );
			if ( ! is_array( $shareLogs ) ) {
				$shareLogs = array();
			}
			$shareLogs[] = array(
				'account_id' => $account['id'],
				'network'    => $account['network'],
				'timestamp'  => time(),
			);
			update_post_meta( $postId, '_holvek_share_logs', $shareLogs );
		}

		$next = $offset + count( $slice );
		if ( ! $accountId && $next < count( $eligible ) ) {
			$queueManager = new QueueManager();
			$queueManager->push(
				self::class,
				array(
					'post_id'      => $postId,
					'retries'      => $retries,
					'manual'       => $isManual,
					'offset'       => $next,
					'had_failures' => $hasFailures,
				),
				5
			);
			return;
		}

		if ( ! $hasFailures && ! $isManual ) {
			update_post_meta( $postId, '_holvek_auto_shared', '1' );
		}
	}
}
