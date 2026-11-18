<?php

use Mihdan\IndexNow\SEOCore\BulkEditor\BulkEditorSettings;
use PHPUnit\Framework\TestCase;

require_once CRAWLWP_TESTS_PLUGIN_DIR . '/src/SEOCore/BulkEditor/BulkEditorSettings.php';

class BulkEditorListTest extends TestCase
{
	protected function setUp(): void
	{
		foreach (['post', 'page'] as $type) {
			$GLOBALS['crawlwp_test_state']['post_types'][$type] = (object) [
				'name' => $type,
				'cap'  => (object) ['edit_others_posts' => 'edit_others_' . $type . 's', 'edit_private_posts' => 'edit_private_' . $type . 's'],
			];
		}
	}

	protected function tearDown(): void
	{
		unset($GLOBALS['crawlwp_test_state']['post_types']['post'], $GLOBALS['crawlwp_test_state']['post_types']['page'], $GLOBALS['crawlwp_test_state']['current_user_can']);
	}

	public function test_all_types_listed_for_users_who_can_edit_everyone(): void
	{
		$this->assertTrue(BulkEditorSettings::can_edit_all_of(['post', 'page']));
	}

	public function test_own_posts_only_when_any_type_is_restricted(): void
	{
		$GLOBALS['crawlwp_test_state']['current_user_can'] = ['edit_others_pages' => false];
		$this->assertFalse(BulkEditorSettings::can_edit_all_of(['post', 'page']));

		$GLOBALS['crawlwp_test_state']['current_user_can'] = ['edit_private_posts' => false];
		$this->assertFalse(BulkEditorSettings::can_edit_all_of(['post', 'page']));

		$this->assertFalse(BulkEditorSettings::can_edit_all_of([]));
	}
}
