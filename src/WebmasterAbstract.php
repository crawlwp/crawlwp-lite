<?php
/**
 * Main class.
 *
 * @package mihdan-index-now
 */

namespace Mihdan\IndexNow;

use Mihdan\IndexNow\Logger\Logger;
use Mihdan\IndexNow\Views\WPOSA;

abstract class WebmasterAbstract implements SearchEngineInterface
{
	/**
	 * Logger instance.
	 *
	 * @var Logger $logger
	 */
	protected $logger;

	/**
	 * WPOSA instance.
	 *
	 * @var WPOSA $wposa
	 */
	protected $wposa;

	abstract public function get_token(): string;

	abstract public function get_ping_endpoint(): string;

	abstract public function get_quota(): array;

	abstract public function ping(int $post_id);

	/**
	 * WebmasterAbstract constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct(Logger $logger, WPOSA $wposa)
	{
		$this->logger = $logger;
		$this->wposa  = $wposa;
	}

	public function is_connected()
	{
		return ! empty($this->get_token());
	}

	/**
	 * Whether submissions to this service are paused; logs the skipped URL when so.
	 */
	protected function is_paused(string $option, string $url): bool
	{
		$until = Indexing::get_pause_expiry($option);

		if ($until > 0) {
			Indexing::log_paused_skip($this->logger, $this->get_slug(), [$url], $until);

			return true;
		}

		return false;
	}

	/**
	 * Log the outcome of a submission, naming the URL it concerned.
	 */
	protected function log_submission(string $url, string $title, int $status_code, string $error = ''): void
	{
		$data = [
			'status_code'   => $status_code,
			'search_engine' => $this->get_slug(),
		];

		if (Utils::is_response_code_success($status_code)) {
			$this->logger->info(Indexing::url_link($url, $title) . ' - OK', $data);

			return;
		}

		if ($error === '') {
			$error = __('Unknown error', 'mihdan-index-now');
		}

		$this->logger->error(Indexing::url_link($url, $title) . ' - ' . $error, $data);
	}

	/**
	 * Submit one URL by hand (Settings > Submit URLs), logged like automatic submissions.
	 *
	 * @param string $url     URL on this site.
	 * @param bool   $removed True when the page was removed.
	 *
	 * @return array{status: int, message: string}
	 */
	public function submit_url(string $url, bool $removed = false): array
	{
		if ( ! $this->is_connected()) {
			return ['status' => 0, 'message' => __('Not connected.', 'mihdan-index-now')];
		}

		if ($this->is_paused($this->rate_limit_option(), $url)) {
			return ['status' => 0, 'message' => __('Paused after reaching the daily limit. Try again later.', 'mihdan-index-now')];
		}

		[$status, $message] = $this->send_url($url, $removed);

		$post_id = url_to_postid($url);
		$title   = $post_id ? get_the_title($post_id) : $url;

		$this->log_submission($url, $removed ? sprintf(/* translators: %s: page title or URL */ __('%s (removed)', 'mihdan-index-now'), $title) : $title, $status, $message);

		if ($post_id && ! $removed && Utils::is_response_code_success($status)) {
			do_action('crawlwp/index_pinged', 'post', $post_id);
		}

		return ['status' => $status, 'message' => $message];
	}

	/**
	 * Send one URL to the service.
	 *
	 * @return array{0: int, 1: string} HTTP status code (0 when nothing was accepted) and error message.
	 */
	protected function send_url(string $url, bool $removed): array
	{
		return [0, __('Not supported.', 'mihdan-index-now')];
	}

	/**
	 * Option holding the time submissions are paused until after a rate limit.
	 */
	protected function rate_limit_option(): string
	{
		return '';
	}
}
