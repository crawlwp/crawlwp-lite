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
	/** Each provider has its own option (crawlwp_oauth_google, crawlwp_oauth_bing) so saving one never overwrites the other. */
	public const OPTION_PREFIX = 'crawlwp_oauth_';
	public const DROPPED       = 'crawlwp_oauth_dropped';
	public const PROVIDERS     = ['google', 'bing'];

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

	/**
	 * @param bool $fresh Skip the object cache, e.g. after waiting for another request that may have changed it.
	 */
	public static function get_connection(string $provider, bool $fresh = false): array
	{
		if ($fresh) {
			wp_cache_delete(self::OPTION_PREFIX . $provider, 'options');
		}

		$conn = get_option(self::OPTION_PREFIX . $provider, []);

		return is_array($conn) && ! empty($conn['refresh_token']) ? $conn : [];
	}

	public static function is_connected(string $provider): bool
	{
		return self::get_connection($provider) !== [];
	}

	private static function save_connection(string $provider, ?array $data): void
	{
		$data === null ? delete_option(self::OPTION_PREFIX . $provider) : update_option(self::OPTION_PREFIX . $provider, $data, false);
	}

	/**
	 * A valid access token, refreshed through the proxy when it is about to expire. Empty when not connected
	 * or when the token has expired and can't be refreshed right now (see unavailable_reason()).
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
			// Another request may have refreshed, disconnected or reconnected while we waited for the lock.
			$conn = self::get_connection($provider, true);

			if ( ! $conn) {
				return '';
			}

			if ((int)$conn['expires_at'] > time() + 60) {
				return (string)$conn['access_token'];
			}

			return self::refresh($provider, $conn) ?? $current;
		} finally {
			self::unlock($provider);
		}
	}

	/**
	 * @return string|null The new access token, '' when the connection was revoked, null on a temporary failure.
	 */
	private static function refresh(string $provider, array $conn): ?string
	{
		$response = wp_remote_post(add_query_arg('action', 'refresh', self::proxy_url()), [
			'timeout' => 10,
			'body'    => ['provider' => $provider, 'refresh_token' => $conn['refresh_token']],
		]);

		$body  = json_decode((string)wp_remote_retrieve_body($response), true);
		$error = is_array($body) ? (string)($body['error'] ?? '') : '';

		if ( ! is_wp_error($response) && ! empty($body['access_token'])) {
			// Don't resurrect a connection that was disconnected (or replaced by a reconnect) during the request.
			$latest = self::get_connection($provider, true);

			if ( ! $latest || $latest['refresh_token'] !== $conn['refresh_token']) {
				return $latest ? (string)$latest['access_token'] : '';
			}

			$conn['access_token'] = (string)$body['access_token'];
			$conn['expires_at']   = time() + (int)$body['expires_in'];

			if ( ! empty($body['refresh_token'])) {
				$conn['refresh_token'] = (string)$body['refresh_token'];
			}

			self::save_connection($provider, $conn);

			return $conn['access_token'];
		}

		// invalid_grant: the user removed CrawlWP's access (or the token expired). Drop it and tell them.
		if ($error === 'invalid_grant') {
			self::save_connection($provider, null);
			self::set_dropped($provider, true);
			do_action('crawlwp_oauth_disconnected', $provider);

			return '';
		}

		// Anything else is temporary or a problem on CrawlWP's side (e.g. a misconfigured proxy returning
		// unauthorized_client/invalid_client): keep the connection and retry later, longer for config errors.
		$wait = in_array($error, ['unauthorized_client', 'invalid_client'], true) ? HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS;
		set_transient('crawlwp_oauth_backoff_' . $provider, 1, $wait);

		return null;
	}

	/**
	 * Why no access token is available, for logs and error messages.
	 */
	public static function unavailable_reason(string $provider): string
	{
		$label = self::label($provider);

		return self::is_connected($provider)
			/* translators: %s: Google or Bing */
			? sprintf(__('Could not refresh the %s connection right now. CrawlWP will retry in a few minutes.', 'mihdan-index-now'), $label)
			/* translators: %s: Google or Bing */
			: sprintf(__('%1$s is not connected. Connect %1$s under API Settings.', 'mihdan-index-now'), $label);
	}

	/**
	 * Truly atomic lock (INSERT IGNORE, as WP_Upgrader::create_lock does): add_option() checks then upserts,
	 * so two requests could both "win". A lock older than 30 seconds belongs to a request that died.
	 */
	private static function lock(string $provider): bool
	{
		global $wpdb;

		$name = 'crawlwp_oauth_lock_' . $provider;

		if ((int)$wpdb->query($wpdb->prepare("INSERT IGNORE INTO `$wpdb->options` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')", $name, time())) === 1) {
			return true;
		}

		$held = (int)$wpdb->get_var($wpdb->prepare("SELECT option_value FROM `$wpdb->options` WHERE option_name = %s", $name));

		// Compare-and-swap so only one request can take over a stale lock.
		return $held < time() - 30
			&& (int)$wpdb->query($wpdb->prepare("UPDATE `$wpdb->options` SET option_value = %s WHERE option_name = %s AND option_value = %s", time(), $name, $held)) === 1;
	}

	private static function unlock(string $provider): void
	{
		global $wpdb;

		$wpdb->query($wpdb->prepare("DELETE FROM `$wpdb->options` WHERE option_name = %s", 'crawlwp_oauth_lock_' . $provider));
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
		// Same filtered read as the rest of the plugin (wposa/get_option), so a key supplied by a filter still counts.
		return trim((string)Utils::wposa_get_option('json_key', 'google_webmaster'));
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
		return trim((string)Utils::wposa_get_option('api_key', 'bing_webmaster'));
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

		return wp_nonce_url($url, 'crawlwp_oauth_connect_' . $provider);
	}

	public static function disconnect_url(string $provider): string
	{
		return wp_nonce_url(admin_url('admin-post.php?action=crawlwp_oauth_disconnect&provider=' . $provider), 'crawlwp_oauth_disconnect_' . $provider);
	}

	public static function dismiss_url(string $provider): string
	{
		return wp_nonce_url(admin_url('admin-post.php?action=crawlwp_oauth_dismiss&provider=' . $provider), 'crawlwp_oauth_dismiss_' . $provider);
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
		check_admin_referer('crawlwp_oauth_connect_' . $provider);

		$state  = wp_generate_password(32, false);
		$return = wp_validate_redirect(esc_url_raw(wp_unslash($_GET['return'] ?? '')), '');

		set_transient(self::state_key($provider), ['state' => $state, 'provider' => $provider, 'return' => $return], 15 * MINUTE_IN_SECONDS);

		// Not wp_safe_redirect(): the proxy is an external host by design.
		wp_redirect(add_query_arg([
			'action'     => 'authorize',
			'provider'   => $provider,
			// only a hash travels through the browser; the state itself is sent server to server at fetch.
			'state'      => hash('sha256', $state),
			'return_url' => rawurlencode(admin_url('admin-post.php?action=crawlwp_oauth_return')),
		], self::proxy_url()));
		exit;
	}

	public static function handle_return(): void
	{
		$provider = self::request_provider();
		$pending  = get_transient(self::state_key($provider));
		$handle   = sanitize_text_field(wp_unslash($_GET['crawlwp_oauth_handle'] ?? ''));
		$error    = sanitize_key(wp_unslash($_GET['crawlwp_oauth_error'] ?? ''));

		// A bare visit (e.g. a link someone sent) must not cancel a connection in progress.
		if ($handle !== '' || $error !== '') {
			delete_transient(self::state_key($provider));
		}

		$label = self::label($provider);

		$back = is_array($pending) ? (string)($pending['return'] ?? '') : '';

		if ( ! is_array($pending) || $pending['provider'] !== $provider) {
			self::finish('error', __('The connection request expired. Please click Connect again.', 'mihdan-index-now'), $back);
		}

		if ($handle === '' && $error === '') {
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
				'handle' => $handle,
				'state'  => $pending['state'],
			],
		]);

		$data = json_decode((string)wp_remote_retrieve_body($response), true);

		if (is_wp_error($response) || empty($data['access_token']) || empty($data['refresh_token'])) {
			/* translators: %s: Google or Bing */
			self::finish('error', sprintf(__('%s connection failed: no access was granted. Please try again.', 'mihdan-index-now'), $label), $back);
		}

		// Google's consent screen lets people untick individual permissions: without Search Console access
		// the connection would look fine but every request would fail.
		$required = $provider === 'google' ? 'webmasters.readonly' : 'webmaster.manage';

		if ( ! str_contains((string)($data['scope'] ?? ''), $required)) {
			self::finish('error', $provider === 'google'
				? __('Google was connected without access to Search Console. Please connect again and allow CrawlWP to view your Search Console data.', 'mihdan-index-now')
				: __('Bing was connected without access to Bing Webmaster Tools. Please connect again and allow all requested permissions.', 'mihdan-index-now'), $back);
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
		check_admin_referer('crawlwp_oauth_disconnect_' . $provider);

		// Tokens are not revoked at Google/Bing: a revoke cancels the whole grant for the shared CrawlWP app,
		// which would also disconnect every other site connected with the same account. Instead the proxy is
		// told to refuse this refresh token, so a copy left in a database backup stops working.
		$conn = self::get_connection($provider, true);

		if ( ! empty($conn['refresh_token'])) {
			wp_remote_post(add_query_arg('action', 'revoke', self::proxy_url()), [
				'timeout' => 5,
				'body'    => ['provider' => $provider, 'refresh_token' => $conn['refresh_token']],
			]);
		}

		self::save_connection($provider, null);
		self::set_dropped($provider, false);
		do_action('crawlwp_oauth_disconnected', $provider);

		/* translators: %s: Google or Bing */
		self::finish('success', sprintf(__('Disconnected from %s.', 'mihdan-index-now'), self::label($provider)));
	}

	public static function handle_dismiss(): void
	{
		$provider = self::request_provider();
		check_admin_referer('crawlwp_oauth_dismiss_' . $provider);

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
				esc_url(self::dismiss_url($provider)),
				esc_html__('Dismiss', 'mihdan-index-now')
			);
		}
	}

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
			// With a key already in use, connecting is optional: a secondary button and no "no key needed" pitch.
			$button = sprintf(
				'<p><a class="button %s" href="%s">%s</a></p>',
				$has_key ? '' : 'button-primary',
				esc_url(self::connect_url($provider)),
				/* translators: %s: Google or Bing */
				esc_html(sprintf(__('Connect with %s', 'mihdan-index-now'), $label))
			);

			if ($has_key) {
				return $button . $key_note;
			}

			return $button . '<p class="description">' . ($provider === 'google'
					? esc_html__('Sign in with the Google account that has access to this site in Google Search Console. No Google Cloud project is needed.', 'mihdan-index-now')
					: esc_html__('Sign in with the Microsoft account that has this site in Bing Webmaster Tools. No API key is needed.', 'mihdan-index-now')
				) . '</p>';
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
