<?php

namespace Mihdan\IndexNow\SEOCore\Importer\Sources;

use Mihdan\IndexNow\SEOCore\Importer\Source;
use Mihdan\IndexNow\SEOCore\Importer\Writer;
use Mihdan\IndexNow\SEOCore\Redirects\RedirectsManager;
use Mihdan\IndexNow\SEOCore\TitleMeta\Entities;
use Mihdan\IndexNow\SEOCore\TitleMeta\Variables;

class RankMath extends Source
{
	public function id(): string
	{
		return 'rankmath';
	}

	public function label(): string
	{
		return 'Rank Math';
	}

	public function is_available(): bool
	{
		return defined('RANK_MATH_VERSION')
			|| get_option('rank-math-options-general') !== false
			|| $this->has_meta('rank_math_title');
	}

	public function counts(): array
	{
		return [
			'posts'     => $this->count_objects_with_meta('post', ['rank_math_title', 'rank_math_description']),
			'terms'     => $this->count_term_meta('rank_math_title'),
			'users'     => $this->count_objects_with_meta('user', ['rank_math_title', 'rank_math_description']),
			'redirects' => $this->count_table($this->redirects_table()),
		];
	}

	public function import_posts(int $offset, int $limit, bool $overwrite): array
	{
		$ids      = $this->post_ids($offset, $limit);
		$imported = 0;
		$skipped  = 0;

		foreach ($ids as $post_id) {
			$data = $this->object_payload('post', $post_id);

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
			$data = $this->object_payload('term', (int) $term->term_id);

			if ($data === []) {
				continue;
			}

			Writer::write_term((int) $term->term_id, $data, $overwrite) ? $imported++ : $skipped++;
		}

		return $this->batch_result($imported, $skipped, $offset, count($terms), $limit);
	}

	public function import_users(int $offset, int $limit, bool $overwrite): array
	{
		$ids      = $this->user_ids($offset, $limit);
		$imported = 0;
		$skipped  = 0;

		foreach ($ids as $user_id) {
			$data = $this->object_payload('user', $user_id);

			if ($data === []) {
				continue;
			}

			Writer::write_user($user_id, $data, $overwrite) ? $imported++ : $skipped++;
		}

		return $this->batch_result($imported, $skipped, $offset, count($ids), $limit);
	}

	public function import_redirects(int $offset, int $limit): array
	{
		global $wpdb;

		$table = $this->redirects_table();

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
			$sources = maybe_unserialize($row['sources'] ?? '');

			if (! is_array($sources)) {
				$skipped++;
				continue;
			}

			foreach ($sources as $source) {
				$from = is_array($source) ? (string) ($source['pattern'] ?? '') : (string) $source;

				if ($from === '' || $manager->exists_from_url($from)) {
					$skipped++;
					continue;
				}

				$comparison = is_array($source) ? (string) ($source['comparison'] ?? 'exact') : 'exact';
				$match      = $comparison === 'regex' ? 'regex' : 'exact';
				$type       = (int) ($row['header_code'] ?? 301);
				$enabled    = (($row['status'] ?? 'active') === 'active') ? 1 : 0;

				$ok = $manager->insert([
					'from_url'            => $from,
					'to_url'              => (string) ($row['url_to'] ?? ''),
					'redirect_type'       => in_array($type, [301, 302, 307, 410, 451], true) ? $type : 301,
					'match_type'          => $match,
					'note'                => __('Imported from Rank Math', 'mihdan-index-now'),
					'ignore_query_string' => 1,
					'enabled'             => $enabled,
				]);

				$ok ? $imported++ : $skipped++;
			}
		}

		return $this->batch_result($imported, $skipped, $offset, count($rows), $limit);
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function settings_payload(): array
	{
		$titles = get_option('rank-math-options-titles');

		if (! is_array($titles) || $titles === []) {
			return [];
		}

		$entities = [];

		$entities['home'] = $this->entity_fields($titles, 'homepage_title', 'homepage_description', 'homepage');

		foreach (Entities::post_types() as $post_type) {
			$prefix = 'pt_' . $post_type->name;
			$fields = $this->entity_fields($titles, $prefix . '_title', $prefix . '_description', $prefix);

			$archive_title = (string) ($titles[ $prefix . '_archive_title' ] ?? '');
			$archive_desc  = (string) ($titles[ $prefix . '_archive_description' ] ?? '');

			if ($archive_title !== '') {
				$fields['archive_title'] = $this->convert($archive_title);
			}

			if ($archive_desc !== '') {
				$fields['archive_description'] = $this->convert($archive_desc);
			}

			$entities[ Entities::post_type_key($post_type->name) ] = $fields;
		}

		foreach (Entities::taxonomies() as $taxonomy) {
			$prefix = 'tax_' . $taxonomy->name;

			$entities[ Entities::taxonomy_key($taxonomy->name) ] = $this->entity_fields(
				$titles,
				$prefix . '_title',
				$prefix . '_description',
				$prefix
			);
		}

		$entities['author'] = $this->entity_fields($titles, 'author_archive_title', 'author_archive_description', 'author');

		if (($titles['disable_author_archives'] ?? '') === 'on') {
			$entities['author']['noindex'] = 'on';
		}

		$entities['date'] = $this->entity_fields($titles, 'date_archive_title', 'date_archive_description', 'date');

		if (($titles['disable_date_archives'] ?? '') === 'on') {
			$entities['date']['noindex'] = 'on';
		}

		$entities['search']    = $this->entity_fields($titles, 'search_title', '', '');
		$entities['not_found'] = $this->entity_fields($titles, '404_title', '', '');

		return array_filter([
			'separator' => $this->separator((string) ($titles['title_separator'] ?? '')),
			'entities'  => array_filter($entities),
			'site_info' => $this->site_info($titles),
			'social'    => $this->social($titles),
			'sitemap'   => $this->sitemap(),
		]);
	}

	/**
	 * Title/description/robots trio for one entity screen.
	 *
	 * Rank Math only honours its `*_robots` array when the matching
	 * `*_custom_robots` switch is on.
	 *
	 * @param array<string,mixed> $titles
	 *
	 * @return array<string,string>
	 */
	private function entity_fields(array $titles, string $title_key, string $description_key, string $robots_prefix): array
	{
		$fields = [];

		$title = (string) ($titles[$title_key] ?? '');

		if ($title !== '') {
			$fields['title'] = $this->convert($title);
		}

		if ($description_key !== '') {
			$description = (string) ($titles[$description_key] ?? '');

			if ($description !== '') {
				$fields['description'] = $this->convert($description);
			}
		}

		if ($robots_prefix === '' || ($titles[ $robots_prefix . '_custom_robots' ] ?? '') !== 'on') {
			return $fields;
		}

		$robots = $titles[ $robots_prefix . '_robots' ] ?? [];

		if (! is_array($robots)) {
			return $fields;
		}

		$fields['noindex']   = in_array('noindex', $robots, true) ? 'on' : 'off';
		$fields['nofollow']  = in_array('nofollow', $robots, true) ? 'on' : 'off';
		$fields['noarchive'] = in_array('noarchive', $robots, true) ? 'on' : 'off';

		return $fields;
	}

	/**
	 * @param array<string,mixed> $titles
	 *
	 * @return array<string,mixed>
	 */
	private function site_info(array $titles): array
	{
		$type = (string) ($titles['knowledgegraph_type'] ?? '');

		$info = [
			'site_type' => $type === 'person' ? 'person' : 'organization',
			'site_name' => (string) ($titles['knowledgegraph_name'] ?? ''),
		];

		$logo = absint($titles['knowledgegraph_logo_id'] ?? 0);

		if ($logo > 0) {
			$info['logo'] = $logo;
		}

		return array_filter($info, static function ($v) {
			return $v !== '' && $v !== 0;
		});
	}

	/**
	 * @param array<string,mixed> $titles
	 *
	 * @return array<string,mixed>
	 */
	private function social(array $titles): array
	{
		$handles = (string) ($titles['twitter_author_names'] ?? '');
		$handle  = trim(explode(',', $handles)[0]);

		$fields = [
			'facebook_author' => (string) ($titles['social_url_facebook'] ?? ''),
			'twitter_creator' => $handle === '' ? '' : '@' . ltrim($handle, '@'),
			'fb_app_id'       => (string) ($titles['facebook_app_id'] ?? ''),
		];

		$card = (string) ($titles['twitter_card_type'] ?? '');

		if (in_array($card, ['summary', 'summary_large_image'], true)) {
			$fields['twitter_card'] = $card;
		}

		$image = absint($titles['open_graph_image_id'] ?? 0);

		if ($image > 0) {
			$fields['social_image_fallback'] = $image;
		}

		return array_filter($fields, static function ($v) {
			return $v !== '' && $v !== 0;
		});
	}

	/**
	 * Rank Math ships the news and video sitemaps as optional modules.
	 *
	 * @return array<string,string>
	 */
	private function sitemap(): array
	{
		$modules = get_option('rank_math_modules');

		if (! is_array($modules)) {
			return [];
		}

		$fields = [];

		if (in_array('news-sitemap', $modules, true)) {
			$fields['news_enabled'] = 'on';
		}

		if (in_array('video-sitemap', $modules, true)) {
			$fields['video_enabled'] = 'on';
		}

		return $fields;
	}

	private function separator(string $stored): string
	{
		$stored = trim($stored);

		return array_key_exists($stored, Variables::separator_choices()) ? $stored : '';
	}

	private function redirects_table(): string
	{
		global $wpdb;

		return $wpdb->prefix . 'rank_math_redirections';
	}

	/**
	 * @return array<string,mixed>
	 */
	private function object_payload(string $type, int $id): array
	{
		if ($type === 'term') {
			$get = 'get_term_meta';
		} elseif ($type === 'user') {
			$get = 'get_user_meta';
		} else {
			$get = 'get_post_meta';
		}

		$title = $this->convert((string) $get($id, 'rank_math_title', true));
		$desc  = $this->convert((string) $get($id, 'rank_math_description', true));
		$kw    = (string) $get($id, 'rank_math_focus_keyword', true);
		$robots = $get($id, 'rank_math_robots', true);
		$noindex = is_array($robots) && in_array('noindex', $robots, true);
		$nofollow = is_array($robots) && in_array('nofollow', $robots, true);

		$robots_advanced = is_array($robots)
			? array_values(array_intersect($robots, Writer::ROBOTS_ADVANCED_VALUES))
			: [];

		$advanced = $get($id, 'rank_math_advanced_robots', true);
		$advanced = is_array($advanced) ? $advanced : [];

		$twitter_use_fb = (string) $get($id, 'rank_math_twitter_use_facebook', true);

		$schema_page_type    = '';
		$schema_article_type = '';
		$redirect_url        = '';

		if ($type === 'post') {
			$snippet = (string) $get($id, 'rank_math_rich_snippet', true);
			if ($snippet === 'off') {
				$schema_article_type = 'none';
			} elseif ($snippet === 'article') {
				$art_type            = (string) $get($id, 'rank_math_article_type', true);
				$schema_article_type = $art_type !== '' ? $art_type : 'Article';
			} elseif ($get($id, 'rank_math_schema_NewsArticle', true)) {
				$schema_article_type = 'NewsArticle';
			} elseif ($get($id, 'rank_math_schema_BlogPosting', true)) {
				$schema_article_type = 'BlogPosting';
			} elseif ($get($id, 'rank_math_schema_Article', true)) {
				$schema_article_type = 'Article';
			}

			$redirect_url = (string) $get($id, 'rank_math_redirection_url', true);
		}

		$data = [
			'title'               => $title,
			'description'         => $desc,
			'focus_keyword'       => $kw,
			'canonical'           => (string) $get($id, 'rank_math_canonical_url', true),
			'og_title'            => $this->convert((string) $get($id, 'rank_math_facebook_title', true)),
			'og_description'      => $this->convert((string) $get($id, 'rank_math_facebook_description', true)),
			'og_image'            => (string) $get($id, 'rank_math_facebook_image_id', true)
				?: (string) $get($id, 'rank_math_facebook_image', true),
			'x_title'             => $this->convert((string) $get($id, 'rank_math_twitter_title', true)),
			'x_description'       => $this->convert((string) $get($id, 'rank_math_twitter_description', true)),
			'x_image'             => $twitter_use_fb === 'on'
				? ''
				: ((string) $get($id, 'rank_math_twitter_image_id', true) ?: (string) $get($id, 'rank_math_twitter_image', true)),
			'x_card_type'         => (string) $get($id, 'rank_math_twitter_card_type', true),
			'max_image'           => (string) ($advanced['max-image-preview'] ?? ''),
			'primary_category'    => (int) $get($id, 'rank_math_primary_category', true),
			'cornerstone'         => (string) $get($id, 'rank_math_pillar_content', true),
			'redirect_url'        => $redirect_url,
			'schema_page_type'    => $schema_page_type,
			'schema_article_type' => $schema_article_type,
		];

		if ($noindex) {
			$data['robots_index'] = 'noindex';
		}

		if ($nofollow) {
			$data['robots_follow'] = 'nofollow';
		}

		if ($robots_advanced !== []) {
			$data['robots_advanced'] = $robots_advanced;
		}

		$data = array_filter($data, static function ($v) {
			return $v !== '' && $v !== null && $v !== 0 && $v !== '0';
		});

		/*
		 * A zero length means "no snippet", so it is added after the filter that
		 * drops empty values.
		 */
		if (is_numeric($advanced['max-snippet'] ?? null)) {
			$data['max_snippet'] = $advanced['max-snippet'];
		}

		return $data;
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

	private function count_term_meta(string $key): int
	{
		global $wpdb;

		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value <> ''",
			$key
		));
	}
}
