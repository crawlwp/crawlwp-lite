<?php

namespace Mihdan\IndexNow\SEOCore\Importer\Sources;

use Mihdan\IndexNow\SEOCore\Importer\Source;
use Mihdan\IndexNow\SEOCore\Importer\Writer;
use Mihdan\IndexNow\SEOCore\TitleMeta\Entities;

/**
 * SureRank (by Brainstorm Force).
 *
 * Posts and terms keep their values in grouped meta: `surerank_settings_general`
 * (title, description, canonical, focus keyword), `surerank_settings_social`
 * and the scalar `surerank_settings_post_no_index` / `_no_follow` / `_no_archive`
 * ('yes' when set). Site-wide settings live in the `surerank_settings` option,
 * the site identity in `surerank_settings_onboarding`. SureRank has no redirects.
 *
 * SureRank fills empty per-post fields with its templates when a post is saved,
 * so values that only repeat a template are not imported as overrides.
 */
class SureRank extends Source
{
	private const META_GENERAL = 'surerank_settings_general';
	private const META_SOCIAL  = 'surerank_settings_social';

	/** SureRank's own defaults for the site-wide templates. */
	private const DEFAULT_TITLE       = '%title% - %site_name%';
	private const DEFAULT_DESCRIPTION = '%content%';

	public function id(): string
	{
		return 'surerank';
	}

	public function label(): string
	{
		return 'SureRank';
	}

	public function is_available(): bool
	{
		return defined('SURERANK_VERSION')
			|| is_array(get_option('surerank_settings'))
			|| $this->count_meta(self::META_GENERAL, 'postmeta') > 0
			|| $this->count_meta(self::META_GENERAL, 'termmeta') > 0;
	}

	public function counts(): array
	{
		return [
			'posts'     => $this->count_objects('postmeta', 'post_id'),
			'terms'     => $this->count_objects('termmeta', 'term_id'),
			'users'     => 0,
			'redirects' => 0,
		];
	}

	public function import_posts(int $offset, int $limit, bool $overwrite): array
	{
		$ids      = $this->post_ids($offset, $limit);
		$imported = 0;
		$skipped  = 0;

		foreach ($ids as $post_id) {
			$data = $this->object_data(static function (string $key) use ($post_id) {
				return get_post_meta($post_id, $key, true);
			});

			if ($data === []) {
				continue;
			}

			Writer::write_post($post_id, $data, $overwrite) ? $imported++ : $skipped++;
		}

		return $this->batch_result($imported, $skipped, $offset, count($ids), $limit);
	}

	public function import_terms(int $offset, int $limit, bool $overwrite): array
	{
		$terms    = $this->terms($offset, $limit);
		$imported = 0;
		$skipped  = 0;

		foreach ($terms as $term) {
			$term_id = (int) $term->term_id;
			$data    = $this->object_data(static function (string $key) use ($term_id) {
				return get_term_meta($term_id, $key, true);
			});

			if ($data === []) {
				continue;
			}

			Writer::write_term($term_id, $data, $overwrite) ? $imported++ : $skipped++;
		}

		return $this->batch_result($imported, $skipped, $offset, count($terms), $limit);
	}

	/**
	 * CrawlWP fields for one post or term.
	 *
	 * @param callable(string): mixed $get Reads one meta key.
	 *
	 * @return array<string,mixed>
	 */
	private function object_data(callable $get): array
	{
		$general = $get(self::META_GENERAL);
		$social  = $get(self::META_SOCIAL);
		$general = is_array($general) ? $general : [];
		$social  = is_array($social) ? $social : [];

		$data = [
			'title'          => $this->override($general['page_title'] ?? ''),
			'description'    => $this->override($general['page_description'] ?? ''),
			'canonical'      => (string) ($general['canonical_url'] ?? ''),
			'focus_keyword'  => (string) ($general['focus_keyword'] ?? ''),
			'og_title'       => $this->override($social['facebook_title'] ?? ''),
			'og_description' => $this->override($social['facebook_description'] ?? ''),
			'og_image'       => (string) (! empty($social['facebook_image_id']) ? $social['facebook_image_id'] : ($social['facebook_image_url'] ?? '')),
		];

		// "Use the Facebook values for X" is on by default; then X has nothing of its own.
		if (isset($social['twitter_same_as_facebook']) && ! $social['twitter_same_as_facebook']) {
			$data['x_title']       = $this->override($social['twitter_title'] ?? '');
			$data['x_description'] = $this->override($social['twitter_description'] ?? '');
			$data['x_image']       = (string) (! empty($social['twitter_image_id']) ? $social['twitter_image_id'] : ($social['twitter_image_url'] ?? ''));
		}

		$card = (string) ($social['twitter_card_type'] ?? '');

		if (in_array($card, Writer::X_CARD_TYPES, true) && $card !== $this->setting('twitter_card_type', 'summary_large_image')) {
			$data['x_card_type'] = $card;
		}

		if ($get('surerank_settings_post_no_index') === 'yes') {
			$data['robots_index'] = 'noindex';
		}

		if ($get('surerank_settings_post_no_follow') === 'yes') {
			$data['robots_follow'] = 'nofollow';
		}

		if ($get('surerank_settings_post_no_archive') === 'yes') {
			$data['robots_advanced'] = ['noarchive'];
		}

		return array_filter($data, static function ($v) {
			return $v !== '' && $v !== null && $v !== [];
		});
	}

	/**
	 * A per-post or per-term text, or '' when it only repeats one of SureRank's templates.
	 *
	 * @param mixed $value
	 */
	private function override($value): string
	{
		$value = is_scalar($value) ? trim((string) $value) : '';

		return $value === '' || in_array($value, $this->templates(), true) ? '' : $this->convert($value);
	}

	/**
	 * Templates SureRank copies into posts and terms: its defaults and the site's own.
	 *
	 * @return string[]
	 */
	private function templates(): array
	{
		static $templates = null;

		if ($templates === null) {
			$title       = (string) $this->setting('page_title', '');
			$description = (string) $this->setting('page_description', '');

			$templates = array_values(array_filter(array_unique([
				'%title% - %site_name%', '%term_title% - %site_name%',
				'%content%', '%excerpt%', '%term_description%',
				$title, str_replace('%title%', '%term_title%', $title),
				$description, str_replace('%excerpt%', '%term_description%', $description),
			])));
		}

		return $templates;
	}

	/**
	 * Separator, home page, post type and archive templates, robots defaults,
	 * social defaults and the site identity.
	 *
	 * SureRank has one title and description template for every post type, so
	 * it is applied to each, unless it is still SureRank's default (then
	 * CrawlWP's own default is kept).
	 *
	 * @return array<string,mixed>
	 */
	protected function settings_payload(): array
	{
		$settings = get_option('surerank_settings');

		if (! is_array($settings) || $settings === []) {
			return [];
		}

		$payload  = [];
		$entities = [];

		$separator = trim((string) ($settings['separator'] ?? ''));

		if ($separator !== '') {
			$payload['separator'] = $separator;
		}

		$home = array_filter([
			'title'       => $this->convert((string) ($settings['home_page_title'] ?? '')),
			'description' => $this->convert((string) ($settings['home_page_description'] ?? '')),
		]);

		$home_robots = $settings['home_page_robots']['general'] ?? [];

		if (is_array($home_robots) && $home_robots !== []) {
			$home['noindex']  = in_array('noindex', $home_robots, true) ? 'on' : 'off';
			$home['nofollow'] = in_array('nofollow', $home_robots, true) ? 'on' : 'off';
		}

		$entities['home'] = $home;

		$title       = (string) ($settings['page_title'] ?? '');
		$description = (string) ($settings['page_description'] ?? '');
		$template    = array_filter([
			'title'       => $title !== self::DEFAULT_TITLE ? $this->convert($title) : '',
			'description' => $description !== self::DEFAULT_DESCRIPTION ? $this->convert($description) : '',
		]);

		$no_index  = is_array($settings['no_index'] ?? null) ? $settings['no_index'] : [];
		$no_follow = is_array($settings['no_follow'] ?? null) ? $settings['no_follow'] : [];

		$robots = static function (string $name) use ($no_index, $no_follow): array {
			return [
				'noindex'  => in_array($name, $no_index, true) ? 'on' : 'off',
				'nofollow' => in_array($name, $no_follow, true) ? 'on' : 'off',
			];
		};

		foreach (Entities::post_types() as $post_type) {
			$entities[ Entities::post_type_key($post_type->name) ] = $template + $robots($post_type->name);
		}

		foreach (Entities::taxonomies() as $taxonomy) {
			$entities[ Entities::taxonomy_key($taxonomy->name) ] = $robots($taxonomy->name);
		}

		foreach (['author', 'date', 'search'] as $archive) {
			$entities[ $archive ] = $robots($archive);
		}

		$payload['entities'] = array_filter($entities);

		$handle = trim((string) ($settings['twitter_profile_username'] ?? ''));
		$card   = (string) ($settings['twitter_card_type'] ?? '');

		$payload['social'] = array_filter([
			'social_image_fallback' => (string) ($settings['fallback_image'] ?? ''),
			'twitter_creator'       => $handle === '' ? '' : '@' . ltrim($handle, '@'),
			'twitter_card'          => in_array($card, Writer::X_CARD_TYPES, true) ? $card : '',
		]);

		$payload['site_info'] = $this->site_info();

		return array_filter($payload);
	}

	/**
	 * Site identity from SureRank's setup wizard.
	 *
	 * @return array<string,mixed>
	 */
	private function site_info(): array
	{
		$onboarding = get_option('surerank_settings_onboarding');

		if (! is_array($onboarding)) {
			return [];
		}

		$type     = (string) ($onboarding['website_type'] ?? '');
		$profiles = is_array($onboarding['social_profiles'] ?? null) ? $onboarding['social_profiles'] : [];
		$logo     = $onboarding['website_logo'] ?? '';

		if (is_array($logo)) {
			$logo = $logo['id'] ?? $logo['url'] ?? '';
		}

		$other = array_filter(array_map('strval', array_diff_key($profiles, ['facebook' => 1, 'twitter' => 1])));

		return array_filter([
			'site_type'          => $type === '' ? '' : (in_array($type, ['blog', 'personal'], true) ? 'person' : 'organization'),
			'site_name'          => (string) ($onboarding['website_name'] ?? ''),
			'logo'               => is_scalar($logo) ? $logo : '',
			'profile_facebook'   => (string) ($profiles['facebook'] ?? ''),
			'profile_x'          => (string) ($profiles['twitter'] ?? ''),
			'profile_additional' => implode("\n", $other),
		], static function ($v) {
			return $v !== '' && $v !== 0 && $v !== '0';
		});
	}

	/**
	 * @param mixed $default
	 *
	 * @return mixed
	 */
	private function setting(string $key, $default)
	{
		static $settings = null;

		if ($settings === null) {
			$settings = get_option('surerank_settings');
			$settings = is_array($settings) ? $settings : [];
		}

		return $settings[$key] ?? $default;
	}

	/**
	 * Posts or terms that have any SureRank value.
	 */
	private function count_objects(string $table, string $id_column): int
	{
		global $wpdb;

		$table = $table === 'termmeta' ? $wpdb->termmeta : $wpdb->postmeta;

		return (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT {$id_column}) FROM {$table} WHERE meta_key IN ('surerank_settings_general', 'surerank_settings_social', 'surerank_settings_post_no_index', 'surerank_settings_post_no_follow', 'surerank_settings_post_no_archive') AND meta_value <> ''" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table and column names.
		);
	}

	private function count_meta(string $key, string $table): int
	{
		global $wpdb;

		$table = $table === 'termmeta' ? $wpdb->termmeta : $wpdb->postmeta;

		return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE meta_key = %s", $key)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
