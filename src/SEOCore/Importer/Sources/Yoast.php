<?php

namespace Mihdan\IndexNow\SEOCore\Importer\Sources;

use Mihdan\IndexNow\SEOCore\Importer\Source;
use Mihdan\IndexNow\SEOCore\Importer\Writer;
use Mihdan\IndexNow\SEOCore\Redirects\RedirectsManager;
use Mihdan\IndexNow\SEOCore\TitleMeta\Entities;

class Yoast extends Source
{
	/**
	 * Flattened `wpseo_taxonomy_meta` option, parsed once per request instead
	 * of on every 50-term batch.
	 *
	 * @var array<int,array>|null
	 */
	private static ?array $terms_cache = null;

	public function id(): string
	{
		return 'yoast';
	}

	public function label(): string
	{
		return 'Yoast SEO';
	}

	public function is_available(): bool
	{
		return get_option('wpseo') !== false
			|| defined('WPSEO_VERSION')
			|| $this->has_meta('_yoast_wpseo_title');
	}

	public function counts(): array
	{
		$redirects = get_option('wpseo-premium-redirects-base', []);

		return [
			'posts'     => $this->count_objects_with_meta('post', ['_yoast_wpseo_title', '_yoast_wpseo_metadesc']),
			'terms'     => $this->count_yoast_terms(),
			'users'     => $this->count_objects_with_meta('user', ['wpseo_title', 'wpseo_metadesc']),
			'redirects' => is_array($redirects) ? count($redirects) : 0,
		];
	}

	public function import_posts(int $offset, int $limit, bool $overwrite): array
	{
		$ids      = $this->post_ids($offset, $limit);
		$imported = 0;
		$skipped  = 0;

		foreach ($ids as $post_id) {
			$data = $this->post_payload($post_id);

			if ($data === []) {
				continue;
			}

			Writer::write_post($post_id, $data, $overwrite) ? $imported++ : $skipped++;
		}

		return $this->batch_result($imported, $skipped, $offset, count($ids), $limit);
	}

	public function import_terms(int $offset, int $limit, bool $overwrite): array
	{
		$all      = $this->yoast_terms();
		$slice    = array_slice($all, $offset, $limit, true);
		$imported = 0;
		$skipped  = 0;

		$this->prime_meta('term', array_keys($slice));

		foreach ($slice as $term_id => $meta) {
			if (! is_array($meta)) {
				continue;
			}

			$data = [
				'title'          => $this->convert((string) ($meta['wpseo_title'] ?? '')),
				'description'    => $this->convert((string) ($meta['wpseo_desc'] ?? '')),
				'focus_keyword'  => (string) ($meta['wpseo_focuskw'] ?? ''),
				'canonical'      => (string) ($meta['wpseo_canonical'] ?? ''),
				'og_title'       => $this->convert((string) ($meta['wpseo_opengraph-title'] ?? '')),
				'og_description' => $this->convert((string) ($meta['wpseo_opengraph-description'] ?? '')),
				'og_image'       => (string) ($meta['wpseo_opengraph-image-id'] ?? '')
					?: (string) ($meta['wpseo_opengraph-image'] ?? ''),
				'x_title'        => $this->convert((string) ($meta['wpseo_twitter-title'] ?? '')),
				'x_description'  => $this->convert((string) ($meta['wpseo_twitter-description'] ?? '')),
				'x_image'        => (string) ($meta['wpseo_twitter-image-id'] ?? '')
					?: (string) ($meta['wpseo_twitter-image'] ?? ''),
				'cornerstone'    => (string) ($meta['wpseo_is_cornerstone'] ?? ''),
				'robots_index'   => (! empty($meta['wpseo_noindex']) && $meta['wpseo_noindex'] === 'noindex') ? 'noindex' : '',
			];

			$data = array_filter($data, static function ($v) {
				return $v !== '' && $v !== null;
			});

			if ($data === []) {
				continue;
			}

			Writer::write_term((int) $term_id, $data, $overwrite) ? $imported++ : $skipped++;
		}

		return $this->batch_result($imported, $skipped, $offset, count($slice), $limit);
	}

	public function import_users(int $offset, int $limit, bool $overwrite): array
	{
		$ids      = $this->user_ids($offset, $limit);
		$imported = 0;
		$skipped  = 0;

		foreach ($ids as $user_id) {
			$data = [
				'title'       => $this->convert((string) get_user_meta($user_id, 'wpseo_title', true)),
				'description' => $this->convert((string) get_user_meta($user_id, 'wpseo_metadesc', true)),
			];

			if ((string) get_user_meta($user_id, 'wpseo_noindex_author', true) === 'on') {
				$data['robots_index'] = 'noindex';
			}

			$data = array_filter($data, static function ($v) {
				return $v !== '' && $v !== null;
			});

			if ($data === []) {
				continue;
			}

			Writer::write_user($user_id, $data, $overwrite) ? $imported++ : $skipped++;
		}

		return $this->batch_result($imported, $skipped, $offset, count($ids), $limit);
	}

	public function import_redirects(int $offset, int $limit): array
	{
		$results = get_option('wpseo-premium-redirects-base', []);

		if (! is_array($results) || $results === []) {
			return $this->batch_result(0, 0, $offset, 0, $limit);
		}

		$batch = array_slice(array_values($results), $offset, $limit);

		if ($batch === []) {
			return $this->batch_result(0, 0, $offset, 0, $limit);
		}

		$manager  = new RedirectsManager();
		$imported = 0;
		$skipped  = 0;

		foreach ($batch as $row) {
			if (! is_array($row) || empty($row['origin'])) {
				$skipped++;
				continue;
			}

			$from = (string) $row['origin'];

			if ($manager->exists_from_url($from)) {
				$skipped++;
				continue;
			}

			$type = (int) ($row['type'] ?? 301);
			$ok   = $manager->insert([
				'from_url'             => $from,
				'to_url'               => (string) ($row['url'] ?? ''),
				'redirect_type'        => in_array($type, [301, 302, 307, 410, 451], true) ? $type : 301,
				'match_type'           => (($row['format'] ?? '') === 'regex') ? 'regex' : 'exact',
				'note'                 => __('Imported from Yoast SEO', 'mihdan-index-now'),
				'ignore_query_string'  => 1,
				'enabled'              => 1,
			]);

			$ok ? $imported++ : $skipped++;
		}

		return $this->batch_result($imported, $skipped, $offset, count($batch), $limit);
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function settings_payload(): array
	{
		$titles = get_option('wpseo_titles');
		$social = get_option('wpseo_social');

		if (! is_array($titles)) {
			$titles = [];
		}

		if (! is_array($social)) {
			$social = [];
		}

		if ($titles === [] && $social === []) {
			return [];
		}

		$entities = [];

		$entities['home'] = $this->entity_fields(
			$titles['title-home-wpseo'] ?? '',
			$titles['metadesc-home-wpseo'] ?? '',
			null
		);

		foreach (Entities::post_types() as $post_type) {
			$name   = $post_type->name;
			$fields = $this->entity_fields(
				$titles[ 'title-' . $name ] ?? '',
				$titles[ 'metadesc-' . $name ] ?? '',
				$titles[ 'noindex-' . $name ] ?? null
			);

			$archive_title = (string) ($titles[ 'title-ptarchive-' . $name ] ?? '');
			$archive_desc  = (string) ($titles[ 'metadesc-ptarchive-' . $name ] ?? '');

			if ($archive_title !== '') {
				$fields['archive_title'] = $this->convert($archive_title);
			}

			if ($archive_desc !== '') {
				$fields['archive_description'] = $this->convert($archive_desc);
			}

			if (isset($titles[ 'noindex-ptarchive-' . $name ])) {
				$fields['archive_noindex'] = $this->switch_value($titles[ 'noindex-ptarchive-' . $name ]);
			}

			$entities[ Entities::post_type_key($name) ] = $fields;
		}

		foreach (Entities::taxonomies() as $taxonomy) {
			$name = $taxonomy->name;

			$entities[ Entities::taxonomy_key($name) ] = $this->entity_fields(
				$titles[ 'title-tax-' . $name ] ?? '',
				$titles[ 'metadesc-tax-' . $name ] ?? '',
				$titles[ 'noindex-tax-' . $name ] ?? null
			);
		}

		$entities['author'] = $this->entity_fields(
			$titles['title-author-wpseo'] ?? '',
			$titles['metadesc-author-wpseo'] ?? '',
			$titles['noindex-author-wpseo'] ?? null
		);

		$entities['date'] = $this->entity_fields(
			$titles['title-archive-wpseo'] ?? '',
			$titles['metadesc-archive-wpseo'] ?? '',
			$titles['noindex-archive-wpseo'] ?? null
		);

		$entities['search'] = $this->entity_fields($titles['title-search-wpseo'] ?? '', '', null);
		$entities['not_found'] = $this->entity_fields($titles['title-404-wpseo'] ?? '', '', null);

		/*
		 * Yoast's `enable_xml_sitemap` is deliberately not mapped: CrawlWP relies
		 * on the WordPress core sitemap, which has no equivalent master switch.
		 */
		return array_filter([
			'separator' => $this->separator((string) ($titles['separator'] ?? '')),
			'entities'  => array_filter($entities),
			'site_info' => $this->site_info($titles),
			'social'    => $this->social($social),
		]);
	}

	/**
	 * Title/description/noindex trio for one entity screen.
	 *
	 * @param mixed $noindex
	 *
	 * @return array<string,string>
	 */
	private function entity_fields($title, $description, $noindex): array
	{
		$fields = [];

		if (is_string($title) && $title !== '') {
			$fields['title'] = $this->convert($title);
		}

		if (is_string($description) && $description !== '') {
			$fields['description'] = $this->convert($description);
		}

		if ($noindex !== null) {
			$fields['noindex'] = $this->switch_value($noindex);
		}

		return $fields;
	}

	/**
	 * @param array<string,mixed> $titles
	 *
	 * @return array<string,mixed>
	 */
	private function site_info(array $titles): array
	{
		$is_person = ($titles['company_or_person'] ?? '') === 'person';

		$info = [
			'site_type' => $is_person ? 'person' : 'organization',
			'site_name' => (string) ($is_person ? ($titles['person_name'] ?? '') : ($titles['company_name'] ?? '')),
		];

		$logo = absint($titles['company_logo_id'] ?? 0);

		if ($logo > 0) {
			$info['logo'] = $logo;
		}

		return array_filter($info, static function ($v) {
			return $v !== '' && $v !== 0;
		});
	}

	/**
	 * @param array<string,mixed> $social
	 *
	 * @return array<string,mixed>
	 */
	private function social(array $social): array
	{
		$handle = (string) ($social['twitter_site'] ?? '');

		$fields = [
			'facebook_author' => (string) ($social['facebook_site'] ?? ''),
			'twitter_creator' => $handle === '' ? '' : '@' . ltrim($handle, '@'),
			'fb_app_id'       => (string) ($social['fbadminapp'] ?? ''),
		];

		$card = (string) ($social['twitter_card_type'] ?? '');

		if (in_array($card, ['summary', 'summary_large_image'], true)) {
			$fields['twitter_card'] = $card;
		}

		$image = absint($social['og_default_image_id'] ?? 0);

		if ($image > 0) {
			$fields['social_image_fallback'] = $image;
		}

		return array_filter($fields, static function ($v) {
			return $v !== '' && $v !== 0;
		});
	}

	/**
	 * Yoast stores the separator as a `sep-*` key.
	 */
	private function separator(string $stored): string
	{
		$map = [
			'sep-dash'    => '-',
			'sep-ndash'   => '–',
			'sep-mdash'   => '—',
			'sep-middot'  => '·',
			'sep-bull'    => '•',
			'sep-pipe'    => '|',
			'sep-tilde'   => '~',
			'sep-laquo'   => '«',
			'sep-raquo'   => '»',
			'sep-lt'      => '<',
			'sep-gt'      => '>',
		];

		return $map[$stored] ?? '';
	}

	/**
	 * @param mixed $value
	 */
	private function switch_value($value): string
	{
		return ($value === true || $value === 1 || $value === '1' || $value === 'on') ? 'on' : 'off';
	}

	/**
	 * @return array<string,mixed>
	 */
	private function post_payload(int $post_id): array
	{
		$title = $this->convert((string) get_post_meta($post_id, '_yoast_wpseo_title', true));
		$desc  = $this->convert((string) get_post_meta($post_id, '_yoast_wpseo_metadesc', true));
		$kw    = (string) get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
		$canon = (string) get_post_meta($post_id, '_yoast_wpseo_canonical', true);
		$noindex = (int) get_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', true);
		$nofollow = (int) get_post_meta($post_id, '_yoast_wpseo_meta-robots-nofollow', true);

		$schema_page_type    = (string) get_post_meta($post_id, '_yoast_wpseo_schema_page_type', true);
		$schema_article_type = (string) get_post_meta($post_id, '_yoast_wpseo_schema_article_type', true);
		if (strtolower($schema_article_type) === 'none') {
			$schema_article_type = 'none';
		}

		$data = [
			'title'               => $title,
			'description'         => $desc,
			'focus_keyword'       => $kw,
			'canonical'           => $canon,
			'og_title'            => $this->convert((string) get_post_meta($post_id, '_yoast_wpseo_opengraph-title', true)),
			'og_description'      => $this->convert((string) get_post_meta($post_id, '_yoast_wpseo_opengraph-description', true)),
			'og_image'            => (string) get_post_meta($post_id, '_yoast_wpseo_opengraph-image-id', true)
				?: (string) get_post_meta($post_id, '_yoast_wpseo_opengraph-image', true),
			'x_title'             => $this->convert((string) get_post_meta($post_id, '_yoast_wpseo_twitter-title', true)),
			'x_description'       => $this->convert((string) get_post_meta($post_id, '_yoast_wpseo_twitter-description', true)),
			'x_image'             => (string) get_post_meta($post_id, '_yoast_wpseo_twitter-image-id', true)
				?: (string) get_post_meta($post_id, '_yoast_wpseo_twitter-image', true),
			'primary_category'    => (int) get_post_meta($post_id, '_yoast_wpseo_primary_category', true),
			'cornerstone'         => (string) get_post_meta($post_id, '_yoast_wpseo_is_cornerstone', true),
			'redirect_url'        => (string) get_post_meta($post_id, '_yoast_wpseo_redirect', true),
			'schema_page_type'    => $schema_page_type,
			'schema_article_type' => $schema_article_type,
		];

		if ($noindex === 1) {
			$data['robots_index'] = 'noindex';
		}

		if ($nofollow === 1) {
			$data['robots_follow'] = 'nofollow';
		}

		/*
		 * A stored `none` means "no advanced directives", so it is not imported.
		 * Yoast has no term equivalent: `wpseo_taxonomy_meta` only carries
		 * `wpseo_noindex`, which import_terms() already reads.
		 */
		$robots_advanced = (string) get_post_meta($post_id, '_yoast_wpseo_meta-robots-adv', true);

		if ($robots_advanced !== '' && $robots_advanced !== 'none') {
			$data['robots_advanced'] = $robots_advanced;
		}

		return array_filter($data, static function ($v) {
			return $v !== '' && $v !== null && $v !== 0 && $v !== '0';
		});
	}

	private function has_meta(string $key): bool
	{
		global $wpdb;

		$found = $wpdb->get_var($wpdb->prepare(
			"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1",
			$key
		));

		return $found !== null;
	}

	private function count_yoast_terms(): int
	{
		return count($this->yoast_terms());
	}

	/**
	 * Flattened `term_id => meta` map, parsed once per request.
	 *
	 * @return array<int,array>
	 */
	private function yoast_terms(): array
	{
		if (self::$terms_cache !== null) {
			return self::$terms_cache;
		}

		$option = get_option('wpseo_taxonomy_meta');

		if (! is_array($option)) {
			self::$terms_cache = [];

			return self::$terms_cache;
		}

		$flat = [];

		foreach ($option as $terms) {
			if (! is_array($terms)) {
				continue;
			}

			foreach ($terms as $term_id => $meta) {
				$flat[(int) $term_id] = $meta;
			}
		}

		self::$terms_cache = $flat;

		return self::$terms_cache;
	}
}
