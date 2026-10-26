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
		// hosts and paths that would make the confirmation page show a different site than the browser visits
		$this->assertFalse(\is_valid_return_url('https://evil.test\\victim.test/wp-admin/admin-post.php'));
		$this->assertFalse(\is_valid_return_url('https://evil.test/https://victim.test/wp-admin/admin-post.php'));
		$this->assertFalse(\is_valid_return_url('https://evil.test//victim.test/wp-admin/admin-post.php'));
		$this->assertFalse(\is_valid_return_url('https://evil.test/a\\b/wp-admin/admin-post.php'));
		$this->assertFalse(\is_valid_return_url('https://-bad.test/wp-admin/admin-post.php'));
		$this->assertTrue(\is_valid_return_url('https://xn--bcher-kva.test/wp-admin/admin-post.php'));
		$this->assertTrue(\is_valid_return_url('https://site.test:8443/wp-admin/admin-post.php'));
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

	public function test_revoked_refresh_tokens_are_remembered_by_keyed_hash(): void
	{
		$dir    = sys_get_temp_dir() . '/cwp-revoke-' . bin2hex(random_bytes(4));
		mkdir($dir);
		$config = ['storage_dir' => $dir, 'secret' => str_repeat('s', 32)];

		$this->assertFalse(\is_revoked($config, 'google', 'token-a'));

		file_put_contents(\revoked_path($config, 'google', 'token-a'), '1');

		$this->assertTrue(\is_revoked($config, 'google', 'token-a'));
		$this->assertFalse(\is_revoked($config, 'google', 'token-b'));
		$this->assertFalse(\is_revoked($config, 'bing', 'token-a'));
		$this->assertStringNotContainsString('token-a', \revoked_path($config, 'google', 'token-a'));

		array_map('unlink', glob($dir . '/*'));
		rmdir($dir);
	}

	public function test_site_ownership_matches_domain_and_url_properties(): void
	{
		$domain = ['sc-domain:example.com'];
		$prefix = ['https://www.example.com/'];

		// Domain property: the domain itself, www and any subdomain.
		$this->assertTrue(\site_matches($domain, 'example.com'));
		$this->assertTrue(\site_matches($domain, 'www.example.com'));
		$this->assertTrue(\site_matches($domain, 'blog.example.com'));
		$this->assertTrue(\site_matches($domain, 'EXAMPLE.com'));
		$this->assertTrue(\site_matches(['sc-domain:www.example.com'], 'example.com'));

		// URL property: the same host, with or without www; ports are not compared.
		$this->assertTrue(\site_matches($prefix, 'example.com'));
		$this->assertTrue(\site_matches($prefix, 'www.example.com'));
		$this->assertTrue(\site_matches(['http://example.com:8080/blog/'], 'example.com'));
		$this->assertFalse(\site_matches($prefix, 'blog.example.com'));

		// Lookalikes and other sites.
		foreach (['example.com.evil.io', 'evil-example.com', 'notexample.com', 'example.co', 'www.example.com.evil.io', ''] as $host) {
			$this->assertFalse(\site_matches($domain, $host), $host);
			$this->assertFalse(\site_matches($prefix, $host), $host);
		}

		$this->assertFalse(\site_matches([], 'example.com'));
		$this->assertFalse(\site_matches(['sc-domain:'], 'example.com'));
		$this->assertFalse(\site_matches(['not a url'], 'example.com'));
	}

	public function test_site_urls_skip_unverified_entries(): void
	{
		$google = ['siteEntry' => [
			['siteUrl' => 'sc-domain:mine.test', 'permissionLevel' => 'siteOwner'],
			['siteUrl' => 'https://pending.test/', 'permissionLevel' => 'siteUnverifiedUser'],
			['siteUrl' => ['bad']],
		]];
		$bing   = ['d' => [
			['Url' => 'https://verified.test/', 'IsVerified' => true],
			['Url' => 'https://unverified.test/', 'IsVerified' => false],
		]];

		$this->assertSame(['sc-domain:mine.test'], \site_urls($google));
		$this->assertSame(['https://verified.test/'], \site_urls($bing));
		$this->assertSame([], \site_urls(['error' => ['code' => 403]]));
	}

	public function test_site_check_needs_the_search_console_scope_and_honours_the_kill_switch(): void
	{
		$google = ['required' => 'https://www.googleapis.com/auth/webmasters.readonly', 'sites' => 'https://invalid.invalid/'];

		$this->assertSame('missing_scope', \site_check($google, ['access_token' => 'x', 'scope' => 'openid email'], 'example.com', []));
		$this->assertSame('ok', \site_check($google, ['access_token' => 'x', 'scope' => 'openid'], 'example.com', ['verify_site_ownership' => false]));
	}

	public function test_flow_cookies_use_the_host_prefix(): void
	{
		// __Host- cookies can't be set by sibling subdomains or over plain HTTP.
		$this->assertStringStartsWith('__Host-', \COOKIE);
		$this->assertStringStartsWith('__Host-', \CONFIRM);
		$this->assertSame('/', \cookie_options(1)['path']);
		$this->assertTrue(\cookie_options(1)['secure']);
		$this->assertArrayNotHasKey('domain', \cookie_options(1));
	}

	public function test_optional_features_add_their_scope(): void
	{
		$providers = [
			'google' => ['scope' => 'openid email https://www.googleapis.com/auth/webmasters.readonly', 'features' => ['analytics' => 'https://www.googleapis.com/auth/analytics.readonly']],
			'bing'   => ['scope' => 'webmaster.manage'],
		];

		$this->assertSame(['analytics'], \requested_features($providers, 'google', 'analytics, evil'));
		$this->assertSame([], \requested_features($providers, 'google', ''));
		$this->assertSame([], \requested_features($providers, 'bing', 'analytics'));
		$this->assertSame(
			'openid email https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/analytics.readonly',
			\scope_for($providers, 'google', ['analytics'])
		);
		$this->assertSame('webmaster.manage', \scope_for($providers, 'bing', []));
	}
}
