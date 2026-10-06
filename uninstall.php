<?php
/**
 * Uninstall routine.
 *
 * Intentionally does NOT include the main plugin file (which boots the plugin);
 * only WordPress core functions and $wpdb are used here.
 *
 * @package mihdan-index-now
 */

namespace Mihdan\IndexNow;

if ( ! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

/**
 * Check whether complete data removal on uninstall is enabled.
 */
function crawlwp_lite_is_uninstall_enabled()
{
	$advanced = get_option('crawlwp_advanced', []);

	return is_array($advanced)
		&& ! empty($advanced['remove_plugin_data'])
		&& in_array($advanced['remove_plugin_data'], ['on', 'yes', 'true', true], true);
}

/**
 * Whether CrawlWP Premium is still installed. Its options and table are then
 * left to its own uninstall routine.
 */
function crawlwp_lite_is_pro_installed()
{
	return file_exists(WP_PLUGIN_DIR . '/mihdan-index-now-pro/mihdan-index-now-pro.php');
}

/**
 * Options owned by CrawlWP Premium: exact names and LIKE prefixes.
 */
function crawlwp_lite_pro_owned_options()
{
	return [
		'names'    => [
			'crawlwp_auto_index',
			'crawlwp_auto_index_stat',
			'crawlwp_plc_queued_data',
			'crawlwp_email_reports',
			'crawlwp_email_reports_sent',
			'crawlwp_internal_links',
			'crawlwp_google_inspect_url_limit_expiration',
			'crawlwp_bing_inspect_url_limit_expiration',
			'crawlwp_yandex_inspect_url_limit_expiration',
			'crawlwp_google_find_website_request_error',
			'crawlwp_install_date',
			'crawlwp_plugin_activated',
			'crawlwp_after_activation_flag',
			'crawlwp_db_ver',
			'crawlwp_html_sitemap_cache_keys',
			'crawlwp_video_sitemap_backfill_offset',
			'crawlwp_video_sitemap_backfilled',
			'mihdan_index_now_license',
			// PAnD dismissals of Premium's notices.
			'pand-' . md5('crawlwp-license-expired-notice'),
			'pand-' . md5('crawlwp_catch_late_event_notice'),
		],
		'prefixes' => [
			'crawlwp_pro_',
			'crawlwp_license',
		],
	];
}

/**
 * Remove all plugin data for the current blog.
 *
 * @param bool $force Whether to force uninstall without re-checking the option.
 */
function crawlwp_lite_mo_uninstall_function($force = false)
{
	if ( ! $force && ! crawlwp_lite_is_uninstall_enabled()) {
		return;
	}

	global $wpdb;

	// Scheduled events.
	$keep_pro = crawlwp_lite_is_pro_installed();

	$cron_hooks = [
		'mihdan-index-now__clear-log',
		'crawlwp_delayed_post_ping',
		'crawlwp_delayed_term_ping',
		'crawlwp_backfill_robots_index_meta',
		'crawlwp_redirects_flush_hits',
		'crawlwp_404_prune',
	];

	if ( ! $keep_pro) {
		$cron_hooks[] = 'crawlwp_video_sitemap_backfill';
	}

	foreach ($cron_hooks as $cron_hook) {
		wp_clear_scheduled_hook($cron_hook);
	}

	// Background process healthcheck crons (see BackgroundProcess\Setup and WP_Background_Process).
	$bg_identifier = 'wp_' . get_current_blog_id() . '_crawlwp_bg_process';
	wp_clear_scheduled_hook($bg_identifier . '_cron');
	wp_clear_scheduled_hook($bg_identifier . '_cron_custom_healthcheck');

	// Custom tables.
	$drop_tables = [
		"DROP TABLE IF EXISTS {$wpdb->prefix}crawlwp_log",
		"DROP TABLE IF EXISTS {$wpdb->prefix}crawlwp_redirects",
		"DROP TABLE IF EXISTS {$wpdb->prefix}crawlwp_404_log",
		"DROP TABLE IF EXISTS {$wpdb->prefix}index_now_log", // Legacy.
	];

	if ( ! $keep_pro) {
		// Orphaned Premium table (Premium is no longer installed to remove it).
		$drop_tables[] = "DROP TABLE IF EXISTS {$wpdb->prefix}crawlwp_autoindex";
	}

	foreach ($drop_tables as $sql) {
		$wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	$options = [
		'crawlwp_general',
		'crawlwp_advanced',
		'crawlwp_index_now',
		'crawlwp_bing_webmaster',
		'crawlwp_google_webmaster',
		'crawlwp_yandex_webmaster',
		'crawlwp_logs',
		'crawlwp_version',
		'crawlwp_lite_db_ver',
		'crawlwp_google_indexing_rate_limit_expiration',
		'crawlwp_bing_indexing_rate_limit_expiration',
		'crawlwp_yandex_indexing_rate_limit_expiration',
		'crawlwp_yandex_find_website_request_error',
	];

	foreach ($options as $option) {
		delete_option($option);
	}

	// Ensure leftovers (options and transients) are deleted.
	$patterns = [
		'crawlwp%',
		'mihdan_index_now%',
		'_transient_crawlwp%',
		'_transient_timeout_crawlwp%',
		'_transient_mihdan-index-now%',
		'_transient_timeout_mihdan-index-now%',
		'_site_transient_crawlwp%',
		'_site_transient_timeout_crawlwp%',
		// Background process batches, status and lock (stored as site options/transients).
		'%' . $wpdb->esc_like('crawlwp_bg_process') . '%',
	];

	$exclude_sql = '';

	if ($keep_pro) {
		$pro_options = crawlwp_lite_pro_owned_options();

		foreach ($pro_options['names'] as $name) {
			$exclude_sql .= $wpdb->prepare(' AND option_name <> %s', $name);
		}

		foreach ($pro_options['prefixes'] as $prefix) {
			$exclude_sql .= $wpdb->prepare(' AND option_name NOT LIKE %s', $wpdb->esc_like($prefix) . '%');
		}
	} else {
		foreach (crawlwp_lite_pro_owned_options()['names'] as $name) {
			delete_option($name);
		}
	}

	foreach ($patterns as $pattern) {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$pattern
			) . $exclude_sql // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	// Post, term, user and comment meta written by the plugin (see SEOCore\MetaBox\MetaFields),
	// plus the unprefixed "last submission" meta (Indexing::LAST_UPDATE_META).
	$meta_tables = [
		$wpdb->postmeta,
		$wpdb->termmeta,
		$wpdb->usermeta,
		$wpdb->commentmeta,
	];

	$meta_like = $wpdb->esc_like('_crawlwp_') . '%';

	foreach ($meta_tables as $meta_table) {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$meta_table} WHERE meta_key LIKE %s OR meta_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$meta_like,
				'crawlwp_last_update'
			)
		);
	}

	wp_cache_flush();
}

if ( ! is_multisite()) {
	crawlwp_lite_mo_uninstall_function();
} else {

	$network_enabled = crawlwp_lite_is_uninstall_enabled();
	$offset          = 0;

	// Paged so large networks (over 10,000 sites) are cleaned too.
	do {
		$site_ids = get_sites(['fields' => 'ids', 'number' => 100, 'offset' => $offset]);

		foreach ($site_ids as $site_id) {
			switch_to_blog($site_id);
			crawlwp_lite_mo_uninstall_function($network_enabled);
			restore_current_blog();
		}

		$offset += 100;
	} while (count($site_ids) === 100);

	if ($network_enabled) {
		// Background process batches are stored as network options on multisite.
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
				'%crawlwp_bg_process%'
			)
		);

		// PAnD dismissals are network options on multisite.
		if ( ! crawlwp_lite_is_pro_installed()) {
			foreach (crawlwp_lite_pro_owned_options()['names'] as $name) {
				if (strpos($name, 'pand-') === 0) {
					delete_site_option($name);
				}
			}
		}

		wp_cache_flush();
	}
}
