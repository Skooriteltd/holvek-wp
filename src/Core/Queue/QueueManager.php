<?php

namespace Holvek\WordPress\Core\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Production-grade background job queue.
 *
 * Uses Action Scheduler when available, with an automatic fallback to native
 * wp_schedule_single_event for sites without Action Scheduler.
 */
class QueueManager {

	private const ACTION_HOOK = 'holvek_process_job';

	public function boot(): void {
		add_action( self::ACTION_HOOK, array( $this, 'process' ), 10, 2 );
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	public function registerRoutes(): void {
		register_rest_route(
			'holvek/v1',
			'/system/queue',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getQueueStatus' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
			)
		);
	}

	public function getQueueStatus( \WP_REST_Request $request ): \WP_REST_Response {
		$status = array(
			'driver'  => $this->hasActionScheduler() ? 'action_scheduler' : 'wp_cron',
			'pending' => 0,
			'failed'  => 0,
		);

		if ( $this->hasActionScheduler() ) {
			$status['pending'] = \ActionScheduler_Store::instance()->query_actions(
				array(
					'hook'   => self::ACTION_HOOK,
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				),
				'count'
			);
			$status['failed']  = \ActionScheduler_Store::instance()->query_actions(
				array(
					'hook'   => self::ACTION_HOOK,
					'status' => \ActionScheduler_Store::STATUS_FAILED,
				),
				'count'
			);
		} else {
			$crons = get_option( 'cron', array() );
			$count = 0;
			foreach ( $crons as $timestamp => $hooks ) {
				if ( is_array( $hooks ) && isset( $hooks[ self::ACTION_HOOK ] ) ) {
					$count += count( $hooks[ self::ACTION_HOOK ] );
				}
			}
			$status['pending'] = $count;
		}

		return rest_ensure_response( $status );
	}

	/**
	 * Push a job onto the background queue.
	 */
	public function push( string $jobClass, array $data = array(), int $delay = 0 ): void {
		$args = array( $jobClass, $data );

		if ( $this->hasActionScheduler() ) {
			if ( $delay > 0 ) {
				as_schedule_single_action( time() + $delay, self::ACTION_HOOK, $args );
			} else {
				as_enqueue_async_action( self::ACTION_HOOK, $args );
			}
		} else {
			// Prevent WP-Cron option bloat scale issue (PR-018)
			$crons = get_option( 'cron', array() );
			$count = 0;
			foreach ( $crons as $timestamp => $hooks ) {
				if ( is_array( $hooks ) && isset( $hooks[ self::ACTION_HOOK ] ) ) {
					$count += count( $hooks[ self::ACTION_HOOK ] );
				}
			}
			if ( $count > 250 ) {
				// Drop job gracefully instead of killing the site database with a massive cron array
				return;
			}
			wp_schedule_single_event( time() + $delay, self::ACTION_HOOK, $args );
		}
	}

	/**
	 * Schedule a recurring job.
	 */
	public function pushRecurring( string $jobClass, array $data = array(), int $intervalSeconds = 3600, string $uniqueGroup = '' ): void {
		$args = array( $jobClass, $data );

		if ( $this->hasActionScheduler() ) {
			if ( ! empty( $uniqueGroup ) ) {
				$existing = as_get_scheduled_actions(
					array(
						'hook'   => self::ACTION_HOOK,
						'args'   => $args,
						'group'  => $uniqueGroup,
						'status' => \ActionScheduler_Store::STATUS_PENDING,
					)
				);
				if ( ! empty( $existing ) ) {
					return;
				}
			}

			as_schedule_recurring_action(
				time(),
				$intervalSeconds,
				self::ACTION_HOOK,
				$args,
				$uniqueGroup ?: ''
			);
		} else {
			$cronHook = self::ACTION_HOOK . '_recurring_' . md5( $jobClass );
			if ( ! wp_next_scheduled( $cronHook, $args ) ) {
				wp_schedule_event( time(), 'hourly', $cronHook, $args );
			}
			add_action( $cronHook, array( $this, 'process' ), 10, 2 );
		}
	}

	/**
	 * Process a queued job by instantiating its class and calling handle().
	 *
	 * @throws \Throwable When the queued job handler fails.
	 */
	public function process( string $jobClass, array $data ): void {
		if ( ! class_exists( $jobClass ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Background queue failures are written to the PHP log.
				error_log( "[Holvek Queue] Job class not found: {$jobClass}" );
			}
			return;
		}

		try {
			$job = new $jobClass( $data );
			if ( method_exists( $job, 'handle' ) ) {
				$job->handle();
			}
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Background queue failures are written to the PHP log.
				error_log( "[Holvek Queue] Job failed ({$jobClass}): " . $e->getMessage() );
			}
			throw $e;
		}
	}

	/**
	 * Check whether Action Scheduler is available.
	 */
	private function hasActionScheduler(): bool {
		return function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * Cleanup on plugin deactivation: unschedule pending cron fallback events.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::ACTION_HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION_HOOK );
		}
	}
}
