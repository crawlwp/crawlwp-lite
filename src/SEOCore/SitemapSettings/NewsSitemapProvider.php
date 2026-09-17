<?php

namespace Mihdan\IndexNow\SEOCore\SitemapSettings;

use Mihdan\IndexNow\SEOCore\MetaBox\MetaFields;
use WP_Site;

/**
 * Google News Sitemap provider.
 *
 * Extends WP_Sitemaps_Provider so WordPress fully manages routing and the
 * sitemap index entry. The provider is registered under the name 'crawlwp-news'
 * and its sitemap is accessible at:
 *
 *   {site}/wp-sitemap-crawlwp-news-1.xml
 *
 * Because WordPress's default sitemap renderer does not support custom XML
 * namespaces, this class intercepts template_redirect for its own sitemap
 * request and outputs Google-News-compliant XML directly.
 *
 * Only posts published within the last 2 days are included, per the Google
 * News Sitemap specification (max 1 000 entries).
 *
 * Configuration is read from SitemapSettings:
 *   news_enabled          – whether the news sitemap is active (default off).
 *   news_publication_name – the <news:name> value (defaults to site title).
 *   news_post_types       – multicheck array of post types to include.
 *
 * The rendered XML is cached in the crawlwp_news_sitemap_xml transient for an
 * hour (and served with matching Cache-Control/Expires headers); the cache is
 * invalidated whenever a post is saved or deleted, or the sitemap settings
 * change.
 *
 * Developer hooks:
 *   crawlwp_news_sitemap_query_args   – filter WP_Query args before fetching entries.
 *   crawlwp_news_sitemap_entry        – filter / exclude a single entry (return false to skip).
 *   crawlwp_news_sitemap_language     – filter the <news:language> value.
 */
class NewsSitemapProvider extends \WP_Sitemaps_Provider
{
	/**
	 * Provider name used with wp_register_sitemap_provider().
	 *
	 * Must NOT contain hyphens: WordPress's sitemap rewrite rule
	 * ^wp-sitemap-([a-z]+?)-([a-z\d_-]+?)-(\d+?)\.xml$ would otherwise
	 * split 'crawlwp-news' across the provider and subtype capture groups,
	 * causing a 'crawlwp' provider lookup that fails and falls through to a
	 * normal page render.
	 */
	const PROVIDER_NAME = 'crawlwpnews';

	/** Transient holding the rendered sitemap XML. */
	const XML_TRANSIENT = 'crawlwp_news_sitemap_xml';

	/** How long the rendered XML is cached and advertised as cacheable. */
	const CACHE_TTL = HOUR_IN_SECONDS;

	public function __construct()
	{
		$this->name        = self::PROVIDER_NAME;
		$this->object_type = 'crawlwp_news_item';

		/*
		 * Register the provider with WordPress's sitemap system so it gets
		 * a proper entry in /wp-sitemap.xml index and a canonical URL.
		 * This must run on 'init' after WordPress finishes its own sitemap setup.
		 */
		add_action('init', [$this, 'register_provider'], 20);

		/*
		 * Intercept the sitemap URL for our provider and render Google-News
		 * XML with the news: namespace, which WordPress's renderer cannot output.
		 */
		add_action('template_redirect', [$this, 'maybe_render'], 1);

		/*
		 * Keep the cached XML in sync with the content and the settings.
		 */
		add_action('save_post', [$this, 'flush_cache']);
		add_action('deleted_post', [$this, 'flush_cache']);
		add_action('update_option_crawlwp_' . SitemapSettings::SECTION, [$this, 'flush_cache']);
		add_action('add_option_crawlwp_' . SitemapSettings::SECTION, [$this, 'flush_cache']);
	}

	/**
	 * Drop the cached XML.
	 *
	 * Hooks: save_post, deleted_post, update_option_crawlwp_sitemap_settings.
	 */
	public function flush_cache(): void
	{
		delete_transient(self::XML_TRANSIENT);
	}

	// -------------------------------------------------------------------------
	// WordPress sitemap provider API (abstract methods)
	// -------------------------------------------------------------------------

	/**
	 * Returns the list of URL entries for a given page.
	 *
	 * WordPress calls this when it renders a normal sitemap for the provider.
	 * In practice we intercept before the default renderer runs (see
	 * maybe_render()), so this only executes if something bypasses our hook.
	 *
	 * @param int $page_num Page number (1-based).
	 * @param string $object_subtype Unused; news has no subtypes.
	 * @return array<int,array{loc:string}>
	 */
	public function get_url_list($page_num, $object_subtype = ''): array
	{
		$entries = $this->get_entries();
		$urls    = [];

		foreach ($entries as $entry) {
			$urls[] = ['loc' => $entry['loc']];
		}

		return $urls;
	}

	/**
	 * Returns the maximum number of pages.
	 *
	 * Google News sitemaps are always a single page (≤ 1 000 items).
	 *
	 * @param string $object_subtype Unused.
	 * @return int
	 */
	public function get_max_num_pages($object_subtype = ''): int
	{
		if (SitemapSettings::get('news_enabled', 'off') !== 'on') {
			return 0;
		}

		return 1;
	}

	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	/**
	 * Register this provider with WordPress's sitemap system.
	 * Runs on 'init' at priority 20 (after core sitemap setup).
	 */
	public function register_provider(): void
	{
		if (function_exists('wp_register_sitemap_provider')) {
			wp_register_sitemap_provider(self::PROVIDER_NAME, $this);
		}
	}

	// -------------------------------------------------------------------------
	// Custom Google-News XML output
	// -------------------------------------------------------------------------

	/**
	 * Intercept the sitemap request for our provider and render Google-News XML.
	 *
	 * WordPress sets $wp_query->get('sitemap') to the provider name when
	 * serving a provider sitemap, so we can detect our own URL cleanly.
	 */
	public function maybe_render(): void
	{
		/* Only act on sitemap requests for our provider. */
		if (get_query_var('sitemap') !== self::PROVIDER_NAME) {
			return;
		}

		/* Respect the news_enabled toggle; return 404 when disabled. */
		if (SitemapSettings::get('news_enabled', 'off') !== 'on') {
			wp_die(
				esc_html__('The News Sitemap is disabled. Enable it under Advanced → Sitemap.', 'mihdan-index-now'),
				esc_html__('News Sitemap disabled', 'mihdan-index-now'),
				['response' => 404]
			);
		}

		$this->render_xml();
		exit;
	}

	/**
	 * The <news:language> value.
	 *
	 * Derived from the site locale, but filterable so multilingual integrations
	 * can report the language their own settings define.
	 *
	 * @return string
	 */
	private function get_language(): string
	{
		$locale = strtolower(str_replace('_', '-', get_locale()));

		$language = in_array($locale, ['zh-cn', 'zh-tw'], true) ? $locale : explode('-', $locale)[0];

		/**
		 * Filter the language reported in the News Sitemap.
		 *
		 * Google News expects a 2-letter ISO 639-1 code (plus zh-cn / zh-tw).
		 *
		 * @param string $language Language code derived from the site locale.
		 */
		$language = (string)apply_filters('crawlwp_news_sitemap_language', $language);

		$language = strtolower(str_replace('_', '-', trim($language)));
		$language = (string)preg_replace('/[^a-z0-9-]/', '', $language);

		return $language !== '' ? $language : 'en';
	}

	/**
	 * Output a Google-News-compliant sitemap with the news: namespace.
	 *
	 * The document is generated once per hour and kept in a transient; the
	 * response also carries Cache-Control/Expires headers so proxies and CDNs
	 * do not re-request it on every crawl.
	 */
	private function render_xml(): void
	{
		$xml = get_transient(self::XML_TRANSIENT);

		if (!is_string($xml) || $xml === '') {
			$xml = $this->build_xml();

			set_transient(self::XML_TRANSIENT, $xml, self::CACHE_TTL);
		}

		header('Content-Type: application/xml; charset=UTF-8');
		header('Cache-Control: public, max-age=' . self::CACHE_TTL);
		header('Expires: ' . gmdate('D, d M Y H:i:s', time() + self::CACHE_TTL) . ' GMT');

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value is escaped in build_xml().
		echo $xml;
	}

	/**
	 * Build the complete, fully escaped sitemap XML document.
	 */
	private function build_xml(): string
	{
		$entries          = $this->get_entries();
		$publication_name = SitemapSettings::get('news_publication_name', '') ?: get_bloginfo('name');
		$language         = $this->get_language();

		$stylesheet_url = SitemapStylesheet::get_stylesheet_url('news');

		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		if ($stylesheet_url !== '') {
			$xml .= '<?xml-stylesheet type="text/xsl" href="' . esc_url($stylesheet_url) . '" ?>' . "\n";
		}
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
		$xml .= '        xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";

		foreach ($entries as $entry) {
			$xml .= "\t<url>\n";
			$xml .= "\t\t<loc>" . esc_url($entry['loc']) . "</loc>\n";
			$xml .= "\t\t<news:news>\n";
			$xml .= "\t\t\t<news:publication>\n";
			$xml .= "\t\t\t\t<news:name>" . esc_xml($publication_name) . "</news:name>\n";
			$xml .= "\t\t\t\t<news:language>" . esc_xml($entry['language'] ?? $language) . "</news:language>\n";
			$xml .= "\t\t\t</news:publication>\n";
			$xml .= "\t\t\t<news:publication_date>" . esc_xml($entry['publication_date']) . "</news:publication_date>\n";
			$xml .= "\t\t\t<news:title>" . esc_xml($entry['title']) . "</news:title>\n";
			$xml .= "\t\t</news:news>\n";
			$xml .= "\t</url>\n";
		}

		$xml .= '</urlset>';

		return $xml;
	}

	// -------------------------------------------------------------------------
	// Data
	// -------------------------------------------------------------------------

	/**
	 * Retrieve recent posts for the news sitemap.
	 *
	 * @return array<int,array{loc:string,title:string,publication_date:string}>
	 */
	private function get_entries(): array
	{
		$post_types = $this->get_enabled_post_types();

		if (empty($post_types)) {
			return [];
		}

		/* Google News only accepts content published within the last 2 days (strict UTC cutoff). */
		$args = [
			'post_type' => $post_types,
			'post_status' => 'publish',
			'has_password' => false,
			'posts_per_page' => 1000,
			'date_query' => [
				[
					'column' => 'post_date_gmt',
					'after' => gmdate('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS),
					'inclusive' => true,
				],
			],
			/* Skip posts an editor marked noindex in the SEO metabox. */
			'meta_query' => [
				'relation' => 'OR',
				[
					'key' => MetaFields::ROBOTS_INDEX,
					'compare' => 'NOT EXISTS',
				],
				[
					'key' => MetaFields::ROBOTS_INDEX,
					'value' => 'noindex',
					'compare' => '!=',
				],
			],
			'orderby' => 'date',
			'order' => 'DESC',
			'no_found_rows' => true,
		];

		/**
		 * Filter the WP_Query arguments used to fetch News Sitemap entries.
		 *
		 * @param array $args WP_Query arguments.
		 */
		$args = apply_filters('crawlwp_news_sitemap_query_args', $args);

		$query   = new \WP_Query($args);
		$entries = [];

		foreach ($query->posts as $post) {

			if (!($post instanceof \WP_Post)) {
				continue;
			}

			$entry = [
				'loc' => get_permalink($post),
				'title' => $post->post_title,
				'publication_date' => mysql2date('c', $post->post_date_gmt, false),
			];

			/**
			 * Filter a single News Sitemap entry.
			 *
			 * Return false to exclude the entry.
			 *
			 * @param array $entry News sitemap entry data.
			 * @param \WP_Post $post Post object.
			 * @param array $entries All News sitemap entries.
			 */
			$entry = apply_filters('crawlwp_news_sitemap_entry', $entry, $post, $entries);

			if ($entry) {
				if (is_array($entry) && isset($entry[0]) && is_array($entry[0])) {
					foreach ($entry as $item) {
						$entries[] = $item;
					}
				} else {
					$entries[] = $entry;
				}
			}
		}

		wp_reset_postdata();

		return $entries;
	}

	/**
	 * Return the list of enabled post types, validated against registered types.
	 *
	 * @return string[]
	 */
	private function get_enabled_post_types(): array
	{
		$saved = SitemapSettings::get('news_post_types', []);

		if (!is_array($saved) || empty($saved)) {
			/* Default to 'post' when nothing has been saved yet. */
			return ['post'];
		}

		$registered = get_post_types(['public' => true]);
		$enabled    = [];

		foreach (array_keys($saved) as $name) {
			if ($saved[$name] && isset($registered[$name])) {
				$enabled[] = $name;
			}
		}

		return $enabled;
	}
}
