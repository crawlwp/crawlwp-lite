<?php
/**
 * FeatureGate — master on/off switch for all on-page SEO features.
 *
 * New installs (option never saved) automatically have the feature enabled.
 * Existing installs that have explicitly saved the option respect whatever
 * value was stored, so upgrading users who turned it off are not affected.
 *
 * @package mihdan-index-now
 */

namespace Mihdan\IndexNow\SEOCore\FeatureGate;

use Mihdan\IndexNow\SEOCore\Wizard\Wizard;
use Mihdan\IndexNow\Utils;

class FeatureGate
{
	const OPTION_KEY = 'crawlwp_seo_features'; // Full WP option name (what get_option/update_option use directly)
	const SECTION_ID = 'seo_features';         // Bare id passed to add_section(); WPOSA prepends 'crawlwp_' → 'crawlwp_seo_features'
	const OPTION_FIELD = 'enabled';

	/**
	 * In-memory cache so we only read the DB once per request.
	 *
	 * @var bool|null
	 */
	private static $cache = null;

	/**
	 * Register the admin promo/settings section.
	 */
	public function __construct()
	{
		if (!self::is_enabled()) {
			add_action('crawlwp_pre_setup_fields', [$this, 'register_menu'], -20);
			add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

			return;
		}

		// The gate page no longer exists once the features are on — send anyone
		// landing on it (bookmark, stale link) back to the main settings screen.
		if (is_admin() && Utils::_GET_var('wposa-menu') === self::OPTION_KEY) {
			Utils::content_http_redirect(CRAWLWP_SETTINGS_URL);
		}
	}

	/**
	 * Stylesheet for the promo screen.
	 */
	public function enqueue_assets(string $hook): void
	{
		if (strpos($hook, Utils::get_plugin_slug()) === false) {
			return;
		}

		wp_enqueue_style(
			'crawlwp-feature-gate',
			CRAWLWP_PLUGIN_URL . 'src/SEOCore/FeatureGate/assets/feature-gate.css',
			[],
			CRAWLWP_VERSION
		);
	}

	/**
	 * Return true when the on-page SEO features are enabled.
	 *
	 * This is a pure read: it never writes to the database. When the option has
	 * never been saved we derive the default — brand-new installs get the
	 * features on, installs upgrading from a pre-gate version stay off until the
	 * admin opts in on the "SEO Features" tab. The derived value is persisted
	 * once, by maybe_persist_default(), from the activation/upgrade routine.
	 */
	public static function is_enabled(): bool
	{
		if (self::$cache === null) {

			$raw = get_option(self::OPTION_KEY);

			if ($raw === false) {
				self::$cache = self::is_fresh_install();
			} else {
				$options = is_array($raw) ? $raw : [];

				self::$cache = isset($options[self::OPTION_FIELD]) && $options[self::OPTION_FIELD] === 'on';
			}
		}

		return (bool)apply_filters('crawlwp_seo_features_is_enabled', self::$cache);
	}

	/**
	 * True when the plugin has no pre-existing data, i.e. this is not an
	 * upgrade from a version that shipped before the feature gate.
	 */
	public static function is_fresh_install(): bool
	{
		$index_now = get_option('crawlwp_index_now', []);
		if (is_array($index_now)) {
			unset($index_now['api_key']);
		} else {
			$index_now = [];
		}

		if (!empty($index_now)) {
			return false;
		}

		$pre_gate_options = [
			'crawlwp_general',
			'crawlwp_bing_webmaster',
			'crawlwp_google_webmaster',
			'crawlwp_yandex_webmaster',
			'crawlwp_logs',
			'crawlwp_webmaster_tools',
			'crawlwp_site_verification',
			'crawlwp_email_reports',
			'mihdan_index_now_general',
			'mihdan_index_now_index_now',
			'mihdan_index_now_bing_webmaster',
			'mihdan_index_now_google_webmaster',
			'mihdan_index_now_yandex_webmaster',
			'mihdan_index_now_logs',
			'mihdan_index_now_webmaster_tools',
			'mihdan_index_now_site_verification',
			'mihdan_index_now_email_reports',
		];

		foreach ($pre_gate_options as $option) {
			if (!empty(get_option($option, ''))) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Persist the default gate state once, on activation/upgrade.
	 *
	 * Keeps is_enabled() free of side effects — no frontend request ever writes
	 * to wp_options just by asking whether the features are on.
	 */
	public static function maybe_persist_default(): void
	{
		if (get_option(self::OPTION_KEY) !== false) {
			return;
		}

		if (self::is_fresh_install()) {
			self::enable();
			if (! get_option(Wizard::COMPLETED_OPTION)) {
				set_transient(Wizard::REDIRECT_TRANSIENT, 1, 60);
			}
		} else {
			self::disable();
		}
	}

	/**
	 * Enable the on-page SEO features.
	 */
	public static function enable(): void
	{
		$options = get_option(self::OPTION_KEY, []);
		$options = is_array($options) ? $options : [];
		$options[self::OPTION_FIELD] = 'on';
		update_option(self::OPTION_KEY, $options);
		self::$cache = true;
	}

	/**
	 * Disable the on-page SEO features.
	 */
	public static function disable(): void
	{
		$options = get_option(self::OPTION_KEY, []);
		$options = is_array($options) ? $options : [];
		$options[self::OPTION_FIELD] = 'off';
		update_option(self::OPTION_KEY, $options);
		self::$cache = false;
	}

	/**
	 * Register the "SEO Features" header menu and its promo section.
	 *
	 * @param \Mihdan\IndexNow\Views\WPOSA $wposa
	 */
	public function register_menu($wposa): void
	{
		$wposa->add_header_menu([
			'id' => 'seo_features',
			'title' => __('SEO Features', 'mihdan-index-now'),
		]);

		if ($wposa->get_active_header_menu() == Utils::get_plugin_prefix() . '_' . self::SECTION_ID) {

			$wposa->add_section([
				'header_menu_id' => self::SECTION_ID,
				'id' => self::SECTION_ID,
				'title' => '',
				'desc' => '',
				'callback' => [$this, 'render_promo'],
			]);
			$wposa->add_field(
				self::SECTION_ID,
				[
					'id' => self::OPTION_FIELD,
					'type' => 'checkbox',
					// callback_checkbox() renders `desc` as the label text next to the box.
					'desc' => __('Enable all on-page SEO features on this website', 'mihdan-index-now'),
				]
			);
		}
	}

	/**
	 * Render the promotional HTML block above the enable checkbox.
	 */
	public function render_promo(): void
	{
		$features = self::features();

		$illustration_url = CRAWLWP_PLUGIN_URL . 'src/SEOCore/FeatureGate/assets/feature-gate-preview.svg';

		require __DIR__ . '/views/promo.php';
	}

	/**
	 * Feature cards shown on the promo screen.
	 *
	 * @return array<int, array{icon: string, title: string, desc: string}>
	 */
	private static function features(): array
	{
		return [
			[
				'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>',
				'title' => __('Title & Meta Tags', 'mihdan-index-now'),
				'desc'  => __('Global title & description templates across post types, archives, taxonomies and special pages.', 'mihdan-index-now'),
			],
			[
				'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"></path></svg>',
				'title' => __('Open Graph & Social Cards', 'mihdan-index-now'),
				'desc'  => __('og:title, og:image, og:locale, article tags and fb:app_id for great link previews on every social network.', 'mihdan-index-now'),
			],
			[
				'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>',
				'title' => __('Schema / JSON-LD', 'mihdan-index-now'),
				'desc'  => __('WebSite, Organization/Person, WebPage, Article & BreadcrumbList structured data for Google rich results.', 'mihdan-index-now'),
			],
			[
				'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>',
				'title' => __('Breadcrumbs', 'mihdan-index-now'),
				'desc'  => __('[crawlwp_breadcrumbs] shortcode with automatic BreadcrumbList JSON-LD schema included.', 'mihdan-index-now'),
			],
			[
				'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>',
				'title' => __('Robots & robots.txt', 'mihdan-index-now'),
				'desc'  => __('Per-post and global noindex / nofollow / noarchive controls, plus a full robots.txt editor.', 'mihdan-index-now'),
			],
			[
				'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>',
				'title' => __('Canonical & Pagination', 'mihdan-index-now'),
				'desc'  => __('Self-referencing canonical tags and rel=prev/next links to prevent duplicate-content penalties.', 'mihdan-index-now'),
			],
			[
				'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>',
				'title' => __('XML Sitemap Enhancements', 'mihdan-index-now'),
				'desc'  => __('Google News sitemap, custom URL lists, noindex filtering and Polylang / WPML / TranslatePress hreflang.', 'mihdan-index-now'),
			],
			[
				'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"></polyline><path d="M3 11V9a4 4 0 0 1 4-4h14"></path><polyline points="7 23 3 19 7 15"></polyline><path d="M21 13v2a4 4 0 0 1-4 4H3"></path></svg>',
				'title' => __('URL Redirects', 'mihdan-index-now'),
				'desc'  => __('301/302/307/410/451 redirects with regex support, hit tracking and automatic permalink-change detection.', 'mihdan-index-now'),
			],
			[
				'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>',
				'title' => __('RSS Feed Branding', 'mihdan-index-now'),
				'desc'  => __('Prepend and append custom text (with tokens) to feed items — deters scrapers and builds brand recognition.', 'mihdan-index-now'),
			],
			[
				'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>',
				'title' => __('Site & Author Profiles', 'mihdan-index-now'),
				'desc'  => __('Declare your Organization or Person identity; authors can set Facebook URL, X handle and extra profile URLs.', 'mihdan-index-now'),
			],
			[
				'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>',
				'title' => __('SEO Notifications', 'mihdan-index-now'),
				'desc'  => __('Admin bar notification centre alerts you to discouraged indexing, conflicting plugins and robots.txt problems.', 'mihdan-index-now'),
			],
			[
				'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>',
				'title' => __('Post List SEO Column', 'mihdan-index-now'),
				'desc'  => __('Per-post SEO score column with Quick Edit support for SEO title and meta description directly from the list table.', 'mihdan-index-now'),
			],
			[
				'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 3 21 3 21 8"></polyline><line x1="4" y1="20" x2="21" y2="3"></line><polyline points="21 16 21 21 16 21"></polyline><line x1="15" y1="15" x2="21" y2="21"></line><line x1="4" y1="4" x2="9" y2="9"></line></svg>',
				'title' => __('Import from other SEO plugins', 'mihdan-index-now'),
				'desc'  => __('One-click migration of titles, descriptions, robots, canonicals and redirects from Yoast, Rank Math, AIOSEO, SEOPress, The SEO Framework and Slim SEO.', 'mihdan-index-now'),
			],
			[
				'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>',
				'title' => __('Local SEO & 404 monitor', 'mihdan-index-now'),
				'desc'  => __('LocalBusiness NAP schema, a 404 log with one-click redirects, Video/HTML sitemaps and llms.txt for AI crawlers.', 'mihdan-index-now'),
			],
		];
	}
}
