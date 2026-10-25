<?php

namespace Mihdan\IndexNow\SEOCore;


/**
 * Whether a post can be in the XML sitemap, for the SEO box warning.
 *
 * Covers what can't change while the post is being edited. The SEO box adds
 * the fields that can (noindex, canonical URL and redirect) as they change.
 */
class SitemapStatus
{
	/**
	 * @return array{reason: string, link: string} reason: '' (can be listed), draft, off,
	 *                                               type_noindex, type, protected or excluded.
	 */
	public static function for_post(\WP_Post $post): array
	{
		$status = static function (string $reason, string $link = ''): array {
			return ['reason' => $reason, 'link' => $link];
		};

		// Drafts and scheduled posts join the sitemap when they are published.
		if (! in_array($post->post_status, ['publish', 'private'], true)) {
			return $status('draft');
		}

		$provider = self::posts_provider();

		if ($provider === null) {
			// "Discourage search engines" (Settings > Reading) turns WordPress sitemaps off.
			return $status('off', (int) get_option('blog_public') === 0 ? admin_url('options-reading.php') : '');
		}

		if (Sitemap::is_post_type_hidden($post->post_type)) {
			return $status('type_noindex', admin_url('admin.php?page=crawlwp&wposa-menu=crawlwp_title_meta'));
		}

		if (! array_key_exists($post->post_type, (array) $provider->get_object_subtypes())) {
			return $status('type');
		}

		if ($post->post_status === 'private' || $post->post_password !== '') {
			return $status('protected');
		}

		$excluded = array_map('intval', (array) apply_filters('crawlwp_sitemap_excluded_post_ids', []));

		if (in_array((int) $post->ID, $excluded, true)) {
			return $status('excluded');
		}

		return $status('');
	}

	/**
	 * Data for the SEO box: the fixed reason, the permalink to compare the
	 * canonical URL with, and the wording.
	 */
	public static function for_metabox(\WP_Post $post): array
	{
		$type   = get_post_type_object($post->post_type);
		$plural = $type ? $type->labels->name : $post->post_type;
		$status = self::for_post($post);

		return $status + [
			'permalink' => (string) get_permalink($post),
			'text'      => [
				'out'          => __('Not in your sitemap.', 'mihdan-index-now'),
				'mixed'        => __('Mixed signals.', 'mihdan-index-now'),
				'noindex'      => __('This post is set to noindex, so it is left out of the sitemap and Google is asked not to show it.', 'mihdan-index-now'),
				/* translators: %s: post type name, plural (e.g. "Pages") */
				'type_noindex' => sprintf(__('All %s are set to noindex in Title & Meta, so none of them are in the sitemap.', 'mihdan-index-now'), $plural),
				/* translators: %s: post type name, plural */
				'type'         => sprintf(__('%s aren\'t public, so WordPress doesn\'t list them in the sitemap.', 'mihdan-index-now'), $plural),
				'protected'    => __('Private and password-protected posts are left out of the sitemap.', 'mihdan-index-now'),
				'excluded'     => __('A setting or another plugin keeps this page out of the sitemap. For example, WooCommerce leaves out the cart, checkout and account pages.', 'mihdan-index-now'),
				'off'          => (int) get_option('blog_public') === 0
					? __('Your site has no XML sitemap because Settings > Reading is set to discourage search engines.', 'mihdan-index-now')
					: __('Your site has no XML sitemap right now. Another plugin or a code snippet has turned WordPress sitemaps off.', 'mihdan-index-now'),
				'canonical'    => __('This post is in your sitemap, but its canonical URL points to another page, so Google will probably index that page instead.', 'mihdan-index-now'),
				'redirect'     => __('This post is in your sitemap, but it redirects visitors elsewhere. Remove the redirect, or set the post to noindex.', 'mihdan-index-now'),
				'gone'         => __('This post is in your sitemap, but it is set to tell visitors it is gone (410/451). Set it to noindex, or remove that setting.', 'mihdan-index-now'),
				'toAdvanced'   => __('Change in Advanced →', 'mihdan-index-now'),
				'toCanonical'  => __('Check the canonical URL →', 'mihdan-index-now'),
				'toRedirect'   => __('Check the redirect →', 'mihdan-index-now'),
				'toTitleMeta'  => __('Open Title & Meta →', 'mihdan-index-now'),
				'toReading'    => __('Open Reading settings →', 'mihdan-index-now'),
			],
		];
	}

	/**
	 * WordPress's posts sitemap provider, or null when sitemaps are off.
	 *
	 * @return \WP_Sitemaps_Provider|null
	 */
	private static function posts_provider()
	{
		if (! function_exists('wp_sitemaps_get_server')) {
			return null;
		}

		$server = wp_sitemaps_get_server();

		if (! $server->sitemaps_enabled()) {
			return null;
		}

		$provider = $server->registry->get_provider('posts');

		return $provider instanceof \WP_Sitemaps_Provider ? $provider : null;
	}
}
