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
	private const URL_DELETED = 'URL_DELETED';
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
		add_action('crawlwp/post_deleted', [$this, 'ping_deleted'], 10, 2);
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

		$status_code = $this->notify($post_url, self::URL_UPDATED, $message);

		$this->log_submission($post_url, get_the_title($post_id), $status_code, $message);

		if (Utils::is_response_code_success($status_code)) {
			do_action('crawlwp/index_pinged', 'post', $post_id);
		}
	}

	/**
	 * Tell Google a post's URL was removed: the post is in the trash or deleted.
	 *
	 * Unpublished posts (draft, pending, private) are not reported: switching a post
	 * to draft to edit it is common, and Google would drop the page until it is
	 * crawled again. Checked when the submission is sent, so a post restored from
	 * the trash in the meantime is not reported either.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $permalink Permalink captured before the post was removed.
	 */
	public function ping_deleted(int $post_id, string $permalink)
	{
		if ($permalink === '' || empty($this->get_token())) return;

		$post = get_post($post_id);

		// A post still being deleted (before_delete_post, when sent straight away) counts as removed.
		if ($post instanceof \WP_Post && $post->post_status !== 'trash' && ! doing_action('before_delete_post')) return;

		if ($this->is_paused(self::RATE_LIMIT_OPTION, $permalink)) return;

		$status_code = $this->notify($permalink, self::URL_DELETED, $message);

		/* translators: %s: URL */
		$this->log_submission($permalink, sprintf(__('%s (removed)', 'mihdan-index-now'), $permalink), $status_code, $message);

		// Removals must not move the "last submitted" dates forward, so they get their own action.
		if (Utils::is_response_code_success($status_code)) {
			do_action('crawlwp/index_removal_pinged', 'post', $post_id, $permalink);
		}
	}

	/**
	 * Send one Indexing API notification. Pauses Google submissions after a rate limit.
	 *
	 * @param string      $url     URL.
	 * @param string      $type    URL_UPDATED or URL_DELETED.
	 * @param string|null $message Set to Google's error message, if any.
	 *
	 * @return int HTTP status code, 0 when nothing was accepted.
	 */
	private function notify(string $url, string $type, ?string &$message = null): int
	{
		$status_code   = 0;
		$message       = '';
		$is_rate_limit = false;

		try {
			$client = new Client();
			$client->setApplicationName(Utils::get_plugin_name());
			$client->setAuthConfig(json_decode($this->get_token(), true));
			$client->addScope(Indexing::INDEXING);
			$client->setUseBatch(true);

			$body = new UrlNotification();
			$body->setType($type);
			$body->setUrl($url);

			$service = new Indexing($client);
			$batch   = $service->createBatch();
			$batch->add($service->urlNotifications->publish($body), 'url-0');

			foreach ($batch->execute() as $result) {
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

		$message = (string)$message;

		if ($is_rate_limit) {
			update_option(self::RATE_LIMIT_OPTION, time() + (6 * HOUR_IN_SECONDS), false);
		}

		return $status_code;
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
