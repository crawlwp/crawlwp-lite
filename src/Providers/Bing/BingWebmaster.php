<?php

namespace Mihdan\IndexNow\Providers\Bing;

use Mihdan\IndexNow\Indexing;
use Mihdan\IndexNow\WebmasterAbstract;
use Mihdan\IndexNow\Utils;

class BingWebmaster extends WebmasterAbstract
{
	private const RECRAWL_ENDPOINT = 'https://ssl.bing.com/webmaster/api.svc/json/SubmitUrlbatch?apikey=%s';
	private const RATE_LIMIT_OPTION = 'crawlwp_bing_indexing_rate_limit_expiration';

	public function get_ping_endpoint(): string
	{
		return self::RECRAWL_ENDPOINT;
	}

	public function get_slug(): string
	{
		return 'bing-webmaster';
	}

	public function get_name(): string
	{
		return __('Bing Webmaster', 'mihdan-index-now');
	}

	public function get_token(): string
	{
		return $this->wposa->get_option('api_key', 'bing_webmaster');
	}

	public function is_enabled(): bool
	{
		return $this->wposa->get_option('enable', 'bing_webmaster', 'off') === 'on';
	}

	public function setup_hooks()
	{
		if ( ! $this->is_enabled()) return;

		add_action('crawlwp/post_added', [$this, 'ping']);
		add_action('crawlwp/post_updated', [$this, 'ping']);
	}

	/**
	 * Bing Webmaster ping.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @link https://www.bing.com/webmasters/url-submission-api#APIs
	 */
	public function ping(int $post_id)
	{
		$token = $this->get_token();

		if (empty($token)) return;

		if (Indexing::get_post_skip_reason($post_id) !== '') return;

		$post_url = Utils::normalized_get_permalink($post_id);

		if ($this->is_paused(self::RATE_LIMIT_OPTION, $post_url)) return;

		$url = sprintf($this->get_ping_endpoint(), $token);

		$args = array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode(
				[
					'siteUrl' => Utils::normalized_home_url(),
					'urlList' => [
						$post_url,
					],
				]
			),
		);

		$response = wp_remote_post($url, $args);

		if (is_wp_error($response)) {
			$this->log_submission($post_url, get_the_title($post_id), 0, $response->get_error_message());

			return;
		}

		$status_code = (int)wp_remote_retrieve_response_code($response);
		$body        = json_decode(wp_remote_retrieve_body($response), true);
		$error       = is_array($body) ? (string)($body['Message'] ?? '') : '';

		if ($error === '' && ! Utils::is_response_code_success($status_code)) {
			$error = (string)wp_remote_retrieve_response_message($response);
		}

		// Bing reports an exhausted daily quota as a 400 with a quota message or a throttle error code.
		if (
			$status_code === 429 ||
			in_array((int)($body['ErrorCode'] ?? 0), [4, 5], true) ||
			stripos($error, 'quota') !== false
		) {
			update_option(self::RATE_LIMIT_OPTION, time() + (6 * HOUR_IN_SECONDS), false);
		}

		$this->log_submission($post_url, get_the_title($post_id), $status_code, $error);

		if (Utils::is_response_code_success($status_code)) {
			do_action('crawlwp/index_pinged', 'post', $post_id);
		}
	}

	public function get_quota(): array
	{
		// TODO: Implement get_quota() method.
		return [];
	}

	protected function rate_limit_option(): string
	{
		return self::RATE_LIMIT_OPTION;
	}

	protected function send_url(string $url, bool $removed): array
	{
		// Bing's URL submission has no "removed"; removed pages reach Bing through IndexNow.
		if ($removed) return [0, __('Bing only accepts removed pages through IndexNow.', 'mihdan-index-now')];

		$request = $this->api_request('SubmitUrlbatch');

		if ($request === null) return [0, __('Not connected.', 'mihdan-index-now')];

		$response = wp_remote_post($request['url'], [
			'timeout' => 30,
			'headers' => ['Content-Type' => 'application/json'] + $request['headers'],
			'body'    => wp_json_encode(['siteUrl' => Utils::normalized_home_url(), 'urlList' => [$url]]),
		]);

		if (is_wp_error($response)) return [0, $response->get_error_message()];

		$status = (int)wp_remote_retrieve_response_code($response);
		$body   = json_decode(wp_remote_retrieve_body($response), true);
		$error  = is_array($body) ? (string)($body['Message'] ?? '') : '';

		if ($error === '' && ! Utils::is_response_code_success($status)) {
			$error = (string)wp_remote_retrieve_response_message($response);
		}

		// Same quota signals as ping().
		if ($status === 429 || in_array((int)($body['ErrorCode'] ?? 0), [4, 5], true) || stripos($error, 'quota') !== false) {
			update_option(self::RATE_LIMIT_OPTION, time() + (6 * HOUR_IN_SECONDS), false);
		}

		return [$status, $error];
	}

	/**
	 * URLs Bing still accepts today, or null when unknown. Cached for 10 minutes.
	 */
	public function remaining_today(): ?int
	{
		$cached = get_transient('crawlwp_bing_url_quota');

		if ($cached !== false) return $cached === '' ? null : (int)$cached;

		$remaining = null;
		$request   = $this->is_connected() ? $this->api_request('GetUrlSubmissionQuota') : null;

		if ($request !== null) {
			$response = wp_remote_get(add_query_arg('siteUrl', rawurlencode(Utils::normalized_home_url()), $request['url']), [
				'timeout' => 15,
				'headers' => $request['headers'],
			]);
			$body = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);

			if (isset($body['d']['DailyQuota'])) $remaining = (int)$body['d']['DailyQuota'];
		}

		set_transient('crawlwp_bing_url_quota', $remaining === null ? '' : (string)$remaining, 10 * MINUTE_IN_SECONDS);

		return $remaining;
	}

	/**
	 * URL and headers for a Bing Webmaster API method: the API key, or "Connect with Bing" when available.
	 *
	 * @return array{url: string, headers: array<string, string>}|null
	 */
	private function api_request(string $method): ?array
	{
		$oauth = '\\Mihdan\\IndexNow\\OAuth\\OAuth';

		if (class_exists($oauth) && method_exists($oauth, 'bing_request')) {
			return $oauth::bing_request($method);
		}

		$key = (string)$this->get_token();

		return $key === '' ? null : [
			'url'     => add_query_arg('apikey', rawurlencode($key), 'https://ssl.bing.com/webmaster/api.svc/json/' . $method),
			'headers' => [],
		];
	}
}
