<?php

namespace Mihdan\IndexNow\SEOCore\Integrations;

/**
 * Jetpack integration.
 *
 * Jetpack ships features that overlap with CrawlWP and only steps aside for
 * the SEO plugins on its hard-coded conflict lists, which CrawlWP is not on.
 * We therefore opt out ourselves:
 *
 *  - Open Graph tags (functions.opengraph.php), printed when the Sharing or
 *    Publicize modules are active, are disabled via the
 *    `jetpack_enable_open_graph` filter, and Twitter Card tags via the
 *    `jetpack_disable_twitter_cards` filter. Jetpack evaluates both on
 *    `plugins_loaded` (`Jetpack::check_open_graph()` /
 *    `Jetpack::check_twitter_tags()`), so they must be registered before
 *    then — this class is instantiated while CrawlWP's main file loads.
 *  - The Sitemaps, SEO Tools and Site Verification modules are removed from
 *    Jetpack's available modules. Jetpack only treats available modules as
 *    active, so they are neither loaded nor offered in its settings, while the
 *    stored `jetpack_active_modules` option is left untouched — they come back
 *    as they were if CrawlWP is deactivated.
 */
class Jetpack
{
	/**
	 * Jetpack modules that duplicate CrawlWP functionality.
	 */
	private const CONFLICTING_MODULES = [
		'sitemaps',
		'seo-tools',
		'verification-tools',
	];

	public function __construct()
	{
		/* Same priority Jetpack uses for its own conflicting-plugin check, so it
		 * wins over the `__return_true` Sharing/Publicize add at priority 0. */
		add_filter('jetpack_enable_open_graph', '__return_false', 99);
		add_filter('jetpack_disable_twitter_cards', '__return_true', 99);

		add_filter('jetpack_get_available_modules', [$this, 'remove_conflicting_modules'], 99);
	}

	/**
	 * @param array<string,mixed> $modules Available modules keyed by slug.
	 *
	 * @return array<string,mixed>
	 */
	public function remove_conflicting_modules($modules)
	{
		if (! is_array($modules)) {
			return $modules;
		}

		return array_diff_key($modules, array_flip(self::CONFLICTING_MODULES));
	}
}
