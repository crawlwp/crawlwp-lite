<?php

declare(strict_types=1);

namespace Mihdan\IndexNow\Tests\Unit;

use PHPUnit\Framework\TestCase;

class UninstallTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		if (!defined('WP_UNINSTALL_PLUGIN')) {
			define('WP_UNINSTALL_PLUGIN', true);
		}
		$GLOBALS['crawlwp_test_state']['options'] = [];
		$GLOBALS['crawlwp_test_state']['cleared_crons'] = [];
		$GLOBALS['crawlwp_test_state']['cache_flushed'] = false;
		if (isset($GLOBALS['wpdb'])) {
			$GLOBALS['wpdb']->queries = [];
		}
		require_once dirname(__DIR__, 2) . '/uninstall.php';
	}

	/**
	 * @param string $function
	 * @param mixed ...$args
	 * @return mixed
	 */
	private function call_uninstall_function(string $function, ...$args)
	{
		/** @var callable $callable */
		$callable = $function;
		return call_user_func_array($callable, $args);
	}

	public function test_uninstall_is_disabled_by_default(): void
	{
		$result = $this->call_uninstall_function('\Mihdan\IndexNow\crawlwp_lite_is_uninstall_enabled');
		$this->assertFalse($result);
	}

	public function test_uninstall_is_enabled_via_advanced_settings(): void
	{
		update_option('crawlwp_advanced', ['remove_plugin_data' => 'off']);
		$this->assertFalse($this->call_uninstall_function('\Mihdan\IndexNow\crawlwp_lite_is_uninstall_enabled'));

		update_option('crawlwp_advanced', ['remove_plugin_data' => 'on']);
		$this->assertTrue($this->call_uninstall_function('\Mihdan\IndexNow\crawlwp_lite_is_uninstall_enabled'));

		update_option('crawlwp_advanced', ['remove_plugin_data' => 'yes']);
		$this->assertTrue($this->call_uninstall_function('\Mihdan\IndexNow\crawlwp_lite_is_uninstall_enabled'));
	}

	public function test_uninstall_function_does_nothing_when_disabled(): void
	{
		update_option('crawlwp_version', '3.0');
		$this->call_uninstall_function('\Mihdan\IndexNow\crawlwp_lite_mo_uninstall_function');

		$this->assertSame('3.0', get_option('crawlwp_version'));
		$this->assertEmpty($GLOBALS['wpdb']->queries);
		$this->assertEmpty($GLOBALS['crawlwp_test_state']['cleared_crons']);
	}

	public function test_uninstall_function_cleans_up_when_enabled(): void
	{
		update_option('crawlwp_advanced', ['remove_plugin_data' => 'on']);
		update_option('crawlwp_version', '3.0');
		update_option('crawlwp_general', ['some' => 'val']);

		$this->call_uninstall_function('\Mihdan\IndexNow\crawlwp_lite_mo_uninstall_function');

		$this->assertFalse(get_option('crawlwp_version', false));
		$this->assertNotEmpty($GLOBALS['wpdb']->queries);
		$this->assertNotEmpty($GLOBALS['crawlwp_test_state']['cleared_crons']);
		$this->assertTrue($GLOBALS['crawlwp_test_state']['cache_flushed']);
	}
}
