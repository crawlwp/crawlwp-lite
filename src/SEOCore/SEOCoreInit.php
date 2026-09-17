<?php

namespace Mihdan\IndexNow\SEOCore;

use Mihdan\IndexNow\SEOCore\Breadcrumbs\Breadcrumbs;
use Mihdan\IndexNow\SEOCore\Breadcrumbs\BreadcrumbSettings;
use Mihdan\IndexNow\SEOCore\Notifications\Notifications;
use Mihdan\IndexNow\SEOCore\MetaBox\Assets;
use Mihdan\IndexNow\SEOCore\MetaBox\PostListColumn;
use Mihdan\IndexNow\SEOCore\MetaBox\FrontendHead;
use Mihdan\IndexNow\SEOCore\MetaBox\MetaBox;
use Mihdan\IndexNow\SEOCore\SiteVerification\SiteVerificationFrontendOutput;
use Mihdan\IndexNow\SEOCore\SiteVerification\SiteVerificationSettings;
use Mihdan\IndexNow\SEOCore\RssSettings\RssSettings;
use Mihdan\IndexNow\SEOCore\SiteInfoSettings\SiteInfoSettings;
use Mihdan\IndexNow\SEOCore\SocialSettings\SocialSettings;
use Mihdan\IndexNow\SEOCore\SocialSettings\UserProfile;
use Mihdan\IndexNow\SEOCore\TitleMeta\FrontendOutput;
use Mihdan\IndexNow\SEOCore\Sitemap;
use Mihdan\IndexNow\SEOCore\SitemapSettings\SitemapSettings;
use Mihdan\IndexNow\SEOCore\RobotsSettings\RobotsSettings;
use Mihdan\IndexNow\SEOCore\SitemapSettings\NewsSitemapProvider;
use Mihdan\IndexNow\SEOCore\Redirects\RedirectsManager;
use Mihdan\IndexNow\SEOCore\Redirects\RedirectsSettings;
use Mihdan\IndexNow\SEOCore\Redirects\RedirectsProcessor;
use Mihdan\IndexNow\SEOCore\Redirects\PermalinkTracker;
use Mihdan\IndexNow\SEOCore\Redirects\Monitor404;
use Mihdan\IndexNow\SEOCore\BulkEditor\BulkEditorSettings;
use Mihdan\IndexNow\SEOCore\Integrations\WooCommerce;
use Mihdan\IndexNow\SEOCore\Integrations\Elementor;
use Mihdan\IndexNow\SEOCore\FeatureGate\FeatureGate;
use Mihdan\IndexNow\SEOCore\Wizard\Wizard;
use Mihdan\IndexNow\SEOCore\Importer\ImporterSettings;
use Mihdan\IndexNow\SEOCore\TermSEO\TermMetaBox;
use Mihdan\IndexNow\SEOCore\Schema\Graph;
use Mihdan\IndexNow\SEOCore\SitemapSettings\SitemapStylesheet;
use Mihdan\IndexNow\SEOCore\ImageSEO\ImageSEO;
use Mihdan\IndexNow\SEOCore\LlmsTxt\LlmsTxt;
use Mihdan\IndexNow\SEOCore\InternalLinks\InternalLinksUpsell;
use Mihdan\IndexNow\SEOCore\EmailReports\EmailReportsUpsell;
use Mihdan\IndexNow\SEOCore\Code\CodeSettings;

class SEOCoreInit
{
	use GetInstanceTrait;

	/**
	 * Modules whose hooks are required on every kind of request — frontend,
	 * admin, REST and cron alike.
	 *
	 * @var string[]
	 */
	private const MODULES_ALWAYS = [
		Graph::class,
		SitemapStylesheet::class,
		ImageSEO::class,
		LlmsTxt::class,
		InternalLinksUpsell::class,
		EmailReportsUpsell::class,
		CodeSettings::class,
		Elementor::class,
		CoreSettings\CoreSettings::class,
		SiteInfoSettings::class,
		SocialSettings::class,
		RssSettings::class,
		BreadcrumbSettings::class,
		RobotsSettings::class,
		SitemapSettings::class,
		NewsSitemapProvider::class,
		MetaBox::class,
		Assets::class,
		PostListColumn::class,
		FrontendHead::class,
		FrontendOutput::class,
		Sitemap::class,
		WooCommerce::class,
	];

	/**
	 * Modules that only ever register admin-screen or admin-ajax hooks, so
	 * there is nothing for them to do on a frontend, REST or cron request.
	 *
	 * @var string[]
	 */
	private const MODULES_ADMIN = [
		Notifications::class,
		BulkEditorSettings::class,
		ImporterSettings::class,
		TermMetaBox::class,
		CoreSettings\Assets::class,
		UserProfile::class,
	];

	public function __construct()
	{
		// Feature gate — registers the "SEO Features" promo/settings tab.
		// Must be first so the tab appears before all other header menus.
		new FeatureGate();
		new Wizard();

		new AdvancedSettings();
		new SiteVerificationSettings();
		new SiteVerificationFrontendOutput();

		// The on-page SEO output features are gated behind the master toggle.
		// Admins who are upgrading from another SEO plugin can review settings
		// first, then flip the switch on the "SEO Features" tab.
		if (!FeatureGate::is_enabled()) {
			return;
		}

		// Redirects — manager must be instantiated early so the DB table
		// is created on plugins_loaded before any other code queries it.
		$redirects_manager = new RedirectsManager();
		new RedirectsProcessor($redirects_manager);
		new PermalinkTracker($redirects_manager);
		new Monitor404($redirects_manager);

		foreach (self::MODULES_ALWAYS as $module) {
			new $module();
		}

		if (self::is_admin_request()) {
			new RedirectsSettings($redirects_manager);

			foreach (self::MODULES_ADMIN as $module) {
				new $module();
			}
		}

		// init hooking to prevent "Function _load_textdomain_just_in_time was called incorrectly" error.
		add_action('init', function() {
			$breadcrumbs = new Breadcrumbs();
			$breadcrumbs->setup();
		});
	}

	/**
	 * True for wp-admin screens and admin-ajax requests, i.e. the only places
	 * where the admin-only modules have any hook to fire.
	 */
	private static function is_admin_request(): bool
	{
		return is_admin();
	}
}
