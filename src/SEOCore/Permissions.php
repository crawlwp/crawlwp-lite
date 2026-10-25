<?php

namespace Mihdan\IndexNow\SEOCore;

use Mihdan\IndexNow\SEOCore\BulkEditor\BulkEditorSettings;
use Mihdan\IndexNow\SEOCore\Redirects\Monitor404;
use Mihdan\IndexNow\SEOCore\Redirects\RedirectsSettings;

/**
 * Who can use which part of CrawlWP.
 *
 * The defaults are how CrawlWP has always worked: anyone who can edit posts
 * gets the whole SEO box, everything else is for administrators. CrawlWP
 * Premium's Role Manager changes the answer per role through the
 * `crawlwp_user_can` filter. Administrators (manage_options) can always do
 * everything, whatever the filter says.
 */
class Permissions
{
	/** The SEO box on posts the user can edit. */
	public const SEO_BOX = 'seo_box';

	/** Robots, canonical, redirect and schema fields (Schema and Advanced tabs). */
	public const SEO_ADVANCED = 'seo_advanced';

	/** The SEO box's Insights tab. */
	public const INSIGHTS = 'insights';

	/** SEO Stats, Analytics and the dashboard widgets. */
	public const STATS = 'stats';

	/** The SEO Indexing page. */
	public const INDEXING = 'indexing';

	/** Redirects and the 404 monitor. */
	public const REDIRECTS = 'redirects';

	/** The Bulk Editor. */
	public const BULK_EDITOR = 'bulk_editor';

	/** Every CrawlWP settings page. */
	public const SETTINGS = 'settings';

	/** Main settings sections a user without the settings permission may still open. */
	private const SECTION_PERMISSIONS = [
		RedirectsSettings::SECTION  => self::REDIRECTS,
		Monitor404::SECTION         => self::REDIRECTS,
		BulkEditorSettings::SECTION => self::BULK_EDITOR,
	];

	public function __construct()
	{
		add_filter('crawlwp_admin_page_capability', [__CLASS__, 'page_capability'], 10, 2);
		add_filter('crawlwp_wposa_sections', [__CLASS__, 'visible_sections'], 10, 3);
	}

	/**
	 * @return string[]
	 */
	public static function all(): array
	{
		return [self::SEO_BOX, self::SEO_ADVANCED, self::INSIGHTS, self::STATS, self::INDEXING, self::REDIRECTS, self::BULK_EDITOR, self::SETTINGS];
	}

	/**
	 * The answer before Premium's Role Manager changes it.
	 */
	public static function default_for(string $permission, int $user_id): bool
	{
		return in_array($permission, [self::SEO_BOX, self::SEO_ADVANCED, self::INSIGHTS], true)
			? user_can($user_id, 'edit_posts')
			: user_can($user_id, 'manage_options');
	}

	public static function can(string $permission, int $user_id = 0): bool
	{
		$user_id = $user_id ?: get_current_user_id();

		if (! $user_id) return false;

		if (user_can($user_id, 'manage_options')) return true;

		/**
		 * Filter whether a user may use a part of CrawlWP. Administrators always may.
		 *
		 * @param bool   $allowed    Default: the SEO box for users who can edit posts, the rest for administrators.
		 * @param string $permission One of the Permissions constants.
		 * @param int    $user_id    User ID.
		 */
		return (bool) apply_filters('crawlwp_user_can', self::default_for($permission, $user_id), $permission, $user_id);
	}

	/**
	 * Whether the user can open the main CrawlWP settings screen (or part of it).
	 */
	public static function can_open_settings(): bool
	{
		return self::can(self::SETTINGS) || self::can(self::REDIRECTS) || self::can(self::BULK_EDITOR);
	}

	/**
	 * Capability for a CrawlWP admin page. WordPress needs a capability string,
	 * so a user CrawlWP lets in gets 'read' and everyone else 'manage_options'.
	 */
	public static function page_capability(string $capability, string $slug): string
	{
		if ($slug === CRAWLWP_SLUG) {
			return self::can_open_settings() ? 'read' : $capability;
		}

		return $capability;
	}

	/**
	 * On the main settings screen, users without the settings permission only
	 * see the Redirects, 404 and Bulk Editor sections they are allowed.
	 *
	 * @param array<int, array<string, mixed>> $sections
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function visible_sections(array $sections, string $prefix, string $slug): array
	{
		if ($slug !== CRAWLWP_SLUG || self::can(self::SETTINGS)) {
			return $sections;
		}

		return array_values(array_filter($sections, static function ($section) use ($prefix) {
			$id = substr((string) ($section['id'] ?? ''), strlen($prefix) + 1);

			return isset(self::SECTION_PERMISSIONS[$id]) && self::can(self::SECTION_PERMISSIONS[$id]);
		}));
	}
}
