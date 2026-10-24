<?php
/**
 * "Learn more" links from settings sections to the CrawlWP docs.
 *
 * @package mihdan-index-now
 */

namespace Mihdan\IndexNow\Views;

/**
 * Maps settings section ids to docs articles, in one place so links are easy to keep current.
 */
class DocLinks
{
	const BASE = 'https://crawlwp.com/article/';

	/**
	 * Section id (as rendered, with the crawlwp_ prefix) => article slug.
	 */
	const SECTIONS = [
		// Settings.
		'crawlwp_site_info'            => 'setting-up-organization-vs-person',
		'crawlwp_site_verification'    => 'verify-website-google-search-console',
		'crawlwp_social'               => 'setting-up-open-graph-for-facebook',
		'crawlwp_sitemap_settings'     => 'understanding-crawlwp-sitemaps',
		'crawlwp_email_reports'        => 'seo-performance-email-reports',
		'crawlwp_code'                 => 'adding-header-footer-scripts-wordpress',
		'crawlwp_breadcrumbs_settings' => 'setting-up-breadcrumbs',
		'crawlwp_rss'                  => 'rss-footer-and-content-attribution',
		'crawlwp_redirects'            => 'creating-redirect-rule-wordpress',
		'crawlwp_monitor_404'          => 'using-the-404-monitor',
		'crawlwp_robots'               => 'editing-robots-txt',
		'crawlwp_llms_txt'             => 'how-to-create-llms-txt-for-wordpress',
		'crawlwp_image_seo'            => 'image-seo-and-automatic-alt-text',
		'crawlwp_internal_links'       => 'setting-up-automatic-internal-linking',
		'crawlwp_advanced'             => 'uninstalling-crawlwp-and-what-gets-removed',
		'crawlwp_bulk_editor'          => 'using-the-bulk-seo-editor',
		'crawlwp_importer'             => 'what-gets-imported-and-what-doesnt',
		// Title & Meta (post type and taxonomy sections are matched by prefix below).
		'crawlwp_tm_home'              => 'setting-up-your-homepage-title-and-meta-description',
		'crawlwp_tm_author'            => 'title-and-meta-description-template-variables',
		'crawlwp_tm_date'              => 'title-and-meta-description-template-variables',
		'crawlwp_tm_search'            => 'title-and-meta-description-template-variables',
		'crawlwp_tm_not_found'         => 'title-and-meta-description-template-variables',
		// Indexing (the Bing, Google and Yandex ids are shared with API Settings).
		'crawlwp_general'              => 'setting-up-search-engine-indexing-for-wordpress',
		'crawlwp_index_now'            => 'what-is-indexnow',
		'crawlwp_bing_webmaster'       => 'integrating-wordpress-with-bing',
		'crawlwp_google_webmaster'     => 'integrating-wordpress-with-google-search',
		'crawlwp_yandex_webmaster'     => 'integrating-wordpress-with-yandex',
		'crawlwp_logs'                 => 'reading-the-indexing-submission-log',
	];

	const PREFIXES = [
		'crawlwp_tm_pt_'  => 'search-appearance-settings-for-post-types',
		'crawlwp_tm_tax_' => 'search-appearance-settings-for-taxonomies',
	];

	/**
	 * Docs URL for a settings section, or an empty string when there is none.
	 */
	public static function url(string $section_id): string
	{
		$slug = self::SECTIONS[$section_id] ?? '';

		if ($slug === '') {
			foreach (self::PREFIXES as $prefix => $prefix_slug) {
				if (strpos($section_id, $prefix) === 0) {
					$slug = $prefix_slug;
					break;
				}
			}
		}

		$url = $slug === '' ? '' : add_query_arg(
			[
				'utm_source'   => 'wp_dashboard',
				'utm_medium'   => 'settings_learn_more',
				'utm_campaign' => $section_id,
			],
			self::BASE . $slug . '/'
		);

		/**
		 * Filter the docs link shown next to a settings section heading. Return an empty string to hide it.
		 *
		 * @param string $url        Docs URL.
		 * @param string $section_id Settings section id.
		 */
		return (string)apply_filters('crawlwp_settings_doc_link', $url, $section_id);
	}

	/**
	 * Section heading with its "Learn more" link.
	 */
	public static function title(string $title, string $section_id): string
	{
		$url = self::url($section_id);

		if ($title === '' || $url === '') {
			return $title;
		}

		return sprintf(
			'%1$s <a class="wposa-doc-link" href="%2$s" target="_blank" rel="noopener">%3$s<span class="screen-reader-text"> %4$s</span></a>',
			$title,
			esc_url($url),
			esc_html__('Learn more', 'mihdan-index-now'),
			/* translators: accessibility text */
			esc_html__('(opens in a new tab)', 'mihdan-index-now')
		);
	}
}
