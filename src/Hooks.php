<?php
/**
 * Class Hooks.
 *
 * @package mihdan-index-now
 */

namespace Mihdan\IndexNow;

use Mihdan\IndexNow\Views\WPOSA;
use WP_Post;
use WP_Comment;

/**
 * Class Hooks.
 */
class Hooks {
	/**
	 * WPOSA Instance.
	 *
	 * @var WPOSA
	 */
	private WPOSA $wposa;

	/**
	 * Ping delay in seconds.
	 *
	 * @var int $ping_delay
	 */
	private int $ping_delay;

	/**
	 * Hooks constructor.
	 *
	 * @param WPOSA $wposa WPOSA instance.
	 */
	public function __construct( WPOSA $wposa ) {
		$this->wposa      = $wposa;
		$this->ping_delay = (int) $this->wposa->get_option( 'ping_delay', 'general', 60 );
	}

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

		// Delay.
		$last_update = (int) get_comment_meta(
			$id,
			Utils::get_plugin_prefix() . '_last_update',
			true
		);

		if ( ( current_time( 'timestamp' ) - $last_update ) < $this->ping_delay ) {
			return;
		}

		do_action( 'crawlwp/comment_updated', $comment->comment_post_ID, $comment );

		update_comment_meta(
			$id,
			Utils::get_plugin_prefix() . '_last_update',
			current_time( 'timestamp' )
		);
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

		if ( function_exists( 'is_post_publicly_viewable' ) && ! is_post_publicly_viewable( $post ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, (array) $this->wposa->get_option( 'post_types', 'general', [] ), true ) ) {
			return;
		}

		// Disable for Bulk Edit screen.
		if ( isset( $_REQUEST['bulk_edit'] ) && $this->wposa->get_option( 'disable_for_bulk_edit', 'general', 'on' ) === 'on' ) {
			return;
		}

		// Delay.
		$last_update = (int) get_post_meta(
			$post->ID,
			Utils::get_plugin_prefix() . '_last_update',
			true
		);

		if ( ( current_time( 'timestamp' ) - $last_update ) < $this->ping_delay ) {
			return;
		}

		if ( $old_status === $new_status ) {
			// Post updated.
			if ( $this->wposa->get_option( 'ping_on_post_updated', 'general', 'on' ) === 'on' ) {
				do_action( 'crawlwp/post_updated', $post->ID, $post );
			}
		} else {
			// Post added.
			if ( $this->wposa->get_option( 'ping_on_post', 'general', 'on' ) === 'on' ) {
				do_action( 'crawlwp/post_added', $post->ID, $post );
			}
		}

		update_post_meta(
			$post->ID,
			Utils::get_plugin_prefix() . '_last_update',
			current_time( 'timestamp' )
		);
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

		if ( ! in_array( $post->post_type, (array) $this->wposa->get_option( 'post_types', 'general', [] ), true ) ) {
			return;
		}

		// Disable for Bulk Edit screen.
		if ( isset( $_REQUEST['bulk_edit'] ) && $this->wposa->get_option( 'disable_for_bulk_edit', 'general', 'on' ) === 'on' ) {
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

		// Delay.
		$last_update = (int) get_comment_meta(
			$comment->comment_ID,
			Utils::get_plugin_prefix() . '_last_update',
			true
		);

		if ( ( current_time( 'timestamp' ) - $last_update ) < $this->ping_delay ) {
			return;
		}

		if ( $new_status !== 'approved' ) {
			return;
		}

		do_action( 'crawlwp/comment_updated', $comment->comment_post_ID, $comment );

		update_comment_meta(
			$comment->comment_ID,
			Utils::get_plugin_prefix() . '_last_update',
			current_time( 'timestamp' )
		);
	}

	/**
	 * Fires after a term has been saved, and the term cache has been cleared.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function term_updated( int $term_id, int $tt_id, string $taxonomy ): void {

		// Delay.
		$last_update = (int) get_term_meta(
			$term_id,
			Utils::get_plugin_prefix() . '_last_update',
			true
		);

		if ( ( current_time( 'timestamp' ) - $last_update ) < $this->ping_delay ) {
			return;
		}

		do_action( 'crawlwp/term_updated', $term_id, $taxonomy );

		update_term_meta(
			$term_id,
			Utils::get_plugin_prefix() . '_last_update',
			current_time( 'timestamp' )
		);
	}
}
