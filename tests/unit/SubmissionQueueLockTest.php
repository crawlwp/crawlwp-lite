<?php

namespace {

if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		return true;
	}
}

if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '' ) {
		return false;
	}
}

if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $value, $group = '' ) {
		return true;
	}
}
}

namespace Mihdan\IndexNow\Tests\Unit {

use Mihdan\IndexNow\Hooks;
use PHPUnit\Framework\TestCase;

require_once CRAWLWP_TESTS_PLUGIN_DIR . '/src/Hooks.php';

/**
 * The background submission queue is only changed by one request at a time.
 */
class SubmissionQueueLockTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['crawlwp_test_state']['options'][ Hooks::QUEUE_LOCK ], $GLOBALS['crawlwp_test_state']['options'][ Hooks::QUEUE_OPTION ] );
	}

	public function test_one_request_holds_the_lock_until_it_releases_it(): void {
		$this->assertTrue( Hooks::lock_queue() );
		$this->assertArrayHasKey( Hooks::QUEUE_LOCK, $GLOBALS['crawlwp_test_state']['options'] );

		Hooks::unlock_queue();

		$this->assertTrue( Hooks::lock_queue() );
		Hooks::unlock_queue();
	}

	public function test_a_held_lock_is_not_taken(): void {
		$this->assertTrue( Hooks::lock_queue() );

		// A second request waits about two seconds, then gives up.
		$this->assertFalse( Hooks::lock_queue() );

		Hooks::unlock_queue();
	}

	public function test_a_dead_requests_lock_is_taken_over(): void {
		$GLOBALS['crawlwp_test_state']['options'][ Hooks::QUEUE_LOCK ] = time() - 60;

		$this->assertTrue( Hooks::lock_queue() );
		$this->assertGreaterThan( time() - 5, $GLOBALS['crawlwp_test_state']['options'][ Hooks::QUEUE_LOCK ] );

		Hooks::unlock_queue();
	}

	public function test_read_queue_returns_what_is_stored(): void {
		$GLOBALS['crawlwp_test_state']['options'][ Hooks::QUEUE_OPTION ] = [ 'post:5' => [ 'crawlwp/post_updated', [ 5 ] ] ];

		$this->assertSame( [ 'post:5' => [ 'crawlwp/post_updated', [ 5 ] ] ], Hooks::read_queue() );

		$GLOBALS['crawlwp_test_state']['options'][ Hooks::QUEUE_OPTION ] = 'junk';

		$this->assertSame( [], Hooks::read_queue() );
	}
}
}
