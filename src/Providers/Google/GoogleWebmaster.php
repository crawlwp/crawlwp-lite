<?php

namespace Mihdan\IndexNow\Providers\Google;


use Mihdan\IndexNow\WebmasterAbstract;
use Mihdan\IndexNow\Utils;
use Mihdan\IndexNow\Dependencies\Google\Service\Indexing;
use Mihdan\IndexNow\Indexing as IndexingRules;
use Mihdan\IndexNow\Dependencies\Google\Service\Indexing\UrlNotification;
use Mihdan\IndexNow\Dependencies\Google\Client;
use Mihdan\IndexNow\Dependencies\Google\Service\Exception as Google_Service_Exception;

class GoogleWebmaster extends WebmasterAbstract
{
	private const URL_UPDATED = 'URL_UPDATED';
	private const RECRAWL_ENDPOINT = '';
	private const RATE_LIMIT_OPTION = 'crawlwp_google_indexing_rate_limit_expiration';

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

		if (IndexingRules::get_post_skip_reason($post_id) !== '') return;

		$post_url = Utils::normalized_get_permalink($post_id);

		if ($this->is_paused(self::RATE_LIMIT_OPTION, $post_url)) return;

		$status_code   = 0;
		$message       = '';
		$is_rate_limit = false;

		try {
			$client = new Client();
			$client->setApplicationName(Utils::get_plugin_name());
			$client->setAuthConfig(json_decode($token, true));
			$client->addScope(Indexing::INDEXING);
			$client->setUseBatch(true);

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
					$status_code   = (int)$result->getCode();
					$message       = $result->getErrors()[0]['message'] ?? $result->getMessage();
					$is_rate_limit = $this->is_rate_limit_error($result);
				} else {
					$status_code = 200;
				}
				break;
			}

		} catch (Google_Service_Exception $e) {
			$status_code   = (int)$e->getCode();
			$message       = $e->getErrors()[0]['message'] ?? $e->getMessage();
			$is_rate_limit = $this->is_rate_limit_error($e);
		} catch (\Throwable $e) {
			// Invalid JSON key, network failure… nothing was accepted, but it is not a rate limit.
			$message = $e->getMessage();
		}

		if ($is_rate_limit) {
			update_option(self::RATE_LIMIT_OPTION, time() + (6 * HOUR_IN_SECONDS), false);
		}

		$this->log_submission($post_url, get_the_title($post_id), $status_code, (string)$message);

		if (Utils::is_response_code_success($status_code)) {
			do_action('crawlwp/index_pinged', 'post', $post_id);
		}
	}

	/**
	 * Whether Google rejected the call because of the quota / rate limit.
	 */
	private function is_rate_limit_error(Google_Service_Exception $e): bool
	{
		if ((int)$e->getCode() === 429) {
			return true;
		}

		foreach ((array)$e->getErrors() as $error) {
			if (in_array($error['reason'] ?? '', ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded', 'RESOURCE_EXHAUSTED'], true)) {
				return true;
			}
		}

		return false;
	}

	public function get_quota(): array
	{
		// TODO: Implement get_quota() method.
		return [];
	}
}
