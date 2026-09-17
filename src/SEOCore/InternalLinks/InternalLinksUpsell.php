<?php

namespace Mihdan\IndexNow\SEOCore\InternalLinks;

use Mihdan\IndexNow\Utils;
use Mihdan\IndexNow\Views\WPOSA;

/**
 * Registers the Internal Linking upsell section and card under the Advanced tab
 * when CrawlWP SEO Premium is not active.
 */
class InternalLinksUpsell
{
	public const SECTION = 'internal_links';

	public function __construct()
	{
		add_action('crawlwp_setup_fields', [$this, 'settings_fields'], 20);
	}

	public function settings_fields(WPOSA $wposa): void
	{
		if (defined('CRAWLWP_PRO_VERSION')) {
			return;
		}

		if ($wposa->get_active_header_menu() !== Utils::get_plugin_prefix() . '_advanced_settings') {
			return;
		}

		$wposa->add_section([
			'header_menu_id' => 'advanced_settings',
			'id'             => self::SECTION,
			'title'          => __('Internal Linking', 'mihdan-index-now'),
			'desc'           => __('Automatically turn keywords in post content into internal links.', 'mihdan-index-now'),
		]);

		$upgrade_url = 'https://crawlwp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=crawlwp-internal-links-upsell';

		$desc = sprintf(
			'<div class="cwp-upsell-notice no-left-border">' .
				'<h4 style="margin:0 0 8px;font-size:14px;display:flex;align-items:center;gap:8px;">' .
					'<span>%1$s</span>' .
					'<span style="background:var(--wp-admin-theme-color);color:#fff;font-size:10px;font-weight:700;padding:2px 6px;border-radius:3px;letter-spacing:0.5px;text-transform:uppercase;">%2$s</span>' .
				'</h4>' .
				'<p>%3$s</p>' .
				'<p><strong>%4$s</strong> %5$s</p>' .
				'<a href="%6$s" target="_blank" rel="noopener noreferrer" class="button button-primary">%7$s &rarr;</a>' .
			'</div><style>#submit_crawlwp_internal_links {display:none;}#crawlwp_internal_links table tr th {display: none;}</style>',
			esc_html__('Automated Keyword Internal Linking', 'mihdan-index-now'),
			esc_html__('PRO', 'mihdan-index-now'),
			esc_html__('Supercharge your on-page SEO by automatically converting target keywords in your post and page content into internal links. Build a strong site structure, distribute link equity, and improve rankings effortlessly.', 'mihdan-index-now'),
			esc_html__('Premium Features:', 'mihdan-index-now'),
			esc_html__('Define custom keyword-to-URL matching rules, set maximum links per keyword and post, prevent duplicate links, and automatically avoid linking inside headings, code blocks, or existing anchors.', 'mihdan-index-now'),
			esc_url($upgrade_url),
			esc_html__('Upgrade to CrawlWP SEO Premium', 'mihdan-index-now')
		);

		$wposa->add_field(self::SECTION, [
			'id'   => 'internal_links_upsell',
			'type' => 'html',
			'name' => __('Automatic Keyword Linking', 'mihdan-index-now'),
			'desc' => $desc,
		]);
	}
}
