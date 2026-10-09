<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Core\Event\EventManager;
use Holvek\WordPress\Modules\Admin\AdminMenu;
use Holvek\WordPress\Modules\Admin\AdminModule;
use Holvek\WordPress\Modules\Indexing\BingIndexNowApi;
use Holvek\WordPress\Modules\Indexing\IndexingJob;
use Holvek\WordPress\Modules\Indexing\IndexNowFlushJob;
use PHPUnit\Framework\TestCase;

class IndexNowSubmissionTest extends TestCase {

	private const KEY = 'abcd1234';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['mock_http_log']       = array();
		$GLOBALS['mock_http_responses'] = array();
		$GLOBALS['mock_cron_events']    = array();
		unset( $GLOBALS['mock_http_response'], $_SERVER['REQUEST_URI'] );
		delete_option( 'holvek_indexnow_key' );
		delete_option( 'holvek_auto_index' );
		delete_option( 'holvek_indexnow_endpoint' );
		delete_option( BingIndexNowApi::QUEUE_OPTION );
		delete_option( BingIndexNowApi::FLUSH_PENDING );
		delete_option( 'holvek_indexnow_log' );
		delete_option( 'holvek_breadcrumb_home_label' );
	}

	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_URI'] );
		parent::tearDown();
	}

	public function testKeyFileMatchesHomePathAndIgnoresTheQueryString(): void {
		$home = 'https://example.com/blog/';
		$key  = self::KEY;

		$this->assertTrue( BingIndexNowApi::keyFilePathMatches( '/blog/' . $key . '.txt?preview=1', $home, $key ) );
		$this->assertTrue( BingIndexNowApi::keyFilePathMatches( '/blog/' . $key . '.txt', 'https://example.com/blog', $key ) );
		$this->assertTrue( BingIndexNowApi::keyFilePathMatches( '/' . $key . '.txt?utm=1', 'https://example.com', $key ) );

		$this->assertFalse( BingIndexNowApi::keyFilePathMatches( '/' . $key . '.txt', $home, $key ) );
		$this->assertFalse( BingIndexNowApi::keyFilePathMatches( '/blog/' . $key . '.txt.evil', $home, $key ) );
		$this->assertFalse( BingIndexNowApi::keyFilePathMatches( '/other/' . $key . '.txt', $home, $key ) );
		$this->assertFalse( BingIndexNowApi::keyFilePathMatches( '/blog/' . strtoupper( $key ) . '.txt', $home, $key ) );
		$this->assertFalse( BingIndexNowApi::keyFilePathMatches( '/blog/../' . $key . '.txt', $home, $key ) );
		$this->assertFalse( BingIndexNowApi::keyFilePathMatches( '/blog/' . $key . '.txt', $home, 'bad key' ) );
		$this->assertFalse( BingIndexNowApi::keyFilePathMatches( '/blog/' . $key . '.txt', $home, '../secret' ) );
	}

	public function testPublishDoesNotCallIndexNowOrCreateAKey(): void {
		$api  = new BingIndexNowApi();
		$post = new \WP_Post(
			array(
				'ID'        => 7,
				'post_type' => 'post',
			)
		);

		update_option( 'holvek_indexnow_key', self::KEY );
		$api->autoSubmitOnPublish( 'publish', 'draft', $post );
		$this->assertSame( array(), $GLOBALS['mock_http_log'] );
		$this->assertSame( array(), $api->getQueue() );
		$this->assertSame( self::KEY, get_option( 'holvek_indexnow_key' ) );

		delete_option( 'holvek_indexnow_key' );
		update_option( 'holvek_auto_index', 'yes' );
		$api->autoSubmitOnPublish( 'publish', 'draft', $post );
		$this->assertFalse( get_option( 'holvek_indexnow_key', false ) );
		$this->assertSame( array(), $GLOBALS['mock_http_log'] );
		$this->assertSame( array(), $api->getQueue() );
	}

	public function testEnabledPublishQueuesAndCronSendsASmallBatch(): void {
		$api = $this->enabledApi();
		$api->autoSubmitOnPublish(
			'publish',
			'draft',
			new \WP_Post(
				array(
					'ID'        => 7,
					'post_type' => 'post',
				)
			)
		);

		$this->assertSame( array(), $GLOBALS['mock_http_log'] );
		$this->assertSame( array( 'https://example.com/post-7/' ), $api->getQueue() );
		$this->assertCount( 1, $this->flushEvents() );

		update_post_meta( 8, '_holvek_noindex', 'yes' );
		$api->autoSubmitOnPublish(
			'publish',
			'publish',
			new \WP_Post(
				array(
					'ID'        => 8,
					'post_type' => 'post',
				)
			)
		);
		$api->autoSubmitOnPublish(
			'publish',
			'draft',
			new \WP_Post(
				array(
					'ID'        => 9,
					'post_type' => 'revision',
				)
			)
		);
		$this->assertSame( array( 'https://example.com/post-7/' ), $api->getQueue() );

		for ( $i = 1; $i <= 12; $i++ ) {
			$this->assertTrue( $api->enqueueUrl( 'https://example.com/batch-' . $i . '/' ) );
		}
		$this->assertFalse( $api->enqueueUrl( 'https://evil.example/owned/' ) );
		$this->assertCount( 13, $api->getQueue() );

		$GLOBALS['mock_cron_events'] = array();
		$GLOBALS['mock_http_responses']['api.indexnow.org'] = array(
			'response' => array( 'code' => 202 ),
		);
		$api->flushQueue();

		$this->assertCount( 1, $GLOBALS['mock_http_log'] );
		$this->assertSame( 'https://api.indexnow.org/indexnow', $GLOBALS['mock_http_log'][0]['url'] );
		$body = json_decode( $GLOBALS['mock_http_log'][0]['args']['body'], true );
		$this->assertSame( self::KEY, $body['key'] );
		$this->assertSame( 'example.com', $body['host'] );
		$this->assertSame( 'https://example.com/' . self::KEY . '.txt', $body['keyLocation'] );
		$this->assertCount( BingIndexNowApi::BATCH_SIZE, $body['urlList'] );
		$this->assertCount( 3, $api->getQueue() );
		$this->assertCount( 1, $this->flushEvents() );

		$GLOBALS['mock_http_log']    = array();
		$GLOBALS['mock_cron_events'] = array();
		$api->flushQueue();
		$second = json_decode( $GLOBALS['mock_http_log'][0]['args']['body'], true );
		$this->assertCount( 3, $second['urlList'] );
		$this->assertSame( array(), $api->getQueue() );
	}

	public function testFailedBatchStaysQueuedAndADisabledFlushSendsNothing(): void {
		$api = $this->enabledApi();
		$this->assertTrue( $api->enqueueUrl( 'https://example.com/keep/' ) );
		$GLOBALS['mock_cron_events'] = array();
		$api->flushQueue();

		$this->assertCount( 1, $GLOBALS['mock_http_log'] );
		$this->assertSame( array( 'https://example.com/keep/' ), $api->getQueue() );
		$this->assertNotSame( 200, $api->getSubmissionLog()[0]['status_code'] );

		$GLOBALS['mock_http_log'] = array();
		update_option( 'holvek_auto_index', 'no' );
		$api->flushQueue();
		$this->assertSame( array(), $GLOBALS['mock_http_log'] );
		$this->assertSame( array( 'https://example.com/keep/' ), $api->getQueue() );
		$this->assertFalse( $api->ping( 'https://example.com/keep/' ) );
	}

	public function testQueueStopsAtTheCapAndTheIndexingJobDoesNotPing(): void {
		$api = $this->enabledApi();
		for ( $i = 1; $i <= BingIndexNowApi::QUEUE_MAX; $i++ ) {
			$this->assertTrue( $api->enqueueUrl( 'https://example.com/item-' . $i . '/' ) );
		}
		$this->assertFalse( $api->enqueueUrl( 'https://example.com/overflow/' ) );
		$this->assertCount( BingIndexNowApi::QUEUE_MAX, $api->getQueue() );

		$job = new IndexingJob(
			array(
				'url'     => 'https://example.com/post-1/',
				'post_id' => 1,
				'action'  => 'URL_UPDATED',
			)
		);
		$before = count( $GLOBALS['mock_http_log'] );
		$job->handle();
		$this->assertCount( $before, $GLOBALS['mock_http_log'] );
	}

	public function testSettingsRejectAnInvalidKeyAndDoNotInventOne(): void {
		$admin = new AdminModule( new EventManager(), new AdminMenu() );
		update_option( 'holvek_indexnow_key', self::KEY );
		update_option( 'holvek_breadcrumb_home_label', 'Start' );

		$rejected = new \WP_REST_Request();
		$rejected->set_json_params(
			array(
				'indexnow_key'             => 'bad key',
				'auto_index'               => true,
				'breadcrumb_home_label'    => 'Home',
			)
		);
		$result = $admin->updateSettings( $rejected );
		$this->assertSame( 'invalid_indexnow_key', $result->get_error_code() );
		$this->assertSame( self::KEY, get_option( 'holvek_indexnow_key' ) );
		$this->assertSame( 'Start', get_option( 'holvek_breadcrumb_home_label' ) );
		$this->assertFalse( get_option( 'holvek_auto_index', false ) );

		$enabled = new \WP_REST_Request();
		$enabled->set_json_params( array( 'auto_index' => true ) );
		$admin->updateSettings( $enabled );
		$this->assertSame( 'yes', get_option( 'holvek_auto_index' ) );
		$this->assertSame( self::KEY, get_option( 'holvek_indexnow_key' ) );

		delete_option( 'holvek_indexnow_key' );
		$admin->updateSettings( $enabled );
		$this->assertFalse( get_option( 'holvek_indexnow_key', false ) );

		$api = new BingIndexNowApi();
		$request = new \WP_REST_Request();
		$request->set_json_params( array( 'url' => 'https://example.com/manual/' ) );
		$manual = $api->manualSubmit( $request );
		$this->assertSame( 'indexnow_disabled', $manual->get_error_code() );
		$this->assertSame( array(), $GLOBALS['mock_http_log'] );
	}

	private function enabledApi(): BingIndexNowApi {
		update_option( 'holvek_indexnow_key', self::KEY );
		update_option( 'holvek_auto_index', 'yes' );
		return new BingIndexNowApi();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function flushEvents(): array {
		$events = $GLOBALS['mock_cron_events'] ?? array();
		if ( ! is_array( $events ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$events,
				static fn( $event ) => is_array( $event )
					&& 'holvek_process_job' === ( $event['hook'] ?? '' )
					&& IndexNowFlushJob::class === ( $event['args'][0] ?? '' )
			)
		);
	}
}
