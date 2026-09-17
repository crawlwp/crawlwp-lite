<?php

namespace Mihdan\IndexNow\SEOCore\Redirects;

/**
 * Automatic permalink-change tracker.
 *
 * Whenever a published post's permalink changes, this class automatically
 * creates a 301 redirect from the old URL to the new one — preventing
 * broken links for SEO and usability.
 *
 * Also flushes the redirect cache when the site's global permalink
 * structure changes (since all stored paths may no longer be valid).
 */
class PermalinkTracker
{
	/** @var RedirectsManager */
	private RedirectsManager $manager;

	public function __construct(RedirectsManager $manager)
	{
		$this->manager = $manager;

		add_action('post_updated', [$this, 'track_permalink_change'], 10, 3);
		add_action('update_option_permalink_structure', [$this, 'flush_cache']);
		add_action('update_option_category_base', [$this, 'flush_cache']);
		add_action('update_option_tag_base', [$this, 'flush_cache']);
	}

	/**
	 * Detect permalink changes and auto-create a 301 redirect.
	 *
	 * @param int      $post_id     Post ID.
	 * @param \WP_Post $post_after  Post object after the update.
	 * @param \WP_Post $post_before Post object before the update.
	 */
	public function track_permalink_change(int $post_id, \WP_Post $post_after, \WP_Post $post_before): void
	{
		// Only track publicly-visible posts that were already published.
		if ($post_before->post_status !== 'publish' || $post_after->post_status !== 'publish') {
			return;
		}

		// Skip attachments and other non-public types.
		$post_type_obj = get_post_type_object($post_after->post_type);
		if (!$post_type_obj || !$post_type_obj->public) {
			return;
		}

		// Retrieve the old and new permalinks.
		$old_url = get_permalink($post_before);
		$new_url = get_permalink($post_after);

		if (!$old_url || !$new_url) {
			return;
		}

		// No change — nothing to do.
		if ($old_url === $new_url) {
			return;
		}

		// Compare against the path-only form used for storage (strip scheme + host).
		$old_path = (string) wp_parse_url($old_url, PHP_URL_PATH);
		$new_path = (string) wp_parse_url($new_url, PHP_URL_PATH);

		// Never create a self-referencing redirect (from == to).
		if ($old_path === '' || $new_path === '' || untrailingslashit($old_path) === untrailingslashit($new_path)) {
			return;
		}

		/**
		 * Filters whether to auto-create a redirect for this permalink change.
		 *
		 * @param bool     $should_create Whether to create the redirect.
		 * @param string   $old_url       Old permalink.
		 * @param string   $new_url       New permalink.
		 * @param int      $post_id       Post ID.
		 */
		$should_create = apply_filters('crawlwp_auto_redirect_permalink_change', true, $old_url, $new_url, $post_id);

		if (!$should_create) {
			return;
		}

		// If the new slug was itself a former old slug (A→B, then B→A), drop the
		// stale rule pointing away from the new URL to prevent a redirect loop.
		$reverse = $this->manager->get_by_from_url($new_path);
		if ($reverse) {
			$this->manager->delete((int) $reverse->id);
		}

		// A redirect from the old URL already exists — point it at the new
		// destination rather than leaving it aimed at an outdated URL.
		$existing = $this->manager->get_by_from_url($old_path);
		if ($existing) {
			$this->manager->update((int) $existing->id, ['to_url' => $new_url]);
			return;
		}

		$this->manager->insert([
			'from_url'             => $old_url,
			'to_url'               => $new_url,
			'redirect_type'        => 301,
			'match_type'           => 'exact',
			'note'                 => __('Auto-created on permalink change', 'mihdan-index-now'),
			'ignore_query_string'  => 1,
			'enabled'              => 1,
		]);
	}

	/**
	 * Flush the redirect cache when global permalink settings change.
	 * This prevents stale cached paths from being used.
	 */
	public function flush_cache(): void
	{
		$this->manager->flush_cache();
	}
}
