<?php

namespace Mihdan\IndexNow\SEOCore\Importer\Sources;

use Mihdan\IndexNow\SEOCore\Importer\Source;
use Mihdan\IndexNow\SEOCore\Importer\Writer;
use Mihdan\IndexNow\SEOCore\Redirects\RedirectsManager;
use Mihdan\IndexNow\SEOCore\TitleMeta\Entities;
use Mihdan\IndexNow\SEOCore\TitleMeta\Variables;

class AIOSEO extends Source
{
	public function id(): string
	{
		return 'aioseo';
	}

	public function label(): string
	{
		return 'All in One SEO';
	}

	public function is_available(): bool
	{
		return defined('AIOSEO_VERSION')
			|| get_option('aioseo_options') !== false
			|| $this->table_exists($this->table('posts'))
			|| $this->has_meta('_aioseop_title');
	}

	public function counts(): array
	{
		$posts = $this->count_table($this->table('posts'));

		if ($posts === 0) {
			$posts = $this->count_meta('_aioseop_title');
		}

		return [
			'posts'     => $posts,
			'terms'     => $this->count_table($this->table('terms')),
			'users'     => 0,
			'redirects' => $this->count_table($this->table('redirects')),
		];
	}

	public function import_posts(int $offset, int $limit, bool $overwrite): array
	{
		global $wpdb;

		$table = $this->table('posts');

		if ($this->table_exists($table)) {
			$rows = $wpdb->get_results(
				$this->table_query('SELECT * FROM %i ORDER BY id ASC LIMIT %d OFFSET %d', $table, $limit, $offset),
				ARRAY_A
			);

			$rows     = (array) $rows;
			$imported = 0;
			$skipped  = 0;

			$this->prime_meta('post', array_map(static function ($row) {
				return (int) ($row['post_id'] ?? 0);
			}, $rows));

			foreach ($rows as $row) {
				$data = $this->row_payload($row);

				if ($data === []) {
					continue;
				}

				Writer::write_post((int) $row['post_id'], $data, $overwrite) ? $imported++ : $skipped++;
			}

			return $this->batch_result($imported, $skipped, $offset, count($rows), $limit);
		}

		$ids      = $this->post_ids($offset, $limit);
		$imported = 0;
		$skipped  = 0;

		foreach ($ids as $post_id) {
			$data = $this->legacy_post_payload($post_id);

			if ($data === []) {
				continue;
			}

			Writer::write_post($post_id, $data, $overwrite) ? $imported++ : $skipped++;
		}

		return $this->batch_result($imported, $skipped, $offset, count($ids), $limit);
	}

	public function import_terms(int $offset, int $limit, bool $overwrite): array
	{
		global $wpdb;

		$table = $this->table('terms');

		if (! $this->table_exists($table)) {
			return $this->batch_result(0, 0, $offset, 0, $limit);
		}

		$rows = (array) $wpdb->get_results(
			$this->table_query('SELECT * FROM %i ORDER BY id ASC LIMIT %d OFFSET %d', $table, $limit, $offset),
			ARRAY_A
		);

		$imported = 0;
		$skipped  = 0;

		$this->prime_meta('term', array_map(static function ($row) {
			return (int) ($row['term_id'] ?? 0);
		}, $rows));

		foreach ($rows as $row) {
			$data = $this->row_payload($row);

			if ($data === []) {
				continue;
			}

			$term_id = (int) ($row['term_id'] ?? 0);

			Writer::write_term($term_id, $data, $overwrite) ? $imported++ : $skipped++;
		}

		return $this->batch_result($imported, $skipped, $offset, count($rows), $limit);
	}

	public function import_redirects(int $offset, int $limit): array
	{
		global $wpdb;

		$table = $this->table('redirects');

		if (! $this->table_exists($table)) {
			return $this->batch_result(0, 0, $offset, 0, $limit);
		}

		$rows = $wpdb->get_results(
			$this->table_query('SELECT * FROM %i ORDER BY id ASC LIMIT %d OFFSET %d', $table, $limit, $offset),
			ARRAY_A
		);

		if (! is_array($rows) || $rows === []) {
			return $this->batch_result(0, 0, $offset, 0, $limit);
		}

		$manager  = new RedirectsManager();
		$imported = 0;
		$skipped  = 0;

		foreach ($rows as $row) {
			$from = (string) ($row['source_url'] ?? $row['from_url'] ?? '');

			if ($from === '' || $manager->exists_from_url($from)) {
				$skipped++;
				continue;
			}

			$type = (int) ($row['type'] ?? 301);
			$ok   = $manager->insert([
				'from_url'            => $from,
				'to_url'              => (string) ($row['target_url'] ?? ''),
				'redirect_type'       => in_array($type, [301, 302, 307, 410, 451], true) ? $type : 301,
				'match_type'          => ! empty($row['regex']) ? 'regex' : 'exact',
				'note'                => __('Imported from All in One SEO', 'mihdan-index-now'),
				'ignore_query_string' => 1,
				'enabled'             => empty($row['enabled']) ? 1 : (int) (bool) $row['enabled'],
			]);

			$ok ? $imported++ : $skipped++;
		}

		return $this->batch_result($imported, $skipped, $offset, count($rows), $limit);
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function settings_payload(): array
	{
		$options = $this->decode_option('aioseo_options');
		$dynamic = $this->decode_option('aioseo_options_dynamic');

		if ($options === [] && $dynamic === []) {
			return [];
		}

		$global = $this->dig($options, 'searchAppearance', 'global');

		$entities = [];

		$entities['home'] = $this->entity_fields([
			'title'       => $global['siteTitle'] ?? '',
			'description' => $global['metaDescription'] ?? '',
		]);

		foreach (Entities::post_types() as $post_type) {
			$node = $this->dig($dynamic, 'searchAppearance', 'postTypes', $post_type->name);

			$entities[ Entities::post_type_key($post_type->name) ] = $this->entity_fields($node);
		}

		foreach (Entities::taxonomies() as $taxonomy) {
			$node = $this->dig($dynamic, 'searchAppearance', 'taxonomies', $taxonomy->name);

			$entities[ Entities::taxonomy_key($taxonomy->name) ] = $this->entity_fields($node);
		}

		$entities['author']    = $this->entity_fields($this->dig($dynamic, 'searchAppearance', 'archives', 'author'));
		$entities['date']      = $this->entity_fields($this->dig($dynamic, 'searchAppearance', 'archives', 'date'));
		$entities['search']    = $this->entity_fields($this->dig($dynamic, 'searchAppearance', 'archives', 'search'));
		$entities['not_found'] = $this->entity_fields($this->dig($dynamic, 'searchAppearance', 'archives', 'notFound'));

		return array_filter([
			'separator' => $this->separator((string) ($global['separator'] ?? '')),
			'entities'  => array_filter($entities),
			'site_info' => $this->site_info($this->dig($options, 'searchAppearance', 'global', 'schema')),
			'social'    => $this->social($options),
			'sitemap'   => $this->sitemap($options),
		]);
	}

	/**
	 * Title/description/robots for one AIOSEO search-appearance node.
	 *
	 * @param array<string,mixed> $node
	 *
	 * @return array<string,string>
	 */
	private function entity_fields(array $node): array
	{
		$fields = [];

		$title = (string) ($node['title'] ?? '');
		$desc  = (string) ($node['metaDescription'] ?? $node['description'] ?? '');

		if ($title !== '') {
			$fields['title'] = $this->convert($title);
		}

		if ($desc !== '') {
			$fields['description'] = $this->convert($desc);
		}

		$robots = $this->dig($node, 'advanced', 'robotsMeta');

		if ($robots === [] || ! empty($robots['default'])) {
			return $fields;
		}

		$fields['noindex']   = ! empty($robots['noindex']) ? 'on' : 'off';
		$fields['nofollow']  = ! empty($robots['nofollow']) ? 'on' : 'off';
		$fields['noarchive'] = ! empty($robots['noarchive']) ? 'on' : 'off';

		return $fields;
	}

	/**
	 * @param array<string,mixed> $schema
	 *
	 * @return array<string,mixed>
	 */
	private function site_info(array $schema): array
	{
		$represents = (string) ($schema['siteRepresents'] ?? '');
		$is_person  = $represents === 'person';

		$info = [
			'site_type' => $is_person ? 'person' : 'organization',
			'site_name' => (string) ($schema['organizationName'] ?? ''),
		];

		$logo = absint($schema['organizationLogo'] ?? 0);

		if ($logo > 0) {
			$info['logo'] = $logo;
		}

		return array_filter($info, static function ($v) {
			return $v !== '' && $v !== 0;
		});
	}

	/**
	 * @param array<string,mixed> $options
	 *
	 * @return array<string,mixed>
	 */
	private function social(array $options): array
	{
		$urls   = $this->dig($options, 'social', 'profiles', 'urls');
		$handle = (string) ($urls['twitterUrl'] ?? '');

		if ($handle !== '' && strpos($handle, 'http') === 0) {
			$handle = (string) preg_replace('#^https?://(?:www\.)?(?:twitter|x)\.com/#', '', $handle);
		}

		$handle = trim($handle, '/');

		$fields = [
			'facebook_author' => (string) ($urls['facebookPageUrl'] ?? ''),
			'twitter_creator' => $handle === '' ? '' : '@' . ltrim($handle, '@'),
			'fb_app_id'       => (string) ($this->dig($options, 'social', 'facebook', 'advanced')['appId'] ?? ''),
		];

		$card = (string) ($this->dig($options, 'social', 'twitter', 'general')['defaultCardType'] ?? '');

		if (in_array($card, ['summary', 'summary_large_image'], true)) {
			$fields['twitter_card'] = $card;
		}

		return array_filter($fields, static function ($v) {
			return $v !== '';
		});
	}

	/**
	 * @param array<string,mixed> $options
	 *
	 * @return array<string,string>
	 */
	private function sitemap(array $options): array
	{
		$fields = [];

		if (! empty($this->dig($options, 'sitemap', 'news')['enable'])) {
			$fields['news_enabled'] = 'on';
		}

		if (! empty($this->dig($options, 'sitemap', 'video')['enable'])) {
			$fields['video_enabled'] = 'on';
		}

		return $fields;
	}

	private function separator(string $stored): string
	{
		$stored = trim($stored);

		return array_key_exists($stored, Variables::separator_choices()) ? $stored : '';
	}

	/**
	 * AIOSEO stores its settings as a JSON string.
	 *
	 * @return array<string,mixed>
	 */
	private function decode_option(string $name): array
	{
		$raw = get_option($name);

		if (is_array($raw)) {
			return $raw;
		}

		if (! is_string($raw) || $raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);

		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Safely walk a nested array.
	 *
	 * @param array<string,mixed> $source
	 *
	 * @return array<string,mixed>
	 */
	private function dig(array $source, string ...$keys): array
	{
		$node = $source;

		foreach ($keys as $key) {
			if (! is_array($node) || ! isset($node[$key])) {
				return [];
			}

			$node = $node[$key];
		}

		return is_array($node) ? $node : [];
	}

	private function table(string $suffix): string
	{
		global $wpdb;

		return $wpdb->prefix . 'aioseo_' . $suffix;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function row_payload(array $row): array
	{
		$kw = '';
		if (! empty($row['keyphrases'])) {
			$decoded = json_decode((string) $row['keyphrases'], true);
			if (is_array($decoded)) {
				$kw = (string) ($decoded['focus']['keyphrase'] ?? $decoded['keyphrase'] ?? '');
			}
		}

		$primary_category = 0;
		$raw_primary      = $row['primary_term'] ?? null;
		if (is_numeric($raw_primary)) {
			$primary_category = (int) $raw_primary;
		} elseif (is_string($raw_primary) && strpos($raw_primary, '{') !== false) {
			$decoded = json_decode($raw_primary, true);
			if (is_array($decoded)) {
				$primary_category = (int) ($decoded['category'] ?? reset($decoded) ?? 0);
			}
		}

		$schema_page_type    = '';
		$schema_article_type = '';
		if (! empty($row['schema_type_options'])) {
			$schema_opts = json_decode((string) $row['schema_type_options'], true);
			if (is_array($schema_opts)) {
				if (! empty($schema_opts['webPage']['webPageType'])) {
					$schema_page_type = (string) $schema_opts['webPage']['webPageType'];
				}
				if (! empty($schema_opts['article']['articleType'])) {
					$schema_article_type = (string) $schema_opts['article']['articleType'];
				}
			}
		}

		if ($schema_page_type === '' && ! empty($row['schema_type'])) {
			if (in_array($row['schema_type'], ['WebPage', 'AboutPage', 'ContactPage', 'FAQPage', 'ItemPage', 'ProfilePage'], true)) {
				$schema_page_type = (string) $row['schema_type'];
			} elseif ($row['schema_type'] === 'Article' && $schema_article_type === '') {
				$schema_article_type = 'Article';
			}
		}

		/*
		 * AIOSEO only honours its per-object robots columns when `robots_default`
		 * is off, so the advanced directives are read under the same condition.
		 */
		$robots_advanced = [];
		$max_snippet     = null;
		$max_image       = '';

		if (empty($row['robots_default'])) {
			$robots_advanced = array_keys(array_filter([
				'noarchive'    => ! empty($row['robots_noarchive']),
				'nosnippet'    => ! empty($row['robots_nosnippet']),
				'noimageindex' => ! empty($row['robots_noimageindex']),
				'notranslate'  => ! empty($row['robots_notranslate']),
			]));

			$max_snippet = $row['robots_max_snippet'] ?? null;
			$max_image   = (string) ($row['robots_max_imagepreview'] ?? '');
		}

		$card = (string) ($row['twitter_card'] ?? '');
		if ($card === 'default') {
			$card = '';
		}

		$data = [
			'title'               => $this->convert((string) ($row['title'] ?? '')),
			'description'         => $this->convert((string) ($row['description'] ?? '')),
			'focus_keyword'       => $kw,
			'canonical'           => (string) ($row['canonical_url'] ?? ''),
			'og_title'            => $this->convert((string) ($row['og_title'] ?? '')),
			'og_description'      => $this->convert((string) ($row['og_description'] ?? '')),
			'og_image'            => (string) ($row['og_image_url'] ?? '')
				?: (string) ($row['og_image_custom_url'] ?? ''),
			'x_title'             => $this->convert((string) ($row['twitter_title'] ?? '')),
			'x_description'       => $this->convert((string) ($row['twitter_description'] ?? '')),
			'x_image'             => (string) ($row['twitter_image_url'] ?? '')
				?: (string) ($row['twitter_image_custom_url'] ?? ''),
			'x_card_type'         => $card,
			'max_image'           => $max_image,
			'primary_category'    => $primary_category,
			'cornerstone'         => ! empty($row['pillar_content']),
			'schema_page_type'    => $schema_page_type,
			'schema_article_type' => $schema_article_type,
		];

		if (! empty($row['robots_noindex'])) {
			$data['robots_index'] = 'noindex';
		}

		if (! empty($row['robots_nofollow'])) {
			$data['robots_follow'] = 'nofollow';
		}

		if ($robots_advanced !== []) {
			$data['robots_advanced'] = $robots_advanced;
		}

		$data = array_filter($data, static function ($v) {
			return $v !== '' && $v !== null && $v !== 0 && $v !== '0' && $v !== false;
		});

		/*
		 * A zero length means "no snippet", so it is added after the filter that
		 * drops empty values.
		 */
		if (is_numeric($max_snippet)) {
			$data['max_snippet'] = $max_snippet;
		}

		return $data;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function legacy_post_payload(int $post_id): array
	{
		$primary_category = (int) get_post_meta($post_id, '_aioseop_primary_term', true);
		if ($primary_category === 0) {
			$primary_category = (int) get_post_meta($post_id, '_aioseop_primary_category', true);
		}

		$data = [
			'title'            => $this->convert((string) get_post_meta($post_id, '_aioseop_title', true)),
			'description'      => $this->convert((string) get_post_meta($post_id, '_aioseop_description', true)),
			'focus_keyword'    => (string) get_post_meta($post_id, '_aioseop_keywords', true),
			'canonical'        => (string) get_post_meta($post_id, '_aioseop_custom_link', true),
			'primary_category' => $primary_category,
		];

		if ((string) get_post_meta($post_id, '_aioseop_noindex', true) === 'on') {
			$data['robots_index'] = 'noindex';
		}

		if ((string) get_post_meta($post_id, '_aioseop_nofollow', true) === 'on') {
			$data['robots_follow'] = 'nofollow';
		}

		return array_filter($data, static function ($v) {
			return $v !== '' && $v !== null;
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

	private function count_meta(string $key): int
	{
		global $wpdb;

		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''",
			$key
		));
	}
}
