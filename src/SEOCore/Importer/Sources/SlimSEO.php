<?php

namespace Mihdan\IndexNow\SEOCore\Importer\Sources;

use Mihdan\IndexNow\SEOCore\Importer\Source;
use Mihdan\IndexNow\SEOCore\Importer\Writer;
use Mihdan\IndexNow\SEOCore\Redirects\RedirectsManager;
use Mihdan\IndexNow\SEOCore\TitleMeta\Entities;

class SlimSEO extends Source
{
	public function id(): string
	{
		return 'slimseo';
	}

	public function label(): string
	{
		return 'Slim SEO';
	}

	public function is_available(): bool
	{
		return defined('SLIM_SEO_VER')
			|| get_option('slim_seo') !== false
			|| $this->has_meta('slim_seo')
			|| get_option('ss_redirects') !== false;
	}

	public function counts(): array
	{
		return [
			'posts'     => $this->count_meta('slim_seo'),
			'terms'     => $this->count_term_meta('slim_seo'),
			'users'     => 0,
			'redirects' => count($this->redirects_option()),
		];
	}

	public function import_posts(int $offset, int $limit, bool $overwrite): array
	{
		$ids      = $this->post_ids($offset, $limit);
		$imported = 0;
		$skipped  = 0;

		foreach ($ids as $post_id) {
			$data = $this->from_bundle(get_post_meta($post_id, 'slim_seo', true));

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
			$data = $this->from_bundle(get_term_meta((int) $term->term_id, 'slim_seo', true));

			if ($data === []) {
				continue;
			}

			Writer::write_term((int) $term->term_id, $data, $overwrite) ? $imported++ : $skipped++;
		}

		return $this->batch_result($imported, $skipped, $offset, count($terms), $limit);
	}

	public function import_redirects(int $offset, int $limit): array
	{
		$redirects = $this->redirects_option();

		if ($redirects === []) {
			return $this->batch_result(0, 0, $offset, 0, $limit);
		}

		$batch = array_slice(array_values($redirects), $offset, $limit);

		if ($batch === []) {
			return $this->batch_result(0, 0, $offset, 0, $limit);
		}

		$manager  = new RedirectsManager();
		$imported = 0;
		$skipped  = 0;

		$cond_map = [
			'exact-match' => 'exact',
			'contain'     => 'contains',
			'start-with'  => 'starts_with',
			'end-with'    => 'ends_with',
			'regex'       => 'regex',
		];

		foreach ($batch as $row) {
			if (! is_array($row)) {
				$skipped++;
				continue;
			}

			$from = (string) ($row['from'] ?? $row['from_url'] ?? '');

			if ($from === '' || $manager->exists_from_url($from)) {
				$skipped++;
				continue;
			}

			$type = (int) ($row['type'] ?? $row['redirect_type'] ?? 301);
			$cond = (string) ($row['condition'] ?? $row['match_type'] ?? 'exact');

			if (isset($cond_map[$cond])) {
				$match = $cond_map[$cond];
			} elseif (in_array($cond, ['exact', 'regex', 'contains', 'starts_with', 'ends_with'], true)) {
				$match = $cond;
			} else {
				$match = 'exact';
			}

			$ok = $manager->insert([
				'from_url'            => $from,
				'to_url'              => (string) ($row['to'] ?? $row['to_url'] ?? ''),
				'redirect_type'       => in_array($type, [301, 302, 307, 410, 451], true) ? $type : 301,
				'match_type'          => $match,
				'note'                => __('Imported from Slim SEO', 'mihdan-index-now'),
				'ignore_query_string' => ! empty($row['ignoreParameters']) ? 1 : 0,
				'enabled'             => isset($row['enable']) ? (int) (bool) $row['enable'] : 1,
			]);

			$ok ? $imported++ : $skipped++;
		}

		return $this->batch_result($imported, $skipped, $offset, count($batch), $limit);
	}

	/**
	 * Slim SEO keeps its global defaults in a single nested `slim_seo` option.
	 *
	 * It has no separator, robots-default or organization settings, so only
	 * the template fields are mapped.
	 *
	 * @return array<string,mixed>
	 */
	protected function settings_payload(): array
	{
		$option = get_option('slim_seo');

		if (! is_array($option) || $option === []) {
			return [];
		}

		$entities = [];

		$entities['home'] = $this->entity_fields($option['home'] ?? []);

		$post_types = is_array($option['post_types'] ?? null) ? $option['post_types'] : [];
		$taxonomies = is_array($option['taxonomies'] ?? null) ? $option['taxonomies'] : [];

		foreach (Entities::post_types() as $post_type) {
			$entities[ Entities::post_type_key($post_type->name) ] = $this->entity_fields($post_types[ $post_type->name ] ?? []);
		}

		foreach (Entities::taxonomies() as $taxonomy) {
			$entities[ Entities::taxonomy_key($taxonomy->name) ] = $this->entity_fields($taxonomies[ $taxonomy->name ] ?? []);
		}

		$entities['author'] = $this->entity_fields($option['author'] ?? []);
		$entities['date']   = $this->entity_fields($option['date'] ?? []);
		$entities['search'] = $this->entity_fields($option['search'] ?? []);

		$entities = array_filter($entities);

		if ($entities === []) {
			return [];
		}

		return ['entities' => $entities];
	}

	/**
	 * @param mixed $node
	 *
	 * @return array<string,string>
	 */
	private function entity_fields($node): array
	{
		if (! is_array($node)) {
			return [];
		}

		$fields = [];

		$title = (string) ($node['title'] ?? '');
		$desc  = (string) ($node['description'] ?? '');

		if ($title !== '') {
			$fields['title'] = $this->convert($title);
		}

		if ($desc !== '') {
			$fields['description'] = $this->convert($desc);
		}

		if (isset($node['noindex'])) {
			$fields['noindex'] = ! empty($node['noindex']) ? 'on' : 'off';
		}

		return $fields;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function redirects_option(): array
	{
		$redirects = get_option('ss_redirects');

		return is_array($redirects) ? $redirects : [];
	}

	/**
	 * @param mixed $bundle
	 * @return array<string,mixed>
	 */
	private function from_bundle($bundle): array
	{
		if (! is_array($bundle) || $bundle === []) {
			return [];
		}

		$data = [
			'title'         => $this->convert((string) ($bundle['title'] ?? '')),
			'description'   => $this->convert((string) ($bundle['description'] ?? '')),
			'canonical'     => (string) ($bundle['canonical'] ?? $bundle['canonical_url'] ?? ''),
			'og_image'      => (string) ($bundle['facebook_image'] ?? $bundle['og_image'] ?? ''),
			'x_image'       => (string) ($bundle['twitter_image'] ?? ''),
			'og_title'      => $this->convert((string) ($bundle['facebook_title'] ?? '')),
			'og_description'=> $this->convert((string) ($bundle['facebook_description'] ?? '')),
			'x_title'       => $this->convert((string) ($bundle['twitter_title'] ?? '')),
			'x_description' => $this->convert((string) ($bundle['twitter_description'] ?? '')),
		];

		$noindex = $bundle['noindex'] ?? $bundle['robots_index'] ?? null;

		if ($noindex === 1 || $noindex === '1' || $noindex === true || $noindex === 'noindex') {
			$data['robots_index'] = 'noindex';
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

	private function count_term_meta(string $key): int
	{
		global $wpdb;

		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value <> ''",
			$key
		));
	}
}
