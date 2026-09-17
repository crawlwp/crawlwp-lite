<?php

declare(strict_types=1);

namespace Mihdan\IndexNow\Tests\Unit;

use Mihdan\IndexNow\SEOCore\EmailReports\EmailReportsUpsell;
use Mihdan\IndexNow\Views\WPOSA;
use PHPUnit\Framework\TestCase;

class EmailReportsUpsellTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		unset($_GET['wposa-menu']);
		unset($GLOBALS['crawlwp_test_state']['filters']['crawlwp_is_pro_active']);
	}

	protected function tearDown(): void
	{
		unset($_GET['wposa-menu']);
		unset($GLOBALS['crawlwp_test_state']['filters']['crawlwp_is_pro_active']);
		parent::tearDown();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function get_sections(WPOSA $wposa): array
	{
		return (function () {
			return $this->sections_array;
		})->bindTo($wposa, WPOSA::class)();
	}

	/**
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function get_fields(WPOSA $wposa): array
	{
		return (function () {
			return $this->fields_array;
		})->bindTo($wposa, WPOSA::class)();
	}

	public function test_upsell_registered_when_pro_is_not_active(): void
	{
		$_GET['wposa-menu'] = 'crawlwp_advanced_settings';

		add_filter('crawlwp_is_pro_active', '__return_false');

		$wposa = new WPOSA('CrawlWP', '3.0', 'crawlwp', 'crawlwp');
		$upsell = new EmailReportsUpsell();
		$upsell->settings_fields($wposa);

		$sections = $this->get_sections($wposa);
		$email_reports_section = null;

		foreach ($sections as $section) {
			if (($section['id'] ?? '') === 'crawlwp_email_reports') {
				$email_reports_section = $section;
				break;
			}
		}

		$this->assertNotNull($email_reports_section, 'Email reports section should be registered');
		$this->assertSame('Email Reports', $email_reports_section['title']);
		$this->assertSame('crawlwp_advanced_settings', $email_reports_section['header_menu_id']);

		$fields = $this->get_fields($wposa);
		$this->assertArrayHasKey('crawlwp_email_reports', $fields);

		$upsell_field = null;
		foreach ($fields['crawlwp_email_reports'] as $field) {
			if (($field['id'] ?? '') === 'email_reports_upsell') {
				$upsell_field = $field;
				break;
			}
		}

		$this->assertNotNull($upsell_field, 'Email reports upsell field should be present');
		$this->assertSame('html', $upsell_field['type']);
		$this->assertSame('Scheduled Email Reports', $upsell_field['name']);

		$desc = $upsell_field['desc'];
		$this->assertStringContainsString('cwp-upsell-notice', $desc);
		$this->assertStringContainsString('PRO', $desc);
		$this->assertStringContainsString('crawlwp-email-reports-upsell', $desc);
		$this->assertStringContainsString('#submit_crawlwp_email_reports {display:none;}', $desc);
	}

	public function test_upsell_not_registered_when_pro_is_active(): void
	{
		$_GET['wposa-menu'] = 'crawlwp_advanced_settings';

		add_filter('crawlwp_is_pro_active', '__return_true');

		$wposa = new WPOSA('CrawlWP', '3.0', 'crawlwp', 'crawlwp');
		$upsell = new EmailReportsUpsell();
		$upsell->settings_fields($wposa);

		$sections = $this->get_sections($wposa);
		$found = false;
		foreach ($sections as $section) {
			if (($section['id'] ?? '') === 'crawlwp_email_reports') {
				$found = true;
				break;
			}
		}

		$this->assertFalse($found, 'Email reports upsell should not be registered when pro is active');
	}

	public function test_upsell_not_registered_on_other_menu(): void
	{
		$_GET['wposa-menu'] = 'crawlwp_index_settings';

		add_filter('crawlwp_is_pro_active', '__return_false');

		$wposa = new WPOSA('CrawlWP', '3.0', 'crawlwp', 'crawlwp');
		$upsell = new EmailReportsUpsell();
		$upsell->settings_fields($wposa);

		$sections = $this->get_sections($wposa);
		$found = false;
		foreach ($sections as $section) {
			if (($section['id'] ?? '') === 'crawlwp_email_reports') {
				$found = true;
				break;
			}
		}

		$this->assertFalse($found, 'Email reports upsell should not be registered on non-advanced menu');
	}

	public function test_hook_priority_is_16(): void
	{
		$actions = $GLOBALS['crawlwp_test_state']['actions']['crawlwp_setup_fields'] ?? [];
		$found_priority = null;

		foreach ($actions as $action) {
			if (isset($action['callback'][0]) && $action['callback'][0] instanceof EmailReportsUpsell) {
				$found_priority = $action['priority'];
				break;
			}
		}

		$this->assertSame(16, $found_priority, 'Hook priority should be 16 to sit between Sitemap and CodeSettings');
	}
}
