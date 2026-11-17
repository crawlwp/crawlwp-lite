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
 * The options table as the lock uses it: INSERT IGNORE, SELECT, compare-and-swap UPDATE and DELETE,
 * kept in the same place as the option stubs.
 */
class QueueLockDb {

	public string $options = 'wp_options';

	public function prepare( $query, ...$args ) {
		return [ $query, $args ];
	}

	public function query( $prepared ) {
		[ $query, $args ] = $prepared;
		$options          = &$GLOBALS['crawlwp_test_state']['options'];

		if ( strpos( $query, 'INSERT IGNORE' ) === 0 ) {
			if ( isset( $options[ $args[0] ] ) ) {
				return 0;
			}

			$options[ $args[0] ] = (string) $args[1];

			return 1;
		}

		if ( strpos( $query, 'UPDATE' ) === 0 ) {
			if ( ! isset( $options[ $args[1] ] ) || (string) $options[ $args[1] ] !== (string) $args[2] ) {
				return 0;
			}

			$options[ $args[1] ] = (string) $args[0];

			return 1;
		}

		if ( strpos( $query, 'DELETE' ) === 0 ) {
			if ( ! isset( $options[ $args[0] ] ) || (string) $options[ $args[0] ] !== (string) $args[1] ) {
				return 0;
			}

			unset( $options[ $args[0] ] );

			return 1;
		}

		return 0;
	}

	public function get_var( $prepared ) {
		$value = $GLOBALS['crawlwp_test_state']['options'][ $prepared[1][0] ] ?? null;

		return $value === null ? null : (string) $value;
	}
}

/**
 * The background submission queue is only changed by one request at a time, and a batch
 * that was being sent when a run died is sent again.
 */
class SubmissionQueueLockTest extends TestCase {

	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['crawlwp_test_state']['options'][ Hooks::QUEUE_LOCK ], $GLOBALS['crawlwp_test_state']['options'][ Hooks::QUEUE_OPTION ] );

		$this->wpdb      = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new QueueLockDb();
	}

	protected function tearDown(): void {
		Hooks::unlock_queue();
		$GLOBALS['wpdb'] = $this->wpdb;
		parent::tearDown();
	}

	public function test_one_request_holds_the_lock_until_it_releases_it(): void {
		$this->assertTrue( Hooks::lock_queue() );
		$this->assertArrayHasKey( Hooks::QUEUE_LOCK, $GLOBALS['crawlwp_test_state']['options'] );

		Hooks::unlock_queue();

		$this->assertArrayNotHasKey( Hooks::QUEUE_LOCK, $GLOBALS['crawlwp_test_state']['options'] );
		$this->assertTrue( Hooks::lock_queue() );
		Hooks::unlock_queue();
	}

	public function test_a_held_lock_is_not_taken(): void {
		// Another request's live lock.
		$GLOBALS['crawlwp_test_state']['options'][ Hooks::QUEUE_LOCK ] = time() . ':other';

		// Waits about two seconds, then gives up, and leaves the other request's lock alone.
		$this->assertFalse( Hooks::lock_queue() );

		Hooks::unlock_queue();
		$this->assertSame( 'other', explode( ':', $GLOBALS['crawlwp_test_state']['options'][ Hooks::QUEUE_LOCK ] )[1] );
	}

	public function test_a_dead_requests_lock_is_taken_over(): void {
		$GLOBALS['crawlwp_test_state']['options'][ Hooks::QUEUE_LOCK ] = ( time() - 60 ) . ':dead';

		$this->assertTrue( Hooks::lock_queue() );
		$this->assertGreaterThan( time() - 5, (int) $GLOBALS['crawlwp_test_state']['options'][ Hooks::QUEUE_LOCK ] );

		Hooks::unlock_queue();
	}

	public function test_releasing_does_not_remove_a_lock_that_was_taken_over(): void {
		$this->assertTrue( Hooks::lock_queue() );

		// This request stalled, and another one took the lock over.
		$GLOBALS['crawlwp_test_state']['options'][ Hooks::QUEUE_LOCK ] = time() . ':other';

		Hooks::unlock_queue();

		$this->assertArrayHasKey( Hooks::QUEUE_LOCK, $GLOBALS['crawlwp_test_state']['options'] );
	}

	public function test_read_queue_returns_what_is_stored(): void {
		$GLOBALS['crawlwp_test_state']['options'][ Hooks::QUEUE_OPTION ] = [ 'post:5' => [ 'crawlwp/post_updated', [ 5 ] ] ];

		$this->assertSame( [ 'post:5' => [ 'crawlwp/post_updated', [ 5 ] ] ], Hooks::read_queue() );

		$GLOBALS['crawlwp_test_state']['options'][ Hooks::QUEUE_OPTION ] = 'junk';

		$this->assertSame( [], Hooks::read_queue() );
	}

	public function test_a_batch_is_claimed_with_removals_last(): void {
		$queue = [
			'deleted:1' => [ 'crawlwp/post_deleted', [ 1, 'https://example.test/a/' ] ],
			'post:2'    => [ 'crawlwp/post_added', [ 2 ] ],
			'post:3'    => [ 'crawlwp/post_updated', [ 3 ] ],
		];

		[ $batch, $rest ] = Hooks::claim_batch( $queue, [], 2, 1000 );

		$this->assertSame( [ 'post:2', 'post:3' ], array_keys( $batch ) );
		$this->assertSame( [ 'deleted:1' ], array_keys( $rest ) );
	}

	public function test_nothing_is_claimed_while_another_run_is_sending(): void {
		$queue = [ 'post:2' => [ 'crawlwp/post_added', [ 2 ] ] ];
		$claim = [ 'at' => 1000 - 30, 'items' => [ 'post:9' => [ 'crawlwp/post_added', [ 9 ] ] ] ];

		[ $batch, $rest ] = Hooks::claim_batch( $queue, $claim, 200, 1000 );

		$this->assertNull( $batch );
		$this->assertSame( $queue, $rest );
	}

	public function test_a_dead_runs_batch_is_sent_again_but_not_forever(): void {
		$now   = 100000;
		$claim = [
			'at'    => $now - Hooks::CLAIM_TTL - 1,
			'items' => [
				'post:9'  => [ 'crawlwp/post_added', [ 9 ] ],
				// already part of two dead runs: this would be the third.
				'post:10' => [ 'crawlwp/post_added', [ 10 ], Hooks::MAX_TRIES - 1 ],
				// saved again since: the waiting entry is the one that is sent.
				'post:2'  => [ 'crawlwp/comment_updated', [ 2 ] ],
			],
		];
		$queue = [ 'post:2' => [ 'crawlwp/post_updated', [ 2 ] ] ];

		[ $batch, $rest ] = Hooks::claim_batch( $queue, $claim, 200, $now );

		$this->assertSame( [], $rest );
		$this->assertSame( [ 'crawlwp/post_updated', [ 2 ] ], $batch['post:2'] );
		$this->assertSame( [ 'crawlwp/post_added', [ 9 ], 1 ], $batch['post:9'] );
		$this->assertArrayNotHasKey( 'post:10', $batch );
	}
}
}
