<?php

namespace Mihdan\IndexNow\BackgroundProcess;

use Mihdan\IndexNow\BackgroundProcess\Libs\WP_Background_Process;
use Mihdan\IndexNow\Utils;

class Setup extends WP_Background_Process
{
	protected $action = 'crawlwp_bg_process';

	protected $cron_interval = 1;

	protected $is_alternate_cron_runner = true;

	/**
	 * True while a batch is being handled in the current request.
	 *
	 * @var bool
	 */
	protected $is_handling = false;

	public function __construct()
	{
		// Uses unique prefix per blog so each blog has separate queue.
		$this->prefix = 'wp_' . get_current_blog_id();

		parent::__construct();

		add_filter($this->identifier . '_seconds_between_batches', function ($seconds) {
			return 1;
		});

		add_filter($this->identifier . '_wp_die', '__return_false');

		$this->custom_cron_healthcheck();
	}

	public function custom_cron_healthcheck()
	{
		add_filter('cron_schedules', function ($schedules) {
			$schedules['cwp_5min'] = [
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => 'Every 5 Minute'
			];

			return $schedules;
		});

		add_action('init', function () {
			if ( ! wp_next_scheduled($this->cron_hook_identifier . '_custom_healthcheck')) {
				wp_schedule_event(time(), 'cwp_5min', $this->cron_hook_identifier . '_custom_healthcheck');
			}
		});

		add_action($this->cron_hook_identifier . '_custom_healthcheck', [$this, 'maybe_handle']);
	}

	public function dispatch()
	{
		if (apply_filters('crawlwp_alternate_cron_runner', $this->is_alternate_cron_runner)) {

			// handle() calls dispatch() when the queue isn't empty. Running the next batch in the same
			// request recurses until max_execution_time is hit, so leave it to the healthcheck cron.
			if ($this->is_handling) {
				$this->schedule_event();

				return false;
			}

			return $this->maybe_handle();
		}

		// Perform remote post.
		return parent::dispatch();
	}

	public function maybe_handle()
	{
		// Don't lock up other requests while processing.
		session_write_close();

		if ($this->is_processing()) {
			// Background process already running.
			return $this->maybe_wp_die();
		}

		if ($this->is_cancelled()) {
			$this->clear_scheduled_event();
			$this->delete_all();

			return $this->maybe_wp_die();
		}

		if ($this->is_paused()) {
			$this->clear_scheduled_event();
			$this->paused();

			return $this->maybe_wp_die();
		}

		if ($this->is_queue_empty()) {
			// No data to process.
			return $this->maybe_wp_die();
		}

		if ( ! $this->is_alternate_cron_runner) {
			check_ajax_referer($this->identifier, 'nonce');
		}

		$this->is_handling = true;

		try {
			$this->handle();
		} finally {
			$this->is_handling = false;
		}

		return $this->maybe_wp_die();
	}

	protected function task($item)
	{
		if ( ! defined('CRAWLWP_BACKGROUND_PROCESS_TASK')) {
			define('CRAWLWP_BACKGROUND_PROCESS_TASK', 'true');
		}

		$action = Utils::_var($item, 'action', '');

		do_action('crawlwp/bg_process_task', $action, $item, $this);

		return false;
	}

	/**
	 * Get count of batches.
	 *
	 * @return string|null
	 */
	public function get_batches_count()
	{
		global $wpdb;

		$table      = $wpdb->options;
		$column     = 'option_name';
		$key_column = 'option_id';

		if (is_multisite()) {
			$table      = $wpdb->sitemeta;
			$column     = 'meta_key';
			$key_column = 'meta_id';
		}

		$key = $wpdb->esc_like($this->identifier . '_batch_') . '%';

		$sql = '
			SELECT COUNT(*)
			FROM ' . $table . '
			WHERE ' . $column . ' LIKE %s
			ORDER BY ' . $key_column . ' ASC
			';

		$args = array($key);

		return $wpdb->get_var($wpdb->prepare($sql, $args));
	}
}
