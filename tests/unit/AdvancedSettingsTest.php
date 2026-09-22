<?php

declare(strict_types=1);

namespace Mihdan\IndexNow\Tests\Unit;

use Mihdan\IndexNow\Logger\Logger;
use Mihdan\IndexNow\SEOCore\AdvancedSettings;
use Mihdan\IndexNow\SEOCore\CoreSettings\CoreSettings;
use Mihdan\IndexNow\SEOCore\FeatureGate\FeatureGate;
use Mihdan\IndexNow\Views\Settings;
use Mihdan\IndexNow\Views\WPOSA;
use PHPUnit\Framework\TestCase;

class AdvancedSettingsTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		unset($_GET['wposa-menu']);
		$GLOBALS['crawlwp_test_state']['actions'] = [];
		$GLOBALS['crawlwp_test_state']['options'] = [];
	}

	public function test_settings_tab_is_first_menu_when_feature_gate_disabled(): void
	{
		FeatureGate::disable();

		new FeatureGate();
		new AdvancedSettings();

		$wposa = new WPOSA('CrawlWP', '3.0', 'crawlwp', 'crawlwp');
		$logger = $this->createMock(Logger::class);
		$settings = new Settings($logger, $wposa);
		$settings->setup_fields();

		$refProperty = new \ReflectionProperty(WPOSA::class, 'header_menu_array');
		$refProperty->setAccessible(true);
		$menus = $refProperty->getValue($wposa);

		$this->assertNotEmpty($menus);
		$this->assertSame('crawlwp_advanced_settings', $menus[0]['id']);
		$this->assertSame('Settings', $menus[0]['title']);

		// When viewing CrawlWP Settings without ?wposa-menu=..., Settings is the active menu
		$this->assertSame('crawlwp_advanced_settings', $wposa->get_active_header_menu());
	}

	public function test_settings_tab_is_first_menu_when_feature_gate_enabled(): void
	{
		FeatureGate::enable();

		new AdvancedSettings();
		new CoreSettings();

		$wposa = new WPOSA('CrawlWP', '3.0', 'crawlwp', 'crawlwp');
		$logger = $this->createMock(Logger::class);
		$settings = new Settings($logger, $wposa);
		$settings->setup_fields();

		$refProperty = new \ReflectionProperty(WPOSA::class, 'header_menu_array');
		$refProperty->setAccessible(true);
		$menus = $refProperty->getValue($wposa);

		$this->assertNotEmpty($menus);
		$this->assertSame('crawlwp_advanced_settings', $menus[0]['id']);
		$this->assertSame('Settings', $menus[0]['title']);

		// When viewing CrawlWP Settings without ?wposa-menu=..., Settings is the active menu
		$this->assertSame('crawlwp_advanced_settings', $wposa->get_active_header_menu());
	}
}
