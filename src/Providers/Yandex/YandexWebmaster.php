<?php
/**
 * Main class.
 *
 * @package mihdan-index-now
 */

namespace Mihdan\IndexNow\Providers\Yandex;

use Mihdan\IndexNow\Utils;
use Mihdan\IndexNow\WebmasterAbstract;

class YandexWebmaster extends WebmasterAbstract
{
	private const USER_ENDPOINT = 'https://api.webmaster.yandex.net/v4/user/';
	private const TOKEN_ENDPOINT = 'https://oauth.yandex.com/token';
	private const HOSTS_ENDPOINT = 'https://api.webmaster.yandex.net/v4/user/%d/hosts';
	private const RECRAWL_ENDPOINT = 'https://api.webmaster.yandex.net/v4/user/%s/hosts/%s/recrawl/queue';
	private const QUOTA_ENDPOINT = 'https://api.webmaster.yandex.net/v4/user/%s/hosts/%s/recrawl/quota';
	private const API_BASE_URL = 'https://api.webmaster.yandex.net/v4/user/%s/hosts/%s/';

	public function get_slug(): string
	{
		return 'yandex-webmaster';
	}

	public function get_name(): string
	{
		return __('Yandex Webmaster', 'mihdan-index-now');
	}

	public function get_token(): string
	{
		$token = $this->wposa->get_option('access_token', 'yandex_webmaster');
		$expires_in = (int)$this->wposa->get_option('expires_in', 'yandex_webmaster');

		if (empty($token) || ($expires_in > 0 && time() > $expires_in)) {
			$token = $this->refresh_api_token();
		}

		return (string)$token;
	}

	public function get_user_id(): string
	{
		return $this->wposa->get_option('user_id', 'yandex_webmaster');
	}

	public function get_host_id(): string
	{
		return $this->wposa->get_option('host_id', 'yandex_webmaster');
	}

	public function get_client_id(): string
	{
		return $this->wposa->get_option('client_id', 'yandex_webmaster');
	}

	public function get_client_secret(): string
	{
		return $this->wposa->get_option('client_secret', 'yandex_webmaster');
	}

	public function get_ping_endpoint(): string
	{
		return self::RECRAWL_ENDPOINT;
	}

	public function get_quota_endpoint(): string
	{
		return self::QUOTA_ENDPOINT;
	}

	public function get_api_base_url()
	{
		return sprintf(self::API_BASE_URL, $this->get_user_id(), $this->get_host_id());
	}

	public function is_enabled(): bool
	{
		return $this->wposa->get_option('enable', 'yandex_webmaster', 'off') === 'on';
	}

	public function setup_hooks()
	{
		add_action('admin_init', [$this, 'get_api_token']);
		add_action('admin_init', [$this, 'yandex_webmaster_find_website'], -1);

		if (!$this->is_enabled()) {
			return;
		}

		add_action('crawlwp/post_added', [$this, 'ping']);
		add_action('crawlwp/post_updated', [$this, 'ping']);
	}

	public function get_api_token()
	{
		if (isset($_GET['code'], $_GET['state']) && check_admin_referer('yandex_oauth_nonce', 'state') && current_user_can('manage_options')) {
			$data = [];
			$data['body'] = [
				'grant_type' => 'authorization_code',
				'code' => sanitize_text_field(wp_unslash($_GET['code'])),
				'client_id' => $this->get_client_id(),
				'client_secret' => $this->get_client_secret(),
			];

			$response = wp_remote_post(self::TOKEN_ENDPOINT, $data);
			$status_code = wp_remote_retrieve_response_code($response);
			$body = json_decode(wp_remote_retrieve_body($response), true);

			if ($status_code !== 200) {
				$this->logger->error($body['error_description'] ?? 'Unknown error', [
					'search_engine' => $this->get_slug(),
					'status_code' => $status_code
				]);

				return;
			}

			$this->wposa->set_option('access_token', $body['access_token'], 'yandex_webmaster');
			$this->wposa->set_option('refresh_token', $body['refresh_token'], 'yandex_webmaster');
			$this->wposa->set_option('expires_in', $body['expires_in'] + time(), 'yandex_webmaster');

			$user_id = $this->get_api_user_id($body['access_token']);

			if ($user_id) {
				$this->wposa->set_option('user_id', $user_id, 'yandex_webmaster');

				$host_ids = $this->get_api_host_id($user_id, $body['access_token']);

				if ($host_ids) {
					$this->wposa->set_option('host_ids', $host_ids, 'yandex_webmaster');
				}
			}

			wp_safe_redirect(CRAWLWP_API_SETTINGS_URL);
			exit;
		}
	}

	/**
	 * @return void
	 */
	public function yandex_webmaster_find_website()
	{
		if (empty($this->get_token())) return;

		$saved_host_id = $this->get_host_id();

		if (!empty($saved_host_id) && empty(Utils::_POST_var('submit_' . Utils::get_plugin_prefix() . '_yandex_webmaster'))) return;

		if (!empty(Utils::_POST_var('submit_' . Utils::get_plugin_prefix() . '_yandex_webmaster'))) {
			delete_option('crawlwp_yandex_find_website_request_error');
		}

		if (time() < (int)get_option('crawlwp_yandex_find_website_request_error', 0)) return;

		$user_id = $this->get_api_user_id($this->get_token());

		if ($user_id) {

			$host_ids = $this->get_api_host_id($user_id, $this->get_token());

			if ($host_ids) {

				$this->wposa->set_option('host_ids', $host_ids, 'yandex_webmaster');

				$home_url = Utils::normalized_home_url();

				$is_https = strstr($home_url, 'https') !== false;
				$parse = parse_url($home_url);
				$website_domain = str_replace('www.', '', $parse["host"] ?? $home_url);

				$detected_https_host_id = false;
				$detected_http_host_id = false;

				foreach ($host_ids as $host_id) {

					if ($is_https && strstr($host_id, 'https') && strstr($host_id, $website_domain)) {
						$detected_https_host_id = $host_id;
						break;
					}

					if (strstr($host_id, $website_domain)) {
						$detected_http_host_id = $host_id;
						break;
					}
				}

				$detected_host_id = !empty($detected_https_host_id) ? $detected_https_host_id : (!empty($detected_http_host_id) ? $detected_http_host_id : '');

				if (!empty($detected_host_id)) {
					$this->wposa->set_option('host_id', $detected_host_id, 'yandex_webmaster');
					return;
				}
			}
		}

		update_option('crawlwp_yandex_find_website_request_error', time() + (10 * MINUTE_IN_SECONDS));
	}

	/**
	 * Refresh API token.
	 *
	 * @return string
	 */
	public function refresh_api_token(): string
	{
		$refresh_token = $this->wposa->get_option('refresh_token', 'yandex_webmaster');

		if (empty($refresh_token)) return '';

		$data = [];
		$data['body'] = [
			'grant_type' => 'refresh_token',
			'refresh_token' => $refresh_token,
			'client_id' => $this->get_client_id(),
			'client_secret' => $this->get_client_secret(),
		];

		$response = wp_remote_post(self::TOKEN_ENDPOINT, $data);
		$status_code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if ($status_code !== 200) {
			$this->logger->error($body['error_description'] ?? 'Token refresh failed', [
				'search_engine' => $this->get_slug(),
				'status_code' => $status_code
			]);

			return '';
		}

		$this->wposa->set_option('access_token', $body['access_token'], 'yandex_webmaster');
		$this->wposa->set_option('refresh_token', $body['refresh_token'], 'yandex_webmaster');
		$this->wposa->set_option('expires_in', $body['expires_in'] + time(), 'yandex_webmaster');

		return $body['access_token'];
	}

	/**
	 * Get user ID.
	 *
	 * @param string $token Access token.
	 *
	 * @return int
	 */
	public function get_api_user_id(string $token): int
	{
		$args = [
			'headers' => [
				'Authorization' => 'OAuth ' . $token,
				'Content-Type' => 'application/json',
			],
			'timeout' => 60,
		];

		$response = wp_remote_get(self::USER_ENDPOINT, $args);
		$status_code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if ($status_code !== 200) {
			$this->logger->error($body['error_message'] ?? 'Unknown error', [
				'search_engine' => $this->get_slug(),
				'status_code' => $status_code
			]);

			return 0;
		}

		return (int)($body['user_id'] ?? 0);
	}

	/**
	 * Get user ID.
	 *
	 * @param int $user_id User ID.
	 * @param string $token Access token.
	 *
	 * @return array
	 */
	public function get_api_host_id(int $user_id, string $token): array
	{
		$args = [
			'headers' => [
				'Authorization' => 'OAuth ' . $token,
				'Content-Type' => 'application/json',
			],
			'timeout' => 60,
		];

		$response = wp_remote_get(sprintf(self::HOSTS_ENDPOINT, $user_id), $args);
		$status_code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if ($status_code !== 200) {
			$this->logger->error($body['error_message'] ?? 'Unknown error', [
				'search_engine' => $this->get_slug(),
				'status_code' => $status_code
			]);

			return [];
		}

		return is_array($body['hosts'] ?? null)
			? wp_list_pluck($body['hosts'], 'host_id')
			: [];
	}

	/**
	 * Yandex Webmaster ping.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @link https://yandex.com/dev/webmaster/doc/dg/reference/host-recrawl-post.html
	 */
	public function ping(int $post_id)
	{
		$token = $this->get_token();

		$user_id = $this->get_user_id();

		$host_id = $this->get_host_id();

		if (empty($token) || empty($user_id) || empty($host_id)) return;

		if (time() < (int)get_option('crawlwp_yandex_indexing_rate_limit_expiration', 0)) return;

		$url = sprintf($this->get_ping_endpoint(), $this->get_user_id(), $this->get_host_id());

		$post_url = Utils::normalized_get_permalink($post_id);

		$args = [
			'timeout' => 60,
			'headers' => [
				'Authorization' => 'OAuth ' . $token,
				'Content-Type' => 'application/json',
			],
			'body' => wp_json_encode(
				[
					'url' => $post_url,
				]
			),
		];

		$response = wp_remote_post($url, $args);
		$status_code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		$data = [
			'status_code' => $status_code,
			'search_engine' => $this->get_slug(),
		];

		if ($status_code >= 400 && $status_code < 500) {
			update_option('crawlwp_yandex_indexing_rate_limit_expiration', time() + (6 * HOUR_IN_SECONDS));
		}

		if (Utils::is_response_code_success($status_code)) {
			$message = sprintf('<a href="%s" target="_blank">%s</a> - OK', $post_url, get_the_title($post_id));
			$this->logger->info($message, $data);
		} else {
			$this->logger->error($body['error_message'] ?? 'Unknown error', $data);
		}

		do_action('crawlwp/index_pinged', 'post', $post_id);
	}

	public function get_quota(): array
	{
		$token = $this->get_token();

		if (empty($token)) {
			return [
				'daily_quota' => 0,
				'quota_remainder' => 0,
			];
		}

		$url = sprintf($this->get_quota_endpoint(), $this->get_user_id(), $this->get_host_id());

		$args = [
			'timeout' => 60,
			'headers' => [
				'Authorization' => 'OAuth ' . $token,
				'Content-Type' => 'application/json'
			],
		];

		$response = wp_remote_get($url, $args);
		$status_code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		$data = [
			'status_code' => $status_code,
			'search_engine' => $this->get_slug(),
		];

		if (Utils::is_response_code_success($status_code)) {
			$message = 'Data on daily limit successfully received';
			$this->logger->info($message, $data);

			return is_array($body) ? $body : [];
		} else {
			$this->logger->error($body['error_message'] ?? 'Unknown error', $data);

			return [
				'daily_quota' => 0,
				'quota_remainder' => 0,
			];
		}
	}
}
