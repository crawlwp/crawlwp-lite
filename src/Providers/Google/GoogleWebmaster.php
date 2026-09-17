<?php

namespace Mihdan\IndexNow\Providers\Google;


use Mihdan\IndexNow\WebmasterAbstract;
use Mihdan\IndexNow\Utils;
use Mihdan\IndexNow\Dependencies\Google\Service\Indexing;
use Mihdan\IndexNow\Dependencies\Google\Service\Indexing\UrlNotification;
use Mihdan\IndexNow\Dependencies\Google\Client;
use Mihdan\IndexNow\Dependencies\Google\Service\Exception as Google_Service_Exception;
use Exception;

class GoogleWebmaster extends WebmasterAbstract
{
	private const URL_UPDATED = 'URL_UPDATED';
	private const RECRAWL_ENDPOINT = '';

	public function get_ping_endpoint(): string
	{
		return self::RECRAWL_ENDPOINT;
	}

	public function get_slug(): string
	{
		return 'google-webmaster';
	}

	public function get_name(): string
	{
		return __('Google Webmaster', 'mihdan-index-now');
	}

	public function get_token(): string
	{
		return $this->wposa->get_option('json_key', 'google_webmaster');
	}

	public function is_enabled(): bool
	{
		return $this->wposa->get_option('enable', 'google_webmaster', 'off') === 'on';
	}

	public function setup_hooks()
	{
		if ( ! $this->is_enabled()) {
			return;
		}

		add_action('crawlwp/post_added', [$this, 'ping']);
		add_action('crawlwp/post_updated', [$this, 'ping']);
	}

	/**
	 * Google Webmaster ping.
	 *
	 * @param int $post_id Post ID.
	 *
	 * throws \Google\Exception
	 */
	public function ping(int $post_id)
	{
		$token = $this->get_token();

		if (empty($token)) return;

		if (time() < (int)get_option('crawlwp_google_indexing_rate_limit_expiration', 0)) return;

		$status_code = 0;
		$message     = '';

		try {
			$client = new Client();
			$client->setApplicationName(Utils::get_plugin_name());
			$client->setAuthConfig(json_decode($token, true));
			$client->addScope(Indexing::INDEXING);
			$client->setUseBatch(true);

			$post_url = Utils::normalized_get_permalink($post_id);

			$body = new UrlNotification();
			$urls = [$post_url];

			$service = new Indexing($client);
			$batch   = $service->createBatch();

			foreach ($urls as $i => $url) {
				$body->setType(self::URL_UPDATED);
				$body->setUrl($url);

				$batch->add($service->urlNotifications->publish($body), 'url-' . $i);
			}

			$results = $batch->execute();

			foreach ($results as $result) {
				if ($result instanceof Google_Service_Exception) {
					$status_code = (int)$result->getCode();
					$message     = $result->getErrors()[0]['message'] ?? $result->getMessage();
				} else {
					$status_code = 200;
					$message     = sprintf('<a href="%s" target="_blank">%s</a> - OK', $post_url, get_the_title($post_id));
				}
				break;
			}

		} catch (Exception $e) {
			$message     = $e->getMessage();
			$status_code = 400;
		}

		$data = [
			'status_code'   => $status_code,
			'search_engine' => $this->get_slug(),
		];

		if ($status_code >= 400 && $status_code < 500) {
			update_option('crawlwp_google_indexing_rate_limit_expiration', time() + (6 * HOUR_IN_SECONDS));
		}

		if (Utils::is_response_code_success($status_code)) {
			$this->logger->info($message, $data);
		} else {
			$this->logger->error($message, $data);
		}

		do_action('crawlwp/index_pinged', 'post', $post_id);
	}

	public function get_quota(): array
	{
		// TODO: Implement get_quota() method.
		return [];
	}
}
