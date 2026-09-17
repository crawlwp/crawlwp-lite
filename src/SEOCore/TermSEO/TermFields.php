<?php

namespace Mihdan\IndexNow\SEOCore\TermSEO;

use Mihdan\IndexNow\SEOCore\MetaBox\FieldProcessor;
use Mihdan\IndexNow\SEOCore\MetaBox\MetaFields;

/**
 * Per-term SEO values stored with the same meta keys as posts.
 */
class TermFields
{
	public static function get(int $term_id, string $key, $default = '')
	{
		$value = get_term_meta($term_id, $key, true);

		return $value !== '' ? $value : $default;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function get_all(int $term_id): array
	{
		return [
			'seo_title'       => self::get($term_id, MetaFields::SEO_TITLE),
			'seo_description' => self::get($term_id, MetaFields::SEO_DESCRIPTION),
			'robots_index'    => self::get($term_id, MetaFields::ROBOTS_INDEX, 'index'),
			'robots_follow'   => self::get($term_id, MetaFields::ROBOTS_FOLLOW, 'follow'),
			'canonical_url'   => self::get($term_id, MetaFields::CANONICAL_URL),
			'og_title'        => self::get($term_id, MetaFields::OG_TITLE),
			'og_description'  => self::get($term_id, MetaFields::OG_DESCRIPTION),
			'og_image'        => self::get($term_id, MetaFields::OG_IMAGE, 0),
			'x_title'         => self::get($term_id, MetaFields::X_TITLE),
			'x_description'   => self::get($term_id, MetaFields::X_DESCRIPTION),
			'x_image'         => self::get($term_id, MetaFields::X_IMAGE, 0),
		];
	}

	/**
	 * The term subset of the SEO fields, described for {@see FieldProcessor}.
	 *
	 * Sharing the definitions with MetaFields keeps the term and post savers
	 * from drifting apart: the sanitisation rule for a key lives in exactly one
	 * place.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function field_definitions(): array
	{
		return [
			MetaFields::SEO_TITLE       => ['type' => FieldProcessor::TYPE_TEXT],
			MetaFields::OG_TITLE        => ['type' => FieldProcessor::TYPE_TEXT],
			MetaFields::X_TITLE         => ['type' => FieldProcessor::TYPE_TEXT],
			MetaFields::SEO_DESCRIPTION => ['type' => FieldProcessor::TYPE_TEXTAREA],
			MetaFields::OG_DESCRIPTION  => ['type' => FieldProcessor::TYPE_TEXTAREA],
			MetaFields::X_DESCRIPTION   => ['type' => FieldProcessor::TYPE_TEXTAREA],
			MetaFields::CANONICAL_URL   => ['type' => FieldProcessor::TYPE_URL],
			MetaFields::OG_IMAGE        => ['type' => FieldProcessor::TYPE_INT],
			MetaFields::X_IMAGE         => ['type' => FieldProcessor::TYPE_INT],
			/* The term form always renders both robots selects, so an absent
			   value legitimately means "back to the default". */
			MetaFields::ROBOTS_INDEX    => [
				'type'     => FieldProcessor::TYPE_SELECT,
				'allowed'  => ['index', 'noindex'],
				'fallback' => 'index',
				'always'   => true,
			],
			MetaFields::ROBOTS_FOLLOW   => [
				'type'     => FieldProcessor::TYPE_SELECT,
				'allowed'  => ['follow', 'nofollow'],
				'fallback' => 'follow',
				'always'   => true,
			],
		];
	}

	public static function save(int $term_id): void
	{
		if (
			! isset($_POST[MetaFields::NONCE_NAME]) ||
			! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[MetaFields::NONCE_NAME])), MetaFields::NONCE_ACTION)
		) {
			return;
		}

		if (! current_user_can('edit_term', $term_id)) {
			return;
		}

		foreach (FieldProcessor::process(self::field_definitions(), $_POST) as $key => $value) {
			update_term_meta($term_id, $key, $value);
		}
	}
}
