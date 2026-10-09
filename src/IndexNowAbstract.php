<?php
/**
 * Main class.
 *
 * @package mihdan-index-now
 */

namespace Mihdan\IndexNow;

use Mihdan\IndexNow\Logger\Logger;
use Mihdan\IndexNow\Views\WPOSA;
use WP;
use WP_Post;

abstract class IndexNowAbstract implements SearchEngineInterface
{
	/**
	 * API key.
	 *
	 * @var string $api_key
	 */
	private $api_key;

	/**
	 * Logger instance.
	 *
	 * @var Logger $logger
	 */
	private $logger;

	/**
	 * WPOSA instance.
	 *
	 * @var WPOSA $wposa
	 */
	private $wposa;

	/**
	 * IndexNowAbstract constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct(Logger $logger, WPOSA $wposa)
	{
		$this->logger     = $logger;
		$this->wposa      = $wposa;
		$this->api_key    = $this->get_or_create_api_key();
	}

	/**
	 * Return the persisted API key, generating and saving it once when missing,
	 * so the key shown in settings, sent to the API and served in {key}.txt agree.
	 *
	 * @return string
	 */
	private function get_or_create_api_key(): string
	{
		$api_key = (string)$this->wposa->get_option('api_key', 'index_now', '');

		if ($api_key === '') {
			$api_key = Utils::generate_key();
			$this->wposa->set_option('api_key', $api_key, 'index_now');

			if ($this->wposa->get_option('search_engine', 'index_now', '') === '') {
				$this->wposa->set_option('search_engine', Indexing::get_default('search_engine', 'index_now'), 'index_now');
			}
		}

		return $api_key;
	}

	public function setup_hooks()
	{
		if ( ! $this->is_enabled()) {
			return false;
		}

		add_action('parse_request', [$this, 'set_virtual_key_file']);
		add_action('crawlwp/post_added', [$this, 'ping_on_post_update'], 10, 2);
		add_action('crawlwp/post_updated', [$this, 'ping_on_post_update'], 10, 2);
		add_action('crawlwp/post_deleted', [$this, 'ping_on_post_delete'], 10, 2);
		add_action('crawlwp/comment_updated', [$this, 'ping_on_comment_update'], 10, 2);

		if ($this->is_ping_on_term()) {
			add_action('crawlwp/term_updated', [$this, 'ping_on_insert_term'], 10, 2);
		}
	}

	abstract protected function get_api_url(): string;

	abstract protected function get_bot_useragent(): string;

	public function is_enabled(): bool
	{
		return $this->wposa->get_option('enable', 'index_now', Indexing::get_default('enable', 'index_now')) === 'on';
	}

	private function is_ping_on_term(): bool
	{
		return $this->wposa->get_option('ping_on_term', 'general', Indexing::get_default('ping_on_term', 'general')) === 'on';
	}

	private function is_key_logging_enabled(): bool
	{
		return $this->wposa->get_option('key_logging', 'logs', Indexing::get_default('key_logging', 'logs')) === 'on';
	}

	private function get_current_search_engine(): string
	{
		return (string)$this->wposa->get_option('search_engine', 'index_now', Indexing::get_default('search_engine', 'index_now'));
	}

	private function rate_limit_db_key()
	{
		return Indexing::get_indexnow_pause_option($this->get_current_search_engine());
	}

	/**
	 * Fires actions related to the transitioning of a post's status.
	 *
	 * @param int $post_id Post ID.
	 * @param WP_Post $post Post data.
	 *
	 * @link https://yandex.com/dev/webmaster/doc/dg/reference/host-recrawl-post.html
	 */
	public function ping_on_post_update(int $post_id, WP_Post $post)
	{
		$this->maybe_do_ping_post($post_id);
	}

	/**
	 * Ping the URL of a post that has been unpublished, trashed or deleted.
	 * IndexNow treats deletions as a regular URL submission.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $permalink Permalink captured before the post was removed.
	 */
	public function ping_on_post_delete(int $post_id, string $permalink)
	{
		if ($permalink === '' || $this->get_current_search_engine() !== $this->get_slug()) {
			return;
		}

		// Removals must not move the "last submitted" dates forward, so they get their own action.
		if ($this->push([$permalink])) {
			do_action('crawlwp/index_removal_pinged', 'post', $post_id, $permalink);
		}
	}

	/**
	 * Ping the parent post permalink when a comment gets approved.
	 *
	 * @param int         $post_id Parent post ID.
	 * @param \WP_Comment $comment Comment object.
	 */
	public function ping_on_comment_update(int $post_id, $comment = null)
	{
		if ($comment instanceof \WP_Comment && (string)$comment->comment_approved !== '1') {
			return;
		}

		$this->maybe_do_ping_post($post_id);
	}

	public function ping_on_insert_term(int $term_id, string $taxonomy)
	{
		$this->maybe_do_ping_term($term_id, $taxonomy);
	}

	private function maybe_do_ping_post(int $post_id)
	{
		if ($this->get_current_search_engine() !== $this->get_slug()) {
			return;
		}

		// Post type (filtered), status and effective noindex apply to every submission path.
		if (Indexing::get_post_skip_reason($post_id) !== '') {
			return;
		}

		if ($this->push([Utils::normalized_get_permalink($post_id)])) {
			do_action('crawlwp/index_pinged', 'post', $post_id);
		}
	}

	private function maybe_do_ping_term(int $term_id, string $taxonomy)
	{
		if ($this->get_current_search_engine() !== $this->get_slug()) {
			return;
		}

		if (Indexing::get_term_skip_reason($term_id, $taxonomy) !== '') {
			return;
		}

		if ($this->push([Utils::normalized_get_term_link($term_id, $taxonomy)])) {
			do_action('crawlwp/index_pinged', 'taxonomy', $term_id);
		}
	}

	/**
	 * Get host name.
	 *
	 * @return string
	 */
	private function get_host()
	{
		return apply_filters('crawlwp/host', wp_parse_url(Utils::normalized_home_url(), PHP_URL_HOST));
	}

	/**
	 * Submit URLs to the IndexNow endpoint.
	 *
	 * @param string[] $url_list URLs.
	 *
	 * @return bool True only when the engine accepted the URLs.
	 */
	public function push(array $url_list): bool
	{
		$paused_until = Indexing::get_pause_expiry($this->rate_limit_db_key());

		if ($paused_until > 0) {
			Indexing::log_paused_skip($this->logger, $this->get_current_search_engine(), $url_list, $paused_until);

			return false;
		}

		$args = [
			'timeout' => 30,
			'body'    => wp_json_encode(
				[
					'host'        => $this->get_host(),
					'key'         => $this->get_api_key(),
					'keyLocation' => $this->get_api_key_location(),
					'urlList'     => $url_list,
				]
			),
			'headers' => [
				'Content-Type' => 'application/json',
			],
		];

		$response = wp_remote_post($this->get_api_url(), $args);

		$data = [
			'status_code'   => 0,
			'search_engine' => $this->get_current_search_engine(),
		];

		if (is_wp_error($response)) {
			$this->log_failure($url_list, $response->get_error_message(), $data);

			return false;
		}

		$body             = wp_remote_retrieve_body($response);
		$status_code      = (int)wp_remote_retrieve_response_code($response);
		$response_message = wp_remote_retrieve_response_message($response);

		if (Utils::is_json($body)) {
			$body = json_decode($body, true);
		}

		$data['status_code'] = $status_code;

		// Only a rate limit pauses submissions; other 4xx (bad key, invalid URL…) are per-request failures.
		if ($status_code === 429) {
			Indexing::pause($this->rate_limit_db_key(), 3 * HOUR_IN_SECONDS, $response);
		}

		if (Utils::is_response_code_success($status_code)) {
			foreach ($url_list as $url) {
				$this->logger->info(Indexing::url_link($url) . ' - OK', $data);
			}

			return true;
		}

		if ( ! empty($body['message'])) {
			$message = $body['message'];
		} elseif ( ! empty($body) && is_array($body)) {
			$message = print_r($body, true); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		} elseif ( ! empty($response_message)) {
			$message = $response_message;
		} else {
			$message = is_string($body) ? substr(trim(wp_strip_all_tags($body)), 0, 300) : '';
		}

		$this->log_failure($url_list, (string)$message, $data);

		return false;
	}

	/**
	 * Log a failed submission, naming the URLs it concerned.
	 *
	 * @param string[] $url_list URLs.
	 * @param string   $message  Error message.
	 * @param array    $data     Log context.
	 */
	private function log_failure(array $url_list, string $message, array $data): void
	{
		$links = implode(', ', array_map([Indexing::class, 'url_link'], $url_list));

		$this->logger->error(trim($links . ' - ' . $message, ' -'), $data);
	}

	/**
	 * Get API key.
	 *
	 * @return string
	 */
	private function get_api_key()
	{
		return $this->api_key;
	}

	/**
	 * Get virtual API key location.
	 *
	 * @return string
	 */
	private function get_api_key_location(): string
	{
		return Indexing::get_key_location($this->get_api_key());
	}

	/**
	 * Set virtual key file.
	 *
	 * @param WP $wp WP instance.
	 */
	public function set_virtual_key_file(WP $wp)
	{
		$api_key = $this->get_api_key();

		if (get_option('permalink_structure')) {
			$is_key_request = $wp->request === $api_key . '.txt';
		} else {
			// Pretty paths never reach WordPress here; the key is served from a query URL instead.
			$is_key_request = isset($_GET[Indexing::KEY_QUERY_VAR]) && sanitize_text_field(wp_unslash($_GET[Indexing::KEY_QUERY_VAR])) === $api_key; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( ! $is_key_request) {
			return;
		}

		// Exclude key file from cache.
		if ( ! defined('DONOTCACHEPAGE')) {
			define('DONOTCACHEPAGE', true);
		}

		if ($this->is_key_logging_enabled()) {
			$data = [
				'search_engine' => $this->get_current_search_engine(),
				'direction'     => 'incoming',
			];

			$this->logger->info(__('Bot checked the key file', 'mihdan-index-now'), $data);
		}

		header('Content-Type: text/plain');
		header('X-Robots-Tag: noindex');
		status_header(200);
		echo esc_html($api_key);
		die;
	}
}
