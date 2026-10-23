<?php
/**
 * Noindex settings of other SEO plugins.
 *
 * @package mihdan-index-now
 */

namespace Mihdan\IndexNow;

use WP_Post;

/**
 * Reads whether another active SEO plugin hides a post from search results, so
 * CrawlWP does not submit it for indexing. Covers sites that use CrawlWP for
 * indexing next to Yoast SEO, Rank Math, All in One SEO, SEOPress, The SEO
 * Framework or Slim SEO.
 *
 * Each plugin is only checked while it is active, because only then does it
 * output its robots meta tag. A per-post choice wins; otherwise the plugin's
 * default for the post type applies.
 */
final class ThirdPartyRobots
{
	/**
	 * Name of the active SEO plugin that sets the post to noindex, or empty.
	 */
	public static function post_noindex_source(WP_Post $post): string
	{
		$checks = [
			'Yoast SEO'          => [defined('WPSEO_VERSION'), 'yoast'],
			'Rank Math'          => [defined('RANK_MATH_VERSION'), 'rank_math'],
			'All in One SEO'     => [defined('AIOSEO_VERSION'), 'aioseo'],
			'SEOPress'           => [defined('SEOPRESS_VERSION'), 'seopress'],
			'The SEO Framework'  => [defined('THE_SEO_FRAMEWORK_VERSION'), 'the_seo_framework'],
			'Slim SEO'           => [defined('SLIM_SEO_VER'), 'slim_seo'],
		];

		$source = '';

		foreach ($checks as $label => [$active, $method]) {
			if ($active && self::$method($post)) {
				$source = $label;
				break;
			}
		}

		/**
		 * Filter the SEO plugin that sets a post to noindex. Return a plugin name
		 * to skip the post, or an empty string to submit it.
		 *
		 * @param string  $source Plugin name, empty when no plugin sets noindex.
		 * @param WP_Post $post   Post object.
		 */
		return (string)apply_filters('crawlwp/third_party_post_noindex', $source, $post);
	}

	private static function yoast(WP_Post $post): bool
	{
		// 1 = noindex, 2 = index, empty = post type default.
		$value = (string)get_post_meta($post->ID, '_yoast_wpseo_meta-robots-noindex', true);

		if ($value === '1' || $value === '2') {
			return $value === '1';
		}

		$titles = get_option('wpseo_titles');

		return is_array($titles) && ! empty($titles['noindex-' . $post->post_type]);
	}

	private static function rank_math(WP_Post $post): bool
	{
		$robots = get_post_meta($post->ID, 'rank_math_robots', true);

		if (is_array($robots) && $robots !== []) {
			return in_array('noindex', $robots, true);
		}

		$titles = get_option('rank-math-options-titles');

		if ( ! is_array($titles)) {
			return false;
		}

		$prefix = 'pt_' . $post->post_type;

		// Rank Math only uses the post type's robots when its custom robots switch is on.
		$robots = ($titles[$prefix . '_custom_robots'] ?? '') === 'on'
			? ($titles[$prefix . '_robots'] ?? [])
			: ($titles['robots_global'] ?? []);

		return is_array($robots) && in_array('noindex', $robots, true);
	}

	private static function aioseo(WP_Post $post): bool
	{
		global $wpdb;

		$table = $wpdb->prefix . 'aioseo_posts';

		$suppress = $wpdb->suppress_errors(true);
		$row      = $wpdb->get_row($wpdb->prepare("SELECT robots_default, robots_noindex FROM {$table} WHERE post_id = %d", $post->ID), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->suppress_errors($suppress);

		// The per-post robots columns only apply when "use default settings" is off.
		if (is_array($row) && empty($row['robots_default'])) {
			return ! empty($row['robots_noindex']);
		}

		$dynamic = get_option('aioseo_options_dynamic');
		$dynamic = is_string($dynamic) ? json_decode($dynamic, true) : $dynamic;
		$robots  = $dynamic['searchAppearance']['postTypes'][$post->post_type]['advanced']['robotsMeta'] ?? [];

		return is_array($robots) && empty($robots['default']) && ! empty($robots['noindex']);
	}

	private static function seopress(WP_Post $post): bool
	{
		if ((string)get_post_meta($post->ID, '_seopress_robots_index', true) === 'yes') {
			return true;
		}

		$titles = get_option('seopress_titles_option_name');

		return is_array($titles) && ! empty($titles['seopress_titles_single_titles'][$post->post_type]['noindex']);
	}

	private static function the_seo_framework(WP_Post $post): bool
	{
		// 1 = noindex, -1 = index, 0 or empty = post type default.
		$value = (int)get_post_meta($post->ID, '_genesis_noindex', true);

		if ($value !== 0) {
			return $value === 1;
		}

		$settings = get_option('autodescription-site-settings');

		return is_array($settings) && ! empty($settings['noindex_post_types'][$post->post_type]);
	}

	private static function slim_seo(WP_Post $post): bool
	{
		$meta = get_post_meta($post->ID, 'slim_seo', true);

		if (is_array($meta) && ! empty($meta['noindex'])) {
			return true;
		}

		$settings = get_option('slim_seo');

		return is_array($settings) && ! empty($settings[$post->post_type]['noindex']);
	}
}
