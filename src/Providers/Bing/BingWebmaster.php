<?php

namespace Mihdan\IndexNow\Providers\Bing;

use Mihdan\IndexNow\WebmasterAbstract;
use Mihdan\IndexNow\Utils;

class BingWebmaster extends WebmasterAbstract
{
	private const RECRAWL_ENDPOINT = 'https://ssl.bing.com/webmaster/api.svc/json/SubmitUrlbatch?apikey=%s';

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

		if (time() < (int)get_option('crawlwp_bing_indexing_rate_limit_expiration', 0)) return;

		$url = sprintf($this->get_ping_endpoint(), $token);

		$post_url = Utils::normalized_get_permalink($post_id);

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

		$response    = wp_remote_post($url, $args);
		$status_code = wp_remote_retrieve_response_code($response);
		$body        = json_decode(wp_remote_retrieve_body($response), true);

		if ($status_code >= 400 && $status_code < 500) {
			update_option('crawlwp_bing_indexing_rate_limit_expiration', time() + (6 * HOUR_IN_SECONDS));
		}

		$data = [
			'status_code'   => $status_code,
			'search_engine' => $this->get_slug(),
		];

		if (Utils::is_response_code_success($status_code)) {
			$message = sprintf('<a href="%s" target="_blank">%s</a> - OK', $post_url, get_the_title($post_id));
			$this->logger->info($message, $data);
		} else {
			$this->logger->error($body['Message'] ?? '', $data);
		}

		do_action('crawlwp/index_pinged', 'post', $post_id);
	}

	public function get_quota(): array
	{
		// TODO: Implement get_quota() method.
		return [];
	}
}
