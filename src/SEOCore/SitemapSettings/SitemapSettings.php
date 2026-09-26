<?php

namespace Mihdan\IndexNow\SEOCore\SitemapSettings;

use Mihdan\IndexNow\SEOCore\SettingsFieldsTrait;
use Mihdan\IndexNow\Utils;
use Mihdan\IndexNow\Views\WPOSA;

/**
 * Registers the "Sitemap" settings section under the "Advanced" tab.
 *
 * Provides:
 *   - A button to open the WordPress sitemap index in a new tab.
 *   - News Sitemap settings: publication name and post types to include.
 *
 * Option row: crawlwp_sitemap_settings
 * All fields are stored as crawlwp_sitemap_settings[field_id].
 */
class SitemapSettings
{
	use SettingsFieldsTrait;

	/** Option/section id (without the crawlwp_ prefix). */
	const SECTION = 'sitemap_settings';

	public function __construct()
	{
		add_action('crawlwp_setup_fields', [$this, 'settings_fields'], 15, 2);

		/*
		 * Record a timestamp whenever the sitemap settings option is saved.
		 * CustomUrlsSitemapProvider uses this to populate the lastmod field.
		 */
		add_action('update_option_crawlwp_' . self::SECTION, [$this, 'record_save_timestamp']);
		add_action('add_option_crawlwp_' . self::SECTION, [$this, 'record_save_timestamp']);
	}

	/**
	 * Save the current UTC timestamp whenever the sitemap settings are written.
	 */
	public function record_save_timestamp(): void
	{
		update_option('crawlwp_sitemap_settings_updated', time(), false);
	}

	// -------------------------------------------------------------------------
	// Settings registration
	// -------------------------------------------------------------------------

	public function settings_fields(WPOSA $wposa, $settingsInstance): void
	{
		if ($wposa->get_active_header_menu() !== Utils::get_plugin_prefix() . '_advanced_settings') {
			return;
		}

		$wposa->add_section([
			'header_menu_id' => 'advanced_settings',
			'id' => self::SECTION,
			'title' => __('Sitemap', 'mihdan-index-now'),
			'desc' => __('WordPress generates a sitemap index automatically. Use the button below to open it in a new tab and verify it is accessible to search engines.', 'mihdan-index-now')
		]);

		$this->add_sitemap_fields($wposa);

		if (! defined('CRAWLWP_DETACH_LIBSODIUM')) {
			$this->add_video_html_upsell($wposa);
			$this->add_custom_urls_upsell($wposa);
			$this->add_multilingual_upsell($wposa);
		}
	}

	// -------------------------------------------------------------------------
	// Fields
	// -------------------------------------------------------------------------

	/**
	 * General sitemap fields — open-sitemap button.
	 */
	private function add_sitemap_fields(WPOSA $wposa): void
	{
		$sitemap_url = get_sitemap_url('index');

		$button_html = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer" class="button button-secondary">%s <span class="dashicons dashicons-external" style="vertical-align:middle;font-size:16px;line-height:1.4;"></span></a>',
			esc_url($sitemap_url),
			esc_html__('Open Sitemap', 'mihdan-index-now')
		);

		$wposa->add_field(self::SECTION, [
			'id' => 'open_sitemap',
			'type' => 'html',
			'name' => __('Sitemap URL', 'mihdan-index-now'),
			'desc' => $button_html,
		]);
	}


	private function add_video_html_upsell(WPOSA $wposa): void
	{
		$upgrade_url = 'https://crawlwp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=crawlwp-sitemap-video-html-upsell';

		$desc = sprintf(
			'<div class="cwp-upsell-notice no-left-border">' .
				'<h4 style="margin:0 0 8px;font-size:14px;display:flex;align-items:center;gap:8px;">' .
					'<span>%1$s</span>' .
					'<span style="background:var(--wp-admin-theme-color);color:#fff;font-size:10px;font-weight:700;padding:2px 6px;border-radius:3px;letter-spacing:0.5px;text-transform:uppercase;">%2$s</span>' .
				'</h4>' .
				'<p>%3$s</p>' .
				'<p><strong>%4$s</strong> %5$s</p>' .
				'<a href="%6$s" target="_blank" rel="noopener noreferrer" class="button button-primary">%7$s &rarr;</a>' .
			'</div>',
			esc_html__('Video & HTML XML Sitemaps', 'mihdan-index-now'),
			esc_html__('PRO', 'mihdan-index-now'),
			esc_html__('Expand your search engine reach with specialized sitemaps. Video Sitemaps help search engines index your video content to appear in Google Video search results, while HTML sitemaps provide human visitors and search spiders with a comprehensive overview of your site.', 'mihdan-index-now'),
			esc_html__('Premium Features:', 'mihdan-index-now'),
			esc_html__('Automatic video detection from embedded YouTube, Vimeo, and MP4 videos, background batch video scanner, and a clean [crawlwp_html_sitemap] shortcode to display an organized page index anywhere on your website.', 'mihdan-index-now'),
			esc_url($upgrade_url),
			esc_html__('Upgrade to CrawlWP SEO Premium', 'mihdan-index-now')
		);

		$this->add_heading(
			$wposa,
			self::SECTION,
			'heading_video_html',
			__('Video & HTML sitemaps', 'mihdan-index-now')
		);

		$wposa->add_field(self::SECTION, [
			'id'   => 'video_html_upsell',
			'type' => 'html',
			'name' => __('Video & HTML Sitemaps', 'mihdan-index-now'),
			'desc' => $desc,
		]);
	}

	private function add_custom_urls_upsell(WPOSA $wposa): void
	{
		$upgrade_url = 'https://crawlwp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=crawlwp-sitemap-custom-urls-upsell';

		$desc = sprintf(
			'<div class="cwp-upsell-notice no-left-border">' .
				'<h4 style="margin:0 0 8px;font-size:14px;display:flex;align-items:center;gap:8px;">' .
					'<span>%1$s</span>' .
					'<span style="background:var(--wp-admin-theme-color);color:#fff;font-size:10px;font-weight:700;padding:2px 6px;border-radius:3px;letter-spacing:0.5px;text-transform:uppercase;">%2$s</span>' .
				'</h4>' .
				'<p>%3$s</p>' .
				'<p><strong>%4$s</strong> %5$s</p>' .
				'<a href="%6$s" target="_blank" rel="noopener noreferrer" class="button button-primary">%7$s &rarr;</a>' .
			'</div>',
			esc_html__('Additional Custom URLs Sitemap', 'mihdan-index-now'),
			esc_html__('PRO', 'mihdan-index-now'),
			esc_html__('Add external or non-WordPress URLs to your XML sitemap index. Ideal for static landing pages, sub-directory applications, headless frontends, or custom checkout funnels.', 'mihdan-index-now'),
			esc_html__('Premium Features:', 'mihdan-index-now'),
			esc_html__('Dedicated XML sitemap generation (/wp-sitemap-crawlwpcustom-1.xml), automatic sitemap index integration, custom lastmod timestamps, and URL validation.', 'mihdan-index-now'),
			esc_url($upgrade_url),
			esc_html__('Upgrade to CrawlWP SEO Premium', 'mihdan-index-now')
		);

		$this->add_heading(
			$wposa,
			self::SECTION,
			'heading_custom_urls',
			__('Additional Custom URLs', 'mihdan-index-now')
		);

		$wposa->add_field(self::SECTION, [
			'id'   => 'custom_urls_upsell',
			'type' => 'html',
			'name' => __('Custom URLs Sitemap', 'mihdan-index-now'),
			'desc' => $desc,
		]);
	}

	private function add_multilingual_upsell(WPOSA $wposa): void
	{
		$upgrade_url = 'https://crawlwp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=crawlwp-sitemap-multilingual-upsell';

		$desc = sprintf(
			'<div class="cwp-upsell-notice no-left-border">' .
				'<h4 style="margin:0 0 8px;font-size:14px;display:flex;align-items:center;gap:8px;">' .
					'<span>%1$s</span>' .
					'<span style="background:var(--wp-admin-theme-color);color:#fff;font-size:10px;font-weight:700;padding:2px 6px;border-radius:3px;letter-spacing:0.5px;text-transform:uppercase;">%2$s</span>' .
				'</h4>' .
				'<p>%3$s</p>' .
				'<p><strong>%4$s</strong> %5$s</p>' .
				'<a href="%6$s" target="_blank" rel="noopener noreferrer" class="button button-primary">%7$s &rarr;</a>' .
			'</div>',
			esc_html__('Multilingual Sitemaps (WPML, Polylang, TranslatePress)', 'mihdan-index-now'),
			esc_html__('PRO', 'mihdan-index-now'),
			esc_html__('Ensure all language versions of your content are properly discovered and indexed by global search engines. CrawlWP SEO Premium seamlessly bridges WPML, Polylang, and TranslatePress with your XML sitemaps.', 'mihdan-index-now'),
			esc_html__('Premium Features:', 'mihdan-index-now'),
			esc_html__('Automatic xhtml:link rel="alternate" hreflang cross-links in XML sitemaps, per-language sitemap index queries, and frontend <head> alternate link output.', 'mihdan-index-now'),
			esc_url($upgrade_url),
			esc_html__('Upgrade to CrawlWP SEO Premium', 'mihdan-index-now')
		);

		$this->add_heading(
			$wposa,
			self::SECTION,
			'heading_multilingual',
			__('Multilingual Sitemaps (WPML, Polylang, TranslatePress)', 'mihdan-index-now')
		);

		$wposa->add_field(self::SECTION, [
			'id'   => 'multilingual_upsell',
			'type' => 'html',
			'name' => __('Multilingual Integrations', 'mihdan-index-now'),
			'desc' => $desc,
		]);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------


	// -------------------------------------------------------------------------
	// Option reader
	// -------------------------------------------------------------------------

	/**
	 * Read a sitemap setting value.
	 *
	 * @param string $field Field id (e.g. 'news_enabled', 'news_publication_name').
	 * @param mixed $default Default value when option is absent or empty.
	 * @return mixed
	 */
	public static function get(string $field, $default = '')
	{
		$options = get_option('crawlwp_' . self::SECTION, []);

		if (!is_array($options)) {
			return $default;
		}

		return (isset($options[$field]) && $options[$field] !== '') ? $options[$field] : $default;
	}
}
