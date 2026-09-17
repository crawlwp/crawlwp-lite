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
 * Remove all plugin data for the current blog.
 */
function crawlwp_lite_mo_uninstall_function()
{
	global $wpdb;

	// Scheduled events.
	$cron_hooks = [
		'mihdan-index-now__clear-log',
		'crawlwp_video_sitemap_backfill',
		'crawlwp_backfill_robots_index_meta',
		'crawlwp_redirects_flush_hits',
		'crawlwp_404_prune',
	];

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

	foreach ($drop_tables as $sql) {
		$wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	$options = [
		'crawlwp_general',
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
	];

	foreach ($patterns as $pattern) {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$pattern
			)
		);
	}

	// Post, term and user meta written by the plugin (see SEOCore\MetaBox\MetaFields).
	$meta_tables = [
		$wpdb->postmeta,
		$wpdb->termmeta,
		$wpdb->usermeta,
	];

	$meta_like = $wpdb->esc_like('_crawlwp_') . '%';

	foreach ($meta_tables as $meta_table) {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$meta_table} WHERE meta_key LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$meta_like
			)
		);
	}

	wp_cache_flush();
}

if ( ! is_multisite()) {
	crawlwp_lite_mo_uninstall_function();
} elseif ( ! wp_is_large_network()) {

	$site_ids = get_sites(['fields' => 'ids', 'number' => 0]);

	foreach ($site_ids as $site_id) {
		switch_to_blog($site_id);
		crawlwp_lite_mo_uninstall_function();
		restore_current_blog();
	}

	// Background process batches are stored as network options on multisite.
	global $wpdb;

	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
			'%crawlwp_bg_process%'
		)
	);

	wp_cache_flush();
}
