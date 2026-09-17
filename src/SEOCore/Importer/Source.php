<?php

namespace Mihdan\IndexNow\SEOCore\Importer;

/**
 * Base importer for a single third-party SEO plugin.
 *
 * Every import_*() method is batched: it receives an offset and a limit, and
 * reports back how far it got so Runner can drive the next request.
 */
abstract class Source
{
	abstract public function id(): string;

	abstract public function label(): string;

	abstract public function is_available(): bool;

	/**
	 * @return array{posts:int,terms:int,users:int,redirects:int}
	 */
	abstract public function counts(): array;

	/**
	 * @return array{imported:int,skipped:int,done:bool,next_offset:int}
	 */
	abstract public function import_posts(int $offset, int $limit, bool $overwrite): array;

	/**
	 * @return array{imported:int,skipped:int,done:bool,next_offset:int}
	 */
	abstract public function import_terms(int $offset, int $limit, bool $overwrite): array;

	/**
	 * Author/user SEO meta. Sources without per-user SEO meta keep the no-op.
	 *
	 * @return array{imported:int,skipped:int,done:bool,next_offset:int}
	 */
	public function import_users(int $offset, int $limit, bool $overwrite): array
	{
		return $this->batch_result(0, 0, $offset, 0, $limit);
	}

	/**
	 * Redirect rules. Batched the same way as posts and terms so large
	 * redirect tables cannot time out a single request.
	 *
	 * @return array{imported:int,skipped:int,done:bool,next_offset:int}
	 */
	public function import_redirects(int $offset, int $limit): array
	{
		return $this->batch_result(0, 0, $offset, 0, $limit);
	}

	/**
	 * Global settings (title/description templates, separator, robots
	 * defaults, organization info, sitemap toggles).
	 *
	 * Always a single batch: the payload is a handful of option rows.
	 *
	 * @return array{imported:int,skipped:int,done:bool,next_offset:int}
	 */
	public function import_settings(int $offset, int $limit, bool $overwrite): array
	{
		$payload = $this->settings_payload();

		if ($payload === []) {
			return $this->single_batch(0, 0);
		}

		$result = Writer::write_settings($payload, $overwrite);

		return $this->single_batch($result['imported'], $result['skipped']);
	}

	/**
	 * Normalised global-settings payload for this source.
	 *
	 * Returning an empty array means "this plugin has no mapping" and the
	 * settings stage is skipped gracefully.
	 *
	 * @see Writer::write_settings() for the payload shape.
	 *
	 * @return array<string,mixed>
	 */
	protected function settings_payload(): array
	{
		return [];
	}

	/**
	 * @return int[]
	 */
	protected function post_ids(int $offset, int $limit): array
	{
		$q = new \WP_Query([
			'post_type'              => 'any',
			'post_status'            => ['publish', 'draft', 'pending', 'future', 'private'],
			'posts_per_page'         => $limit,
			'offset'                 => $offset,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'suppress_filters'       => true,
		]);

		$ids = array_map('intval', $q->posts);

		$this->prime_meta('post', $ids);

		return $ids;
	}

	/**
	 * @return \WP_Term[]
	 */
	protected function terms(int $offset, int $limit): array
	{
		$taxonomies = get_taxonomies(['public' => true], 'names');

		if ($taxonomies === []) {
			return [];
		}

		$terms = get_terms([
			'taxonomy'   => array_values($taxonomies),
			'hide_empty' => false,
			'number'     => $limit,
			'offset'     => $offset,
			'orderby'    => 'term_id',
			'order'      => 'ASC',
		]);

		if (! is_array($terms)) {
			return [];
		}

		$this->prime_meta('term', array_map(static function ($term) {
			return (int) $term->term_id;
		}, $terms));

		return $terms;
	}

	/**
	 * Users that can hold author SEO meta.
	 *
	 * @return int[]
	 */
	protected function user_ids(int $offset, int $limit): array
	{
		$users = get_users([
			'fields'  => 'ID',
			'number'  => $limit,
			'offset'  => $offset,
			'orderby' => 'ID',
			'order'   => 'ASC',
		]);

		$ids = array_map('intval', (array) $users);

		$this->prime_meta('user', $ids);

		return $ids;
	}

	/**
	 * Warm the object meta cache for a whole batch so the per-object reads in
	 * the import loops do not each hit the database.
	 *
	 * @param int[] $ids
	 */
	protected function prime_meta(string $object_type, array $ids): void
	{
		$ids = array_values(array_filter(array_map('intval', $ids)));

		if ($ids === []) {
			return;
		}

		update_meta_cache($object_type, $ids);
	}

	protected function convert(string $text): string
	{
		return TokenMapper::convert($text, $this->id());
	}

	/**
	 * Number of users holding a non-empty value for a meta key.
	 */
	protected function count_user_meta(string $key): int
	{
		global $wpdb;

		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> ''",
			$key
		));
	}

	/**
	 * Number of distinct objects holding a non-empty value for any meta key.
	 *
	 * @param string[] $keys
	 */
	protected function count_objects_with_meta(string $object_type, array $keys): int
	{
		global $wpdb;

		$tables = [
			'post' => [$wpdb->postmeta, 'post_id'],
			'term' => [$wpdb->termmeta, 'term_id'],
			'user' => [$wpdb->usermeta, 'user_id'],
		];

		if (! isset($tables[$object_type]) || $keys === []) {
			return 0;
		}

		[$table, $id_column] = $tables[$object_type];
		$placeholders         = implode(', ', array_fill(0, count($keys), '%s'));

		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(DISTINCT {$id_column}) FROM {$table} WHERE meta_key IN ({$placeholders}) AND meta_value <> ''",
			...$keys
		));
	}

	protected function table_exists(string $table): bool
	{
		global $wpdb;

		$found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

		return $found === $table;
	}

	/**
	 * Row count of a plugin table, identifier-escaped and existence-checked.
	 */
	protected function count_table(string $table): int
	{
		global $wpdb;

		if (! $this->table_exists($table)) {
			return 0;
		}

		return (int) $wpdb->get_var($this->table_query('SELECT COUNT(*) FROM %i', $table));
	}

	/**
	 * Build a query whose only variable part is a table name.
	 *
	 * `%i` needs WordPress 6.2; on older versions the table name is escaped
	 * with backticks after being validated against the identifier charset.
	 *
	 * @param mixed ...$args Extra placeholder values, in order.
	 */
	protected function table_query(string $sql, string $table, ...$args): string
	{
		global $wpdb;

		if ($this->supports_identifier_placeholder()) {
			return (string) $wpdb->prepare($sql, $table, ...$args);
		}

		$sql = str_replace('%i', '`' . $this->escape_identifier($table) . '`', $sql);

		return $args === [] ? $sql : (string) $wpdb->prepare($sql, ...$args);
	}

	private function supports_identifier_placeholder(): bool
	{
		return version_compare(get_bloginfo('version'), '6.2', '>=');
	}

	/**
	 * Strip everything a MySQL identifier may not contain.
	 */
	private function escape_identifier(string $table): string
	{
		return (string) preg_replace('/[^A-Za-z0-9_$]/', '', $table);
	}

	/**
	 * Batch outcome for a stage that walks objects in pages.
	 *
	 * @return array{imported:int,skipped:int,done:bool,next_offset:int}
	 */
	protected function batch_result(int $imported, int $skipped, int $offset, int $processed, int $limit): array
	{
		return [
			'imported'    => $imported,
			'skipped'     => $skipped,
			'processed'   => $processed,
			'done'        => $processed < $limit,
			'next_offset' => $offset + $processed,
		];
	}

	/**
	 * Batch outcome for a stage that completes in one request.
	 *
	 * @return array{imported:int,skipped:int,done:bool,next_offset:int}
	 */
	protected function single_batch(int $imported, int $skipped): array
	{
		return [
			'imported'    => $imported,
			'skipped'     => $skipped,
			'processed'   => 0,
			'done'        => true,
			'next_offset' => 0,
		];
	}
}
