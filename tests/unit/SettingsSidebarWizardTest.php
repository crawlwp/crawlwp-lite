<?php

namespace Mihdan\IndexNow\Tests\Unit;

use Mihdan\IndexNow\Logger\Logger;
use Mihdan\IndexNow\SEOCore\FeatureGate\FeatureGate;
use Mihdan\IndexNow\SEOCore\Wizard\Wizard;
use Mihdan\IndexNow\Views\Settings;
use Mihdan\IndexNow\Views\WPOSA;
use PHPUnit\Framework\TestCase;

class SettingsSidebarWizardTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		$GLOBALS['crawlwp_test_state']['options'] = [];
		$GLOBALS['crawlwp_test_state']['transients'] = [];
		$GLOBALS['submenu'] = [];
		$_GET = [];
	}

	public function test_sidebar_contains_setup_wizard_card_when_feature_enabled(): void
	{
		// Enable SEO features
		$GLOBALS['crawlwp_test_state']['options']['crawlwp_seo_features'] = [
			'enable_seo_features' => 'on',
		];
		// Reset cached state
		$ref = new \ReflectionClass(FeatureGate::class);
		$prop = $ref->getProperty('cache');
		$prop->setAccessible(true);
		$prop->setValue(null, true);

		$_GET['wposa-menu'] = 'non_index_menu';
		$logger   = $this->createMock(Logger::class);
		$wposa    = new WPOSA('CrawlWP', '3.0', 'crawlwp', 'crawlwp');
		$settings = new Settings($logger, $wposa);
		$settings->setup_fields();

		$cards = $wposa->get_sidebar_cards();
		$this->assertNotEmpty($cards);

		$wizard_card = null;
		foreach ($cards as $card) {
			if (($card['id'] ?? '') === 'setup_wizard') {
				$wizard_card = $card;
				break;
			}
		}

		$this->assertNotNull($wizard_card, 'Setup Wizard card should be registered in sidebar');
		$this->assertSame('Setup Wizard', $wizard_card['title']);

		$desc = $wizard_card['desc'];
		$this->assertStringContainsString('Relaunch Setup Wizard', $desc);
		$this->assertStringContainsString(Wizard::wizard_url(), $desc);
		$this->assertStringContainsString('button button-secondary', $desc);
	}

	public function test_setup_wizard_submenu_not_registered_in_dashboard_menus(): void
	{
		global $submenu;
		$submenu = [];

		$wizard = new Wizard();
		$wizard->register_menu_page();

		$this->assertArrayNotHasKey(
			'crawlwp',
			$submenu,
			'Setup Wizard must not appear as a submenu in dashboard menus'
		);
	}

	public function test_wizard_redirect_only_on_fresh_install(): void
	{
		$ref = new \ReflectionClass(FeatureGate::class);
		$prop = $ref->getProperty('cache');
		$prop->setAccessible(true);
		$prop->setValue(null, null);

		// Case 1: Fresh install (crawlwp_index_now is empty, crawlwp_seo_features not set)
		$GLOBALS['crawlwp_test_state']['options'] = [];
		$GLOBALS['crawlwp_test_state']['transients'] = [];

		FeatureGate::maybe_persist_default();
		$this->assertNotEmpty(
			get_transient(Wizard::REDIRECT_TRANSIENT),
			'Fresh install with onsite SEO enabled should set redirect transient'
		);

		// Case 2: Existing install (crawlwp_index_now has options)
		$prop->setValue(null, null);
		$GLOBALS['crawlwp_test_state']['options'] = [
			'crawlwp_index_now' => ['enable' => 'on'],
		];
		$GLOBALS['crawlwp_test_state']['transients'] = [];

		FeatureGate::maybe_persist_default();
		$this->assertEmpty(
			get_transient(Wizard::REDIRECT_TRANSIENT),
			'Existing install should NOT set redirect transient'
		);

		// Case 3: Calling FeatureGate::enable() directly should NOT set redirect transient
		$prop->setValue(null, null);
		$GLOBALS['crawlwp_test_state']['transients'] = [];
		FeatureGate::enable();
		$this->assertEmpty(
			get_transient(Wizard::REDIRECT_TRANSIENT),
			'Directly enabling FeatureGate should NOT set redirect transient'
		);

		// Case 4: Fresh install where crawlwp_index_now only has auto-generated api_key
		$prop->setValue(null, null);
		unset($GLOBALS['crawlwp_test_state']['options'][FeatureGate::OPTION_KEY]);
		$GLOBALS['crawlwp_test_state']['options']['crawlwp_index_now'] = ['api_key' => 'xyz123abc'];
		$GLOBALS['crawlwp_test_state']['transients'] = [];

		FeatureGate::maybe_persist_default();
		$this->assertNotEmpty(
			get_transient(Wizard::REDIRECT_TRANSIENT),
			'Fresh install with only auto-generated api_key should set redirect transient and enable SEO features'
		);
		$this->assertTrue(FeatureGate::is_enabled());

		// Case 5: Existing install with crawlwp_general configured
		$prop->setValue(null, null);
		unset($GLOBALS['crawlwp_test_state']['options'][FeatureGate::OPTION_KEY]);
		$GLOBALS['crawlwp_test_state']['options']['crawlwp_index_now'] = ['api_key' => 'xyz123abc'];
		$GLOBALS['crawlwp_test_state']['options']['crawlwp_general'] = ['post_types' => ['post', 'page']];
		$GLOBALS['crawlwp_test_state']['transients'] = [];

		FeatureGate::maybe_persist_default();
		$this->assertEmpty(
			get_transient(Wizard::REDIRECT_TRANSIENT),
			'Existing install with crawlwp_general should NOT set redirect transient'
		);
		$this->assertFalse(FeatureGate::is_enabled());
	}
}
