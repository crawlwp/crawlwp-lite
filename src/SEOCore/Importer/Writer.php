<?php

namespace Mihdan\IndexNow\SEOCore\Importer;

use Mihdan\IndexNow\SEOCore\MetaBox\MetaFields;
use Mihdan\IndexNow\SEOCore\TitleMeta\Options;
use Mihdan\IndexNow\Utils;

/**
 * Writes a normalised SEO payload into CrawlWP post/term/user meta, and global
 * settings into the WPOSA option rows.
 *
 * Expected $data keys (all optional):
 *   title, description, focus_keyword, canonical, robots_index, robots_follow,
 *   robots_advanced (array or comma separated string), max_snippet, max_image,
 *   og_title, og_description, og_image (attachment ID or URL), og_image_alt,
 *   x_title, x_description, x_image, x_card_type, primary_category, cornerstone,
 *   redirect_url, redirect_type, schema_page_type, schema_article_type
 */
class Writer
{
	/**
	 * Redirect status codes the redirects module understands.
	 *
	 * Kept in sync with RedirectsManager::sanitize() and RedirectsProcessor.
	 */
	public const REDIRECT_TYPES = ['301', '302', '307', '410', '451'];

	/**
	 * Advanced robots directives the SEO metabox stores.
	 *
	 * Kept in sync with MetaFields::field_definitions().
	 */
	public const ROBOTS_ADVANCED_VALUES = ['noimageindex', 'noarchive', 'nosnippet', 'notranslate'];

	/**
	 * Image preview sizes the SEO metabox stores.
	 */
	public const MAX_IMAGE_VALUES = ['large', 'standard', 'none'];

	/**
	 * X (Twitter) card types the SEO metabox stores.
	 */
	public const X_CARD_TYPES = ['summary', 'summary_large_image'];

	/**
	 * Request-level `url => attachment id` cache so the same social image URL
	 * is only resolved with a database query once per import run.
	 *
	 * @var array<string,int>
	 */
	private static array $attachment_ids = [];

	/**
	 * @param array<string,mixed> $data
	 */
	public static function write_post(int $post_id, array $data, bool $overwrite): bool
	{
		if ($post_id <= 0) {
			return false;
		}

		return self::persist('post', $post_id, $data, $overwrite) > 0;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function write_term(int $term_id, array $data, bool $overwrite): bool
	{
		if ($term_id <= 0) {
			return false;
		}

		return self::persist('term', $term_id, $data, $overwrite) > 0;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function write_user(int $user_id, array $data, bool $overwrite): bool
	{
		if ($user_id <= 0) {
			return false;
		}

		return self::persist('user', $user_id, $data, $overwrite) > 0;
	}

	/**
	 * Persist one object's payload and return how many meta keys were written.
	 *
	 * The overwrite flag is evaluated per meta key: an existing SEO title no
	 * longer prevents a missing description or canonical from being filled in.
	 *
	 * @param array<string,mixed> $data
	 */
	private static function persist(string $object_type, int $id, array $data, bool $overwrite): int
	{
		$map = [
			'title'               => MetaFields::SEO_TITLE,
			'description'         => MetaFields::SEO_DESCRIPTION,
			'focus_keyword'       => MetaFields::FOCUS_KEYWORD,
			'canonical'           => MetaFields::CANONICAL_URL,
			'robots_index'        => MetaFields::ROBOTS_INDEX,
			'robots_follow'       => MetaFields::ROBOTS_FOLLOW,
			'robots_advanced'     => MetaFields::ROBOTS_ADVANCED,
			'max_snippet'         => MetaFields::MAX_SNIPPET,
			'max_image'           => MetaFields::MAX_IMAGE,
			'og_title'            => MetaFields::OG_TITLE,
			'og_description'      => MetaFields::OG_DESCRIPTION,
			'og_image_alt'        => MetaFields::OG_IMAGE_ALT,
			'x_title'             => MetaFields::X_TITLE,
			'x_description'       => MetaFields::X_DESCRIPTION,
			'x_card_type'         => MetaFields::X_CARD_TYPE,
			'redirect_url'        => MetaFields::REDIRECT_URL,
			'redirect_type'       => MetaFields::REDIRECT_TYPE,
			'schema_page_type'    => MetaFields::SCHEMA_PAGE_TYPE,
			'schema_article_type' => MetaFields::SCHEMA_ARTICLE_TYPE,
			'primary_category'    => MetaFields::PRIMARY_CATEGORY,
			'cornerstone'         => MetaFields::CORNERSTONE,
		];

		$written    = 0;
		$og_written = false;
		$x_written  = false;

		foreach ($map as $key => $meta_key) {
			if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
				continue;
			}

			if (! self::may_write($object_type, $id, $meta_key, $overwrite)) {
				continue;
			}

			$value = $data[$key];

			if ($key === 'robots_index') {
				$value = $value === 'noindex' || $value === 1 || $value === '1' || $value === true ? 'noindex' : 'index';
			}

			if ($key === 'robots_follow') {
				$value = $value === 'nofollow' || $value === 1 || $value === '1' || $value === true ? 'nofollow' : 'follow';
			}

			if ($key === 'robots_advanced') {
				$value = self::robots_advanced($value);
				if ($value === []) {
					continue;
				}
			}

			if ($key === 'max_snippet') {
				$value = self::max_snippet($value);
				if ($value === '') {
					continue;
				}
			}

			if ($key === 'max_image') {
				$value = (string) $value;
				if (! in_array($value, self::MAX_IMAGE_VALUES, true)) {
					continue;
				}
			}

			if ($key === 'x_card_type') {
				$value = (string) $value;
				if (! in_array($value, self::X_CARD_TYPES, true)) {
					continue;
				}
			}

			if ($key === 'canonical' || $key === 'redirect_url') {
				$value = MetaFields::sanitize_url((string) $value);
				if ($value === '') {
					continue;
				}
			}

			if ($key === 'redirect_type') {
				$value = (string) (int) $value;
				if (! in_array($value, self::REDIRECT_TYPES, true)) {
					$value = '301';
				}
			}

			if ($key === 'primary_category') {
				$value = absint($value);
				if ($value <= 0) {
					continue;
				}
			}

			if ($key === 'cornerstone') {
				$value = $value ? '1' : '0';
			}

			if (in_array($key, ['title', 'focus_keyword', 'og_title', 'og_image_alt', 'x_title', 'schema_page_type', 'schema_article_type'], true)) {
				$value = sanitize_text_field((string) $value);
			}

			if (in_array($key, ['description', 'og_description', 'x_description'], true)) {
				$value = sanitize_textarea_field((string) $value);
			}

			self::update_meta($object_type, $id, $meta_key, $value);
			$written++;

			if ($key === 'og_title' || $key === 'og_description' || $key === 'og_image_alt') {
				$og_written = true;
			}

			if ($key === 'x_title' || $key === 'x_description' || $key === 'x_card_type') {
				$x_written = true;
			}
		}

		if (! empty($data['og_image']) && self::may_write($object_type, $id, MetaFields::OG_IMAGE, $overwrite)) {
			$image_id = self::to_attachment_id($data['og_image']);

			if ($image_id > 0) {
				self::update_meta($object_type, $id, MetaFields::OG_IMAGE, $image_id);
				$written++;
				$og_written = true;
			}
		}

		if (! empty($data['x_image']) && self::may_write($object_type, $id, MetaFields::X_IMAGE, $overwrite)) {
			$image_id = self::to_attachment_id($data['x_image']);

			if ($image_id > 0) {
				self::update_meta($object_type, $id, MetaFields::X_IMAGE, $image_id);
				$written++;
				$x_written = true;
			}
		}

		/*
		 * The sync flags are switched off once per object, no matter how many
		 * of the social fields above were filled in.
		 */
		if ($og_written) {
			self::update_meta($object_type, $id, MetaFields::OG_SYNC, '0');
		}

		if ($x_written) {
			self::update_meta($object_type, $id, MetaFields::X_SYNC, '0');
		}

		return $written;
	}

	/**
	 * Keep only the advanced robots directives the metabox understands.
	 *
	 * @param mixed $raw An array or a comma separated string.
	 *
	 * @return string[]
	 */
	private static function robots_advanced($raw): array
	{
		if (is_string($raw)) {
			$raw = explode(',', $raw);
		}

		if (! is_array($raw)) {
			return [];
		}

		$values = array_map(static function ($v) {
			return is_scalar($v) ? sanitize_text_field((string) $v) : '';
		}, $raw);

		return array_values(array_unique(array_filter($values, static function ($v) {
			return in_array($v, self::ROBOTS_ADVANCED_VALUES, true);
		})));
	}

	/**
	 * Translate a source `max-snippet` length onto one of the stored choices.
	 *
	 * A negative length means "no limit", which is the CrawlWP default and is
	 * therefore not stored.
	 *
	 * @param mixed $raw
	 */
	private static function max_snippet($raw): string
	{
		if ($raw === 'none' || $raw === '160') {
			return $raw;
		}

		if (! is_numeric($raw)) {
			return '';
		}

		$length = (int) $raw;

		if ($length === 0) {
			return 'none';
		}

		return $length > 0 ? '160' : '';
	}

	/**
	 * Whether a single meta key may be written for this object.
	 */
	private static function may_write(string $object_type, int $id, string $meta_key, bool $overwrite): bool
	{
		if ($overwrite) {
			return true;
		}

		return ! self::has_value(self::read_meta($object_type, $id, $meta_key));
	}

	/**
	 * @return mixed
	 */
	private static function read_meta(string $object_type, int $id, string $key)
	{
		if ($object_type === 'term') {
			return get_term_meta($id, $key, true);
		}

		if ($object_type === 'user') {
			return get_user_meta($id, $key, true);
		}

		return get_post_meta($id, $key, true);
	}

	/**
	 * @param mixed $value
	 */
	private static function update_meta(string $object_type, int $id, string $key, $value): void
	{
		if ($object_type === 'term') {
			update_term_meta($id, $key, $value);
			return;
		}

		if ($object_type === 'user') {
			update_user_meta($id, $key, $value);
			return;
		}

		update_post_meta($id, $key, $value);
	}

	/**
	 * @param mixed $raw
	 */
	private static function to_attachment_id($raw): int
	{
		if (is_numeric($raw)) {
			return absint($raw);
		}

		if (! is_string($raw) || $raw === '') {
			return 0;
		}

		$url = esc_url_raw($raw);

		if ($url === '') {
			return 0;
		}

		if (array_key_exists($url, self::$attachment_ids)) {
			return self::$attachment_ids[$url];
		}

		$id = attachment_url_to_postid($url);

		self::$attachment_ids[$url] = $id > 0 ? $id : 0;

		return self::$attachment_ids[$url];
	}

	// -------------------------------------------------------------------------
	// Global settings
	// -------------------------------------------------------------------------

	/**
	 * Write a normalised global-settings payload onto CrawlWP's option rows.
	 *
	 * Payload shape (every part optional):
	 *   [
	 *     'separator' => '-',
	 *     'entities'  => ['home' => ['title' => '…', 'noindex' => 'on'], …],
	 *     'site_info' => ['site_type' => 'organization', …],
	 *     'social'    => ['facebook_author' => '…', …],
	 *     'sitemap'   => ['news_enabled' => 'on', …],
	 *   ]
	 *
	 * @param array<string,mixed> $payload
	 *
	 * @return array{imported:int,skipped:int}
	 */
	public static function write_settings(array $payload, bool $overwrite): array
	{
		$imported = 0;
		$skipped  = 0;

		$entities = isset($payload['entities']) && is_array($payload['entities']) ? $payload['entities'] : [];

		if (isset($payload['separator']) && $payload['separator'] !== '') {
			$entities['home']['separator'] = $payload['separator'];
		}

		foreach ($entities as $entity_key => $fields) {
			if (! is_array($fields) || $fields === []) {
				continue;
			}

			$result = self::write_option_row(Options::section_id((string) $entity_key), $fields, $overwrite);

			$imported += $result['imported'];
			$skipped  += $result['skipped'];
		}

		$rows = [
			'site_info'        => $payload['site_info'] ?? [],
			'social'           => $payload['social'] ?? [],
			'sitemap_settings' => $payload['sitemap'] ?? [],
		];

		if (is_array($rows['site_info']) && array_key_exists('logo', $rows['site_info'])) {
			$rows['site_info']['logo'] = self::global_image_url($rows['site_info']['logo']);
		}

		if (is_array($rows['social']) && array_key_exists('social_image_fallback', $rows['social'])) {
			$rows['social']['social_image_fallback'] = self::global_image_url($rows['social']['social_image_fallback']);
		}

		foreach ($rows as $section => $fields) {
			if (! is_array($fields) || $fields === []) {
				continue;
			}

			$result = self::write_option_row($section, $fields, $overwrite);

			$imported += $result['imported'];
			$skipped  += $result['skipped'];
		}

		Options::flush_cache();

		return [
			'imported' => $imported,
			'skipped'  => $skipped,
		];
	}

	/**
	 * WPOSA image fields store URLs, while several SEO plugins store attachment IDs.
	 *
	 * @param mixed $value
	 */
	private static function global_image_url($value): string
	{
		if (is_numeric($value)) {
			$attachment_id = (int) $value;

			if ($attachment_id <= 0) {
				return '';
			}

			$url = wp_get_attachment_url($attachment_id);

			return is_string($url) ? esc_url_raw($url) : '';
		}

		return is_string($value) ? esc_url_raw($value) : '';
	}

	/**
	 * Merge values into a single WPOSA option row, honouring the overwrite flag
	 * per field.
	 *
	 * @param array<string,mixed> $fields
	 *
	 * @return array{imported:int,skipped:int}
	 */
	private static function write_option_row(string $section, array $fields, bool $overwrite): array
	{
		$option_name = Utils::get_plugin_prefix() . '_' . $section;
		$stored      = get_option($option_name, []);

		if (! is_array($stored)) {
			$stored = [];
		}

		$imported = 0;
		$skipped  = 0;

		foreach ($fields as $key => $value) {
			if ($value === null || $value === '' || $value === []) {
				continue;
			}

			if (! $overwrite && self::has_value($stored[$key] ?? null)) {
				$skipped++;
				continue;
			}

			$stored[$key] = $value;
			$imported++;
		}

		if ($imported > 0) {
			update_option($option_name, $stored);
		}

		return [
			'imported' => $imported,
			'skipped'  => $skipped,
		];
	}

	/**
	 * @param mixed $value
	 */
	private static function has_value($value): bool
	{
		return $value !== '' && $value !== null && $value !== false && $value !== [];
	}
}
