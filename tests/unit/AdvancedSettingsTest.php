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

	public function test_advanced_section_and_uninstall_setting_registered(): void
	{
		$_GET['wposa-menu'] = 'crawlwp_advanced_settings';

		$advanced_settings = new AdvancedSettings();
		$wposa = new WPOSA('CrawlWP', '3.0', 'crawlwp', 'crawlwp');

		$advanced_settings->settings_fields($wposa, null);

		$refSections = new \ReflectionProperty(WPOSA::class, 'sections_array');
		$refSections->setAccessible(true);
		/** @var array<int, array<string, mixed>> $sections */
		$sections = $refSections->getValue($wposa);

		$found_section = null;
		foreach ($sections as $section) {
			if (($section['id'] ?? '') === 'crawlwp_advanced') {
				$found_section = $section;
				break;
			}
		}

		$this->assertNotNull($found_section, 'crawlwp_advanced section should be registered');
		$this->assertSame('Advanced', $found_section['title']);
		$this->assertSame('crawlwp_advanced_settings', $found_section['header_menu_id']);

		$refFields = new \ReflectionProperty(WPOSA::class, 'fields_array');
		$refFields->setAccessible(true);
		/** @var array<string, list<array<string, mixed>>> $fields */
		$fields = $refFields->getValue($wposa);

		$advanced_fields = $fields['crawlwp_advanced'] ?? [];
		$found_field = null;
		foreach ($advanced_fields as $field) {
			if (($field['id'] ?? '') === 'remove_plugin_data') {
				$found_field = $field;
				break;
			}
		}

		$this->assertNotNull($found_field, 'remove_plugin_data field should be registered');
		$this->assertSame('switch', $found_field['type']);
		$this->assertSame('Remove Data on Uninstall', $found_field['name']);
		$this->assertSame('off', $found_field['default']);
	}

	public function test_is_remove_data_on_uninstall_helper(): void
	{
		$this->assertFalse(AdvancedSettings::is_remove_data_on_uninstall());

		update_option('crawlwp_advanced', ['remove_plugin_data' => 'off']);
		$this->assertFalse(AdvancedSettings::is_remove_data_on_uninstall());

		update_option('crawlwp_advanced', ['remove_plugin_data' => 'on']);
		$this->assertTrue(AdvancedSettings::is_remove_data_on_uninstall());

		update_option('crawlwp_advanced', ['remove_plugin_data' => 'yes']);
		$this->assertTrue(AdvancedSettings::is_remove_data_on_uninstall());
	}
}
