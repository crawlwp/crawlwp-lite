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
	 * Prefix of the options holding submissions that wait for the background event (none autoloaded).
	 * The queue is kept in buckets of BUCKET_SIZE items ("crawlwp_submission_queue_q_1", "_q_2"…;
	 * removals in "_d_1"…), so adding to it or taking a batch from it reads and writes one small
	 * option, however long the queue is. An option with exactly this name is the single-option
	 * queue of development builds; it is moved into buckets the first time the queue is used.
	 */
	public const QUEUE_OPTION = 'crawlwp_submission_queue';

	/** Where the queue starts and ends: [ 'v' => 2, 'q' => [ first, last ], 'd' => [ first, last ], 'n' => items ]. */
	public const QUEUE_INDEX = 'crawlwp_submission_queue_index';

	/** Items per queue bucket. */
	public const BUCKET_SIZE = 500;

	/** Most items the queue holds; more are not queued (and logged). */
	public const MAX_QUEUE = 50000;

	/** Held (a few milliseconds) while the queue is read and written, so writers can't overwrite each other. */
	public const QUEUE_LOCK = 'crawlwp_submission_queue_lock';

	/**
	 * The batch a background run is sending now (not autoloaded):
	 * [ 'at' => time, 'owner' => run token, 'items' => [...] ]. It stays until the run finishes,
	 * so a run that dies loses nothing (see recover_claim()).
	 */
	public const CLAIM_OPTION = 'crawlwp_submission_claimed';

	/**
	 * How far the run that owns the claim has got (small, written often):
	 * [ 'owner', 'at', 'flushed' => items fully sent, 'from' / 'to' => items being sent now ].
	 */
	public const CLAIM_BEAT = 'crawlwp_submission_claim_beat';

	/** A claim not updated for this long belongs to a run that died. */
	public const CLAIM_TTL = 600;

	/** Dead runs an item may cause (or, when unknown, be part of) before it is dropped. */
	public const MAX_TRIES = 3;

	/**
	 * Sites without WP-Cron only: when the first held submission may be sent (autoloaded, 0 when
	 * nothing waits), so later requests can send it without a query.
	 */
	public const WAITING_OPTION = 'crawlwp_submission_waiting';

	/**
	 * Seconds a post saved through the REST API (the block editor) waits before it is sent: the
	 * editor saves the SEO box (noindex) in a second request, a moment after the post itself.
	 */
	public const SAVE_DELAY = 45;

	/** Submissions that could not be added to the queue because it stayed locked; the next run adds them. */
	public const HELD_OPTION = 'crawlwp_submission_held';

	/**
	 * Submissions sent per background run; the rest wait for the next run. IndexNow takes
	 * thousands of URLs per request, so a run with only IndexNow sends many.
	 */
	private const BATCH_SIZE = 1000;

	/** Batch size when a webmaster API makes a request per post (see batch_size()). */
	private const BATCH_SIZE_SLOW = 50;

	/** Items collected per IndexNow request within a run. */
	private const CHUNK_SIZE = 500;

	/** The same when a webmaster API is on: small, so little is sent twice after a run dies. */
	private const CHUNK_SIZE_SLOW = 5;

	/** Without a webmaster API the run's position is saved once per this many items. */
	private const BEAT_EVERY = 25;

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
	 * Submissions queued in this request, per site (multisite: a post can be saved in another
	 * site with switch_to_blog()), written to that site's stored queue on shutdown.
	 *
	 * @var array<int, array<string, array>>
	 */
	private $pending = [];

	/** @var int Number of items in $pending. */
	private $pending_count = 0;

	/**
	 * Posts to look at again at the end of the request, per site: [ post ID => action ]. The SEO
	 * box (noindex) is saved after the post's status changes, sometimes in a request of its own.
	 *
	 * @var array<int, array<int, string>>
	 */
	private $recheck = [];

	/**
	 * The background run in progress: [ 'owner', 'flushed', 'current', 'beat_at' ], or null.
	 *
	 * @var array|null
	 */
	private $run = null;

	/** @var bool Whether the shutdown function that records a run's position is registered. */
	private $run_guard = false;

	/** @var int When the first item left waiting by the last run may be sent, 0 when none. */
	private $next_due = 0;

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
		add_action( 'shutdown', [ $this, 'on_shutdown' ], 9 );
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
		$this->submit( 'crawlwp/comment_updated', [ $post->ID, (int) $comment->comment_ID ] );
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

		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		$is_update = $old_status === $new_status;

		if ( ! empty( $_REQUEST['meta-box-loader'] ) ) { // phpcs:ignore
			/*
			 * The block editor's second request, which saves the SEO box. The post itself was
			 * handled by the first one; it is looked at again when this request ends, in case
			 * its noindex setting has just changed (see recheck_posts()).
			 */
			$this->recheck[ get_current_blog_id() ][ $post->ID ] = 'crawlwp/post_updated';

			return;
		}

		// Disable for Bulk Edit screen.
		if ( isset( $_REQUEST['bulk_edit'] ) && Indexing::is_on( 'disable_for_bulk_edit', 'general' ) ) {
			return;
		}

		$action = $is_update ? 'crawlwp/post_updated' : 'crawlwp/post_added';

		if ( ! Indexing::is_on( $is_update ? 'ping_on_post_updated' : 'ping_on_post', 'general' ) ) {
			return;
		}

		if ( Indexing::get_post_skip_reason( $post ) !== '' ) {
			// Noindex now, but the SEO box is saved after the status change: looked at again when the request ends.
			if ( $post->post_status === 'publish' && in_array( $post->post_type, Indexing::get_post_types(), true ) && Indexing::get_post_noindex_source( $post ) !== '' ) {
				$this->recheck[ get_current_blog_id() ][ $post->ID ] = $action;
			}

			return;
		}

		if ( $this->defer_post_if_throttled( $post ) ) {
			return;
		}

		$this->submit( $action, [ $post->ID ] );
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
			// A block editor save: not before its second request has saved the SEO box (a noindex choice).
			$wait = self::is_rest() ? max( $remaining, self::save_delay() ) : $remaining;

			wp_schedule_single_event( time() + $wait, self::DELAYED_POST_EVENT, [ $post->ID ] );
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
	 * on (WP-Cron, WP-CLI) send them when the request ends. So do sites with WP-Cron
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
	 * Whether this request is the REST API. The block editor saves a post through it, and the
	 * SEO box in a second request a moment later.
	 */
	private static function is_rest(): bool {

		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	/**
	 * Seconds a post saved through the REST API waits before it is sent.
	 */
	private static function save_delay(): int {

		/**
		 * Filter how long a post saved through the REST API (the block editor) waits before it
		 * is sent, so its SEO box settings, saved in a second request, are in place. 0 to not wait.
		 *
		 * @param int $seconds Default 45.
		 */
		return max( 0, (int) apply_filters( 'crawlwp/submission_save_delay', self::SAVE_DELAY ) );
	}

	/**
	 * Queue a submission. Nothing is sent while the post, term or comment is being saved: the
	 * item is kept in memory and, when the request ends (see flush_pending()), written to the
	 * stored queue for the background event, or sent there and then on sites without one.
	 *
	 * Only IDs and strings are queued; objects are loaded again when the item is sent, so the
	 * submission uses the post as it is then (a post set to noindex in the meantime is skipped).
	 *
	 * @param string $action Submission action.
	 * @param array  $args   Action arguments (IDs and strings).
	 *
	 * @return void
	 */
	private function submit( string $action, array $args ): void {

		// A very long request (an import) is written out in parts, so little is lost if it is killed.
		if ( $this->pending_count >= self::PENDING_FLUSH ) {
			$this->flush_pending( false );
		}

		$delay = self::is_rest() && in_array( $action, [ 'crawlwp/post_added', 'crawlwp/post_updated' ], true ) ? self::save_delay() : 0;

		$this->add_pending( $action, $args, $delay > 0 ? time() + $delay : 0 );
	}

	/**
	 * Remember a submission for the end of the request, under the site it belongs to.
	 *
	 * @param string $action     Submission action.
	 * @param array  $args       Action arguments.
	 * @param int    $not_before Earliest time to send it, 0 for as soon as possible.
	 */
	private function add_pending( string $action, array $args, int $not_before ): void {

		$blog_id = get_current_blog_id();

		if ( ! isset( $this->pending[ $blog_id ] ) ) {
			$this->pending[ $blog_id ] = [];
		}

		if ( self::add_to_queue( $this->pending[ $blog_id ], self::queue_key( $action, $args ), [ $action, $args, 0, $not_before, (int) current_time( 'timestamp' ) ] ) ) {
			++$this->pending_count;
		}
	}

	/**
	 * A queue item with every field: [ action, args, tries, not before (time), queued at (site time) ].
	 *
	 * @param mixed $item Stored item.
	 */
	private static function normalize_item( $item ): array {

		$item = (array) $item;

		return [ (string) ( $item[0] ?? '' ), array_values( (array) ( $item[1] ?? [] ) ), (int) ( $item[2] ?? 0 ), (int) ( $item[3] ?? 0 ), (int) ( $item[4] ?? 0 ) ];
	}

	/**
	 * Add an item to a queue array, one entry per post: saved twice, or saved and then
	 * commented on, before the queue runs still means one ping. "Added" wins over
	 * "updated", which wins over a comment, so the post is announced the way it first changed.
	 *
	 * @param array  $queue Queue to change.
	 * @param string $key   Queue key.
	 * @param array  $item  Queue item.
	 *
	 * @return bool Whether the key is new in this queue.
	 */
	private static function add_to_queue( array &$queue, string $key, array $item ): bool {

		$item = self::normalize_item( $item );

		if ( ! isset( $queue[ $key ] ) ) {
			$queue[ $key ] = $item;

			return true;
		}

		$old = self::normalize_item( $queue[ $key ] );

		if ( self::action_rank( $item[0] ) > self::action_rank( $old[0] ) ) {
			$old[0] = $item[0];
			$old[1] = $item[1];
		}

		$old[2] = max( $old[2], $item[2] );
		// A new save through the REST API waits again; anything else lets the entry go now.
		$old[3] = $item[3] === 0 ? 0 : max( $old[3], $item[3] );
		// The newer save's time, so a submission made between the two saves doesn't hide the second one.
		// 0 (an item a run already tried to send) stays 0: it is sent whatever was recorded since.
		$old[4] = $item[4] === 0 ? 0 : max( $old[4], $item[4] );

		$queue[ $key ] = $old;

		return false;
	}

	/**
	 * End of the request: write out what was queued, then, on sites without WP-Cron, send
	 * held submissions that are due.
	 *
	 * @return void
	 */
	public function on_shutdown(): void {

		$this->flush_pending();
		$this->run_waiting();
	}

	/**
	 * Posts whose status change was not queued because they were noindex at that moment, and
	 * posts saved by the block editor's second (SEO box) request: looked at again now that the
	 * SEO box has been saved, and queued when they can be submitted after all.
	 */
	private function recheck_posts(): void {

		$recheck       = $this->recheck;
		$this->recheck = [];
		$current       = get_current_blog_id();

		foreach ( $recheck as $blog_id => $posts ) {
			$switched = is_multisite() && (int) $blog_id !== $current;

			if ( $switched ) {
				switch_to_blog( (int) $blog_id );
			}

			try {
				foreach ( $posts as $post_id => $action ) {
					$post = get_post( (int) $post_id );

					if ( ! $post instanceof WP_Post || $post->post_status !== 'publish' ) {
						continue;
					}

					if ( ! Indexing::is_on( $action === 'crawlwp/post_added' ? 'ping_on_post' : 'ping_on_post_updated', 'general' ) ) {
						continue;
					}

					if ( Indexing::get_post_skip_reason( $post ) !== '' || $this->defer_post_if_throttled( $post ) ) {
						continue;
					}

					$this->add_pending( (string) $action, [ $post->ID ], 0 );
				}
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}
	}

	/**
	 * Write this request's queued submissions to the stored queue of the site each belongs to
	 * (one small read and write per site, under the lock) and make sure the background event
	 * is scheduled. Runs on shutdown, and earlier in very long requests.
	 *
	 * @param bool $final Whether the request is ending (false for an early write).
	 *
	 * @return void
	 */
	public function flush_pending( $final = true ): void {

		if ( $final !== false ) {
			$this->recheck_posts();
		}

		if ( $this->pending === [] ) {
			return;
		}

		$pending             = $this->pending;
		$this->pending       = [];
		$this->pending_count = 0;
		$current             = get_current_blog_id();

		foreach ( $pending as $blog_id => $items ) {
			if ( ! $items ) {
				continue;
			}

			// Saved while another site was current (switch_to_blog()): it goes to that site's queue.
			$switched = is_multisite() && (int) $blog_id !== $current;

			if ( $switched ) {
				switch_to_blog( (int) $blog_id );
			}

			try {
				$this->flush_site( $items, $switched );
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}
	}

	/**
	 * Store (or, without a background event, send) the current site's queued submissions.
	 *
	 * @param array $items    Queue items by key.
	 * @param bool  $switched Whether the current site is not the request's own.
	 */
	private function flush_site( array $items, bool $switched ): void {

		if ( $switched && ! self::active_on_current_site() ) {
			// Saved inside switch_to_blog() for a site that doesn't run this plugin: nothing there would ever send it.
			return;
		}

		$background = $this->in_background();

		if ( ! $background && self::waiting_since() === 0 ) {
			/*
			 * No background event: send now, except posts just saved through the REST API (the
			 * block editor), which are held until their SEO box has been saved by the editor's
			 * second request or the delay has passed.
			 */
			$hold = [];

			if ( self::is_rest() ) {
				foreach ( $items as $key => $item ) {
					if ( (int) ( $item[3] ?? 0 ) > time() ) {
						$hold[ $key ] = $item;
					}
				}
			}

			$now = array_diff_key( $items, $hold );

			if ( $now ) {
				$this->send_items( $now );
			}

			if ( ! $hold ) {
				return;
			}

			$items = $hold;
		}

		$earliest = PHP_INT_MAX;

		foreach ( $items as $item ) {
			$earliest = min( $earliest, (int) ( $item[3] ?? 0 ) );
		}

		if ( ! self::lock_queue() ) {
			/*
			 * The queue stayed locked (a stuck request). Nothing is sent from here: a post just
			 * saved in the block editor must wait for its SEO box, and this may be the editor's own
			 * request. The items are set aside and the next run adds them to the queue.
			 */
			$held = self::read_fresh_option( self::HELD_OPTION );
			$held = is_array( $held ) ? $held : [];

			foreach ( $items as $key => $item ) {
				self::add_to_queue( $held, (string) $key, (array) $item );
			}

			update_option( self::HELD_OPTION, $held, false );

			$at = max( time() + 5, $earliest );

			if ( $background ) {
				self::schedule_next_run( $at );
			} elseif ( self::waiting_since() === 0 || self::waiting_since() > $at ) {
				update_option( self::WAITING_OPTION, $at, true );
			}

			return;
		}

		try {
			$index   = self::read_index();
			$dropped = self::enqueue( $items, $index );
			self::save_index( $index );
		} finally {
			self::unlock_queue();
		}

		if ( $dropped > 0 ) {
			/* translators: 1: number of submissions, 2: queue limit */
			self::log( sprintf( __( 'Not queued: %1$d submissions to search engines were left out because %2$d are already waiting to be sent.', 'mihdan-index-now' ), $dropped, self::MAX_QUEUE ) );
		}

		if ( ! $background ) {
			// Held items of this site go out through the stored queue: what is due now, the rest later.
			$this->drain_inline();

			return;
		}

		$at   = max( time(), $earliest );
		$next = wp_next_scheduled( self::BACKGROUND_EVENT );

		if ( $next && $next <= $at ) {
			return;
		}

		if ( $next ) {
			// Scheduled for later than these items need (it was waiting for a post's SEO box): moved forward.
			wp_clear_scheduled_hook( self::BACKGROUND_EVENT );
		}

		if ( ! wp_schedule_single_event( $at, self::BACKGROUND_EVENT ) ) {
			// Could not schedule the queue: send what is due now (within the run's time budget) rather
			// than lose it. Posts still waiting for their SEO box stay queued for the next run.
			$this->process_queue();
		} elseif ( $at <= time() ) {
			if ( $switched ) {
				// The event belongs to the switched-to site, so its cron is started while it is current.
				if ( self::can_spawn_cron() ) {
					spawn_cron();
				}
			} else {
				$this->spawn_cron = true;
			}
		}
	}

	/**
	 * Whether this plugin is active on the current (switched-to) site.
	 */
	private static function active_on_current_site(): bool {

		if ( ! defined( 'CRAWLWP_BASENAME' ) ) {
			return true;
		}

		$network = (array) get_site_option( 'active_sitewide_plugins', [] );

		return isset( $network[ CRAWLWP_BASENAME ] ) || in_array( CRAWLWP_BASENAME, (array) get_option( 'active_plugins', [] ), true );
	}

	/**
	 * Sites without WP-Cron: when the first held submission may be sent, 0 when nothing waits.
	 * Read from the autoloaded options, so it never costs a query.
	 */
	private static function waiting_since(): int {

		$options = wp_load_alloptions();

		return (int) ( $options[ self::WAITING_OPTION ] ?? 0 );
	}

	/**
	 * Sites without WP-Cron: send the stored submissions that are due and remember when the
	 * rest is, for a later request (see run_waiting()).
	 */
	private function drain_inline(): void {

		$this->next_due = 0;
		$this->process_queue();

		$left = self::peek_count();
		$at   = $left > 0 ? ( $this->next_due > 0 ? $this->next_due : time() + MINUTE_IN_SECONDS ) : 0;

		if ( $at === 0 && self::read_fresh_option( self::CLAIM_OPTION ) ) {
			// A batch is still claimed (by a run in progress, or one that died): looked at again
			// once it can be taken over, so it is not left claimed until the next save.
			$at = time() + self::CLAIM_TTL + 1;
		}

		if ( self::waiting_since() !== $at ) {
			update_option( self::WAITING_OPTION, $at, true );
		}
	}

	/**
	 * Sites without WP-Cron: at the end of any later request, send held submissions that are due.
	 */
	private function run_waiting(): void {

		if ( $this->in_background() ) {
			return;
		}

		$due = self::waiting_since();

		if ( $due > 0 && $due <= time() ) {
			// This can be any request, a visitor's page view included: on PHP-FPM the response is
			// completed first (shutdown: the output is already written), so nobody waits on the sending.
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			}

			$this->drain_inline();
		}
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
	 * Name of a queue bucket option.
	 *
	 * @param string $lane   'q' (new and updated content) or 'd' (removals).
	 * @param int    $number Bucket number.
	 */
	public static function bucket_name( string $lane, int $number ): string {

		return self::QUEUE_OPTION . '_' . $lane . '_' . $number;
	}

	/**
	 * A queue bucket as stored now.
	 *
	 * @return array<string, array>
	 */
	private static function read_bucket( string $lane, int $number ): array {

		$bucket = self::read_fresh_option( self::bucket_name( $lane, $number ) );

		return is_array( $bucket ) ? $bucket : [];
	}

	/**
	 * Where the queue starts and ends. Call with the queue lock held: the first call moves a
	 * single-option queue of a development build into buckets.
	 *
	 * @return array{v: int, q: array{0: int, 1: int}, d: array{0: int, 1: int}, n: int}
	 */
	public static function read_index(): array {

		$stored = self::read_fresh_option( self::QUEUE_INDEX );
		$known  = is_array( $stored ) && (int) ( $stored['v'] ?? 0 ) === 2;
		$index  = [ 'v' => 2, 'q' => [ 1, 0 ], 'd' => [ 1, 0 ], 'n' => 0 ];

		if ( $known ) {
			foreach ( [ 'q', 'd' ] as $lane ) {
				if ( isset( $stored[ $lane ][0], $stored[ $lane ][1] ) ) {
					$index[ $lane ] = [ max( 1, (int) $stored[ $lane ][0] ), max( 0, (int) $stored[ $lane ][1] ) ];
				}
			}

			$index['n'] = max( 0, (int) ( $stored['n'] ?? 0 ) );

			return $index;
		}

		$legacy = self::read_fresh_option( self::QUEUE_OPTION );

		if ( is_array( $legacy ) && $legacy ) {
			self::enqueue( $legacy, $index, true );
		}

		if ( $legacy !== [] ) {
			delete_option( self::QUEUE_OPTION );
		}

		self::save_index( $index );

		return $index;
	}

	private static function save_index( array $index ): void {

		update_option( self::QUEUE_INDEX, $index, false );
	}

	/**
	 * Items waiting in the stored queue (as counted; used to decide whether to come back).
	 */
	private static function peek_count(): int {

		$stored = self::read_fresh_option( self::QUEUE_INDEX );

		if ( ! is_array( $stored ) ) {
			return 0;
		}

		return max( self::has_buckets( $stored ) ? 1 : 0, (int) ( $stored['n'] ?? 0 ) );
	}

	/**
	 * Whether the index still points at a bucket in either lane.
	 *
	 * @param array $index Queue index.
	 */
	private static function has_buckets( array $index ): bool {

		foreach ( [ 'q', 'd' ] as $lane ) {
			if ( isset( $index[ $lane ][0], $index[ $lane ][1] ) && (int) $index[ $lane ][0] <= (int) $index[ $lane ][1] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Delete the stored queue of the current site (deactivation): buckets, index, claim, lock
	 * and the waiting marker. Old submissions are not sent weeks later on reactivation.
	 */
	public static function delete_queue(): void {

		global $wpdb;

		foreach ( [ self::QUEUE_OPTION, self::QUEUE_INDEX, self::CLAIM_OPTION, self::CLAIM_BEAT, self::WAITING_OPTION, self::HELD_OPTION ] as $name ) {
			delete_option( $name );
		}

		// Buckets and the lock row, also ones the index no longer points at.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::QUEUE_OPTION . '_' ) . '%' ) ); // phpcs:ignore
	}

	/**
	 * Add items to the end of the stored queue: removals to their own lane, so they are sent
	 * after new and updated content without the queue ever being sorted. Only the last bucket
	 * of a lane is read and written. An item whose post or term is already in that bucket is
	 * merged into it; one in an earlier bucket is left there, and the later copy is skipped
	 * when it is sent (see sent_since_queued()).
	 *
	 * Call with the queue lock held.
	 *
	 * @param array $items  Queue items by key.
	 * @param array $index  Queue index, updated.
	 * @param bool  $no_cap Whether to add past MAX_QUEUE (items that were already queued once).
	 *
	 * @return int Items left out because the queue is full.
	 */
	public static function enqueue( array $items, array &$index, bool $no_cap = false ): int {

		$dropped = 0;
		$open    = [];

		foreach ( $items as $key => $item ) {
			if ( ! is_array( $item ) || ! isset( $item[0] ) ) {
				continue;
			}

			$key  = (string) $key;
			$lane = (string) $item[0] === 'crawlwp/post_deleted' ? 'd' : 'q';

			if ( ! isset( $open[ $lane ] ) ) {
				[ $head, $tail ] = $index[ $lane ];

				$number = $tail >= $head ? $tail : $tail + 1;
				$bucket = self::read_bucket( $lane, $number );

				if ( count( $bucket ) >= self::BUCKET_SIZE ) {
					++$number;
					// Read, not assumed empty: a request that died may have left it behind.
					$bucket = self::read_bucket( $lane, $number );
				}

				$open[ $lane ] = [ $number, $bucket, false ];
			}

			if ( ! isset( $open[ $lane ][1][ $key ] ) && ! $no_cap && $index['n'] >= self::MAX_QUEUE ) {
				++$dropped;

				continue;
			}

			if ( self::add_to_queue( $open[ $lane ][1], $key, $item ) ) {
				++$index['n'];
			}

			$open[ $lane ][2] = true;

			if ( count( $open[ $lane ][1] ) >= self::BUCKET_SIZE ) {
				update_option( self::bucket_name( $lane, $open[ $lane ][0] ), $open[ $lane ][1], false );

				$index[ $lane ][1] = $open[ $lane ][0];
				$open[ $lane ]     = [ $open[ $lane ][0] + 1, self::read_bucket( $lane, $open[ $lane ][0] + 1 ), false ];
			}
		}

		foreach ( $open as $lane => [ $number, $bucket, $dirty ] ) {
			if ( $dirty && $bucket ) {
				update_option( self::bucket_name( $lane, $number ), $bucket, false );

				$index[ $lane ][1] = $number;
			}
		}

		return $dropped;
	}

	/**
	 * Work out the next batch: items that are due, from the front of the queue, new and updated
	 * content before removals. Only the buckets the batch comes from are read. Nothing is
	 * written here: the caller stores the claim first and then applies the returned bucket
	 * changes, so a request that dies in between sends an item twice rather than not at all.
	 *
	 * Call with the queue lock held.
	 *
	 * @param array $index   Queue index, updated.
	 * @param int   $size    Batch size.
	 * @param int   $now     Current time.
	 * @param bool  $all_due Whether to take items that are still waiting too.
	 *
	 * @return array{0: array, 1: int, 2: array} The batch, when the first item left waiting is due (0: none), and the bucket changes ([ name, items or null to delete ]).
	 */
	public static function take_batch( array &$index, int $size, int $now, bool $all_due = false ): array {

		$batch    = [];
		$next_due = 0;
		$changes  = [];
		$size     = max( 1, $size );

		foreach ( [ 'q', 'd' ] as $lane ) {
			while ( count( $batch ) < $size && $index[ $lane ][0] <= $index[ $lane ][1] ) {
				$number = $index[ $lane ][0];
				$keep   = [];
				$taken  = 0;

				foreach ( self::read_bucket( $lane, $number ) as $key => $item ) {
					if ( ! is_array( $item ) || ! isset( $item[0] ) ) {
						++$taken;

						continue;
					}

					$not_before = (int) ( $item[3] ?? 0 );

					if ( count( $batch ) >= $size ) {
						$keep[ $key ] = $item;
					} elseif ( $not_before > $now && ! $all_due ) {
						$keep[ $key ] = $item;
						$next_due     = $next_due > 0 ? min( $next_due, $not_before ) : $not_before;
					} else {
						++$taken;

						if ( ! isset( $batch[ $key ] ) ) {
							$batch[ $key ] = $item;
						}
					}
				}

				$index['n'] = max( 0, $index['n'] - $taken );

				if ( $keep ) {
					$changes[] = [ self::bucket_name( $lane, $number ), $keep ];

					// Items of this bucket are still waiting: later buckets wait behind them.
					break;
				}

				$changes[] = [ self::bucket_name( $lane, $number ), null ];
				++$index[ $lane ][0];
			}

			if ( $index[ $lane ][0] > $index[ $lane ][1] ) {
				$index[ $lane ] = [ 1, 0 ];
			}
		}

		if ( $index['q'] === [ 1, 0 ] && $index['d'] === [ 1, 0 ] ) {
			$index['n'] = 0;
		}

		return [ $batch, $next_due, $changes ];
	}

	/**
	 * What to do with the batch another run claimed.
	 *
	 * A claim that was updated within CLAIM_TTL belongs to a run that is still sending: null.
	 * Otherwise that run died (time limit, memory, a fatal error in a listener), and its unsent
	 * items go back to the queue. The run's last recorded position says which items were fully
	 * sent (left out) and which it was sending when it died: only those count a try, so one item
	 * that kills every run is dropped after MAX_TRIES without taking the rest of its batch along.
	 * When the run never recorded a position, every item counts a try.
	 *
	 * @param mixed $claim Stored CLAIM_OPTION.
	 * @param mixed $beat  Stored CLAIM_BEAT.
	 * @param int   $now   Current time.
	 *
	 * @return array{requeue: array, dropped: string[], found: bool}|null
	 */
	public static function recover_claim( $claim, $beat, int $now ): ?array {

		$result = [ 'requeue' => [], 'dropped' => [], 'found' => false ];

		if ( ! is_array( $claim ) || empty( $claim['items'] ) || ! is_array( $claim['items'] ) ) {
			$result['found'] = is_array( $claim ) && $claim !== [];

			return $result;
		}

		$result['found'] = true;

		$owner    = (string) ( $claim['owner'] ?? '' );
		$has_beat = is_array( $beat ) && $owner !== '' && (string) ( $beat['owner'] ?? '' ) === $owner;
		$dead     = $has_beat && ! empty( $beat['dead'] );
		$alive_at = max( (int) ( $claim['at'] ?? 0 ), $has_beat ? (int) ( $beat['at'] ?? 0 ) : 0 );

		if ( ! $dead && $alive_at >= $now - self::CLAIM_TTL ) {
			return null;
		}

		$flushed = $has_beat ? max( 0, (int) ( $beat['flushed'] ?? 0 ) ) : 0;
		$from    = $has_beat ? (int) ( $beat['from'] ?? 0 ) : 0;
		$to      = $has_beat ? (int) ( $beat['to'] ?? 0 ) : PHP_INT_MAX;
		$i       = -1;

		foreach ( $claim['items'] as $key => $item ) {
			++$i;

			if ( $i < $flushed || ! is_array( $item ) || ! isset( $item[0] ) ) {
				continue;
			}

			$item = self::normalize_item( $item );

			if ( $i >= $from && $i < $to ) {
				++$item[2];
			}

			if ( $i < $to ) {
				// The dead run may have got this one to a webmaster API but not to IndexNow (sent at
				// the end of a chunk): it is sent again, not skipped as "submitted since queued".
				$item[4] = 0;
			}

			if ( $item[2] >= self::MAX_TRIES ) {
				$result['dropped'][] = (string) $key;

				continue;
			}

			$item[3]                           = 0;
			$result['requeue'][ (string) $key ] = $item;
		}

		return $result;
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
	 * Whether a webmaster API (Google, Bing, Yandex) is on: those make a request per post while
	 * the run waits.
	 */
	private static function has_slow_listeners(): bool {

		/**
		 * Filter whether listeners that make a request per submission are active.
		 *
		 * @param bool $slow Default false; the webmaster APIs set it to true.
		 */
		return (bool) apply_filters( 'crawlwp/submission_slow_listeners', false );
	}

	/**
	 * Submissions sent per background run. Smaller when a webmaster API is on, so a batch
	 * can't outlast the server's time limit.
	 */
	private static function batch_size(): int {

		$slow = self::has_slow_listeners();

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

		$this->process_queue();
	}

	/**
	 * Claim the next batch and send it.
	 *
	 * The batch is kept in CLAIM_OPTION until it has been sent, so a run that dies loses
	 * nothing: the next run puts the unsent part back (see recover_claim()).
	 *
	 * @param bool $all_due Whether to also send items that are still waiting for their SEO box.
	 */
	private function process_queue( bool $all_due = false ): void {

		if ( ! self::lock_queue() ) {
			// Another request holds the queue: try again shortly.
			self::schedule_next_run();

			return;
		}

		$owner   = wp_generate_password( 12, false );
		$dropped = [];

		try {
			// Claimed under the lock from what is stored now, so an overlapping run can't send the same items again.
			$recovered = self::recover_claim( self::read_fresh_option( self::CLAIM_OPTION ), self::read_fresh_option( self::CLAIM_BEAT ), time() );

			if ( $recovered === null ) {
				// Another run is still sending its batch.
				self::schedule_next_run();

				return;
			}

			$index   = self::read_index();
			$dropped = $recovered['dropped'];
			$held    = self::read_fresh_option( self::HELD_OPTION );

			if ( is_array( $held ) && $held ) {
				// Set aside by a request that could not get the lock (see flush_site()).
				self::enqueue( $held, $index );
				self::save_index( $index );
			}

			if ( $held ) {
				delete_option( self::HELD_OPTION );
			}

			if ( $recovered['requeue'] ) {
				self::enqueue( $recovered['requeue'], $index, true );
				self::save_index( $index );
			}

			[ $batch, $this->next_due, $changes ] = self::take_batch( $index, self::batch_size(), time(), $all_due );

			if ( $batch ) {
				update_option( self::CLAIM_OPTION, [ 'at' => time(), 'owner' => $owner, 'items' => $batch ], false );
			} elseif ( $recovered['found'] ) {
				delete_option( self::CLAIM_OPTION );
				delete_option( self::CLAIM_BEAT );
			}

			foreach ( $changes as [ $name, $items ] ) {
				if ( $items === null ) {
					delete_option( $name );
				} else {
					update_option( $name, $items, false );
				}
			}

			self::save_index( $index );

			// By the buckets, not only the count: the count can be short after a request died between writing a bucket and the index.
			if ( $index['n'] > 0 || self::has_buckets( $index ) ) {
				self::schedule_next_run( ! $batch && $this->next_due > 0 ? $this->next_due : 0 );
			}

			if ( $batch && ! wp_next_scheduled( self::RECOVER_EVENT ) ) {
				wp_schedule_single_event( time() + self::CLAIM_TTL + MINUTE_IN_SECONDS, self::RECOVER_EVENT );
			}
		} finally {
			self::unlock_queue();
		}

		if ( $dropped ) {
			/* translators: 1: number of tries, 2: list of queue entries, e.g. post:12 */
			self::log( sprintf( __( 'Not sent: these submissions to search engines were dropped after the background run stopped while sending them %1$d times: %2$s', 'mihdan-index-now' ), self::MAX_TRIES, implode( ', ', $dropped ) ) );
		}

		if ( $batch ) {
			$this->send_claimed( $batch, $owner );
		}
	}

	/**
	 * Send a claimed batch.
	 *
	 * IndexNow engines collect the URLs between crawlwp/submission_batch_start and
	 * crawlwp/submission_batch_end and send them in one request (see IndexNowAbstract), so the
	 * batch is sent in chunks: each chunk's URLs go to IndexNow when that chunk is done. The time
	 * budget is checked after every item, and the chunk in progress is closed (its URLs sent)
	 * before the run stops.
	 *
	 * The position is recorded as the run goes (before every item when a webmaster API makes each
	 * one slow, otherwise every BEAT_EVERY items, and exactly when the request is dying), which
	 * also shows other runs that this one is alive.
	 *
	 * @param array  $batch Queue items by key.
	 * @param string $owner This run's token, as stored in the claim.
	 */
	private function send_claimed( array $batch, string $owner ): void {

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

		$keys    = array_keys( $batch );
		$total   = count( $keys );
		$slow    = self::has_slow_listeners();
		$chunk   = $slow ? self::CHUNK_SIZE_SLOW : self::CHUNK_SIZE;
		$step    = $slow ? 1 : self::BEAT_EVERY;
		$budget  = self::time_budget();
		$started = time();
		$retry   = [];
		$i       = 0;
		$stop    = false;

		$this->run = [ 'owner' => $owner, 'flushed' => 0, 'current' => 0, 'beat_at' => $started ];
		$this->guard_run();

		while ( $i < $total && ! $stop ) {
			$end = min( $total, $i + $chunk );

			do_action( 'crawlwp/submission_batch_start' );

			try {
				while ( $i < $end ) {
					if ( ( $i - $this->run['flushed'] ) % $step === 0 && ! $this->beat( $i, min( $total, $i + $step ) ) ) {
						// Another run took this batch over (this one was taken for dead): it sends the rest.
						$this->run = null;

						return;
					}

					$this->run['current'] = $i;

					try {
						$this->send_item( $batch[ $keys[ $i ] ] );
					} catch ( \Throwable $e ) {
						// A listener failed on this item: the rest still goes out, this one is tried again later.
						$retry[ $keys[ $i ] ] = $e->getMessage();
					}

					++$i;

					if ( time() - $started >= $budget ) {
						$stop = true;

						break;
					}
				}
			} finally {
				// Also when stopping early: the chunk's URLs collected so far are sent to IndexNow.
				do_action( 'crawlwp/submission_batch_end' );
			}

			$this->run['flushed'] = $i;
		}

		$this->finish_run( $batch, $keys, $i, $owner, $retry );
	}

	/**
	 * Record how far the run has got. False when the batch now belongs to another run.
	 *
	 * @param int $from First item being sent now.
	 * @param int $to   One past the last item being sent now.
	 */
	private function beat( int $from, int $to ): bool {

		$now = time();

		// Long silence (one very slow request): another run may have taken the batch for dead.
		if ( $now - (int) $this->run['beat_at'] > self::CLAIM_TTL / 2 && ! self::owns_claim( (string) $this->run['owner'] ) ) {
			return false;
		}

		$this->run['beat_at'] = $now;

		update_option(
			self::CLAIM_BEAT,
			[
				'owner'   => $this->run['owner'],
				'at'      => $now,
				'flushed' => (int) $this->run['flushed'],
				'from'    => $from,
				'to'      => $to,
			],
			false
		);

		return true;
	}

	private static function owns_claim( string $owner ): bool {

		$claim = self::read_fresh_option( self::CLAIM_OPTION );

		return is_array( $claim ) && (string) ( $claim['owner'] ?? '' ) === $owner;
	}

	/**
	 * When the request dies in the middle of an item (time limit, memory, a fatal error), record
	 * which one, so only that item counts a try and the next run can pick the batch up at once.
	 */
	private function guard_run(): void {

		if ( $this->run_guard ) {
			return;
		}

		$this->run_guard = true;

		register_shutdown_function(
			function () {
				if ( $this->run === null ) {
					return;
				}

				$current = (int) $this->run['current'];

				update_option(
					self::CLAIM_BEAT,
					[
						'owner'   => $this->run['owner'],
						'at'      => time(),
						'flushed' => (int) $this->run['flushed'],
						'from'    => $current,
						'to'      => $current + 1,
						'dead'    => true,
					],
					false
				);
			}
		);
	}

	/**
	 * End of a run: put back what was not sent (out of time, or a listener failed on it) and
	 * release the claim, unless another run has taken the batch over in the meantime.
	 *
	 * @param array    $batch Claimed items by key.
	 * @param string[] $keys  Their keys, in order.
	 * @param int      $done  Items handled.
	 * @param string   $owner This run's token.
	 * @param array    $retry Keys of items a listener failed on => error message.
	 */
	private function finish_run( array $batch, array $keys, int $done, string $owner, array $retry ): void {

		$left    = [];
		$dropped = [];
		$total   = count( $keys );

		foreach ( $retry as $key => $message ) {
			$item = self::normalize_item( $batch[ $key ] );

			if ( ++$item[2] >= self::MAX_TRIES ) {
				$dropped[] = $key . ' (' . $message . ')';

				continue;
			}

			$item[3]      = time() + MINUTE_IN_SECONDS;
			$item[4]      = 0; // one listener may have recorded it before another failed: not skipped next time.
			$left[ $key ] = $item;
		}

		for ( $j = $done; $j < $total; $j++ ) {
			$left[ $keys[ $j ] ] = $batch[ $keys[ $j ] ];
		}

		$locked = self::lock_queue();

		try {
			if ( ! self::owns_claim( $owner ) ) {
				return;
			}

			if ( $left && ! $locked ) {
				// The queue can't be changed now: the rest stays claimed, marked for the next run to take at once.
				update_option( self::CLAIM_OPTION, [ 'at' => 0, 'owner' => $owner, 'items' => $left ], false );
				update_option( self::CLAIM_BEAT, [ 'owner' => $owner, 'at' => time(), 'flushed' => 0, 'from' => 0, 'to' => 0, 'dead' => true ], false );

				return;
			}

			if ( $left ) {
				$index = self::read_index();
				self::enqueue( $left, $index, true );
				self::save_index( $index );
			}

			delete_option( self::CLAIM_OPTION );
			delete_option( self::CLAIM_BEAT );
			wp_clear_scheduled_hook( self::RECOVER_EVENT );
		} finally {
			if ( $locked ) {
				self::unlock_queue();
			}

			$this->run = null;

			if ( $left ) {
				self::schedule_next_run();
			}

			if ( $dropped ) {
				/* translators: 1: number of tries, 2: list of queue entries with the error, e.g. post:12 (error) */
				self::log( sprintf( __( 'Not sent: these submissions to search engines were dropped after failing %1$d times: %2$s', 'mihdan-index-now' ), self::MAX_TRIES, implode( ', ', $dropped ) ) );
			}
		}
	}

	/**
	 * Make sure the background event is scheduled.
	 *
	 * @param int $at When; 0 for a minute from now.
	 */
	private static function schedule_next_run( int $at = 0 ): void {

		if ( ! wp_next_scheduled( self::BACKGROUND_EVENT ) ) {
			wp_schedule_single_event( $at > 0 ? $at : time() + MINUTE_IN_SECONDS, self::BACKGROUND_EVENT );
		}
	}

	/**
	 * Add a line to the plugin's log (Indexing > Log), when the logger is available.
	 */
	private static function log( string $message ): void {

		try {
			if ( class_exists( Logger\Logger::class ) ) {
				( new Logger\Logger() )->error( $message, [ 'status_code' => 0 ] );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Send some queue items as one IndexNow batch.
	 *
	 * @param array $items Queue items.
	 */
	private function send_items( array $items ): void {

		do_action( 'crawlwp/submission_batch_start' );

		try {
			foreach ( $items as $key => $item ) {
				try {
					$this->send_item( $item );
				} catch ( \Throwable $e ) {
					// A listener failed on this one: the others still go out.
					/* translators: 1: queue entry, e.g. post:12, 2: error message */
					self::log( sprintf( __( 'Not sent: the submission %1$s failed with: %2$s', 'mihdan-index-now' ), (string) $key, $e->getMessage() ) );
				}
			}
		} finally {
			try {
				do_action( 'crawlwp/submission_batch_end' );
			} catch ( \Throwable $e ) {
				self::log( $e->getMessage() );
			}
		}
	}

	/**
	 * Send one queue item, unless its post or term was already submitted after it was queued
	 * (a second copy of it, further back in a long queue).
	 *
	 * @param mixed $item Queue item.
	 */
	private function send_item( $item ): void {

		if ( ! is_array( $item ) || ! isset( $item[0] ) || self::sent_since_queued( $item ) ) {
			return;
		}

		$this->background_submission( (string) $item[0], (array) ( $item[1] ?? [] ) );
	}

	/**
	 * Whether the item's post or term was submitted after the item was queued.
	 */
	private static function sent_since_queued( array $item ): bool {

		$queued = (int) ( $item[4] ?? 0 );

		if ( $queued <= 0 ) {
			return false;
		}

		$id = (int) ( $item[1][0] ?? 0 );

		switch ( (string) $item[0] ) {
			case 'crawlwp/post_added':
			case 'crawlwp/post_updated':
			case 'crawlwp/comment_updated':
				return (int) get_post_meta( $id, Indexing::LAST_UPDATE_META, true ) > $queued;

			case 'crawlwp/term_updated':
				return (int) get_term_meta( $id, Indexing::LAST_UPDATE_META, true ) > $queued;
		}

		return false;
	}

	private static function can_spawn_cron(): bool {

		return ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) && ! ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON );
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

		if ( $this->spawn_cron && self::can_spawn_cron() ) {
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
					// Listeners get the comment again, as before the queue; null when it no longer exists.
					$comment = empty( $args[1] ) ? null : get_comment( (int) $args[1] );

					do_action( $action, $post->ID, $comment instanceof WP_Comment ? $comment : null );
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
