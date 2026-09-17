<?php

declare(strict_types=1);

namespace Mihdan\IndexNow\SEOCore\EmailReports;

use Mihdan\IndexNow\Utils;
use Mihdan\IndexNow\Views\WPOSA;

/**
 * Registers the Email Reports upsell section and card under the Advanced tab
 * when CrawlWP SEO Premium is not active.
 */
class EmailReportsUpsell
{
	public const SECTION = 'email_reports';

	public function __construct()
	{
		add_action('crawlwp_setup_fields', [$this, 'settings_fields'], 16);
	}

	public static function is_pro_active(): bool
	{
		return (bool) apply_filters('crawlwp_is_pro_active', defined('CRAWLWP_DETACH_LIBSODIUM'));
	}

	public function settings_fields(WPOSA $wposa): void
	{
		if (self::is_pro_active()) return;

		if ($wposa->get_active_header_menu() !== Utils::get_plugin_prefix() . '_advanced_settings') {
			return;
		}

		$wposa->add_section([
			'header_menu_id' => 'advanced_settings',
			'id'             => self::SECTION,
			'title'          => __('Email Reports', 'mihdan-index-now'),
			'desc'           => esc_html__('If you want to receive email reports on your website indexing status and search engine performance, then this feature is for you.', 'mihdan-index-now'),
		]);

		$upgrade_url = 'https://crawlwp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=crawlwp-email-reports-upsell';

		$desc = sprintf(
			'<div class="cwp-upsell-notice no-left-border">' .
				'<h4 style="margin:0 0 8px;font-size:14px;display:flex;align-items:center;gap:8px;">' .
					'<span>%1$s</span>' .
					'<span style="background:var(--wp-admin-theme-color);color:#fff;font-size:10px;font-weight:700;padding:2px 6px;border-radius:3px;letter-spacing:0.5px;text-transform:uppercase;">%2$s</span>' .
				'</h4>' .
				'<p>%3$s</p>' .
				'<p><strong>%4$s</strong> %5$s</p>' .
				'<a href="%6$s" target="_blank" rel="noopener noreferrer" class="button button-primary">%7$s &rarr;</a>' .
			'</div><style>#submit_crawlwp_email_reports {display:none;}#crawlwp_email_reports table tr th {display: none;}</style>',
			esc_html__('Scheduled SEO & Indexing Email Reports', 'mihdan-index-now'),
			esc_html__('PRO', 'mihdan-index-now'),
			esc_html__('Stay informed on your search engine performance and indexing health without needing to log in. Get scheduled email summaries delivered straight to your inbox with critical metrics on indexed URLs, search traffic trends, and indexing status updates.', 'mihdan-index-now'),
			esc_html__('Premium Features:', 'mihdan-index-now'),
			esc_html__('Flexible delivery frequencies (Daily, Weekly, Monthly), multiple recipient email addresses, brand color customization, instant test email dispatch, and comprehensive search engine performance insights.', 'mihdan-index-now'),
			esc_url($upgrade_url),
			esc_html__('Upgrade to CrawlWP SEO Premium', 'mihdan-index-now')
		);

		$wposa->add_field(self::SECTION, [
			'id'   => 'email_reports_upsell',
			'type' => 'html',
			'name' => __('Scheduled Email Reports', 'mihdan-index-now'),
			'desc' => $desc,
		]);
	}
}
