<?php

declare(strict_types=1);

namespace Mihdan\IndexNow\Tests\Unit;

use Mihdan\IndexNow\OAuth\OAuth;
use PHPUnit\Framework\TestCase;

/**
 * Just enough of $wpdb for OAuth's INSERT IGNORE / compare-and-swap lock, backed by an array.
 */
class OAuthLockWpdb
{
	public string $options = 'wp_options';
	public array $rows     = [];

	public function prepare($query, ...$args)
	{
		return [$query, $args];
	}

	public function query($prepared)
	{
		[$sql, $args] = $prepared;

		if (str_starts_with($sql, 'INSERT IGNORE')) {
			if (isset($this->rows[$args[0]])) return 0;
			$this->rows[$args[0]] = (string)$args[1];
			return 1;
		}

		if (str_starts_with($sql, 'UPDATE')) {
			if (($this->rows[$args[1]] ?? null) !== (string)$args[2]) return 0;
			$this->rows[$args[1]] = (string)$args[0];
			return 1;
		}

		if (str_starts_with($sql, 'DELETE')) {
			unset($this->rows[$args[0]]);
			return 1;
		}

		return 0;
	}

	public function get_var($prepared)
	{
		return $this->rows[$prepared[1][0]] ?? null;
	}
}

/**
 * "Connect with Google / Bing": token refresh, failure handling and backward compatibility with keys.
 */
class OAuthTest extends TestCase
{
	private $original_wpdb;

	/** @var OAuthLockWpdb */
	private $db;

	protected function setUp(): void
	{
		parent::setUp();
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->db            = $GLOBALS['wpdb'] = new OAuthLockWpdb();
		$GLOBALS['crawlwp_test_state']['options']           = [];
		$GLOBALS['crawlwp_test_state']['transients']        = [];
		$GLOBALS['crawlwp_test_state']['actions']           = [];
		$GLOBALS['crawlwp_test_state']['remote_post_calls'] = [];
		unset($GLOBALS['crawlwp_test_state']['remote_post_response'], $GLOBALS['crawlwp_test_state']['cache_delete_hook'], $GLOBALS['crawlwp_test_state']['remote_post_hook']);
	}

	protected function tearDown(): void
	{
		$GLOBALS['wpdb'] = $this->original_wpdb;
		parent::tearDown();
	}

	private function connect_bing(int $expires_in): void
	{
		update_option(OAuth::OPTION_PREFIX . 'bing', [
			'access_token'  => 'old-access',
			'refresh_token' => 'refresh-1',
			'expires_at'    => time() + $expires_in,
			'email'         => '',
		]);
	}

	private function proxy_replies(array $body): void
	{
		$GLOBALS['crawlwp_test_state']['remote_post_response'] = ['response' => ['code' => 200], 'body' => json_encode($body)];
	}

	private function proxy_calls(): int
	{
		return count($GLOBALS['crawlwp_test_state']['remote_post_calls']);
	}

	public function test_not_connected_without_a_refresh_token(): void
	{
		$this->assertFalse(OAuth::is_connected('google'));
		$this->assertSame('', OAuth::get_access_token('google'));
		$this->assertSame(0, $this->proxy_calls());
	}

	public function test_valid_token_is_used_without_refreshing(): void
	{
		$this->connect_bing(3000);

		$this->assertSame('old-access', OAuth::get_access_token('bing'));
		$this->assertSame(0, $this->proxy_calls());
	}

	public function test_token_close_to_expiry_is_refreshed_and_saved(): void
	{
		$this->connect_bing(30);
		$this->proxy_replies(['access_token' => 'new-access', 'expires_in' => 3600, 'refresh_token' => 'refresh-2']);

		$this->assertSame('new-access', OAuth::get_access_token('bing'));

		$saved = OAuth::get_connection('bing');
		$this->assertSame('refresh-2', $saved['refresh_token']);
		$this->assertGreaterThan(time() + 3000, $saved['expires_at']);
		$this->assertSame([], $this->db->rows, 'lock is released');
	}

	public function test_refresh_token_is_kept_when_none_is_returned(): void
	{
		$this->connect_bing(30);
		$this->proxy_replies(['access_token' => 'new-access', 'expires_in' => 3600]);

		OAuth::get_access_token('bing');

		$this->assertSame('refresh-1', OAuth::get_connection('bing')['refresh_token']);
	}

	public function test_only_one_request_refreshes_at_a_time(): void
	{
		$this->connect_bing(30);
		$this->db->rows['crawlwp_oauth_lock_bing'] = (string)time();

		$this->assertSame('old-access', OAuth::get_access_token('bing'), 'keeps using the still-valid token');
		$this->assertSame(0, $this->proxy_calls());
	}

	public function test_stale_lock_is_taken_over(): void
	{
		$this->connect_bing(30);
		$this->db->rows['crawlwp_oauth_lock_bing'] = (string)(time() - 60);
		$this->proxy_replies(['access_token' => 'new-access', 'expires_in' => 3600]);

		$this->assertSame('new-access', OAuth::get_access_token('bing'));
	}

	public function test_temporary_failure_backs_off(): void
	{
		$this->connect_bing(30);
		$GLOBALS['crawlwp_test_state']['remote_post_response'] = new \WP_Error('http_request_failed', 'timeout');

		$this->assertSame('old-access', OAuth::get_access_token('bing'));
		$this->assertSame('old-access', OAuth::get_access_token('bing'));
		$this->assertSame(1, $this->proxy_calls(), 'no second call during the back-off');
		$this->assertTrue(OAuth::is_connected('bing'), 'a temporary failure keeps the connection');
	}

	public function test_revoked_access_drops_the_connection_and_flags_it(): void
	{
		$this->connect_bing(30);
		$this->proxy_replies(['error' => 'invalid_grant']);
		$disconnected = [];
		add_action('crawlwp_oauth_disconnected', function ($provider) use (&$disconnected) {
			$disconnected[] = $provider;
		});

		$this->assertSame('', OAuth::get_access_token('bing'));
		$this->assertFalse(OAuth::is_connected('bing'));
		$this->assertArrayHasKey('bing', get_option(OAuth::DROPPED));
		$this->assertSame(['bing'], $disconnected);
	}

	public function test_waiting_request_uses_token_refreshed_by_another_request(): void
	{
		// Read as "about to expire", but by the time the lock is taken another request has refreshed it.
		$this->connect_bing(30);
		$GLOBALS['crawlwp_test_state']['cache_delete_hook'] = function () {
			update_option(OAuth::OPTION_PREFIX . 'bing', ['access_token' => 'fresh', 'expires_at' => time() + 3600] + OAuth::get_connection('bing'));
		};

		$this->assertSame('fresh', OAuth::get_access_token('bing'));
		$this->assertSame(0, $this->proxy_calls(), 'no second refresh');
		unset($GLOBALS['crawlwp_test_state']['cache_delete_hook']);
	}

	public function test_refresh_does_not_bring_back_a_disconnected_connection(): void
	{
		$this->connect_bing(30);
		$this->proxy_replies(['access_token' => 'new-access', 'expires_in' => 3600]);
		$GLOBALS['crawlwp_test_state']['remote_post_hook'] = function () {
			delete_option(OAuth::OPTION_PREFIX . 'bing'); // user clicked Disconnect while the refresh was in flight
		};

		$this->assertSame('', OAuth::get_access_token('bing'));
		$this->assertFalse(OAuth::is_connected('bing'));
		unset($GLOBALS['crawlwp_test_state']['remote_post_hook']);
	}

	public function test_proxy_configuration_errors_keep_the_connection(): void
	{
		$this->connect_bing(30);
		$this->proxy_replies(['error' => 'unauthorized_client']);

		OAuth::get_access_token('bing');

		$this->assertTrue(OAuth::is_connected('bing'), 'only invalid_grant disconnects');
		$this->assertFalse(get_option(OAuth::DROPPED));
		$this->assertNotEmpty($GLOBALS['crawlwp_test_state']['transients']['crawlwp_oauth_backoff_bing']);
	}

	public function test_google_and_bing_are_stored_separately(): void
	{
		$this->connect_bing(3000);
		update_option(OAuth::OPTION_PREFIX . 'google', ['access_token' => 'g', 'refresh_token' => 'gr', 'expires_at' => time() + 3000, 'email' => '']);

		delete_option(OAuth::OPTION_PREFIX . 'bing');

		$this->assertTrue(OAuth::is_connected('google'));
		$this->assertFalse(OAuth::is_connected('bing'));
	}

	public function test_unavailable_reason_distinguishes_outage_from_not_connected(): void
	{
		$this->connect_bing(30);
		$this->assertStringContainsString('retry', OAuth::unavailable_reason('bing'));
		$this->assertStringContainsString('not connected', OAuth::unavailable_reason('google'));
	}

	public function test_bing_api_key_takes_priority_over_the_connection(): void
	{
		$this->connect_bing(3000);
		update_option('crawlwp_bing_webmaster', ['api_key' => 'my-key']);

		$request = OAuth::bing_request('GetPageStats');

		$this->assertStringContainsString('apikey=my-key', $request['url']);
		$this->assertSame([], $request['headers']);
	}

	public function test_bing_connection_is_used_without_an_api_key(): void
	{
		$this->connect_bing(3000);

		$request = OAuth::bing_request('GetPageStats');

		$this->assertSame('https://www.bing.com/webmaster/api.svc/json/GetPageStats', $request['url']);
		$this->assertSame(['Authorization' => 'Bearer old-access'], $request['headers']);
	}

	public function test_bing_request_is_null_when_nothing_is_connected(): void
	{
		$this->assertNull(OAuth::bing_request('GetPageStats'));
	}

	public function test_has_feature_reads_the_granted_scopes(): void
	{
		$this->assertFalse(OAuth::has_feature('google', 'analytics'));

		update_option(OAuth::OPTION_PREFIX . 'google', [
			'access_token'  => 'a',
			'refresh_token' => 'r',
			'expires_at'    => time() + 3600,
			'scope'         => 'openid https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/analytics.readonly',
		]);

		$this->assertTrue(OAuth::has_feature('google', 'analytics'));
		$this->assertFalse(OAuth::has_feature('google', 'unknown'));
		$this->assertFalse(OAuth::has_feature('bing', 'analytics'));
	}

	public function test_has_feature_needs_the_exact_scope(): void
	{
		update_option(OAuth::OPTION_PREFIX . 'google', [
			'access_token'  => 'a',
			'refresh_token' => 'r',
			'expires_at'    => time() + 3600,
			'scope'         => 'https://www.googleapis.com/auth/analytics.readonly.extra',
		]);

		$this->assertFalse(OAuth::has_feature('google', 'analytics'));
	}
}
