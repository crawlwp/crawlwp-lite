<?php
/**
 * "Connect with Google / Bing" through the CrawlWP OAuth proxy.
 *
 * The proxy (oauth-proxy/ in this repo) holds CrawlWP's OAuth client secrets, so site owners
 * no longer need their own Google Cloud project or Bing API key. Tokens are stored here and
 * refreshed through the proxy.
 *
 * Google's Indexing API still needs a service account JSON key: a shared OAuth app would share
 * one Indexing API quota between every CrawlWP site. The OAuth connection covers Search Console
 * (search performance, URL inspection). Bing's OAuth token covers everything, URL submission included.
 *
 * @package mihdan-index-now
 */

namespace Mihdan\IndexNow\OAuth;

use Mihdan\IndexNow\Dependencies\Google\Client;
use Mihdan\IndexNow\Utils;

final class OAuth
{
	public const OPTION    = 'crawlwp_oauth';
	public const DROPPED   = 'crawlwp_oauth_dropped';
	public const PROVIDERS = ['google', 'bing'];

	private const BING_KEY_API   = 'https://ssl.bing.com/webmaster/api.svc/json/';
	private const BING_OAUTH_API = 'https://www.bing.com/webmaster/api.svc/json/';

	public static function setup_hooks(): void
	{
		add_action('admin_post_crawlwp_oauth_connect', [__CLASS__, 'handle_connect']);
		add_action('admin_post_crawlwp_oauth_return', [__CLASS__, 'handle_return']);
		add_action('admin_post_crawlwp_oauth_disconnect', [__CLASS__, 'handle_disconnect']);
		add_action('admin_post_crawlwp_oauth_dismiss', [__CLASS__, 'handle_dismiss']);
		add_action('admin_notices', [__CLASS__, 'dropped_notice']);
		add_action('admin_notices', [__CLASS__, 'admin_notice']);
	}

	public static function proxy_url(): string
	{
		$url = defined('CRAWLWP_OAUTH_PROXY_URL') ? CRAWLWP_OAUTH_PROXY_URL : 'https://auth.crawlwp.com/';

		return (string)apply_filters('crawlwp_oauth_proxy_url', $url);
	}

	/* ---------- connection state ---------- */

	public static function get_connection(string $provider): array
	{
		$all = get_option(self::OPTION, []);

		return is_array($all) && ! empty($all[$provider]['refresh_token']) ? $all[$provider] : [];
	}

	public static function is_connected(string $provider): bool
	{
		return self::get_connection($provider) !== [];
	}

	private static function save_connection(string $provider, ?array $data): void
	{
		$all = get_option(self::OPTION, []);
		$all = is_array($all) ? $all : [];

		if ($data === null) {
			unset($all[$provider]);
		} else {
			$all[$provider] = $data;
		}

		update_option(self::OPTION, $all, false);
	}

	/**
	 * A valid access token, refreshed through the proxy when it is about to expire. Empty when not connected.
	 */
	public static function get_access_token(string $provider): string
	{
		$conn = self::get_connection($provider);

		if ( ! $conn) {
			return '';
		}

		$current = (int)$conn['expires_at'] > time() ? (string)$conn['access_token'] : '';

		if ((int)$conn['expires_at'] > time() + 60) {
			return $current;
		}

		// After a failed refresh, wait before asking the proxy again so one page load can't hammer it.
		if (get_transient('crawlwp_oauth_backoff_' . $provider)) {
			return $current;
		}

		// Only one request refreshes at a time; the others keep using the token while it is still valid.
		if ( ! self::lock($provider)) {
			return $current;
		}

		try {
			return self::refresh($provider, $conn) ?? $current;
		} finally {
			delete_option('crawlwp_oauth_lock_' . $provider);
		}
	}

	/**
	 * @return string|null The new access token, '' when the connection was revoked, null on a temporary failure.
	 */
	private static function refresh(string $provider, array $conn): ?string
	{
		$response = wp_remote_post(add_query_arg('action', 'refresh', self::proxy_url()), [
			'timeout' => 20,
			'body'    => ['provider' => $provider, 'refresh_token' => $conn['refresh_token']],
		]);

		$body = json_decode((string)wp_remote_retrieve_body($response), true);

		if ( ! is_wp_error($response) && ! empty($body['access_token'])) {
			$conn['access_token'] = (string)$body['access_token'];
			$conn['expires_at']   = time() + (int)$body['expires_in'];

			if ( ! empty($body['refresh_token'])) {
				$conn['refresh_token'] = (string)$body['refresh_token'];
			}

			self::save_connection($provider, $conn);

			return $conn['access_token'];
		}

		// The user removed CrawlWP's access (or the app was removed): drop the dead connection and tell them.
		if (in_array($body['error'] ?? '', ['invalid_grant', 'unauthorized_client'], true)) {
			self::save_connection($provider, null);
			self::set_dropped($provider, true);
			do_action('crawlwp_oauth_disconnected', $provider);

			return '';
		}

		set_transient('crawlwp_oauth_backoff_' . $provider, 1, 5 * MINUTE_IN_SECONDS);

		return null;
	}

	/**
	 * Atomic lock: add_option() fails when the row already exists. A lock older than 30 seconds is stale.
	 */
	private static function lock(string $provider): bool
	{
		$name = 'crawlwp_oauth_lock_' . $provider;

		if (add_option($name, time(), '', false)) {
			return true;
		}

		if ((int)get_option($name) < time() - 30) {
			delete_option($name);

			return add_option($name, time(), '', false);
		}

		return false;
	}

	private static function set_dropped(string $provider, bool $dropped): void
	{
		$all = (array)get_option(self::DROPPED, []);

		if ($dropped) {
			$all[$provider] = time();
		} else {
			unset($all[$provider]);
		}

		$all ? update_option(self::DROPPED, $all, false) : delete_option(self::DROPPED);
	}

	/* ---------- helpers for API callers (free and Premium) ---------- */

	public static function google_json_key(): string
	{
		$options = get_option(Utils::get_plugin_prefix() . '_google_webmaster', []);

		return is_array($options) ? trim((string)($options['json_key'] ?? '')) : '';
	}

	/**
	 * Authenticate a Google client: the service account JSON key when present, otherwise the OAuth connection.
	 *
	 * @param bool $require_service_account True for Indexing API calls, which only accept a service account.
	 */
	public static function configure_google_client(Client $client, bool $require_service_account = false): bool
	{
		$json = self::google_json_key();

		if ($json !== '') {
			$client->setAuthConfig(json_decode($json, true));

			return true;
		}

		if ($require_service_account) {
			return false;
		}

		$token = self::get_access_token('google');

		if ($token === '') {
			return false;
		}

		$conn = self::get_connection('google');
		$client->setAccessToken([
			'access_token' => $token,
			'expires_in'   => max(1, (int)$conn['expires_at'] - time()),
			'created'      => time(),
		]);

		return true;
	}

	public static function bing_api_key(): string
	{
		$options = get_option(Utils::get_plugin_prefix() . '_bing_webmaster', []);

		return is_array($options) ? trim((string)($options['api_key'] ?? '')) : '';
	}

	/**
	 * URL and headers for a Bing Webmaster API method, using the API key when set, otherwise the OAuth token.
	 *
	 * @return array{url: string, headers: array<string, string>}|null Null when Bing is not connected.
	 */
	public static function bing_request(string $method): ?array
	{
		$key = self::bing_api_key();

		if ($key !== '') {
			return ['url' => add_query_arg('apikey', rawurlencode($key), self::BING_KEY_API . $method), 'headers' => []];
		}

		$token = self::get_access_token('bing');

		return $token === '' ? null : [
			'url'     => self::BING_OAUTH_API . $method,
			'headers' => ['Authorization' => 'Bearer ' . $token],
		];
	}

	/* ---------- connect / return / disconnect ---------- */

	/**
	 * @param string $return Admin URL to come back to afterwards. Defaults to API Settings.
	 */
	public static function connect_url(string $provider, string $return = ''): string
	{
		$url = admin_url('admin-post.php?action=crawlwp_oauth_connect&provider=' . $provider);

		if ($return !== '') {
			$url = add_query_arg('return', rawurlencode($return), $url);
		}

		return wp_nonce_url($url, 'crawlwp_oauth_' . $provider);
	}

	public static function disconnect_url(string $provider): string
	{
		return wp_nonce_url(admin_url('admin-post.php?action=crawlwp_oauth_disconnect&provider=' . $provider), 'crawlwp_oauth_' . $provider);
	}

	private static function request_provider(): string
	{
		$provider = sanitize_key(wp_unslash($_GET['provider'] ?? ''));

		if ( ! current_user_can('manage_options') || ! in_array($provider, self::PROVIDERS, true)) {
			wp_die(esc_html__('You are not allowed to do this.', 'mihdan-index-now'), 403);
		}

		return $provider;
	}

	public static function handle_connect(): void
	{
		$provider = self::request_provider();
		check_admin_referer('crawlwp_oauth_' . $provider);

		$state  = wp_generate_password(32, false);
		$return = wp_validate_redirect(esc_url_raw(wp_unslash($_GET['return'] ?? '')), '');

		set_transient(self::state_key($provider), ['state' => $state, 'provider' => $provider, 'return' => $return], 15 * MINUTE_IN_SECONDS);

		// Not wp_safe_redirect(): the proxy is an external host by design.
		wp_redirect(add_query_arg([
			'action'     => 'authorize',
			'provider'   => $provider,
			'state'      => $state,
			'return_url' => rawurlencode(admin_url('admin-post.php?action=crawlwp_oauth_return')),
		], self::proxy_url()));
		exit;
	}

	public static function handle_return(): void
	{
		$provider = self::request_provider();
		$pending  = get_transient(self::state_key($provider));
		delete_transient(self::state_key($provider));

		$error = sanitize_key(wp_unslash($_GET['crawlwp_oauth_error'] ?? ''));
		$label = self::label($provider);

		$back = is_array($pending) ? (string)($pending['return'] ?? '') : '';

		if ( ! is_array($pending) || $pending['provider'] !== $provider) {
			self::finish('error', __('The connection request expired. Please click Connect again.', 'mihdan-index-now'), $back);
		}

		if ($error !== '') {
			self::finish('error', $error === 'access_denied'
				/* translators: %s: Google or Bing */
				? sprintf(__('%s connection was cancelled.', 'mihdan-index-now'), $label)
				/* translators: 1: Google or Bing, 2: error code */
				: sprintf(__('%1$s connection failed (%2$s). Please try again.', 'mihdan-index-now'), $label, $error), $back);
		}

		$response = wp_remote_post(add_query_arg('action', 'fetch', self::proxy_url()), [
			'timeout' => 20,
			'body'    => [
				'handle' => sanitize_text_field(wp_unslash($_GET['crawlwp_oauth_handle'] ?? '')),
				'state'  => $pending['state'],
			],
		]);

		$data = json_decode((string)wp_remote_retrieve_body($response), true);

		if (is_wp_error($response) || empty($data['access_token']) || empty($data['refresh_token'])) {
			/* translators: %s: Google or Bing */
			self::finish('error', sprintf(__('%s connection failed: no access was granted. Please try again.', 'mihdan-index-now'), $label), $back);
		}

		self::set_dropped($provider, false);
		delete_transient('crawlwp_oauth_backoff_' . $provider);

		self::save_connection($provider, [
			'access_token'  => (string)$data['access_token'],
			'refresh_token' => (string)$data['refresh_token'],
			'expires_at'    => time() + (int)$data['expires_in'],
			'scope'         => sanitize_text_field((string)($data['scope'] ?? '')),
			'email'         => sanitize_email((string)($data['email'] ?? '')),
			'connected_at'  => time(),
		]);

		do_action('crawlwp_oauth_connected', $provider);

		/* translators: %s: Google or Bing */
		self::finish('success', sprintf(__('Connected to %s.', 'mihdan-index-now'), $label), $back);
	}

	public static function handle_disconnect(): void
	{
		$provider = self::request_provider();
		check_admin_referer('crawlwp_oauth_' . $provider);

		// Tokens are not revoked at Google/Bing: a revoke cancels the whole grant for the shared CrawlWP app,
		// which would also disconnect every other site connected with the same account.
		self::save_connection($provider, null);
		self::set_dropped($provider, false);
		do_action('crawlwp_oauth_disconnected', $provider);

		/* translators: %s: Google or Bing */
		self::finish('success', sprintf(__('Disconnected from %s.', 'mihdan-index-now'), self::label($provider)));
	}

	public static function handle_dismiss(): void
	{
		$provider = self::request_provider();
		check_admin_referer('crawlwp_oauth_' . $provider);

		self::set_dropped($provider, false);
		wp_safe_redirect(wp_get_referer() ?: admin_url());
		exit;
	}

	/**
	 * Tells admins that a connection stopped working because access was removed in the Google/Microsoft account.
	 */
	public static function dropped_notice(): void
	{
		if ( ! current_user_can('manage_options')) {
			return;
		}

		foreach (array_keys((array)get_option(self::DROPPED, [])) as $provider) {
			// Nothing stopped working when a key is set: the key was in use, not the connection.
			$has_key = $provider === 'google' ? self::google_json_key() !== '' : self::bing_api_key() !== '';

			if ( ! in_array($provider, self::PROVIDERS, true) || $has_key) {
				continue;
			}

			printf(
				'<div class="notice notice-error"><p>%s</p><p><a class="button button-primary" href="%s">%s</a> <a href="%s" style="margin-left:8px">%s</a></p></div>',
				/* translators: %s: Google or Bing */
				esc_html(sprintf(__('CrawlWP: your %1$s connection has stopped working because access was removed in your %1$s account. Reconnect to keep getting %1$s data.', 'mihdan-index-now'), self::label($provider))),
				esc_url(self::connect_url($provider)),
				/* translators: %s: Google or Bing */
				esc_html(sprintf(__('Reconnect %s', 'mihdan-index-now'), self::label($provider))),
				esc_url(wp_nonce_url(admin_url('admin-post.php?action=crawlwp_oauth_dismiss&provider=' . $provider), 'crawlwp_oauth_' . $provider)),
				esc_html__('Dismiss', 'mihdan-index-now')
			);
		}
	}

	/**
	 * @return never
	 */
	private static function finish(string $type, string $message, string $return = ''): void
	{
		set_transient(self::notice_key(), [$type, $message], MINUTE_IN_SECONDS * 5);
		wp_safe_redirect($return !== '' ? $return : CRAWLWP_API_SETTINGS_URL);
		exit;
	}

	public static function admin_notice(): void
	{
		$notice = get_transient(self::notice_key());

		if ( ! is_array($notice)) {
			return;
		}

		delete_transient(self::notice_key());
		printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($notice[0]), esc_html($notice[1]));
	}

	/* ---------- settings UI ---------- */

	/**
	 * Connection status and Connect/Disconnect button for the API Settings screen.
	 */
	public static function render_status(string $provider): string
	{
		$label   = self::label($provider);
		$conn    = self::get_connection($provider);
		$has_key = $provider === 'google' ? self::google_json_key() !== '' : self::bing_api_key() !== '';

		if ($has_key) {
			$key_note = '<p class="description"><strong>' . esc_html($provider === 'google'
					? __('In use: your service account JSON key. It takes priority over a Google account connection, so connecting is optional.', 'mihdan-index-now')
					: __('In use: your API key. It takes priority over a Bing account connection, so connecting is optional.', 'mihdan-index-now')
				) . '</strong></p>';
		} else {
			$key_note = '';
		}

		if ( ! $conn) {
			return $key_note . sprintf(
				'<p><a class="button button-primary" href="%s">%s</a></p><p class="description">%s</p>',
				esc_url(self::connect_url($provider)),
				/* translators: %s: Google or Bing */
				esc_html(sprintf(__('Connect with %s', 'mihdan-index-now'), $label)),
				$provider === 'google'
					? esc_html__('Sign in with the Google account that has access to this site in Google Search Console. No Google Cloud project is needed.', 'mihdan-index-now')
					: esc_html__('Sign in with the Microsoft account that has this site in Bing Webmaster Tools. No API key is needed.', 'mihdan-index-now')
			);
		}

		$who = $conn['email'] !== ''
			/* translators: 1: Google or Bing, 2: account email */
			? sprintf(__('Connected to %1$s as %2$s.', 'mihdan-index-now'), $label, '<strong>' . esc_html($conn['email']) . '</strong>')
			/* translators: %s: Google or Bing */
			: esc_html(sprintf(__('Connected to %s.', 'mihdan-index-now'), $label));

		return sprintf(
			'<p><span class="dashicons dashicons-yes-alt" style="color:#00a32a"></span> %s <a class="button" href="%s" style="margin-left:8px">%s</a></p>',
			$who,
			esc_url(self::disconnect_url($provider)),
			esc_html__('Disconnect', 'mihdan-index-now')
		) . $key_note;
	}

	private static function label(string $provider): string
	{
		return $provider === 'google' ? 'Google' : 'Bing';
	}

	private static function state_key(string $provider): string
	{
		return 'crawlwp_oauth_state_' . $provider . '_' . get_current_user_id();
	}

	private static function notice_key(): string
	{
		return 'crawlwp_oauth_notice_' . get_current_user_id();
	}
}
