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
}
