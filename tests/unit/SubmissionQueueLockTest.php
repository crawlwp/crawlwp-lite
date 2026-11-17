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
		foreach ( array_keys( $GLOBALS['crawlwp_test_state']['options'] ) as $name ) {
			if ( strpos( (string) $name, Hooks::QUEUE_OPTION ) === 0 || strpos( (string) $name, 'crawlwp_submission_' ) === 0 ) {
				unset( $GLOBALS['crawlwp_test_state']['options'][ $name ] );
			}
		}

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

	private function options(): array {
		return array_filter(
			$GLOBALS['crawlwp_test_state']['options'],
			static function ( $name ) {
				return strpos( (string) $name, Hooks::QUEUE_OPTION ) === 0;
			},
			ARRAY_FILTER_USE_KEY
		);
	}

	private function fresh_index(): array {
		return [ 'v' => 2, 'q' => [ 1, 0 ], 'd' => [ 1, 0 ], 'n' => 0 ];
	}

	public function test_items_are_stored_in_buckets_with_removals_in_their_own_lane(): void {
		$index = $this->fresh_index();

		$dropped = Hooks::enqueue(
			[
				'deleted:1' => [ 'crawlwp/post_deleted', [ 1, 'https://example.test/a/' ] ],
				'post:2'    => [ 'crawlwp/post_updated', [ 2 ] ],
				'term:3:category' => [ 'crawlwp/term_updated', [ 3, 'category' ] ],
			],
			$index
		);

		$this->assertSame( 0, $dropped );
		$this->assertSame( [ 'v' => 2, 'q' => [ 1, 1 ], 'd' => [ 1, 1 ], 'n' => 3 ], $index );
		$this->assertSame( [ 'post:2', 'term:3:category' ], array_keys( $this->options()[ Hooks::bucket_name( 'q', 1 ) ] ) );
		$this->assertSame( [ 'deleted:1' ], array_keys( $this->options()[ Hooks::bucket_name( 'd', 1 ) ] ) );
	}

	public function test_a_post_already_waiting_is_merged_and_added_wins(): void {
		$index = $this->fresh_index();

		Hooks::enqueue( [ 'post:2' => [ 'crawlwp/post_updated', [ 2 ], 0, 2000, 900 ] ], $index );
		Hooks::enqueue( [ 'post:2' => [ 'crawlwp/post_added', [ 2 ], 1, 0, 950 ] ], $index );
		Hooks::enqueue( [ 'post:2' => [ 'crawlwp/comment_updated', [ 2 ], 0, 0, 940 ] ], $index );

		$bucket = $this->options()[ Hooks::bucket_name( 'q', 1 ) ];

		$this->assertSame( 1, $index['n'] );
		// "added" won, the try is kept, it may go now, and it is dated from the newest save, so a
		// submission made between two saves doesn't hide the second one.
		$this->assertSame( [ 'post:2' => [ 'crawlwp/post_added', [ 2 ], 1, 0, 950 ] ], $bucket );

		// An item a run already tried to send (no date) is sent whatever was recorded since.
		Hooks::enqueue( [ 'post:2' => [ 'crawlwp/post_updated', [ 2 ], 1, 0, 0 ] ], $index, true );

		$this->assertSame( 0, $this->options()[ Hooks::bucket_name( 'q', 1 ) ]['post:2'][4] );
	}

	public function test_a_full_bucket_starts_the_next_one_and_only_the_last_is_written(): void {
		$index = $this->fresh_index();
		$items = [];

		for ( $i = 1; $i <= Hooks::BUCKET_SIZE + 1; $i++ ) {
			$items[ 'post:' . $i ] = [ 'crawlwp/post_added', [ $i ] ];
		}

		Hooks::enqueue( $items, $index );

		$this->assertSame( [ 1, 2 ], $index['q'] );
		$this->assertSame( Hooks::BUCKET_SIZE + 1, $index['n'] );
		$this->assertCount( Hooks::BUCKET_SIZE, $this->options()[ Hooks::bucket_name( 'q', 1 ) ] );
		$this->assertCount( 1, $this->options()[ Hooks::bucket_name( 'q', 2 ) ] );

		// The first bucket is not touched again.
		$GLOBALS['crawlwp_test_state']['options'][ Hooks::bucket_name( 'q', 1 ) ] = 'untouched';
		Hooks::enqueue( [ 'post:9001' => [ 'crawlwp/post_added', [ 9001 ] ] ], $index );

		$this->assertSame( 'untouched', $this->options()[ Hooks::bucket_name( 'q', 1 ) ] );
		$this->assertCount( 2, $this->options()[ Hooks::bucket_name( 'q', 2 ) ] );
	}

	public function test_a_full_queue_takes_no_new_items(): void {
		$index      = $this->fresh_index();
		$index['n'] = Hooks::MAX_QUEUE;

		$this->assertSame( 1, Hooks::enqueue( [ 'post:2' => [ 'crawlwp/post_added', [ 2 ] ] ], $index ) );
		$this->assertSame( [], $this->options() );

		// Items that were queued once (put back after a run) still fit.
		$this->assertSame( 0, Hooks::enqueue( [ 'post:2' => [ 'crawlwp/post_added', [ 2 ] ] ], $index, true ) );
		$this->assertSame( Hooks::MAX_QUEUE + 1, $index['n'] );
	}

	public function test_a_batch_is_taken_with_removals_last_and_nothing_is_written_yet(): void {
		$index = $this->fresh_index();

		Hooks::enqueue(
			[
				'deleted:1' => [ 'crawlwp/post_deleted', [ 1, 'https://example.test/a/' ] ],
				'post:2'    => [ 'crawlwp/post_added', [ 2 ] ],
				'post:3'    => [ 'crawlwp/post_updated', [ 3 ] ],
				'post:4'    => [ 'crawlwp/post_updated', [ 4 ] ],
			],
			$index
		);

		$before = $this->options();

		[ $batch, $next_due, $changes ] = Hooks::take_batch( $index, 2, 1000 );

		$this->assertSame( [ 'post:2', 'post:3' ], array_keys( $batch ) );
		$this->assertSame( 0, $next_due );
		$this->assertSame( 2, $index['n'] );
		$this->assertSame( $before, $this->options() );
		$this->assertSame( Hooks::bucket_name( 'q', 1 ), $changes[0][0] );
		$this->assertSame( [ 'post:4' ], array_keys( $changes[0][1] ) );

		// With room for everything: the rest of the content, then the removal; both lanes end up empty.
		$GLOBALS['crawlwp_test_state']['options'][ $changes[0][0] ] = $changes[0][1];

		[ $batch, , $changes ] = Hooks::take_batch( $index, 200, 1000 );

		$this->assertSame( [ 'post:4', 'deleted:1' ], array_keys( $batch ) );
		$this->assertSame( [ [ Hooks::bucket_name( 'q', 1 ), null ], [ Hooks::bucket_name( 'd', 1 ), null ] ], $changes );
		$this->assertSame( $this->fresh_index(), $index );
	}

	public function test_a_post_still_waiting_for_its_seo_box_is_left_in_the_queue(): void {
		$index = $this->fresh_index();

		Hooks::enqueue(
			[
				'post:2' => [ 'crawlwp/post_added', [ 2 ], 0, 1045 ],
				'post:3' => [ 'crawlwp/post_updated', [ 3 ] ],
			],
			$index
		);

		[ $batch, $next_due, $changes ] = Hooks::take_batch( $index, 200, 1000 );

		$this->assertSame( [ 'post:3' ], array_keys( $batch ) );
		$this->assertSame( 1045, $next_due );
		$this->assertSame( [ 'post:2' ], array_keys( $changes[0][1] ) );
		$this->assertSame( 1, $index['n'] );

		$again = $this->fresh_index();
		$again['q'] = [ 1, 1 ];
		$again['n'] = 2;

		$this->assertSame( [ 'post:2', 'post:3' ], array_keys( Hooks::take_batch( $again, 200, 1000, true )[0] ) );
		$this->assertSame( [ 'post:2', 'post:3' ], array_keys( Hooks::take_batch( $index, 200, 1045 )[0] ) );
	}

	public function test_nothing_is_recovered_while_another_run_is_sending(): void {
		$claim = [ 'at' => 1000 - 900, 'owner' => 'a', 'items' => [ 'post:9' => [ 'crawlwp/post_added', [ 9 ] ] ] ];

		// The claim itself is old, but its run reported in 30 seconds ago.
		$this->assertNull( Hooks::recover_claim( $claim, [ 'owner' => 'a', 'at' => 1000 - 30, 'flushed' => 0, 'from' => 0, 'to' => 1 ], 1000 ) );
		// Another run's report says nothing about this claim.
		$this->assertNotNull( Hooks::recover_claim( $claim, [ 'owner' => 'b', 'at' => 1000 - 30 ], 1000 ) );
		$this->assertSame( [ 'requeue' => [], 'dropped' => [], 'found' => false ], Hooks::recover_claim( [], [], 1000 ) );
	}

	public function test_a_dead_run_only_counts_a_try_against_the_item_it_was_sending(): void {
		$now   = 100000;
		$claim = [
			'at'    => $now - Hooks::CLAIM_TTL - 1,
			'owner' => 'a',
			'items' => [
				'post:1' => [ 'crawlwp/post_added', [ 1 ] ],
				'post:2' => [ 'crawlwp/post_added', [ 2 ] ],
				// the run died while sending this one, for the third time.
				'post:3' => [ 'crawlwp/post_added', [ 3 ], Hooks::MAX_TRIES - 1 ],
				'post:4' => [ 'crawlwp/post_added', [ 4 ], 1, 0, 77 ],
			],
		];
		$beat  = [ 'owner' => 'a', 'at' => $now - Hooks::CLAIM_TTL - 1, 'flushed' => 1, 'from' => 2, 'to' => 3 ];

		$result = Hooks::recover_claim( $claim, $beat, $now );

		// post:1 went out; post:2 was handled but its IndexNow request had not gone yet; post:3 is dropped; post:4 never got its turn.
		$this->assertSame( [ 'post:2', 'post:4' ], array_keys( $result['requeue'] ) );
		$this->assertSame( 0, $result['requeue']['post:2'][2] );
		$this->assertSame( [ 'crawlwp/post_added', [ 4 ], 1, 0, 77 ], $result['requeue']['post:4'] );
		$this->assertSame( [ 'post:3' ], $result['dropped'] );
	}

	public function test_a_dead_run_that_left_no_position_counts_a_try_against_every_item(): void {
		$now   = 100000;
		$claim = [
			'at'    => $now - Hooks::CLAIM_TTL - 1,
			'items' => [
				'post:9'  => [ 'crawlwp/post_added', [ 9 ] ],
				'post:10' => [ 'crawlwp/post_added', [ 10 ], Hooks::MAX_TRIES - 1 ],
			],
		];

		$result = Hooks::recover_claim( $claim, [], $now );

		$this->assertSame( 1, $result['requeue']['post:9'][2] );
		$this->assertSame( [ 'post:10' ], $result['dropped'] );
	}

	public function test_a_run_that_reported_its_own_death_is_recovered_at_once(): void {
		$claim = [ 'at' => 1000 - 5, 'owner' => 'a', 'items' => [ 'post:9' => [ 'crawlwp/post_added', [ 9 ] ], 'post:10' => [ 'crawlwp/post_added', [ 10 ] ] ] ];
		$beat  = [ 'owner' => 'a', 'at' => 1000 - 1, 'flushed' => 0, 'from' => 1, 'to' => 2, 'dead' => true ];

		$result = Hooks::recover_claim( $claim, $beat, 1000 );

		$this->assertSame( 0, $result['requeue']['post:9'][2] );
		$this->assertSame( 1, $result['requeue']['post:10'][2] );
	}

	public function test_a_single_option_queue_is_moved_into_buckets_once(): void {
		$GLOBALS['crawlwp_test_state']['options'][ Hooks::QUEUE_OPTION ] = [
			'post:5'    => [ 'crawlwp/post_updated', [ 5 ] ],
			'deleted:6' => [ 'crawlwp/post_deleted', [ 6, 'https://example.test/b/' ] ],
		];

		$index = Hooks::read_index();

		$this->assertSame( [ 'v' => 2, 'q' => [ 1, 1 ], 'd' => [ 1, 1 ], 'n' => 2 ], $index );
		$this->assertArrayNotHasKey( Hooks::QUEUE_OPTION, $this->options() );
		$this->assertSame( $index, $this->options()[ Hooks::QUEUE_INDEX ] );
		$this->assertSame( $index, Hooks::read_index() );
	}
}
}
