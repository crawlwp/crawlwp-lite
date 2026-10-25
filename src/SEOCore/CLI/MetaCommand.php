<?php

namespace Mihdan\IndexNow\SEOCore\CLI;

use Mihdan\IndexNow\SEOCore\MetaBox\FieldProcessor;
use Mihdan\IndexNow\SEOCore\MetaBox\MetaFields;
use WP_CLI;

/**
 * Read and change a post's SEO fields.
 *
 * ## EXAMPLES
 *
 *     $ wp crawlwp meta get 42
 *     $ wp crawlwp meta set 42 seo_title "Pricing {{sep}} {{sitename}}"
 *     $ wp crawlwp meta set 42 robots_index noindex
 *     $ wp crawlwp meta delete 42 canonical_url
 */
class MetaCommand
{
	/**
	 * Field names as shown by `meta get` => meta keys.
	 */
	private const FIELDS = [
		'seo_title'           => MetaFields::SEO_TITLE,
		'seo_description'     => MetaFields::SEO_DESCRIPTION,
		'focus_keyword'       => MetaFields::FOCUS_KEYWORD,
		'robots_index'        => MetaFields::ROBOTS_INDEX,
		'robots_follow'       => MetaFields::ROBOTS_FOLLOW,
		'robots_advanced'     => MetaFields::ROBOTS_ADVANCED,
		'canonical_url'       => MetaFields::CANONICAL_URL,
		'max_snippet'         => MetaFields::MAX_SNIPPET,
		'max_image'           => MetaFields::MAX_IMAGE,
		'redirect_url'        => MetaFields::REDIRECT_URL,
		'redirect_type'       => MetaFields::REDIRECT_TYPE,
		'og_title'            => MetaFields::OG_TITLE,
		'og_description'      => MetaFields::OG_DESCRIPTION,
		'og_image'            => MetaFields::OG_IMAGE,
		'og_image_alt'        => MetaFields::OG_IMAGE_ALT,
		'og_sync'             => MetaFields::OG_SYNC,
		'x_title'             => MetaFields::X_TITLE,
		'x_description'       => MetaFields::X_DESCRIPTION,
		'x_image'             => MetaFields::X_IMAGE,
		'x_card_type'         => MetaFields::X_CARD_TYPE,
		'x_creator'           => MetaFields::X_CREATOR,
		'x_sync'              => MetaFields::X_SYNC,
		'schema_page_type'    => MetaFields::SCHEMA_PAGE_TYPE,
		'schema_article_type' => MetaFields::SCHEMA_ARTICLE_TYPE,
		'schema_headline'     => MetaFields::SCHEMA_HEADLINE,
		'schema_breadcrumb'   => MetaFields::SCHEMA_BREADCRUMB,
		'schema_section'      => MetaFields::SCHEMA_SECTION,
		'primary_category'    => MetaFields::PRIMARY_CATEGORY,
	];

	/**
	 * Show a post's SEO fields.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Post ID.
	 *
	 * [--field=<field>]
	 * : Show one field only.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function get(array $args, array $assoc_args): void
	{
		$id     = self::post_id($args[0]);
		$values = array_intersect_key(MetaFields::get_all($id), self::FIELDS);

		if (isset($assoc_args['field'])) {
			$field = self::field((string) $assoc_args['field']);
			WP_CLI::print_value($values[$field], $assoc_args);

			return;
		}

		$rows = [];

		foreach ($values as $field => $value) {
			$rows[] = ['field' => $field, 'value' => is_array($value) ? implode(',', $value) : (string) $value];
		}

		WP_CLI\Utils\format_items((string) ($assoc_args['format'] ?? 'table'), $rows, ['field', 'value']);
	}

	/**
	 * Change one SEO field. The value is checked the same way as in the SEO box.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Post ID.
	 *
	 * <field>
	 * : Field name, as listed by `wp crawlwp meta get`.
	 *
	 * <value>
	 * : New value. An empty value ("") sets a choice back to Default. robots_advanced takes a
	 * comma-separated list; og_sync and x_sync take 1 or 0.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp crawlwp meta set 42 seo_description "Plans and prices for every team."
	 *     $ wp crawlwp meta set 42 robots_advanced noarchive,nosnippet
	 *
	 * @param string[] $args
	 */
	public function set(array $args): void
	{
		$id    = self::post_id($args[0]);
		$field = self::field($args[1]);
		$key   = self::FIELDS[$field];
		$raw   = (string) $args[2];
		$specs = MetaFields::field_definitions(true);
		$type  = $specs[$key]['type'] ?? FieldProcessor::TYPE_TEXT;

		$input = null;

		if ($type === FieldProcessor::TYPE_CHECKBOX) {
			$value = in_array(strtolower($raw), ['1', 'on', 'yes', 'true'], true) ? '1' : '0';
		} else {
			$input = $type === FieldProcessor::TYPE_MULTI_SELECT ? array_filter(array_map('trim', explode(',', $raw))) : $raw;
			$value = FieldProcessor::process([$key => $specs[$key]], [$key => wp_slash($input)])[$key];
		}

		if (is_array($value) && is_array($input) && count($value) !== count($input)) {
			WP_CLI::warning(sprintf('Ignored unknown values. Allowed: %s.', implode(', ', $specs[$key]['allowed'])));
		}

		if ($type === FieldProcessor::TYPE_SELECT && $raw !== '' && $value !== $raw) {
			WP_CLI::error(sprintf('"%s" is not a valid value for %s. Use one of: %s.', $raw, $field, implode(', ', array_filter($specs[$key]['allowed'], 'strlen'))));
		}

		if ($raw !== '' && ($value === '' || $value === [] || $value === 0)) {
			WP_CLI::error(sprintf('"%s" is not a valid value for %s.', $raw, $field));
		}

		if (is_string($value) && $type !== FieldProcessor::TYPE_SELECT && $type !== FieldProcessor::TYPE_CHECKBOX) {
			MetaFields::save_optional($id, $key, $value);
		} else {
			update_post_meta($id, $key, $value);
		}

		WP_CLI::success(sprintf('Updated %s for post %d.', $field, $id));
	}

	/**
	 * Remove one SEO field, so the post uses the default again.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Post ID.
	 *
	 * <field>
	 * : Field name, as listed by `wp crawlwp meta get`.
	 *
	 * @param string[] $args
	 */
	public function delete(array $args): void
	{
		$id    = self::post_id($args[0]);
		$field = self::field($args[1]);

		MetaFields::save_optional($id, self::FIELDS[$field], '');

		WP_CLI::success(sprintf('Removed %s from post %d.', $field, $id));
	}

	private static function post_id(string $arg): int
	{
		$post = get_post((int) $arg);

		if (! $post) {
			WP_CLI::error(sprintf('Post %s not found.', $arg));
		}

		return (int) $post->ID;
	}

	private static function field(string $field): string
	{
		if (! isset(self::FIELDS[$field])) {
			WP_CLI::error(sprintf('Unknown field "%s". Fields: %s.', $field, implode(', ', array_keys(self::FIELDS))));
		}

		return $field;
	}
}
