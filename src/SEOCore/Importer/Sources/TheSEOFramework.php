<?php

namespace Mihdan\IndexNow\SEOCore\Importer\Sources;

use Mihdan\IndexNow\SEOCore\Importer\Source;
use Mihdan\IndexNow\SEOCore\Importer\Writer;
use Mihdan\IndexNow\SEOCore\TitleMeta\Entities;

class TheSEOFramework extends Source
{
	public function id(): string
	{
		return 'tsf';
	}

	public function label(): string
	{
		return 'The SEO Framework';
	}

	public function is_available(): bool
	{
		return defined('THE_SEO_FRAMEWORK_VERSION')
			|| get_option('autodescription-site-settings') !== false
			|| $this->has_meta('_genesis_title')
			|| $this->has_meta('_genesis_description');
	}

	public function counts(): array
	{
		return [
			'posts'     => $this->count_objects_with_meta('post', ['_genesis_title', '_genesis_description']),
			'terms'     => $this->count_term_meta('autodescription-term-settings'),
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
		$terms    = $this->terms($offset, $limit);
		$imported = 0;
		$skipped  = 0;

		foreach ($terms as $term) {
			$meta = get_term_meta((int) $term->term_id, 'autodescription-term-settings', true);

			if (! is_array($meta) || $meta === []) {
				continue;
			}

			$data = [
				'title'       => $this->convert((string) ($meta['doctitle'] ?? $meta['title'] ?? '')),
				'description' => $this->convert((string) ($meta['description'] ?? '')),
				'canonical'   => (string) ($meta['canonical'] ?? ''),
				'og_image'    => (string) ($meta['social_image_url'] ?? ''),
				'og_title'    => $this->convert((string) ($meta['og_title'] ?? '')),
				'x_title'     => $this->convert((string) ($meta['twitter_title'] ?? '')),
			];

			if (! empty($meta['noindex'])) {
				$data['robots_index'] = 'noindex';
			}

			if (! empty($meta['nofollow'])) {
				$data['robots_follow'] = 'nofollow';
			}

			$data = array_filter($data, static function ($v) {
				return $v !== '' && $v !== null;
			});

			if ($data === []) {
				continue;
			}

			Writer::write_term((int) $term->term_id, $data, $overwrite) ? $imported++ : $skipped++;
		}

		return $this->batch_result($imported, $skipped, $offset, count($terms), $limit);
	}

	/**
	 * The SEO Framework's `tsf-user-meta` holds social profile links and
	 * editor preferences only — there is no per-author title, description or
	 * robots value to import, so this stage is skipped.
	 */
	public function import_users(int $offset, int $limit, bool $overwrite): array
	{
		return $this->single_batch(0, 0);
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function settings_payload(): array
	{
		$settings = get_option('autodescription-site-settings');

		if (! is_array($settings) || $settings === []) {
			return [];
		}

		$noindex_post_types = is_array($settings['noindex_post_types'] ?? null) ? $settings['noindex_post_types'] : [];
		$noindex_taxonomies = is_array($settings['noindex_taxonomies'] ?? null) ? $settings['noindex_taxonomies'] : [];

		$entities = [];

		$entities['home'] = array_filter([
			'title'       => $this->convert((string) ($settings['homepage_title'] ?? '')),
			'description' => $this->convert((string) ($settings['homepage_description'] ?? '')),
			'noindex'     => ! empty($settings['homepage_noindex']) ? 'on' : null,
		], static function ($v) {
			return $v !== '' && $v !== null;
		});

		foreach (Entities::post_types() as $post_type) {
			$fields = [];

			if (isset($noindex_post_types[ $post_type->name ])) {
				$fields['noindex'] = ! empty($noindex_post_types[ $post_type->name ]) ? 'on' : 'off';
			}

			$entities[ Entities::post_type_key($post_type->name) ] = $fields;
		}

		foreach (Entities::taxonomies() as $taxonomy) {
			$fields = [];

			if (isset($noindex_taxonomies[ $taxonomy->name ])) {
				$fields['noindex'] = ! empty($noindex_taxonomies[ $taxonomy->name ]) ? 'on' : 'off';
			}

			$entities[ Entities::taxonomy_key($taxonomy->name) ] = $fields;
		}

		if (isset($settings['author_noindex'])) {
			$entities['author'] = ['noindex' => ! empty($settings['author_noindex']) ? 'on' : 'off'];
		}

		if (isset($settings['date_noindex'])) {
			$entities['date'] = ['noindex' => ! empty($settings['date_noindex']) ? 'on' : 'off'];
		}

		if (isset($settings['search_noindex'])) {
			$entities['search'] = ['noindex' => ! empty($settings['search_noindex']) ? 'on' : 'off'];
		}

		return array_filter([
			'separator' => $this->separator((string) ($settings['title_separator'] ?? '')),
			'entities'  => array_filter($entities),
			'site_info' => $this->site_info($settings),
			'social'    => $this->social($settings),
		]);
	}

	/**
	 * @param array<string,mixed> $settings
	 *
	 * @return array<string,mixed>
	 */
	private function site_info(array $settings): array
	{
		$type = (string) ($settings['knowledge_type'] ?? '');

		$info = [
			'site_type' => $type === 'person' ? 'person' : 'organization',
			'site_name' => (string) ($settings['knowledge_name'] ?? ''),
		];

		return array_filter($info, static function ($v) {
			return $v !== '';
		});
	}

	/**
	 * @param array<string,mixed> $settings
	 *
	 * @return array<string,mixed>
	 */
	private function social(array $settings): array
	{
		$handle = (string) ($settings['twitter_site'] ?? '');

		$fields = [
			'facebook_author' => (string) ($settings['facebook_publisher'] ?? ''),
			'twitter_creator' => $handle === '' ? '' : '@' . ltrim($handle, '@'),
			'fb_app_id'       => (string) ($settings['facebook_appid'] ?? ''),
		];

		$card = (string) ($settings['twitter_card'] ?? '');

		if (in_array($card, ['summary', 'summary_large_image'], true)) {
			$fields['twitter_card'] = $card;
		}

		$image = absint($settings['social_image_fb_id'] ?? 0);

		if ($image > 0) {
			$fields['social_image_fallback'] = $image;
		}

		return array_filter($fields, static function ($v) {
			return $v !== '' && $v !== 0;
		});
	}

	/**
	 * TSF stores the separator by name.
	 */
	private function separator(string $stored): string
	{
		$map = [
			'pipe'   => '|',
			'dash'   => '-',
			'hyphen' => '-',
			'ndash'  => '–',
			'mdash'  => '—',
			'bull'   => '•',
			'middot' => '·',
			'sdot'   => '·',
			'lt'     => '<',
			'gt'     => '>',
			'laquo'  => '«',
			'raquo'  => '»',
			'sim'    => '~',
			'slash'  => '/',
		];

		return $map[ trim($stored) ] ?? '';
	}

	/**
	 * @return array<string,mixed>
	 */
	private function post_payload(int $post_id): array
	{
		$noindex  = (int) get_post_meta($post_id, '_genesis_noindex', true);
		$nofollow = (int) get_post_meta($post_id, '_genesis_nofollow', true);

		$data = [
			'title'         => $this->convert((string) get_post_meta($post_id, '_genesis_title', true)),
			'description'   => $this->convert((string) get_post_meta($post_id, '_genesis_description', true)),
			'canonical'     => (string) get_post_meta($post_id, '_genesis_canonical_uri', true),
			'og_image'      => (string) get_post_meta($post_id, '_social_image_id', true)
				?: (string) get_post_meta($post_id, '_social_image_url', true),
			'og_title'      => $this->convert((string) get_post_meta($post_id, '_open_graph_title', true)),
			'og_description'=> $this->convert((string) get_post_meta($post_id, '_open_graph_description', true)),
			'x_title'       => $this->convert((string) get_post_meta($post_id, '_twitter_title', true)),
			'x_description' => $this->convert((string) get_post_meta($post_id, '_twitter_description', true)),
			'redirect_url'  => (string) get_post_meta($post_id, '_genesis_redirect', true)
				?: (string) get_post_meta($post_id, '_redirect_url', true),
		];

		if ($noindex === 1) {
			$data['robots_index'] = 'noindex';
		}

		if ($nofollow === 1) {
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

	private function count_term_meta(string $key): int
	{
		global $wpdb;

		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value <> ''",
			$key
		));
	}
}
