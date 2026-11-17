<?php
/**
 * Class Hooks.
 *
 * @package mihdan-index-now
 */

namespace Mihdan\IndexNow;

use WP_Post;
use WP_Comment;

/**
 * Class Hooks. Settings are read on demand through Indexing.
 */
class Hooks {
	/**
	 * Single event submitting a post once its Ping Delay window ends.
	 */
	public const DELAYED_POST_EVENT = 'crawlwp_delayed_post_ping';

	/**
	 * Single event submitting a term once its Ping Delay window ends.
	 */
	public const DELAYED_TERM_EVENT = 'crawlwp_delayed_term_ping';

	/**
	 * Single event sending queued submissions in the background, so saving a post,
	 * a term or a comment never waits for search engines to answer.
	 */
	public const BACKGROUND_EVENT = 'crawlwp_background_submission';

	/**
	 * Safety net: runs the queue once more after a batch was claimed, in case the run that
	 * claimed it died with nothing else scheduled. Cleared when the batch is sent.
	 */
	public const RECOVER_EVENT = 'crawlwp_background_submission_recover';

	/**
	 * Submissions waiting for the background event (not autoloaded). One queue and
	 * one event, however many posts change: a bulk trash or an import of thousands
	 * of posts doesn't fill the cron option with an event per post.
	 */
	public const QUEUE_OPTION = 'crawlwp_submission_queue';

	/** Held (a few milliseconds) while the queue is read and written, so writers can't overwrite each other. */
	public const QUEUE_LOCK = 'crawlwp_submission_queue_lock';

	/**
	 * The batch a background run is sending now (not autoloaded): [ 'at' => time, 'items' => [...] ].
	 * It stays until the run finishes, so a run that dies loses nothing (see claim_batch()).
	 */
	public const CLAIM_OPTION = 'crawlwp_submission_claimed';

	/** A claim not updated for this long belongs to a run that died. */
	public const CLAIM_TTL = 600;

	/** Dead runs an item may be part of before it is dropped. */
	public const MAX_TRIES = 3;

	/**
	 * Submissions sent per background run; the rest wait for the next run.
	 */
	private const BATCH_SIZE = 200;

	/** Batch size when a webmaster API makes a request per post (see batch_size()). */
	private const BATCH_SIZE_SLOW = 50;

	/** Items sent per IndexNow request within a run; the claim is updated after each chunk. */
	private const CHUNK_SIZE = 25;

	/** Queued items a request keeps in memory before writing them out early. */
	private const PENDING_FLUSH = 100;

	/**
	 * Submission actions that may be sent in the background.
	 */
	private const BACKGROUND_ACTIONS = [
		'crawlwp/post_added',
		'crawlwp/post_updated',
		'crawlwp/post_deleted',
		'crawlwp/comment_updated',
		'crawlwp/term_updated',
	];

	/**
	 * Whether a background submission was queued in this request.
	 *
	 * @var bool
	 */
	private $spawn_cron = false;

	/**
	 * Submissions queued in this request, written to the stored queue on shutdown.
	 *
	 * @var array<string, array>
	 */
	private $pending = [];

	/**
	 * Value of the queue lock this request holds, or empty.
	 *
	 * @var string
	 */
	private static $lock_token = '';

	/**
	 * Hooks init.
	 *
	 * @return void
	 */
	public function setup_hooks() {
		add_action( 'transition_post_status', [ $this, 'post_updated' ], 10, 3 );
		add_action( 'transition_post_status', [ $this, 'post_unpublished' ], 10, 3 );
		add_action( 'before_delete_post', [ $this, 'post_deleted' ], 10, 2 );
		add_action( 'transition_comment_status', [ $this, 'comment_updated' ], 10, 3 );
		add_action( 'wp_insert_comment', [ $this, 'comment_inserted' ], 10, 2 );
		add_action( 'saved_term', [ $this, 'term_updated' ], 10, 3 );
		add_action( 'crawlwp/index_pinged', [ $this, 'record_submission' ], 10, 2 );
		add_action( self::DELAYED_POST_EVENT, [ $this, 'delayed_post_ping' ] );
		add_action( self::DELAYED_TERM_EVENT, [ $this, 'delayed_term_ping' ], 10, 2 );
		add_action( self::BACKGROUND_EVENT, [ $this, 'run_queue' ], 10, 2 );
		add_action( self::RECOVER_EVENT, [ $this, 'run_queue' ], 10, 0 );
		add_action( 'shutdown', [ $this, 'flush_pending' ], 9 );
		add_action( 'shutdown', [ $this, 'maybe_spawn_cron' ] );
		add_action( 'admin_notices', [ Indexing::class, 'pause_admin_notice' ] );
	}

	/**
	 * Fires immediately after a comment is inserted into the database.
	 *
	 * @param int        $id      Идентификатор комментария.
	 * @param WP_Comment $comment Объект комментария.
	 *
	 * @return void
	 */
	public function comment_inserted( int $id, WP_Comment $comment ): void {

		// Comment must be manually approved.
		if ( (int) $comment->comment_approved !== 1 ) {
			return;
		}

		$this->maybe_ping_comment_post( $comment );
	}

	/**
	 * Fires when the comment status is in transition
	 * from one specific status to another.
	 *
	 * @param int|string $new_status The new comment status.
	 * @param int|string $old_status The old comment status.
	 * @param WP_Comment $comment    Comment object.
	 *
	 * @return void
	 */
	public function comment_updated( $new_status, $old_status, WP_Comment $comment ): void {

		if ( $new_status !== 'approved' ) {
			return;
		}

		$this->maybe_ping_comment_post( $comment );
	}

	/**
	 * Resubmit the parent post of an approved comment, throttled per post.
	 *
	 * @param WP_Comment $comment Comment object.
	 *
	 * @return void
	 */
	private function maybe_ping_comment_post( WP_Comment $comment ): void {

		if ( ! Indexing::is_on( 'ping_on_comment', 'general' ) ) {
			return;
		}

		$post = get_post( (int) $comment->comment_post_ID );

		if ( ! $post instanceof WP_Post || Indexing::get_post_skip_reason( $post ) !== '' ) {
			return;
		}

		if ( $this->defer_post_if_throttled( $post ) ) {
			return;
		}

		// queued per post, not per comment: many comments before the queue runs still mean one ping.
		$this->submit( 'crawlwp/comment_updated', [ $post->ID ] );
	}

	/**
	 * Fires actions related to the transitioning of a post's status.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post data.
	 *
	 * @link https://yandex.com/dev/webmaster/doc/dg/reference/host-recrawl-post.html
	 */
	public function post_updated( string $new_status, string $old_status, WP_Post $post ): void {

		if ( $new_status !== 'publish' ) {
			return;
		}

		if ( ! empty( $_REQUEST['meta-box-loader'] ) ) { // phpcs:ignore
			return;
		}

		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		// Disable for Bulk Edit screen.
		if ( isset( $_REQUEST['bulk_edit'] ) && Indexing::is_on( 'disable_for_bulk_edit', 'general' ) ) {
			return;
		}

		$is_update = $old_status === $new_status;

		if ( ! Indexing::is_on( $is_update ? 'ping_on_post_updated' : 'ping_on_post', 'general' ) ) {
			return;
		}

		if ( Indexing::get_post_skip_reason( $post ) !== '' ) {
			return;
		}

		if ( $this->defer_post_if_throttled( $post ) ) {
			return;
		}

		$this->submit( $is_update ? 'crawlwp/post_updated' : 'crawlwp/post_added', [ $post->ID ] );
	}

	/**
	 * When the post was submitted within the Ping Delay window, schedule one
	 * submission for the end of the window instead of sending it now.
	 *
	 * @param WP_Post $post Post data.
	 *
	 * @return bool True when the submission was deferred.
	 */
	private function defer_post_if_throttled( WP_Post $post ): bool {

		$remaining = $this->get_remaining_delay( (int) get_post_meta( $post->ID, Indexing::LAST_UPDATE_META, true ) );

		if ( $remaining <= 0 ) {
			return false;
		}

		if ( ! wp_next_scheduled( self::DELAYED_POST_EVENT, [ $post->ID ] ) ) {
			wp_schedule_single_event( time() + $remaining, self::DELAYED_POST_EVENT, [ $post->ID ] );
		}

		return true;
	}

	/**
	 * Seconds left in the Ping Delay window after the last submission.
	 *
	 * @param int $last_update Last submission, site local timestamp.
	 *
	 * @return int
	 */
	private function get_remaining_delay( int $last_update ): int {

		if ( $last_update <= 0 ) {
			return 0;
		}

		return ( $last_update + Indexing::get_ping_delay() ) - (int) current_time( 'timestamp' );
	}

	/**
	 * Submit a post whose submission was deferred by the Ping Delay.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	public function delayed_post_ping( $post_id ): void {

		$post = get_post( (int) $post_id );

		if ( ! $post instanceof WP_Post || Indexing::get_post_skip_reason( $post ) !== '' ) {
			return;
		}

		do_action( 'crawlwp/post_updated', $post->ID, $post );
	}

	/**
	 * Submit a term whose submission was deferred by the Ping Delay.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 *
	 * @return void
	 */
	public function delayed_term_ping( $term_id, $taxonomy ): void {

		if ( Indexing::get_term_skip_reason( (int) $term_id, (string) $taxonomy ) !== '' ) {
			return;
		}

		do_action( 'crawlwp/term_updated', (int) $term_id, (string) $taxonomy );
	}

	/**
	 * Record the last successful submission, which drives the Ping Delay and
	 * the "last update" column.
	 *
	 * @param string $type      Object type: post or taxonomy.
	 * @param int    $object_id Object ID.
	 *
	 * @return void
	 */
	public function record_submission( $type, $object_id ): void {

		if ( $type === 'post' ) {
			update_post_meta( (int) $object_id, Indexing::LAST_UPDATE_META, current_time( 'timestamp' ) );
		} elseif ( $type === 'taxonomy' ) {
			update_term_meta( (int) $object_id, Indexing::LAST_UPDATE_META, current_time( 'timestamp' ) );
		}
	}

	/**
	 * Fires when a published post leaves the `publish` status
	 * (trash, draft, private, pending, future).
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post data.
	 *
	 * @return void
	 */
	public function post_unpublished( string $new_status, string $old_status, WP_Post $post ): void {

		if ( $old_status !== 'publish' || $new_status === 'publish' ) {
			return;
		}

		// A later republish starts a fresh Ping Delay window.
		delete_post_meta( $post->ID, Indexing::LAST_UPDATE_META );
		wp_clear_scheduled_hook( self::DELAYED_POST_EVENT, [ $post->ID ] );

		$this->maybe_fire_post_deleted( $post );
	}

	/**
	 * Fires before a post is deleted. Only published posts are handled here;
	 * trashed/unpublished posts were already reported by `post_unpublished()`.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post data.
	 *
	 * @return void
	 */
	public function post_deleted( int $post_id, $post = null ): void {

		wp_clear_scheduled_hook( self::DELAYED_POST_EVENT, [ $post_id ] );

		$post = $post instanceof WP_Post ? $post : get_post( $post_id );

		if ( ! $post instanceof WP_Post || $post->post_status !== 'publish' ) {
			return;
		}

		$this->maybe_fire_post_deleted( $post );
	}

	/**
	 * Capture the public permalink of a post and fire `crawlwp/post_deleted`.
	 *
	 * @param WP_Post $post Post data.
	 *
	 * @return void
	 */
	private function maybe_fire_post_deleted( WP_Post $post ): void {

		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, Indexing::get_post_types(), true ) ) {
			return;
		}

		// Disable for Bulk Edit screen.
		if ( isset( $_REQUEST['bulk_edit'] ) && Indexing::is_on( 'disable_for_bulk_edit', 'general' ) ) {
			return;
		}

		$permalink = $this->get_published_permalink( $post );

		if ( empty( $permalink ) ) {
			return;
		}

		$this->submit( 'crawlwp/post_deleted', [ $post->ID, $permalink ] );
	}

	/**
	 * Build the permalink a post had while it was published.
	 *
	 * The post may already be trashed (slug suffixed with `__trashed`) or
	 * have a non-public status, so a published clone is used to resolve the URL.
	 *
	 * @param WP_Post $post Post data.
	 *
	 * @return string
	 */
	private function get_published_permalink( WP_Post $post ): string {

		$clone              = clone $post;
		$clone->post_status = 'publish';

		$desired_slug = (string) get_post_meta( $post->ID, '_wp_desired_post_slug', true );

		if ( $desired_slug !== '' ) {
			$clone->post_name = $desired_slug;
		} elseif ( str_ends_with( $clone->post_name, '__trashed' ) ) {
			$clone->post_name = substr( $clone->post_name, 0, - strlen( '__trashed' ) );
		}

		$permalink = get_permalink( $clone );

		return is_string( $permalink ) ? Utils::normalize_url( $permalink ) : '';
	}

	/**
	 * Fires after a term has been created or updated, and the term cache has been cleared.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function term_updated( int $term_id, int $tt_id, string $taxonomy ): void {

		if ( ! Indexing::is_on( 'ping_on_term', 'general' ) ) {
			return;
		}

		if ( Indexing::get_term_skip_reason( $term_id, $taxonomy ) !== '' ) {
			return;
		}

		$remaining = $this->get_remaining_delay( (int) get_term_meta( $term_id, Indexing::LAST_UPDATE_META, true ) );

		if ( $remaining > 0 ) {
			if ( ! wp_next_scheduled( self::DELAYED_TERM_EVENT, [ $term_id, $taxonomy ] ) ) {
				wp_schedule_single_event( time() + $remaining, self::DELAYED_TERM_EVENT, [ $term_id, $taxonomy ] );
			}

			return;
		}

		$this->submit( 'crawlwp/term_updated', [ $term_id, $taxonomy ] );
	}

	/**
	 * Whether submissions are sent in the background. Requests that nobody waits
	 * on (WP-Cron, WP-CLI) send them straight away. So do sites with WP-Cron
	 * disabled, since nothing may ever run the queue there; a site with a server
	 * cron can opt in with the filter.
	 */
	private function in_background(): bool {

		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}

		/**
		 * Filter whether pings are sent in the background or while the post,
		 * term or comment is being saved.
		 *
		 * @param bool $in_background Default true, false when DISABLE_WP_CRON is set.
		 */
		return (bool) apply_filters( 'crawlwp/submit_in_background', ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) );
	}

	/**
	 * Fire a submission action now, or add it to the background queue.
	 *
	 * Only IDs and strings are queued; objects are loaded again when the queue
	 * runs, so the submission uses the post as it is then.
	 *
	 * Queued items are kept in memory and written to the stored queue once, at the end
	 * of the request (see flush_pending()), so an import of thousands of posts doesn't
	 * rewrite the whole queue for every post.
	 *
	 * @param string $action Submission action.
	 * @param array  $args   Action arguments (IDs and strings).
	 *
	 * @return void
	 */
	private function submit( string $action, array $args ): void {

		if ( ! $this->in_background() ) {
			$this->background_submission( $action, $args );

			return;
		}

		self::add_to_queue( $this->pending, self::queue_key( $action, $args ), [ $action, $args ] );

		// A very long request (an import) is written out in parts, so little is lost if it is killed.
		if ( count( $this->pending ) >= self::PENDING_FLUSH ) {
			$this->flush_pending();
		}
	}

	/**
	 * Add an item to a queue array, one entry per post: saved twice, or saved and then
	 * commented on, before the queue runs still means one ping. "Added" wins over
	 * "updated", which wins over a comment, so the post is announced the way it first changed.
	 *
	 * @param array  $queue Queue to change.
	 * @param string $key   Queue key.
	 * @param array  $item  [ action, args ].
	 */
	private static function add_to_queue( array &$queue, string $key, array $item ): void {

		if ( ! isset( $queue[ $key ] ) || self::action_rank( (string) $item[0] ) > self::action_rank( (string) ( $queue[ $key ][0] ?? '' ) ) ) {
			$queue[ $key ] = [ (string) $item[0], (array) ( $item[1] ?? [] ) ];
		}
	}

	/**
	 * Write this request's queued submissions to the stored queue (one read and one write,
	 * under the lock) and make sure the background event is scheduled. Runs on shutdown.
	 *
	 * @return void
	 */
	public function flush_pending(): void {

		if ( $this->pending === [] ) {
			return;
		}

		$pending       = $this->pending;
		$this->pending = [];

		if ( ! self::lock_queue() ) {
			// The queue stayed locked (a stuck request): send these now rather than lose them.
			$this->send_items( $pending );

			return;
		}

		try {
			$queue = self::read_queue();

			foreach ( $pending as $key => $item ) {
				self::add_to_queue( $queue, (string) $key, $item );
			}

			update_option( self::QUEUE_OPTION, $queue, false );
		} finally {
			self::unlock_queue();
		}

		if ( wp_next_scheduled( self::BACKGROUND_EVENT ) ) {
			return;
		}

		if ( wp_schedule_single_event( time(), self::BACKGROUND_EVENT ) ) {
			$this->spawn_cron = true;
		} else {
			// Could not schedule the queue: send it now rather than lose it.
			$this->run_queue();
		}
	}

	/**
	 * The queue as stored now, not this request's cached copy of it.
	 *
	 * @return array<string, array>
	 */
	public static function read_queue(): array {

		$queue = self::read_fresh_option( self::QUEUE_OPTION );

		return is_array( $queue ) ? $queue : [];
	}

	/**
	 * An option as stored now, not this request's cached copy of it.
	 *
	 * @param string $name Option name.
	 *
	 * @return mixed Empty array when it doesn't exist.
	 */
	private static function read_fresh_option( string $name ) {

		wp_cache_delete( $name, 'options' );

		$missing = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $missing ) && isset( $missing[ $name ] ) ) {
			unset( $missing[ $name ] );
			wp_cache_set( 'notoptions', $missing, 'options' );
		}

		return get_option( $name, [] );
	}

	/**
	 * Take the queue lock. Waits up to about two seconds.
	 *
	 * Written straight to the options table, not with add_option()/delete_option(): those go
	 * through the object cache and the "notoptions" list, where a request that released the
	 * lock once could take it again while another request holds it. INSERT IGNORE lets exactly
	 * one request create the row. A lock older than 30 seconds belongs to a request that died;
	 * it is taken over with a compare-and-swap, so only one waiter gets it. A missing row is
	 * never treated as stale.
	 */
	public static function lock_queue(): bool {

		global $wpdb;

		for ( $i = 0; $i < 40; $i++ ) {
			$token = time() . ':' . wp_generate_password( 12, false );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO `$wpdb->options` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')", self::QUEUE_LOCK, $token ) ) === 1 ) {
				self::$lock_token = $token;

				return true;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `$wpdb->options` WHERE option_name = %s", self::QUEUE_LOCK ) );

			if (
				is_string( $held ) && $held !== '' && (int) $held < time() - 30
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				&& (int) $wpdb->query( $wpdb->prepare( "UPDATE `$wpdb->options` SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, self::QUEUE_LOCK, $held ) ) === 1
			) {
				self::$lock_token = $token;

				return true;
			}

			usleep( 50000 );
		}

		return false;
	}

	/**
	 * Release the lock, only if it is still this request's (it may have been taken over as stale).
	 */
	public static function unlock_queue(): void {

		global $wpdb;

		if ( self::$lock_token === '' ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM `$wpdb->options` WHERE option_name = %s AND option_value = %s", self::QUEUE_LOCK, self::$lock_token ) );

		self::$lock_token = '';
	}

	/**
	 * Queue key: one per post, term or removed URL.
	 */
	private static function queue_key( string $action, array $args ): string {

		switch ( $action ) {
			case 'crawlwp/post_added':
			case 'crawlwp/post_updated':
			case 'crawlwp/comment_updated':
				return 'post:' . (int) ( $args[0] ?? 0 );

			case 'crawlwp/post_deleted':
				return 'deleted:' . (int) ( $args[0] ?? 0 );

			default:
				return 'term:' . (int) ( $args[0] ?? 0 ) . ':' . (string) ( $args[1] ?? '' );
		}
	}

	private static function action_rank( string $action ): int {

		$ranks = [
			'crawlwp/comment_updated' => 1,
			'crawlwp/post_updated'    => 2,
			'crawlwp/post_added'      => 3,
		];

		return $ranks[ $action ] ?? 0;
	}

	/**
	 * Take the next batch out of the stored queue and remember it as "being sent".
	 *
	 * The batch stays in CLAIM_OPTION until it has been sent, so a run that dies (time limit,
	 * memory, a fatal error in a listener) loses nothing: the next run finds the old claim and
	 * puts its items back. An item that was in MAX_TRIES dead runs is dropped, so one that
	 * crashes every run can't block the queue forever.
	 *
	 * Call with the queue lock held.
	 *
	 * @param array $queue Stored queue.
	 * @param mixed $claim Stored claim ([ 'at' => time, 'items' => [...] ]) or anything else when there is none.
	 * @param int   $size  Batch size.
	 * @param int   $now   Current time.
	 *
	 * @return array{0: array|null, 1: array} The batch (null: another run is still sending its own) and the rest of the queue.
	 */
	public static function claim_batch( array $queue, $claim, int $size, int $now ): array {

		if ( is_array( $claim ) && ! empty( $claim['items'] ) && is_array( $claim['items'] ) ) {
			if ( (int) ( $claim['at'] ?? 0 ) >= $now - self::CLAIM_TTL ) {
				return [ null, $queue ];
			}

			foreach ( $claim['items'] as $key => $item ) {
				$tries = (int) ( $item[2] ?? 0 ) + 1;

				// A newer entry for the same post or term is already waiting: that one is sent.
				if ( ! is_array( $item ) || ! isset( $item[0] ) || $tries >= self::MAX_TRIES || isset( $queue[ $key ] ) ) {
					continue;
				}

				$queue[ $key ] = [ (string) $item[0], (array) ( $item[1] ?? [] ), $tries ];
			}
		}

		// New and updated content first, removals last.
		uasort(
			$queue,
			static function ( $a, $b ) {
				return ( (string) ( $a[0] ?? '' ) === 'crawlwp/post_deleted' ) <=> ( (string) ( $b[0] ?? '' ) === 'crawlwp/post_deleted' );
			}
		);

		$batch = array_slice( $queue, 0, max( 1, $size ), true );

		return [ $batch, array_diff_key( $queue, $batch ) ];
	}

	/**
	 * Submissions sent per background run. Smaller when a webmaster API (Google, Bing, Yandex)
	 * is on: those make a request per post while the run waits, so a big batch could outlast
	 * the server's time limit.
	 */
	private static function batch_size(): int {

		/**
		 * Filter whether listeners that make a request per submission are active.
		 *
		 * @param bool $slow Default false; the webmaster APIs set it to true.
		 */
		$slow = (bool) apply_filters( 'crawlwp/submission_slow_listeners', false );

		/**
		 * Filter how many queued submissions one background run sends.
		 *
		 * @param int  $size Batch size.
		 * @param bool $slow Whether a webmaster API is on.
		 */
		return max( 1, (int) apply_filters( 'crawlwp/submission_batch_size', $slow ? self::BATCH_SIZE_SLOW : self::BATCH_SIZE, $slow ) );
	}

	/**
	 * Seconds one background run may spend sending before it hands the rest to the next run.
	 */
	private static function time_budget(): int {

		$limit = (int) ini_get( 'max_execution_time' );

		return $limit > 0 ? max( 5, min( 40, (int) ( $limit * 0.6 ) ) ) : 40;
	}

	/**
	 * Send queued submissions: new and updated content first, removals last, so a
	 * mass trash can't use up a search engine's quota before new posts are sent.
	 *
	 * IndexNow engines collect the URLs between crawlwp/submission_batch_start and
	 * crawlwp/submission_batch_end and send them in one request (see IndexNowAbstract).
	 * The batch is sent in chunks, so IndexNow gets each chunk's URLs as soon as that
	 * chunk is done instead of only at the very end of the run.
	 *
	 * Also accepts the action and arguments of events queued one by one by earlier
	 * versions, so those still go out after an update.
	 *
	 * @param string|null $action Legacy single event: submission action.
	 * @param array|null  $args   Legacy single event: its arguments.
	 *
	 * @return void
	 */
	public function run_queue( $action = null, $args = null ): void {

		if ( is_string( $action ) && $action !== '' ) {
			$this->background_submission( $action, (array) $args );

			return;
		}

		if ( ! self::lock_queue() ) {
			// Another request holds the queue: try again shortly.
			self::schedule_next_run();

			return;
		}

		try {
			// Claimed under the lock from what is stored now, so an overlapping run (or a request
			// holding an old copy) can't send the same items again or drop new ones.
			[ $batch, $rest ] = self::claim_batch( self::read_queue(), self::read_fresh_option( self::CLAIM_OPTION ), self::batch_size(), time() );

			if ( $batch === null ) {
				// Another run is still sending its batch.
				self::schedule_next_run();

				return;
			}

			if ( $rest ) {
				update_option( self::QUEUE_OPTION, $rest, false );
				self::schedule_next_run();
			} else {
				delete_option( self::QUEUE_OPTION );
			}

			if ( $batch === [] ) {
				delete_option( self::CLAIM_OPTION );

				return;
			}

			update_option( self::CLAIM_OPTION, [ 'at' => time(), 'items' => $batch ], false );

			if ( ! wp_next_scheduled( self::RECOVER_EVENT ) ) {
				wp_schedule_single_event( time() + self::CLAIM_TTL + MINUTE_IN_SECONDS, self::RECOVER_EVENT );
			}
		} finally {
			self::unlock_queue();
		}

		// One query for the batch's posts instead of one per post (All in One SEO's own table).
		$post_ids = [];

		foreach ( $batch as $item ) {
			if ( in_array( (string) ( $item[0] ?? '' ), [ 'crawlwp/post_added', 'crawlwp/post_updated', 'crawlwp/comment_updated' ], true ) ) {
				$post_ids[] = (int) ( $item[1][0] ?? 0 );
			}
		}

		if ( $post_ids ) {
			ThirdPartyRobots::prime( $post_ids );
		}

		$started = time();
		$left    = $batch;

		foreach ( array_chunk( $batch, self::CHUNK_SIZE, true ) as $chunk ) {
			$this->send_items( $chunk );

			$left = array_diff_key( $left, $chunk );

			if ( $left === [] ) {
				break;
			}

			// Sent items leave the claim, which also shows other runs this one is alive.
			update_option( self::CLAIM_OPTION, [ 'at' => time(), 'items' => $left ], false );

			if ( time() - $started >= self::time_budget() ) {
				break;
			}
		}

		if ( $left === [] ) {
			delete_option( self::CLAIM_OPTION );
			wp_clear_scheduled_hook( self::RECOVER_EVENT );

			return;
		}

		// Out of time: the unsent items go back to the queue for the next run.
		if ( ! self::lock_queue() ) {
			// Left in the claim; the next run after CLAIM_TTL picks them up.
			self::schedule_next_run();

			return;
		}

		try {
			$queue = self::read_queue();

			foreach ( $left as $key => $item ) {
				if ( ! isset( $queue[ $key ] ) ) {
					$queue[ $key ] = $item;
				}
			}

			update_option( self::QUEUE_OPTION, $queue, false );
			delete_option( self::CLAIM_OPTION );
			wp_clear_scheduled_hook( self::RECOVER_EVENT );
		} finally {
			self::unlock_queue();
		}

		self::schedule_next_run();
	}

	private static function schedule_next_run(): void {

		if ( ! wp_next_scheduled( self::BACKGROUND_EVENT ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::BACKGROUND_EVENT );
		}
	}

	/**
	 * Send some queue items as one IndexNow batch.
	 *
	 * @param array $items Queue items: [ action, args ].
	 */
	private function send_items( array $items ): void {

		do_action( 'crawlwp/submission_batch_start' );

		try {
			foreach ( $items as $item ) {
				if ( is_array( $item ) && isset( $item[0] ) ) {
					$this->background_submission( (string) $item[0], (array) ( $item[1] ?? [] ) );
				}
			}
		} finally {
			do_action( 'crawlwp/submission_batch_end' );
		}
	}

	/**
	 * Start WP-Cron at the end of the request so queued submissions go out within
	 * seconds. spawn_cron() does not wait for it. When the loopback request is
	 * blocked, the next WP-Cron run (any page view, or the server cron) sends them.
	 *
	 * Skipped with ALTERNATE_WP_CRON: there spawn_cron() redirects and runs cron in
	 * the current request, which can't be done once the response has been sent.
	 *
	 * @return void
	 */
	public function maybe_spawn_cron(): void {

		if (
			$this->spawn_cron
			&& ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON )
			&& ! ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON )
		) {
			$this->spawn_cron = false;
			spawn_cron();
		}
	}

	/**
	 * Send a submission: fire its action with the current post, term or comment.
	 *
	 * @param string $action Submission action.
	 * @param array  $args   Queued arguments.
	 *
	 * @return void
	 */
	public function background_submission( $action, $args ): void {

		$action = (string) $action;
		$args   = array_values( (array) $args );

		if ( ! in_array( $action, self::BACKGROUND_ACTIONS, true ) ) {
			return;
		}

		switch ( $action ) {
			case 'crawlwp/post_added':
			case 'crawlwp/post_updated':
				$post = get_post( (int) ( $args[0] ?? 0 ) );

				// Re-checked: the post may have been unpublished or set to noindex since.
				if ( $post instanceof WP_Post && Indexing::get_post_skip_reason( $post ) === '' ) {
					do_action( $action, $post->ID, $post );
				}
				break;

			case 'crawlwp/post_deleted':
				do_action( $action, (int) ( $args[0] ?? 0 ), (string) ( $args[1] ?? '' ) );
				break;

			case 'crawlwp/comment_updated':
				$post = get_post( (int) ( $args[0] ?? 0 ) );

				// the comment was approved when queued; the post is re-checked as it may have changed since.
				if ( $post instanceof WP_Post && Indexing::get_post_skip_reason( $post ) === '' ) {
					do_action( $action, $post->ID, null );
				}
				break;

			case 'crawlwp/term_updated':
				if ( Indexing::get_term_skip_reason( (int) ( $args[0] ?? 0 ), (string) ( $args[1] ?? '' ) ) === '' ) {
					do_action( $action, (int) $args[0], (string) $args[1] );
				}
				break;
		}
	}
}
