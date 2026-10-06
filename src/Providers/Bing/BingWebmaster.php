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
}
