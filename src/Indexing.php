<?php
/**
 * Shared indexing settings and submission rules.
 *
 * @package mihdan-index-now
 */

namespace Mihdan\IndexNow;

use Mihdan\IndexNow\Logger\Logger;
use Mihdan\IndexNow\SEOCore\FeatureGate\FeatureGate;
use Mihdan\IndexNow\SEOCore\MetaBox\MetaFields;
use Mihdan\IndexNow\SEOCore\TermSEO\TermFields;
use Mihdan\IndexNow\SEOCore\TitleMeta\Entities;
use Mihdan\IndexNow\SEOCore\TitleMeta\FrontendOutput;
use WP_Post;
use WP_Term;

/**
 * Single source of the indexing defaults, plus the rules every submission
 * path (save, comment, term, manual button, auto-index) applies.
 */
class Indexing
{
	/**
	 * Post/term meta holding the last successful submission (site local timestamp).
	 */
	public const LAST_UPDATE_META = 'crawlwp_last_update';

	/**
	 * Query var serving the IndexNow key file when pretty permalinks are off.
	 */
	public const KEY_QUERY_VAR = 'crawlwp_indexnow_key';

	/**
	 * Defaults of the indexing settings, keyed by section then field. The
	 * settings form and every runtime read use these, so a site that never
	 * saved the settings behaves exactly as the form shows.
	 */
	public const DEFAULTS = [
		'general'   => [
			'post_types'              => ['post' => 'post'],
			'taxonomies'              => ['category' => 'category'],
			'ping_on_post'            => 'on',
			'ping_on_post_updated'    => 'on',
			'ping_on_term'            => 'off',
			'ping_on_comment'         => 'on',
			'disable_for_bulk_edit'   => 'on',
			'show_last_update_column' => 'on',
			'ping_delay'              => 60,
		],
		'index_now' => [
			'enable'        => 'on',
			'search_engine' => 'bing-index-now',
		],
		'logs'      => [
			'enable'            => 'on',
			'key_logging'       => 'on',
			'outgoing_requests' => 'on',
			'lifetime'          => 7,
		],
	];

	/**
	 * Default of a setting.
	 *
	 * @return mixed
	 */
	public static function get_default(string $option, string $section)
	{
		return self::DEFAULTS[$section][$option] ?? '';
	}

	/**
	 * Read a setting, falling back to its shared default.
	 *
	 * @return mixed
	 */
	public static function get_option(string $option, string $section)
	{
		return Utils::wposa_get_option($option, $section, self::get_default($option, $section));
	}

	public static function is_on(string $option, string $section): bool
	{
		return self::get_option($option, $section) === 'on';
	}

	/**
	 * Post types enabled for submission, after the `crawlwp/post_types` filter.
	 * Read on demand so themes and late plugins can filter it.
	 *
	 * @return string[]
	 */
	public static function get_post_types(): array
	{
		return array_values(array_filter((array)apply_filters('crawlwp/post_types', array_filter((array)self::get_option('post_types', 'general')))));
	}

	/**
	 * Taxonomies enabled for submission, after the `crawlwp/taxonomies` filter.
	 *
	 * @return string[]
	 */
	public static function get_taxonomies(): array
	{
		return array_values(array_filter((array)apply_filters('crawlwp/taxonomies', array_filter((array)self::get_option('taxonomies', 'general')))));
	}

	public static function get_ping_delay(): int
	{
		return (int)self::get_option('ping_delay', 'general');
	}

	/**
	 * Whether a post is effectively noindex: the per-post robots value wins,
	 * otherwise the post type's "Hide from search results" default applies.
	 * Robots meta is only output while the SEO features are enabled.
	 */
	public static function is_post_noindex(WP_Post $post): bool
	{
		if ( ! FeatureGate::is_enabled()) {
			return false;
		}

		$value = (string)MetaFields::get($post->ID, MetaFields::ROBOTS_INDEX, '');

		if ($value !== '') {
			return $value === 'noindex';
		}

		return FrontendOutput::is_noindexed(Entities::post_type_key($post->post_type));
	}

	/**
	 * Whether a term archive is effectively noindex.
	 */
	public static function is_term_noindex(WP_Term $term): bool
	{
		if ( ! FeatureGate::is_enabled()) {
			return false;
		}

		$value = (string)TermFields::get($term->term_id, MetaFields::ROBOTS_INDEX, '');

		if ($value !== '') {
			return $value === 'noindex';
		}

		return FrontendOutput::is_noindexed(Entities::taxonomy_key($term->taxonomy));
	}

	/**
	 * Why a post must not be submitted for indexing; empty when it can be.
	 *
	 * @param WP_Post|int|null $post Post object or ID.
	 */
	public static function get_post_skip_reason($post): string
	{
		$post = get_post($post);

		if ( ! $post instanceof WP_Post) {
			$reason = __('Post not found.', 'mihdan-index-now');
		} elseif ($post->post_status !== 'publish') {
			$reason = __('Only published posts can be submitted for indexing.', 'mihdan-index-now');
		} elseif ( ! in_array($post->post_type, self::get_post_types(), true)) {
			$type_object = get_post_type_object($post->post_type);

			$reason = sprintf(
				/* translators: %s: post type name */
				__('"%s" is not selected under Indexing > General > Post Types, so it is not submitted to search engines.', 'mihdan-index-now'),
				$type_object ? $type_object->labels->name : $post->post_type
			);
		} elseif (function_exists('is_post_publicly_viewable') && ! is_post_publicly_viewable($post)) {
			$reason = __('This post is not publicly viewable, so it is not submitted to search engines.', 'mihdan-index-now');
		} elseif (self::is_post_noindex($post)) {
			$reason = __('This post is set to noindex (hidden from search results), so it is not submitted to search engines.', 'mihdan-index-now');
		} else {
			$reason = '';
		}

		/**
		 * Filter why a post is not submitted for indexing. Return an empty string to allow it.
		 *
		 * @param string       $reason Reason, empty when the post can be submitted.
		 * @param WP_Post|null $post   Post object.
		 */
		return (string)apply_filters('crawlwp/post_skip_reason', $reason, $post instanceof WP_Post ? $post : null);
	}

	/**
	 * Why a term must not be submitted for indexing; empty when it can be.
	 */
	public static function get_term_skip_reason(int $term_id, string $taxonomy): string
	{
		$term = get_term($term_id, $taxonomy);

		if ( ! $term instanceof WP_Term) {
			$reason = __('Term not found.', 'mihdan-index-now');
		} elseif ( ! in_array($taxonomy, self::get_taxonomies(), true)) {
			$reason = __('This taxonomy is not selected under Indexing > General > Taxonomies.', 'mihdan-index-now');
		} elseif (self::is_term_noindex($term)) {
			$reason = __('This term is set to noindex (hidden from search results), so it is not submitted to search engines.', 'mihdan-index-now');
		} else {
			$reason = '';
		}

		return (string)apply_filters('crawlwp/term_skip_reason', $reason, $term instanceof WP_Term ? $term : null);
	}

	/**
	 * Public URL of the IndexNow key file. Without pretty permalinks the
	 * `/{key}.txt` path never reaches WordPress, so a query URL is used.
	 */
	public static function get_key_location(string $api_key): string
	{
		$home = trailingslashit(Utils::normalized_home_url());

		if (get_option('permalink_structure')) {
			return $home . $api_key . '.txt';
		}

		return add_query_arg(self::KEY_QUERY_VAR, rawurlencode($api_key), $home);
	}

	/**
	 * Option names holding a submission pause (rate limit) expiry, with the service label.
	 *
	 * @return array<string,string>
	 */
	public static function get_pause_options(): array
	{
		$options = [
			'crawlwp_google_indexing_rate_limit_expiration' => __('Google Indexing API', 'mihdan-index-now'),
			'crawlwp_bing_indexing_rate_limit_expiration'   => __('Bing URL Submission API', 'mihdan-index-now'),
			'crawlwp_yandex_indexing_rate_limit_expiration' => __('Yandex Webmaster API', 'mihdan-index-now'),
		];

		$engines = [
			'bing-index-now'   => 'Bing',
			'index-now'        => 'IndexNow.org',
			'yandex-index-now' => 'Yandex',
			'seznam-index-now' => 'Seznam',
			'naver-index-now'  => 'Naver',
		];

		foreach ($engines as $slug => $label) {
			/* translators: %s: search engine name */
			$options[self::get_indexnow_pause_option($slug)] = sprintf(__('IndexNow (%s)', 'mihdan-index-now'), $label);
		}

		return $options;
	}

	public static function get_indexnow_pause_option(string $engine): string
	{
		return sprintf('crawlwp_indexnow_%s_rate_limit_expiration', $engine);
	}

	/**
	 * Timestamp until which a service is paused, 0 when it is not.
	 */
	public static function get_pause_expiry(string $option): int
	{
		$until = (int)get_option($option, 0);

		return $until > time() ? $until : 0;
	}

	/**
	 * Pause a service after it rate limited us. Honours a numeric Retry-After.
	 *
	 * @param array|\WP_Error $response HTTP response, when available.
	 */
	public static function pause(string $option, int $default_seconds, $response = null): void
	{
		$seconds = 0;

		if (is_array($response)) {
			$seconds = (int)wp_remote_retrieve_header($response, 'retry-after');
		}

		update_option($option, time() + ($seconds > 0 ? $seconds : $default_seconds), false);
	}

	/**
	 * Log submissions skipped because the service is paused.
	 *
	 * @param string[] $urls URLs that were not sent.
	 */
	public static function log_paused_skip(Logger $logger, string $search_engine, array $urls, int $until): void
	{
		foreach ($urls as $url) {
			$logger->warning(
				sprintf(
					/* translators: 1: URL, 2: date and time */
					__('%1$s - Skipped: submissions are paused after a rate limit response until %2$s.', 'mihdan-index-now'),
					self::url_link($url),
					wp_date(get_option('date_format') . ' ' . get_option('time_format'), $until)
				),
				[
					'status_code'   => 0,
					'search_engine' => $search_engine,
				]
			);
		}
	}

	/**
	 * Linked URL used to start submission log messages.
	 */
	public static function url_link(string $url, string $label = ''): string
	{
		return sprintf('<a href="%s" target="_blank">%s</a>', esc_url($url), esc_html($label !== '' ? $label : $url));
	}

	/**
	 * Show paused services on the plugin's admin pages.
	 */
	public static function pause_admin_notice(): void
	{
		if ( ! current_user_can('manage_options') || ! Utils::is_admin_page()) {
			return;
		}

		$format = get_option('date_format') . ' ' . get_option('time_format');
		$items  = [];

		foreach (self::get_pause_options() as $option => $label) {
			$until = self::get_pause_expiry($option);

			if ($until > 0) {
				/* translators: 1: service name, 2: date and time */
				$items[] = esc_html(sprintf(__('%1$s until %2$s', 'mihdan-index-now'), $label, wp_date($format, $until)));
			}
		}

		if (empty($items)) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p><ul style="list-style:disc;margin-left:20px"><li>%s</li></ul></div>',
			esc_html__('CrawlWP paused index submissions because a search engine responded with a rate limit (HTTP 429). Submissions made while paused are skipped and recorded in the Indexing Log:', 'mihdan-index-now'),
			implode('</li><li>', $items) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}
}
