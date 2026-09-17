<?php

namespace Mihdan\IndexNow\Tests\Unit;

use Mihdan\IndexNow\SEOCore\Redirects\RedirectsManager;
use Mihdan\IndexNow\SEOCore\Redirects\RedirectsProcessor;
use PHPUnit\Framework\TestCase;

require_once CRAWLWP_TESTS_PLUGIN_DIR . '/src/SEOCore/Redirects/RedirectsManager.php';
require_once CRAWLWP_TESTS_PLUGIN_DIR . '/src/SEOCore/Redirects/RedirectsProcessor.php';

class RedirectsProcessorHookTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		$GLOBALS['crawlwp_test_state']['actions'] = [];
		$GLOBALS['crawlwp_test_state']['filters'] = [];
	}

	public function test_default_hook_is_wp_with_negative_one_priority(): void
	{
		$manager = $this->createMock(RedirectsManager::class);
		$processor = new RedirectsProcessor($manager);

		$actions = $GLOBALS['crawlwp_test_state']['actions']['wp'] ?? [];
		$this->assertNotEmpty($actions);

		$registered = false;
		foreach ($actions as $action) {
			if ($action['callback'] === [$processor, 'process'] && $action['priority'] === -1) {
				$registered = true;
				break;
			}
		}

		$this->assertTrue($registered, 'RedirectsProcessor should be hooked to "wp" with priority -1 by default.');
	}

	public function test_custom_hook_and_priority_via_filters(): void
	{
		add_filter('crawlwp_redirect_hook', function () {
			return 'template_redirect';
		});

		add_filter('crawlwp_redirect_priority', function () {
			return 10;
		});

		$manager = $this->createMock(RedirectsManager::class);
		$processor = new RedirectsProcessor($manager);

		$wp_actions = $GLOBALS['crawlwp_test_state']['actions']['wp'] ?? [];
		$this->assertEmpty($wp_actions, 'RedirectsProcessor should not be hooked to "wp" when filtered.');

		$custom_actions = $GLOBALS['crawlwp_test_state']['actions']['template_redirect'] ?? [];
		$this->assertNotEmpty($custom_actions);

		$registered = false;
		foreach ($custom_actions as $action) {
			if ($action['callback'] === [$processor, 'process'] && $action['priority'] === 10) {
				$registered = true;
				break;
			}
		}

		$this->assertTrue($registered, 'RedirectsProcessor should be hooked to the filtered hook and priority.');
	}
}
