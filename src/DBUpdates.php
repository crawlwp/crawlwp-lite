<?php

namespace Mihdan\IndexNow;

class DBUpdates
{
	public static $instance;

	const DB_VER = 3;

	public function init_options()
	{
		add_option('crawlwp_lite_db_ver', 0);
	}

	public function maybe_update()
	{
		$this->init_options();

		if (get_option('crawlwp_lite_db_ver', 0) >= self::DB_VER) {
			return;
		}

		$this->update();
	}

	public function update()
	{
		// no PHP timeout for running updates
		if (function_exists('set_time_limit') && false === strpos(ini_get('disable_functions'), 'set_time_limit') && !ini_get('safe_mode')) {
			@set_time_limit(0);
		}

		// this is the current database schema version number
		$current_db_ver = get_option('crawlwp_lite_db_ver');

		// this is the target version that we need to reach
		$target_db_ver = self::DB_VER;

		// run update routines one by one until the current version number
		// reaches the target version number
		while ($current_db_ver < $target_db_ver) {
			// increment the current db_ver by one
			$current_db_ver++;

			// each db version will require a separate update function
			$update_method = "update_routine_{$current_db_ver}";

			if (method_exists($this, $update_method)) {
				call_user_func(array($this, $update_method));
			}
		}

		// update the option in the database, so that this process can always
		// pick up where it left off
		update_option('crawlwp_lite_db_ver', $current_db_ver);
	}

	protected function update_routine_1()
	{
		global $wpdb;

		delete_option('mihdan_index_now_version');

		$options_map = [
			'mihdan_index_now_general' => 'crawlwp_general',
			'mihdan_index_now_index_now' => 'crawlwp_index_now',
			'mihdan_index_now_bing_webmaster' => 'crawlwp_bing_webmaster',
			'mihdan_index_now_google_webmaster' => 'crawlwp_google_webmaster',
			'mihdan_index_now_yandex_webmaster' => 'crawlwp_yandex_webmaster',
			'mihdan_index_now_logs' => 'crawlwp_logs',
			'mihdan_index_now_webmaster_tools' => 'crawlwp_webmaster_tools',
			'mihdan_index_now_site_verification' => 'crawlwp_site_verification',
			'mihdan_index_now_email_reports' => 'crawlwp_email_reports',
		];

		foreach ($options_map as $old_option_name => $new_option_name) {

			$new_option = get_option($new_option_name, '');

			if (!empty($new_option)) {
				continue;
			}

			$old_option = get_option($old_option_name, '');

			if (empty($old_option)) {
				continue;
			}

			update_option($new_option_name, $old_option);
		}

		if (is_multisite()) {
			$sites = get_sites(['fields' => 'ids']);

			foreach ($sites as $site_id) {
				switch_to_blog($site_id);
				$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}index_now_log"); // phpcs:ignore
				restore_current_blog();
			}
		} else {
			$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}index_now_log"); // phpcs:ignore
		}

		return true;
	}

	public function update_routine_2()
	{
		global $wpdb;

		$table = $wpdb->prefix . 'crawlwp_redirects';

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			from_url varchar(2048) NOT NULL DEFAULT '',
			to_url varchar(2048) NOT NULL DEFAULT '',
			redirect_type smallint(4) NOT NULL DEFAULT 301,
			match_type varchar(20) NOT NULL DEFAULT 'exact',
			note text NOT NULL DEFAULT '',
			ignore_query_string tinyint(1) NOT NULL DEFAULT 1,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			priority smallint(6) NOT NULL DEFAULT 10,
			allow_external tinyint(1) NOT NULL DEFAULT 0,
			hits bigint(20) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			last_accessed datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY from_url (from_url(255)),
			KEY enabled (enabled),
			KEY priority (priority)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta($sql);
	}

	public function update_routine_3()
	{
		global $wpdb;

		$log_404 = $wpdb->prefix . 'crawlwp_404_log';

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$log_404} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			url varchar(2048) NOT NULL DEFAULT '',
			referer varchar(2048) NOT NULL DEFAULT '',
			hits bigint(20) NOT NULL DEFAULT 1,
			last_seen datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY url_unique (url(191))
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta($sql);
	}

	public static function get_instance()
	{
		if (!isset(self::$instance)) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}
