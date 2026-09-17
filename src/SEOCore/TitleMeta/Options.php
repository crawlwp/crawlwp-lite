<?php

namespace Mihdan\IndexNow\SEOCore\TitleMeta;

use Mihdan\IndexNow\Utils;

/**
 * Thin reader over the per-entity option rows written by WPOSA.
 *
 * Every entity screen is registered as its own WPOSA section, which means each
 * one is stored in a dedicated option row named `crawlwp_tm_<entity>` holding
 * an associative array of that screen's fields.
 */
class Options
{
	/**
	 * Prefix shared by every entity section id.
	 */
	public const SECTION_PREFIX = 'tm_';

	/**
	 * Loaded option rows, keyed by entity key.
	 *
	 * @var array<string, array>
	 */
	private static array $cache = [];

	/**
	 * Full WPOSA section id for an entity, without the plugin prefix.
	 */
	public static function section_id(string $entity_key): string
	{
		return self::SECTION_PREFIX . $entity_key;
	}

	/**
	 * Name of the option row backing an entity screen.
	 */
	public static function option_name(string $entity_key): string
	{
		return Utils::get_plugin_prefix() . '_' . self::section_id($entity_key);
	}

	/**
	 * Every stored value for an entity.
	 */
	public static function all(string $entity_key): array
	{
		if (! isset(self::$cache[$entity_key])) {
			$stored = get_option(self::option_name($entity_key), []);

			self::$cache[$entity_key] = is_array($stored) ? $stored : [];
		}

		return self::$cache[$entity_key];
	}

	/**
	 * A single stored value, falling back to the registered default.
	 *
	 * @param string $entity_key Entity key, e.g. `home`, `pt_post`, `tax_category`.
	 * @param string $field      Field id, e.g. `title`, `description`, `noindex`.
	 * @param mixed  $default    Returned when nothing is stored.
	 *
	 * @return mixed
	 */
	public static function get(string $entity_key, string $field, $default = '')
	{
		$options = self::all($entity_key);

		$value = $options[$field] ?? null;

		if ($value === null || $value === '') {
			return $default;
		}

		/**
		 * Filter a stored title & meta default.
		 *
		 * @param mixed  $value      The stored value.
		 * @param string $entity_key The entity key.
		 * @param string $field      The field id.
		 */
		return apply_filters('crawlwp_title_meta_option', $value, $entity_key, $field);
	}

	/**
	 * Whether a switch field is enabled.
	 */
	public static function is_on(string $entity_key, string $field): bool
	{
		return self::get($entity_key, $field, 'off') === 'on';
	}

	/**
	 * Drop the in-memory cache. Mainly useful for tests.
	 */
	public static function flush_cache(): void
	{
		self::$cache = [];
	}
}
