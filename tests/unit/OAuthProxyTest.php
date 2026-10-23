<?php

declare(strict_types=1);

namespace Mihdan\IndexNow\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! defined('CRAWLWP_OAUTH_PROXY_LIB_ONLY')) {
	define('CRAWLWP_OAUTH_PROXY_LIB_ONLY', true);
}

require_once dirname(__DIR__, 2) . '/oauth-proxy/index.php';

/**
 * The OAuth proxy's security checks (oauth-proxy/index.php). Not shipped in the plugin.
 */
class OAuthProxyTest extends TestCase
{
	private const CONFIG = ['secret' => 'test-secret'];

	public function test_return_url_must_be_a_site_admin_post_url(): void
	{
		$this->assertTrue(\is_valid_return_url('https://site.test/wp-admin/admin-post.php?action=crawlwp_oauth_return'));
		$this->assertTrue(\is_valid_return_url('http://site.test/blog/wp-admin/admin-post.php'));

		$this->assertFalse(\is_valid_return_url('https://evil.test/steal'));
		$this->assertFalse(\is_valid_return_url('javascript:alert(1)//wp-admin/admin-post.php'));
		$this->assertFalse(\is_valid_return_url('https://user:pass@site.test/wp-admin/admin-post.php'));
		$this->assertFalse(\is_valid_return_url('//site.test/wp-admin/admin-post.php'));
	}

	public function test_signed_flow_cookie_round_trips(): void
	{
		$data = ['p' => 'google', 'r' => 'https://site.test/wp-admin/admin-post.php', 's' => 'abc', 'n' => 'nonce', 't' => 1];

		$this->assertSame($data, \verify(\sign($data, self::CONFIG), self::CONFIG));
	}

	public function test_tampered_or_foreign_cookie_is_rejected(): void
	{
		$cookie   = \sign(['p' => 'google', 'r' => 'https://site.test/wp-admin/admin-post.php'], self::CONFIG);
		[$body, ] = explode('.', $cookie);
		$forged   = rtrim(strtr(base64_encode((string)json_encode(['p' => 'google', 'r' => 'https://evil.test/wp-admin/admin-post.php'])), '+/', '-_'), '=');

		$this->assertNull(\verify($forged . '.' . explode('.', $cookie)[1], self::CONFIG));
		$this->assertNull(\verify($body . '.', self::CONFIG));
		$this->assertNull(\verify($cookie, ['secret' => 'other-secret']));
		$this->assertNull(\verify('', self::CONFIG));
	}

	public function test_email_is_read_from_the_id_token(): void
	{
		$payload = rtrim(strtr(base64_encode((string)json_encode(['email' => 'me@example.com'])), '+/', '-_'), '=');

		$this->assertSame('me@example.com', \id_token_email('header.' . $payload . '.signature'));
		$this->assertSame('', \id_token_email('not-a-jwt'));
	}

	public function test_rate_limit_counts_requests_per_ip(): void
	{
		$dir     = sys_get_temp_dir() . '/crawlwp-oauth-test-' . bin2hex(random_bytes(4));
		$old_ip  = $_SERVER['REMOTE_ADDR'] ?? null;
		mkdir($dir);
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

		\rate_limit(['storage_dir' => $dir, 'rate_limit' => 5]);
		\rate_limit(['storage_dir' => $dir, 'rate_limit' => 5]);

		$files = glob($dir . '/rl-*.cnt');
		$this->assertCount(1, $files);
		$this->assertSame('2', file_get_contents($files[0]));

		array_map('unlink', $files);
		rmdir($dir);

		if ($old_ip === null) {
			unset($_SERVER['REMOTE_ADDR']);
		} else {
			$_SERVER['REMOTE_ADDR'] = $old_ip;
		}
	}

	public function test_confirmation_page_names_the_site_that_gets_access(): void
	{
		$this->assertSame('https://site.test', \site_label('https://site.test/wp-admin/admin-post.php?action=crawlwp_oauth_return'));
		$this->assertSame('http://site.test:8080/blog', \site_label('http://site.test:8080/blog/wp-admin/admin-post.php'));
	}
}
