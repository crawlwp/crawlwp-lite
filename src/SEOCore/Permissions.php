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

	/**
	 * Settings screens that hold secrets (API keys, the service-account key,
	 * the licence) or the submission log: administrators only, even for roles
	 * given the settings permission.
	 */
	private const ADMIN_ONLY_MENUS = ['api_settings', 'log', 'license'];

	/** AJAX actions only the SEO box uses: closed to users without the SEO box permission. */
	private const SEO_BOX_AJAX = [
		'crawlwp_ai_generate',
		'crawlwp_submit_indexnow',
		'crawlwp_check_duplicate_keyword',
		'crawlwp_suggested_links',
		'crawlwp_keyword_suggestions',
	];

	public function __construct()
	{
		add_filter('crawlwp_admin_page_capability', [__CLASS__, 'page_capability'], 10, 2);
		add_filter('crawlwp_wposa_sections', [__CLASS__, 'visible_sections'], 10, 3);
		/* admin-ajax.php runs admin_init before the wp_ajax_ action. */
		add_action('admin_init', [__CLASS__, 'guard_seo_box_ajax'], 0);
		add_action('admin_enqueue_scripts', [__CLASS__, 'dequeue_seo_box'], 100);
	}

	/**
	 * Stop direct calls to the SEO box's AJAX actions (AI, IndexNow, keyword
	 * and link lookups) by users who aren't allowed the SEO box.
	 */
	public static function guard_seo_box_ajax(): void
	{
		if (! wp_doing_ajax()) {
			return;
		}

		$action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if (in_array($action, self::SEO_BOX_AJAX, true) && ! self::can(self::SEO_BOX)) {
			wp_send_json_error(['message' => __('You are not allowed to use the SEO box.', 'mihdan-index-now')], 403);
		}
	}

	/**
	 * The SEO box script (and the nonces it carries) only for users allowed the box.
	 */
	public static function dequeue_seo_box(string $hook): void
	{
		if (in_array($hook, ['post.php', 'post-new.php'], true) && ! self::can(self::SEO_BOX)) {
			wp_dequeue_script('crawlwp-seo-metabox');
			wp_dequeue_style('crawlwp-seo-metabox');
		}
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
			? self::can_edit_content($user_id)
			: user_can($user_id, 'manage_options');
	}

	/**
	 * Whether the user can edit posts of any public post type (posts, pages,
	 * products…). Before these permissions existed, anyone who could edit an
	 * item got its SEO box, so this keeps that for roles without edit_posts.
	 */
	public static function can_edit_content(int $user_id): bool
	{
		if (user_can($user_id, 'edit_posts')) {
			return true;
		}

		foreach (get_post_types(['public' => true], 'objects') as $type) {
			if (isset($type->cap->edit_posts) && user_can($user_id, $type->cap->edit_posts)) {
				return true;
			}
		}

		return false;
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
		if ($slug !== CRAWLWP_SLUG || current_user_can('manage_options')) {
			return $sections;
		}

		$admin_only = array_map(static function ($menu) use ($prefix) {
			return $prefix . '_' . $menu;
		}, self::ADMIN_ONLY_MENUS);

		$sections = array_values(array_filter($sections, static function ($section) use ($admin_only) {
			return ! in_array((string) ($section['header_menu_id'] ?? ''), $admin_only, true);
		}));

		if (self::can(self::SETTINGS)) {
			return $sections;
		}

		return array_values(array_filter($sections, static function ($section) use ($prefix) {
			$id = substr((string) ($section['id'] ?? ''), strlen($prefix) + 1);

			return isset(self::SECTION_PERMISSIONS[$id]) && self::can(self::SECTION_PERMISSIONS[$id]);
		}));
	}
}
