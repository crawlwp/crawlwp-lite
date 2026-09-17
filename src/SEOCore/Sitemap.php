<?php

namespace Mihdan\IndexNow\SEOCore;

use Mihdan\IndexNow\SEOCore\MetaBox\MetaFields;
use Mihdan\IndexNow\SEOCore\TitleMeta\Entities;
use Mihdan\IndexNow\SEOCore\TitleMeta\FrontendOutput;

/**
 * Keeps the WordPress core sitemap in sync with the "Hide from search results" toggles.
 */
class Sitemap
{
	/** Default value stored for the robots-index meta key. */
	const ROBOTS_INDEX_DEFAULT = 'index';

	/** Option flag set once every post carries the robots-index meta key. */
	const ROBOTS_BACKFILL_OPTION = 'crawlwp_robots_index_backfilled';

	/** Cron hook that backfills the robots-index meta key in batches. */
	const ROBOTS_BACKFILL_HOOK = 'crawlwp_backfill_robots_index_meta';

	/** Number of posts processed per backfill batch. */
	const ROBOTS_BACKFILL_BATCH = 200;

	public function __construct()
	{
		add_filter('wp_sitemaps_post_types', [$this, 'filter_post_types']);
		add_filter('wp_sitemaps_taxonomies', [$this, 'filter_taxonomies']);
		add_filter('wp_sitemaps_add_provider', [$this, 'filter_providers'], 10, 2);
		add_filter('wp_sitemaps_posts_query_args', [$this, 'filter_posts_query_args']);

		/* Brand the sitemap stylesheet description (works for any site language). */
		add_filter('wp_sitemaps_stylesheet_content', [$this, 'brand_stylesheet']);
		add_filter('wp_sitemaps_stylesheet_index_content', [$this, 'brand_stylesheet']);

		/* Inject additional CSS into the sitemap stylesheet. */
		add_filter('wp_sitemaps_stylesheet_css', [$this, 'enhance_stylesheet_css']);

		/*
		 * Always store a value for the robots-index meta key so the sitemap
		 * queries can use an indexed comparison instead of a NOT EXISTS scan.
		 */
		add_action('save_post', [$this, 'ensure_robots_index_meta'], 99, 2);
		add_action('init', [$this, 'maybe_schedule_robots_backfill'], 30);
		add_action(self::ROBOTS_BACKFILL_HOOK, [$this, 'run_robots_backfill_batch']);
	}

	// -------------------------------------------------------------------------
	// Noindex filters (unchanged from original)
	// -------------------------------------------------------------------------

	/**
	 * Drop post types that are hidden from search results.
	 *
	 * @param array $post_types Post type objects keyed by name.
	 *
	 * @return array
	 */
	public function filter_post_types($post_types)
	{
		if (! is_array($post_types)) {
			return $post_types;
		}

		foreach (array_keys($post_types) as $name) {
			if (FrontendOutput::is_noindexed(Entities::post_type_key((string) $name))) {
				unset($post_types[$name]);
			}
		}

		return $post_types;
	}

	/**
	 * Drop taxonomies that are hidden from search results.
	 *
	 * @param array $taxonomies Taxonomy objects keyed by name.
	 *
	 * @return array
	 */
	public function filter_taxonomies($taxonomies)
	{
		if (! is_array($taxonomies)) {
			return $taxonomies;
		}

		foreach (array_keys($taxonomies) as $name) {
			if (FrontendOutput::is_noindexed(Entities::taxonomy_key((string) $name))) {
				unset($taxonomies[$name]);
			}
		}

		return $taxonomies;
	}

	/**
	 * Drop the users provider when author archives are hidden.
	 *
	 * @param mixed  $provider The sitemap provider.
	 * @param string $name     Provider name.
	 *
	 * @return mixed
	 */
	public function filter_providers($provider, $name)
	{
		if ($name === 'users' && FrontendOutput::is_noindexed('author')) {
			return false;
		}

		return $provider;
	}

	/**
	 * Drop password-protected posts and posts marked noindex in the SEO
	 * metabox from the core posts sitemaps.
	 *
	 * @param array $args WP_Query arguments.
	 *
	 * @return array
	 */
	public function filter_posts_query_args($args)
	{
		if (! is_array($args)) {
			return $args;
		}

		$args['has_password'] = false;

		$noindex_clause = $this->get_noindex_meta_clause();

		if (! empty($args['meta_query']) && is_array($args['meta_query'])) {
			/* Preserve any existing clauses and AND ours onto them. */
			$args['meta_query'] = [
				'relation' => 'AND',
				$args['meta_query'],
				$noindex_clause,
			];
		} else {
			$args['meta_query'] = $noindex_clause;
		}

		return $args;
	}

	// -------------------------------------------------------------------------
	// Robots-index meta key maintenance
	// -------------------------------------------------------------------------

	/**
	 * Build the meta_query clause that excludes noindexed posts.
	 *
	 * Once every post carries the robots-index meta key (see the backfill
	 * below), a single indexed "!= noindex" comparison is enough. Until then we
	 * keep the OR/NOT EXISTS fallback so legacy posts without the key are not
	 * dropped from the sitemap.
	 *
	 * @return array
	 */
	private function get_noindex_meta_clause(): array
	{
		$indexed_clause = [
			'key'     => MetaFields::ROBOTS_INDEX,
			'value'   => 'noindex',
			'compare' => '!=',
		];

		if (get_option(self::ROBOTS_BACKFILL_OPTION) === '1') {
			return $indexed_clause;
		}

		return [
			'relation' => 'OR',
			[
				'key'     => MetaFields::ROBOTS_INDEX,
				'compare' => 'NOT EXISTS',
			],
			$indexed_clause,
		];
	}

	/**
	 * Store the default robots-index value whenever a post is saved without one.
	 *
	 * Hook: save_post
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function ensure_robots_index_meta($post_id, $post = null): void
	{
		$post_id = (int) $post_id;

		if ($post_id <= 0 || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
			return;
		}

		if ($post instanceof \WP_Post && $post->post_type === 'nav_menu_item') {
			return;
		}

		$this->add_default_robots_index($post_id);
	}

	/**
	 * Schedule the one-off batched backfill for posts saved before this release.
	 *
	 * Hook: init
	 */
	public function maybe_schedule_robots_backfill(): void
	{
		if (get_option(self::ROBOTS_BACKFILL_OPTION) === '1') {
			return;
		}

		if (! wp_next_scheduled(self::ROBOTS_BACKFILL_HOOK)) {
			wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::ROBOTS_BACKFILL_HOOK);
		}
	}

	/**
	 * Add the default robots-index value to one batch of legacy posts.
	 *
	 * Re-schedules itself until no post is left without the key, then records
	 * the completion flag so get_noindex_meta_clause() can use the fast query.
	 *
	 * Hook: crawlwp_backfill_robots_index_meta
	 */
	public function run_robots_backfill_batch(): void
	{
		if (get_option(self::ROBOTS_BACKFILL_OPTION) === '1') {
			return;
		}

		$query = new \WP_Query([
			'post_type'              => 'any',
			'post_status'            => 'any',
			'posts_per_page'         => self::ROBOTS_BACKFILL_BATCH,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'suppress_filters'       => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
			'meta_query'             => [
				[
					'key'     => MetaFields::ROBOTS_INDEX,
					'compare' => 'NOT EXISTS',
				],
			],
		]);

		$post_ids = array_map('intval', (array) $query->posts);

		if ($post_ids === []) {
			update_option(self::ROBOTS_BACKFILL_OPTION, '1', false);

			return;
		}

		foreach ($post_ids as $post_id) {
			$this->add_default_robots_index($post_id);
		}

		wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::ROBOTS_BACKFILL_HOOK);
	}

	/**
	 * Write the default robots-index value when the key is absent.
	 *
	 * add_post_meta() with $unique = true never overwrites an editor's choice.
	 *
	 * @param int $post_id Post ID.
	 */
	private function add_default_robots_index(int $post_id): void
	{
		if (metadata_exists('post', $post_id, MetaFields::ROBOTS_INDEX)) {
			return;
		}

		add_post_meta($post_id, MetaFields::ROBOTS_INDEX, self::ROBOTS_INDEX_DEFAULT, true);
	}

	// -------------------------------------------------------------------------
	// Stylesheet branding
	// -------------------------------------------------------------------------

	/**
	 * Replace the generic WordPress description in the sitemap XSL stylesheet
	 * with branded text that credits CrawlWP.
	 *
	 * Works for any language: WordPress inserts the description via esc_xml(__())
	 * so we search for the same escaped translated string — it always matches
	 * whatever language the site runs in.
	 *
	 * Hooks: wp_sitemaps_stylesheet_content, wp_sitemaps_stylesheet_index_content
	 *
	 * @param string $content Full XSL stylesheet content.
	 * @return string
	 */
	public function brand_stylesheet(string $content): string
	{
		/*
		 * WordPress builds the XSL with:
		 *   esc_xml( __( 'This XML Sitemap is generated…' ) )
		 * so on a French/German/etc. site the XSL already contains the translated
		 * (and XML-escaped) version of that string. We call esc_xml(__()) here for
		 * the same reason — it resolves to whatever is actually in the XSL.
		 *
		 * The replacement must NOT be esc_xml()-encoded because it contains a
		 * literal <a> element that should render as a real hyperlink in the browser.
		 */
		$original = esc_xml( __( 'This XML Sitemap is generated by WordPress to make your content more visible for search engines.' ) );
		$branded  = 'This XML Sitemap is generated by WordPress, and improved by <a target="_blank" href="https://crawlwp.com">CrawlWP</a> to make your content more visible for search engines.';

		return str_replace($original, $branded, $content);
	}

	/**
	 * Inject minimal additional CSS into the sitemap stylesheet.
	 *
	 * Hook: wp_sitemaps_stylesheet_css
	 *
	 * @param string $css Existing sitemap CSS.
	 * @return string
	 */
	public function enhance_stylesheet_css(string $css): string
	{
		$extra = '

					/* CrawlWP enhancements */
					body {
						background: #f0f0f1;
						margin: 0;
						padding: 0;
					}

					#sitemap {
						background: #fff;
						border-radius: 4px;
						box-shadow: 0 1px 4px rgba(0,0,0,.08);
						margin: 32px auto;
						overflow: hidden;
					}

					#sitemap__header {
						background: #1d2327;
						color: #f0f0f1;
						padding: 24px 32px;
					}

					#sitemap__header h1 {
						color: #fff;
						font-size: 20px;
						margin: 0 0 8px;
					}

 				#sitemap__header p {
						color: #e8eaed;
						font-size: 13px;
						margin: 4px 0 0;
					}

					#sitemap__header a {
						color: #93c5fd;
						text-decoration: underline;
					}

					#sitemap__content {
						padding: 24px 32px;
					}

					#sitemap__content .text {
						color: #646970;
						font-size: 13px;
						margin-bottom: 16px;
					}

					#sitemap__table {
						border: 1px solid #dcdcde;
						border-radius: 3px;
						overflow: hidden;
					}

					#sitemap__table tr th {
						background: #f6f7f7;
						border-bottom: 1px solid #dcdcde;
						color: #1d2327;
						font-size: 12px;
						font-weight: 600;
						letter-spacing: .03em;
						text-transform: uppercase;
					}

					#sitemap__table tr:nth-child(odd) td {
						background-color: #fff;
					}

					#sitemap__table tr:nth-child(even) td {
						background-color: #f6f7f7;
					}

					#sitemap__table tr:hover td {
						background-color: #f0f6fc;
					}

					#sitemap__table td.loc a {
						color: var(--wp-admin-theme-color);
						text-decoration: none;
					}

					#sitemap__table td.loc a:hover {
						text-decoration: underline;
					}

					#sitemap__table td.lastmod,
					#sitemap__table td.changefreq,
					#sitemap__table td.priority {
						color: #646970;
						font-size: 13px;
					}';

		return $css . $extra;
	}
}
