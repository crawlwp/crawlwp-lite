<?php

namespace Mihdan\IndexNow\Tests\Unit;

use Mihdan\IndexNow\SEOCore\Notifications\Notifications;
use PHPUnit\Framework\TestCase;

class NotificationsBadgeTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		$GLOBALS['crawlwp_test_state']['options'] = [];
		$GLOBALS['crawlwp_test_state']['current_user_can'] = [
			'manage_options' => true,
		];
		$GLOBALS['crawlwp_test_state']['is_admin'] = true;
		$GLOBALS['crawlwp_test_state']['actions'] = [];

		// Initialize dummy $menu global matching WordPress admin menu structure:
		// [0: title, 1: capability, 2: slug, 3: page_title, 4: classes, 5: hook_name, 6: icon_url]
		$GLOBALS['menu'] = [
			0 => ['Dashboard', 'read', 'index.php'],
			10 => ['CrawlWP', 'manage_options', 'crawlwp', 'CrawlWP', 'toplevel_page_crawlwp', 'toplevel_page_crawlwp', 'dashicons-search'],
			20 => ['Settings', 'manage_options', 'options-general.php'],
		];
	}

	public function test_hook_registered_on_admin_menu(): void
	{
		$notifications = new Notifications();

		$this->assertNotEmpty($GLOBALS['crawlwp_test_state']['actions']['admin_menu']);

		$matched = false;
		foreach ($GLOBALS['crawlwp_test_state']['actions']['admin_menu'] as $action) {
			if (
				is_array($action['callback']) &&
				$action['callback'][0] === $notifications &&
				$action['callback'][1] === 'add_menu_badge' &&
				$action['priority'] === 999
			) {
				$matched = true;
				break;
			}
		}

		$this->assertTrue($matched, 'add_menu_badge should be registered on admin_menu with priority 999');
	}

	private function get_all_dismissed_except(array $exceptions = []): array
	{
		$all = [
			'blog_not_public'             => true,
			'crawlwp_site_noindex'        => true,
			'conflicting_seo_plugin'      => true,
			'missing_homepage_title'      => true,
			'missing_homepage_description'=> true,
			'sitemap_disabled'            => true,
			'robots_txt_blocking'         => true,
			'no_ssl'                      => true,
			'physical_robots_txt_exists'  => true,
			'no_permalink_structure'      => true,
			'rss_full_text'               => true,
			'new_post_type'               => true,
		];

		foreach ($exceptions as $ex) {
			unset($all[$ex]);
		}

		return $all;
	}

	public function test_no_badge_added_when_no_active_notifications(): void
	{
		// Default: no issues triggered, blog_public is 1
		$GLOBALS['crawlwp_test_state']['options']['blog_public'] = 1;
		$GLOBALS['crawlwp_test_state']['home_url'] = 'https://example.test';
		$GLOBALS['crawlwp_test_state']['options']['home'] = 'https://example.test';

		// Dismiss all possible notices to ensure 0 active notices
		$GLOBALS['crawlwp_test_state']['options']['crawlwp_dismissed_notices'] = $this->get_all_dismissed_except();

		$notifications = new Notifications();
		$this->assertSame(0, $notifications->get_notification_count());

		$notifications->add_menu_badge();

		$this->assertSame('CrawlWP', $GLOBALS['menu'][10][0]);
		$this->assertStringNotContainsString('cwp-nc-menu-badge', $GLOBALS['menu'][10][0]);
	}

	public function test_badge_added_with_count_when_notifications_exist(): void
	{
		// Trigger blog_not_public notice (discourage search engines = 0)
		$GLOBALS['crawlwp_test_state']['options']['blog_public'] = 0;
		$GLOBALS['crawlwp_test_state']['options']['home'] = 'https://example.test';

		// Dismiss other notices except blog_not_public
		$GLOBALS['crawlwp_test_state']['options']['crawlwp_dismissed_notices'] = $this->get_all_dismissed_except(['blog_not_public']);

		$notifications = new Notifications();
		$this->assertSame(1, $notifications->get_notification_count());

		$notifications->add_menu_badge();

		$title = $GLOBALS['menu'][10][0];
		$this->assertStringContainsString('cwp-nc-menu-badge', $title);
		$this->assertStringContainsString('count-1', $title);
		$this->assertStringContainsString('<span class="plugin-count" aria-hidden="true">1</span>', $title);
		$this->assertStringContainsString('<span class="screen-reader-text">1 notification</span>', $title);
	}

	public function test_badge_updates_cleanly_on_subsequent_calls(): void
	{
		// Start with 1 notice
		$GLOBALS['crawlwp_test_state']['options']['blog_public'] = 0;
		$GLOBALS['crawlwp_test_state']['options']['home'] = 'https://example.test';
		$GLOBALS['crawlwp_test_state']['is_ssl'] = true;

		$GLOBALS['crawlwp_test_state']['options']['crawlwp_dismissed_notices'] = $this->get_all_dismissed_except(['blog_not_public']);

		$notifications = new Notifications();
		$notifications->add_menu_badge();

		$first_title = $GLOBALS['menu'][10][0];
		$this->assertStringContainsString('count-1', $first_title);

		// Now add a second notice (e.g. non-https site: no_ssl)
		$GLOBALS['crawlwp_test_state']['options']['crawlwp_dismissed_notices'] = $this->get_all_dismissed_except(['blog_not_public', 'no_ssl']);
		$GLOBALS['crawlwp_test_state']['options']['home'] = 'http://example.test';
		$GLOBALS['crawlwp_test_state']['is_ssl'] = false;

		$notifications->reset_active_notices_cache();
		$this->assertSame(2, $notifications->get_notification_count());

		$notifications->add_menu_badge();

		$updated_title = $GLOBALS['menu'][10][0];
		$this->assertStringContainsString('count-2', $updated_title);
		$this->assertStringContainsString('<span class="plugin-count" aria-hidden="true">2</span>', $updated_title);
		$this->assertStringContainsString('<span class="screen-reader-text">2 notifications</span>', $updated_title);

		// Ensure no duplicate badges exist in menu title
		$this->assertSame(1, substr_count($updated_title, 'cwp-nc-menu-badge'));
	}

	public function test_badge_removed_when_all_notices_are_dismissed(): void
	{
		// Start with a notice
		$GLOBALS['crawlwp_test_state']['options']['blog_public'] = 0;
		$GLOBALS['crawlwp_test_state']['options']['home'] = 'https://example.test';
		$GLOBALS['crawlwp_test_state']['options']['crawlwp_dismissed_notices'] = $this->get_all_dismissed_except(['blog_not_public']);

		$notifications = new Notifications();
		$notifications->add_menu_badge();
		$this->assertStringContainsString('cwp-nc-menu-badge', $GLOBALS['menu'][10][0]);

		// Now dismiss blog_not_public too
		$GLOBALS['crawlwp_test_state']['options']['crawlwp_dismissed_notices'] = $this->get_all_dismissed_except();

		$notifications->reset_active_notices_cache();
		$this->assertSame(0, $notifications->get_notification_count());

		$notifications->add_menu_badge();

		$this->assertSame('CrawlWP', $GLOBALS['menu'][10][0]);
		$this->assertStringNotContainsString('cwp-nc-menu-badge', $GLOBALS['menu'][10][0]);
	}

	public function test_badge_not_added_if_user_lacks_capability(): void
	{
		$GLOBALS['crawlwp_test_state']['current_user_can']['manage_options'] = false;
		$GLOBALS['crawlwp_test_state']['options']['blog_public'] = 0;

		$notifications = new Notifications();
		$notifications->add_menu_badge();

		$this->assertSame('CrawlWP', $GLOBALS['menu'][10][0]);
	}

	public function test_badge_graceful_when_menu_not_array(): void
	{
		$GLOBALS['menu'] = null;
		$GLOBALS['crawlwp_test_state']['options']['blog_public'] = 0;

		$notifications = new Notifications();
		$notifications->add_menu_badge();

		$this->assertNull($GLOBALS['menu']);
	}
}
