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

		add_action('init', [$this, 'setup_fields'], 110);
	}


	/**
	 * "New in CrawlWP Premium" cards shown on the upsell pages.
	 *
	 * @param array[] $cards Each with title, text and either image (file in assets/images) or icon (SVG markup).
	 */
	public static function new_features(string $intro, array $cards): string
	{
		$html = '<div class="crawlwp-upsell-new">';
		$html .= sprintf('<h3>%s</h3><p>%s</p><div class="crawlwp-upsell-new__cards">', esc_html__('New in CrawlWP Premium', 'mihdan-index-now'), esc_html($intro));

		foreach ($cards as $card) {
			$media = ! empty($card['image'])
				? sprintf('<img src="%s" alt="%s" loading="lazy">', esc_url(Utils::get_plugin_asset_url('images/' . $card['image'])), esc_attr($card['title']))
				: '<span class="crawlwp-upsell-new__icon">' . $card['icon'] . '</span>'; // static SVG from this file

			$html .= sprintf(
				'<div class="crawlwp-upsell-new__card"><div class="crawlwp-upsell-new__media">%1$s</div><div class="crawlwp-upsell-new__text"><h4><span class="crawlwp-new-badge">%2$s</span>%3$s</h4><p>%4$s</p></div></div>',
				$media,
				esc_html__('New', 'mihdan-index-now'),
				esc_html($card['title']),
				esc_html($card['text'])
			);
		}

		return $html . '</div></div>';
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
		$seo_index_upsell .= self::new_features(
			__('Find out what\'s stopping your pages from showing in Google, and fix it.', 'mihdan-index-now'),
			[
				[
					'image' => 'upsell-not-indexed.png',
					'title' => __('Why a page isn\'t indexed', 'mihdan-index-now'),
					'text'  => __('See Google\'s reason for every page it hasn\'t indexed, in plain English, with the steps to fix it and a button to ask Google to look again.', 'mihdan-index-now'),
				],
				[
					'icon'  => '<svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 11l3 3 8-8"/><path d="M20 12v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h9"/></svg>',
					'title' => __('Site Audit', 'mihdan-index-now'),
					'text'  => __('Scan your whole site for broken links, missing or duplicate titles and descriptions, orphan pages, redirect chains and thin content, with the most serious problems first.', 'mihdan-index-now'),
				],
			]
		);
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
		$seo_stat_upsell .= self::new_features(
			__('Turn your search and visitor data into a to-do list.', 'mihdan-index-now'),
			[
				[
					'image' => 'upsell-opportunities.png',
					'title' => __('Opportunities report', 'mihdan-index-now'),
					'text'  => __('Keywords that are almost on page one, pages Google shows but people don\'t click, and pages losing clicks, each with what to do next.', 'mihdan-index-now'),
				],
				[
					'image' => 'upsell-analytics.png',
					'title' => __('Google Analytics visitor stats', 'mihdan-index-now'),
					'text'  => __('Visitors, views and time on page on your dashboard and for each post, read from your Google Analytics. No extra tracking code.', 'mihdan-index-now'),
				],
			]
		);
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
}
