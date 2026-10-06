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

		do_action( 'crawlwp/comment_updated', $post->ID, $comment );
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

		do_action( $is_update ? 'crawlwp/post_updated' : 'crawlwp/post_added', $post->ID, $post );
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

		do_action( 'crawlwp/post_deleted', $post->ID, $permalink );
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

		do_action( 'crawlwp/term_updated', $term_id, $taxonomy );
	}
}
