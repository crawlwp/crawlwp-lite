<?php
/**
 * Settings class.
 *
 * @package mihdan-index-now
 */

namespace Mihdan\IndexNow\Views;

use Mihdan\IndexNow\Utils;

class UpsellAdminPages
{
	/**
	 * WP_OSA instance.
	 *
	 * @var WPOSA $wposa
	 */
	public $wposa;
	/**
	 * WP_OSA instance.
	 *
	 * @var WPOSA $wposa2
	 */
	public $wposa2;
	/**
	 * Site Audit upsell page (Premium feature).
	 *
	 * @var WPOSA $wposa3
	 */
	public $wposa3;

	public function __construct()
	{
		$this->wposa = new WPOSA(
			Utils::get_plugin_name(),
			Utils::get_plugin_version(),
			Utils::get_plugin_slug(),
			'crawlwp_upsell',
			CRAWLWP_PRO_SEO_INDEX_SLUG
		);

		$this->wposa->enable_blank_mode()->setup_hooks();

		$this->wposa2 = new WPOSA(
			Utils::get_plugin_name(),
			Utils::get_plugin_version(),
			Utils::get_plugin_slug(),
			'crawlwp_upsell',
			CRAWLWP_PRO_SEO_STAT_SLUG
		);

		$this->wposa2->enable_blank_mode()->setup_hooks();

		$this->wposa3 = new WPOSA(
			Utils::get_plugin_name(),
			Utils::get_plugin_version(),
			Utils::get_plugin_slug(),
			'crawlwp_upsell',
			CRAWLWP_PRO_SITE_AUDIT_SLUG
		);

		$this->wposa3->enable_blank_mode()->setup_hooks();

		add_action('init', [$this, 'setup_fields'], 110);
	}


	public function setup_fields()
	{
		if (wp_doing_ajax()) return;

		$upgrade_url = 'https://crawlwp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=crawlwp-seo-index-upsell-page';

		$seo_index_upsell = '<div class="crawlwp-full-feature-upsell-page-wrap">';
		$seo_index_upsell .= '<div class="crawlwp-upsell-top">';
		$seo_index_upsell .= sprintf('<h2>%s</h2>', esc_html__('Improve Your Website Indexing by Search Engines', 'mihdan-index-now'));
		$seo_index_upsell .= sprintf('<p>%s</p>', esc_html__('CrawlWP Premium regularly scan your WordPress site and submit pages and content for indexing that are not indexed by search engines, including Google, Bing, Yandex, Yep etc.', 'mihdan-index-now'));
		$seo_index_upsell .= '<div class="crawlwp-upsell-featured-image">';
		$seo_index_upsell .= '<img src="' . Utils::get_plugin_asset_url('images/crawlwp-content-indexing-stat-list.png') . '">';
		$seo_index_upsell .= '</div>';
		$seo_index_upsell .= sprintf('<a class="bbtn" target="_blank" href="%s">%s</a>', $upgrade_url, esc_html__('Upgrade to CrawlWP Premium', 'mihdan-index-now'));
		$seo_index_upsell .= '</div>';
		$seo_index_upsell .= '<div class="crawlwp-upsell-bottom">';
		$seo_index_upsell .= '<div class="crawlwp-upsell-featured-image">';
		$seo_index_upsell .= '<img src="https://crawlwp.com/wp-content/uploads/2024/10/crawlwp-indexing-stat.png">';
		$seo_index_upsell .= '</div>';
		$seo_index_upsell .= sprintf('<h3>%s</h3>', esc_html__('Search Engine Index History', 'mihdan-index-now'));
		$seo_index_upsell .= sprintf('<p>%s</p>', esc_html__('View records of every indexing done, last submitted date, and progress over time to understand better how search engines recognize your site.', 'mihdan-index-now'));
		$seo_index_upsell .= sprintf('<a class="bbtn" target="_blank" href="%s">%s</a>', $upgrade_url, esc_html__('Upgrade to CrawlWP Premium', 'mihdan-index-now'));
		$seo_index_upsell .= '</div>';
		$seo_index_upsell .= '</div>';

		$seo_stat_upsell = '<div class="crawlwp-full-feature-upsell-page-wrap">';
		$seo_stat_upsell .= '<div class="crawlwp-upsell-top">';
		$seo_stat_upsell .= sprintf('<h2>%s</h2>', esc_html__('Search Performance & Insights at Your Fingertips', 'mihdan-index-now'));
		$seo_stat_upsell .= sprintf('<p>%s</p>', esc_html__('Get powerful search ranking insights without leaving WordPress. Track rankings and spot growth opportunities buried in Google Search Console, Bing Webmaster Tools and Yandex Webmaster.', 'mihdan-index-now'));
		$seo_stat_upsell .= '<p>'.sprintf(
			esc_html__('Get %speriodic SEO email report%s on your website performance and pages index status on search results.', 'mihdan-index-now'),
			'<a href="https://crawlwp.com/article/seo-performance-email-reports/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=crawlwp-seo-index-upsell-page" target="_blank">', '</a>'
		) . '</p>';
		$seo_stat_upsell .= '<div class="crawlwp-upsell-featured-image">';
		$seo_stat_upsell .= '<img src="' . Utils::get_plugin_asset_url('images/crawlwp-google-search-console-top-section-stat.png') . '">';
		$seo_stat_upsell .= '</div>';
		$seo_stat_upsell .= sprintf('<a class="bbtn" target="_blank" href="%s">%s</a>', $upgrade_url, esc_html__('Upgrade to CrawlWP Premium', 'mihdan-index-now'));
		$seo_stat_upsell .= '</div>';
		$seo_stat_upsell .= '<div class="crawlwp-upsell-bottom">';
		$seo_stat_upsell .= '<div class="crawlwp-upsell-featured-image">';
		$seo_stat_upsell .= '<img src="' . Utils::get_plugin_asset_url('images/crawlwp-google-search-console-main-stat.png') . '">';
		$seo_stat_upsell .= '</div>';
		$seo_stat_upsell .= sprintf('<h3>%s</h3>', esc_html__('Complete SEO Clarity in One Dashboard', 'mihdan-index-now'));
		$seo_stat_upsell .= sprintf('<p>%s</p>', esc_html__('Track your website search performance with precise keyword rankings, click-through rates, and position data directly from Google, Bing and Yandex. Uncover actionable insights about your top-performing pages, user demographics, and device preferences to optimize your content strategy and boost organic traffic.', 'mihdan-index-now'));
		$seo_stat_upsell .= sprintf('<a class="bbtn" target="_blank" href="%s">%s</a>', $upgrade_url, esc_html__('Upgrade to CrawlWP Premium', 'mihdan-index-now'));
		$seo_stat_upsell .= '</div>';
		$seo_stat_upsell .= '</div>';

		$this->wposa->sub_page_title  = esc_html__('SEO Indexing', 'mihdan-index-now');
		$this->wposa2->sub_page_title = esc_html__('SEO Stats', 'mihdan-index-now');
		$this->wposa3->sub_page_title = esc_html__('Site Audit', 'mihdan-index-now');

		$this->wposa3->add_section([
			'id' => 'site_audit_upsell',
		]);

		$this->wposa3->add_field(
			'site_audit_upsell',
			[
				'id'   => 'pages3',
				'type' => 'html',
				'desc' => [$this, 'site_audit_upsell'],
			]
		);

		$this->wposa->add_section([
			'id' => 'seo_index_upsell',
		]);

		$this->wposa->add_field(
			'seo_index_upsell',
			[
				'id'   => 'pages',
				'type' => 'html',
				'desc' => $seo_index_upsell
			]
		);

		$this->wposa2->add_section([
			'id' => 'seo_stat_upsell',
		]);

		$this->wposa2->add_field(
			'seo_stat_upsell',
			[
				'id'   => 'pages2',
				'type' => 'html',
				'desc' => $seo_stat_upsell
			]
		);
	}

	/**
	 * Site Audit upsell: what the Premium feature does, with screenshots and upgrade buttons.
	 */
	public function site_audit_upsell(): string
	{
		$upgrade_url = 'https://crawlwp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=crawlwp-site-audit-upsell-page';
		$button      = sprintf('<a class="bbtn" target="_blank" href="%s">%s</a>', esc_url($upgrade_url), esc_html__('Upgrade to CrawlWP Premium', 'mihdan-index-now'));

		$checks = [
			__('Broken internal and outbound links', 'mihdan-index-now'),
			__('Duplicate titles, and missing or duplicate meta descriptions', 'mihdan-index-now'),
			__('Orphan pages no other page links to', 'mihdan-index-now'),
			__('Canonical URLs that point to a redirect or a missing page', 'mihdan-index-now'),
			__('404 errors without a redirect, and redirect chains', 'mihdan-index-now'),
			__('Images without alt text, thin content and missing focus keywords', 'mihdan-index-now'),
			__('Sitemap, robots.txt and other site-wide settings', 'mihdan-index-now'),
			__('Pages Google has not indexed', 'mihdan-index-now'),
		];

		$html  = '<div class="crawlwp-full-feature-upsell-page-wrap crawlwp-site-audit-upsell">';
		$html .= '<div class="crawlwp-upsell-top">';
		$html .= sprintf('<h2>%s</h2>', esc_html__('Find and Fix SEO Problems Across Your Whole Site', 'mihdan-index-now'));
		$html .= sprintf('<p>%s</p>', esc_html__('CrawlWP Premium checks every page on your site each week and lists the problems that hold back your search rankings, with the most serious first. Each problem explains why it matters and how to fix it.', 'mihdan-index-now'));
		$html .= '<div class="crawlwp-upsell-featured-image">';
		$html .= '<img src="' . esc_url(Utils::get_plugin_asset_url('images/crawlwp-site-audit-overview.png')) . '" alt="' . esc_attr__('Site Audit overview with a health score and a list of SEO issues', 'mihdan-index-now') . '">';
		$html .= '</div>';
		$html .= '<ul class="crawlwp-upsell-checklist">';

		foreach ($checks as $check) {
			$html .= '<li><span class="dashicons dashicons-yes" aria-hidden="true"></span>' . esc_html($check) . '</li>';
		}

		$html .= '</ul>';
		$html .= $button;
		$html .= '</div>';
		$html .= '<div class="crawlwp-upsell-bottom">';
		$html .= '<div class="crawlwp-upsell-featured-image">';
		$html .= '<img src="' . esc_url(Utils::get_plugin_asset_url('images/crawlwp-site-audit-issue.png')) . '" alt="' . esc_attr__('Fixing missing meta descriptions directly from the Site Audit', 'mihdan-index-now') . '">';
		$html .= '</div>';
		$html .= sprintf('<h3>%s</h3>', esc_html__('Fix Problems Where You Find Them', 'mihdan-index-now'));
		$html .= sprintf('<p>%s</p>', esc_html__('Write a missing meta description or shorten a long title right in the list, or generate it with AI, without opening each post. Ignore what doesn\'t apply, watch your health score rise as you fix things, and get an email when new errors appear.', 'mihdan-index-now'));
		$html .= $button;
		$html .= '</div>';
		$html .= '</div>';

		return $html;
	}
}
