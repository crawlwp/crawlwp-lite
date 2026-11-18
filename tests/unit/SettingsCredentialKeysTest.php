<?php

namespace Mihdan\IndexNow\Tests\Unit;

use Mihdan\IndexNow\Views\WPOSA;
use PHPUnit\Framework\TestCase;

/**
 * A non-administrator allowed the settings must not be able to write the API keys that
 * share an option with the Index Settings fields.
 */
class SettingsCredentialKeysTest extends TestCase
{
	public static function setUpBeforeClass(): void
	{
		/* Loaded by hand when Composer's autoloader isn't installed. */
		foreach (['Utils.php', 'SEOCore/Permissions.php', 'Views/WPOSA.php'] as $file) {
			require_once dirname(__DIR__, 2) . '/src/' . $file;
		}
	}

	private function wposa(): WPOSA
	{
		$wposa = new WPOSA('CrawlWP', '1.0', 'crawlwp', 'crawlwp');
		$wposa->add_section(['header_menu_id' => 'index_settings', 'id' => 'google_webmaster', 'title' => 'Google']);
		$wposa->add_field('google_webmaster', ['id' => 'enable', 'type' => 'switch', 'name' => 'Enable']);
		$wposa->add_section(['header_menu_id' => 'advanced_settings', 'id' => 'local_seo', 'title' => 'Local']);

		return $wposa;
	}

	protected function tearDown(): void
	{
		unset($GLOBALS['crawlwp_test_state']['current_user_can']['manage_options']);
	}

	public function test_non_admin_cannot_write_credentials_or_unregistered_keys(): void
	{
		$GLOBALS['crawlwp_test_state']['current_user_can']['manage_options'] = false;

		$data = $this->wposa()->non_admin_keys_only('crawlwp_google_webmaster', ['enable' => 'on', 'json_key' => 'x', 'made_up' => 'y']);

		$this->assertSame(['enable' => 'on'], $data);
	}

	public function test_non_admin_keeps_unregistered_keys_in_ordinary_sections(): void
	{
		$GLOBALS['crawlwp_test_state']['current_user_can']['manage_options'] = false;

		$data = ['hours' => ['mon' => ['open' => '1']]];

		$this->assertSame($data, $this->wposa()->non_admin_keys_only('crawlwp_local_seo', $data));
	}

	public function test_administrator_is_unchanged(): void
	{
		$GLOBALS['crawlwp_test_state']['current_user_can']['manage_options'] = true;

		$data = ['enable' => 'on', 'json_key' => 'x', 'made_up' => 'y'];

		$this->assertSame($data, $this->wposa()->non_admin_keys_only('crawlwp_google_webmaster', $data));
	}
}
