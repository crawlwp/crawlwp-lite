<?php

namespace Mihdan\IndexNow\Tests\Unit;

use Mihdan\IndexNow\SEOCore\Notifications\Notifications;
use PHPUnit\Framework\TestCase;

class NewPostTypeNotificationTest extends TestCase
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
		$GLOBALS['crawlwp_test_state']['post_types'] = [
			'post' => new \WP_Post_Type('post', 'Post', 'Posts', true),
			'page' => new \WP_Post_Type('page', 'Page', 'Pages', false),
		];
	}

	public function test_first_run_initializes_known_post_types_without_triggering_notice(): void
	{
		$this->assertNull(get_option(Notifications::KNOWN_POST_TYPES_KEY, null));

		$new_types = Notifications::get_new_post_types();
		$this->assertSame([], $new_types);

		$stored = get_option(Notifications::KNOWN_POST_TYPES_KEY, null);
		$this->assertEquals(['post', 'page'], $stored);
	}

	public function test_detects_single_new_post_type(): void
	{
		// Set existing known post types.
		update_option(Notifications::KNOWN_POST_TYPES_KEY, ['post', 'page'], false);

		// Add 'product' to registered post types.
		$GLOBALS['crawlwp_test_state']['post_types']['product'] = new \WP_Post_Type('product', 'Product', 'Products', true);

		$new_types = Notifications::get_new_post_types();
		$this->assertSame(['product'], $new_types);

		$notifications = new Notifications();
		$active = $notifications->get_active_notices();

		$new_pt_notice = null;
		foreach ($active as $notice) {
			if ($notice['id'] === 'new_post_type') {
				$new_pt_notice = $notice;
				break;
			}
		}

		$this->assertNotNull($new_pt_notice, 'new_post_type notice should be active');
		$this->assertSame('info', $new_pt_notice['severity']);
		$this->assertStringContainsString('CrawlWP has detected a new post type: <code>product</code>', $new_pt_notice['message']);
		$this->assertStringContainsString('Titles & Meta page</a>', $new_pt_notice['message']);
		$this->assertStringContainsString('the Sitemap</a>', $new_pt_notice['message']);
		$this->assertStringContainsString('#crawlwp_tm_pt_product', $new_pt_notice['message']);
		$this->assertStringContainsString('#crawlwp_sitemap_settings', $new_pt_notice['message']);
	}

	public function test_detects_multiple_new_post_types_with_plural_message(): void
	{
		update_option(Notifications::KNOWN_POST_TYPES_KEY, ['post', 'page'], false);

		$GLOBALS['crawlwp_test_state']['post_types']['product'] = new \WP_Post_Type('product', 'Product', 'Products', true);
		$GLOBALS['crawlwp_test_state']['post_types']['portfolio'] = new \WP_Post_Type('portfolio', 'Portfolio', 'Portfolios', true);

		$new_types = Notifications::get_new_post_types();
		$this->assertSame(['product', 'portfolio'], $new_types);

		$notifications = new Notifications();
		$active = $notifications->get_active_notices();

		$new_pt_notice = null;
		foreach ($active as $notice) {
			if ($notice['id'] === 'new_post_type') {
				$new_pt_notice = $notice;
				break;
			}
		}

		$this->assertNotNull($new_pt_notice);
		$this->assertStringContainsString('CrawlWP has detected new post types: <code>product</code>, <code>portfolio</code>', $new_pt_notice['message']);
	}

	public function test_dismissing_updates_known_post_types_and_clears_notice(): void
	{
		update_option(Notifications::KNOWN_POST_TYPES_KEY, ['post', 'page'], false);
		$GLOBALS['crawlwp_test_state']['post_types']['product'] = new \WP_Post_Type('product', 'Product', 'Products', true);

		$notifications = new Notifications();
		$this->assertSame(['product'], Notifications::get_new_post_types());

		// Simulate AJAX dismiss
		$_POST['notice_id'] = 'new_post_type';
		$_POST['nonce'] = wp_create_nonce('crawlwp_dismiss_notice');

		try {
			$notifications->ajax_dismiss_notice();
		} catch (\Exception $e) {
			// wp_send_json_success throws in unit test environment
		}

		// Known post types should now include 'product'
		$known = get_option(Notifications::KNOWN_POST_TYPES_KEY, []);
		$this->assertContains('product', $known);

		// Notice should no longer appear
		$notifications->reset_active_notices_cache();
		$this->assertSame([], Notifications::get_new_post_types());
	}

	public function test_non_viewable_and_attachment_post_types_are_ignored(): void
	{
		update_option(Notifications::KNOWN_POST_TYPES_KEY, ['post', 'page'], false);

		// Add attachment
		$GLOBALS['crawlwp_test_state']['post_types']['attachment'] = new \WP_Post_Type('attachment', 'Media', 'Media', false);

		// Add unviewable post type
		$hidden_pt = new \WP_Post_Type('hidden_type', 'Hidden', 'Hidden', false);
		$hidden_pt->publicly_queryable = false;
		$hidden_pt->public = false;
		$GLOBALS['crawlwp_test_state']['post_types']['hidden_type'] = $hidden_pt;

		$this->assertSame([], Notifications::get_new_post_types());
	}
}
