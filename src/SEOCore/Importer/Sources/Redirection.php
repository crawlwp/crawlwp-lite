<?php

namespace Mihdan\IndexNow\SEOCore\Importer\Sources;

use Mihdan\IndexNow\SEOCore\Importer\Source;
use Mihdan\IndexNow\SEOCore\Redirects\RedirectsManager;

/**
 * Redirection (John Godley). Redirects only: it has no SEO titles or settings.
 *
 * Redirects live in `{prefix}redirection_items`, grouped in
 * `{prefix}redirection_groups`. Only plain URL redirects are imported:
 * redirects that depend on login state, referrer, browser, cookies and so on
 * (match_type other than "url") have no CrawlWP equivalent, and neither do
 * the "random post", "pass-through" and "do nothing" actions. Groups set to
 * Apache or Nginx are written to server config files, not run by WordPress,
 * so they are skipped too.
 */
class Redirection extends Source
{
	/** Redirection's "WordPress" module, the only one it runs in PHP. */
	private const MODULE_WORDPRESS = 1;

	public function id(): string
	{
		return 'redirection';
	}

	public function label(): string
	{
		return 'Redirection';
	}

	/** Only redirects: never the "main" SEO plugin, and not one to deactivate after importing. */
	public function redirects_only(): bool
	{
		return true;
	}

	public function is_available(): bool
	{
		return defined('REDIRECTION_VERSION') || $this->table_exists($this->items_table());
	}

	public function counts(): array
	{
		return [
			'posts'     => 0,
			'terms'     => 0,
			'users'     => 0,
			'redirects' => $this->count_table($this->items_table()),
		];
	}

	public function import_posts(int $offset, int $limit, bool $overwrite): array
	{
		return $this->batch_result(0, 0, $offset, 0, $limit);
	}

	public function import_terms(int $offset, int $limit, bool $overwrite): array
	{
		return $this->batch_result(0, 0, $offset, 0, $limit);
	}

	public function import_redirects(int $offset, int $limit): array
	{
		global $wpdb;

		$items  = $this->items_table();
		$groups = $wpdb->prefix . 'redirection_groups';

		if (! $this->table_exists($items)) {
			return $this->batch_result(0, 0, $offset, 0, $limit);
		}

		$rows = $wpdb->get_results(
			$this->table_query('SELECT * FROM %i ORDER BY id ASC LIMIT %d OFFSET %d', $items, $limit, $offset),
			ARRAY_A
		);

		if (! is_array($rows) || $rows === []) {
			return $this->batch_result(0, 0, $offset, 0, $limit);
		}

		$group_info = $this->groups($groups);
		$manager    = new RedirectsManager();
		$imported   = 0;
		$skipped    = 0;

		foreach ($rows as $row) {
			$group = $group_info[(int) ($row['group_id'] ?? 0)] ?? null;

			// Redirection only runs items whose group exists: an item left in a deleted group was never live.
			if ($group === null && $group_info !== []) {
				$skipped++;
				continue;
			}

			$redirect = self::map_row($row, $group, $this->default_query_flag());

			if ($redirect === null || $manager->exists_from_url($redirect['from_url'], 0, $redirect['match_type'])) {
				$skipped++;
				continue;
			}

			// The redirect was already live in Redirection, so a destination on another site keeps working.
			$redirect['allow_external'] = self::allow_external_for($manager, (string) $redirect['to_url']) ? 1 : 0;

			$redirect['note'] = $redirect['note'] !== ''
				/* translators: %s: the redirect's title in Redirection */
				? sprintf(__('Imported from Redirection: %s', 'mihdan-index-now'), $redirect['note'])
				: __('Imported from Redirection', 'mihdan-index-now');

			$manager->insert($redirect) ? $imported++ : $skipped++;
		}

		return $this->batch_result($imported, $skipped, $offset, count($rows), $limit);
	}

	/**
	 * CrawlWP redirect for a Redirection row, or null when it has no equivalent.
	 *
	 * @param array<string,mixed>                       $row
	 * @param array{module: int, enabled: bool}|null    $group
	 *
	 * @return array{from_url: string, to_url: string, redirect_type: int, match_type: string, note: string, ignore_query_string: int, enabled: int}|null
	 */
	public static function map_row(array $row, ?array $group, string $default_query_flag = 'exact'): ?array
	{
		$from = trim((string) ($row['url'] ?? ''));

		if ($from === '' || ($row['match_type'] ?? 'url') !== 'url') {
			return null;
		}

		// Apache and Nginx groups are written to server files, not run by WordPress.
		if ($group !== null && $group['module'] !== self::MODULE_WORDPRESS) {
			return null;
		}

		$action = (string) ($row['action_type'] ?? '');
		$code   = (int) ($row['action_code'] ?? 0);
		$to     = trim((string) ($row['action_data'] ?? ''));

		if ($action === 'url') {
			if ($to === '') return null;
			// CrawlWP has no 308; 301 is the permanent redirect browsers and Google treat the same way.
			$type = [301 => 301, 302 => 302, 303 => 302, 304 => 302, 307 => 307, 308 => 301][$code] ?? 301;
		} elseif ($action === 'error' && in_array($code, [410, 451], true)) {
			$type = $code;
			$to   = '';
		} else {
			return null;
		}

		$data  = json_decode((string) ($row['match_data'] ?? ''), true);
		$query = is_array($data) && isset($data['source']['flag_query']) ? (string) $data['source']['flag_query'] : $default_query_flag;
		$regex = ! empty($row['regex']);

		// Redirection matches a regex against the whole address, query string included (its regex flag forces "exact").
		if ($regex) {
			$query = 'exact';
		}

		$enabled = ($row['status'] ?? 'enabled') === 'enabled' && ($group === null || $group['enabled']);

		return [
			'from_url'            => $from,
			'to_url'              => $to,
			'redirect_type'       => $type,
			'match_type'          => $regex ? 'regex' : 'exact',
			'note'                => trim((string) ($row['title'] ?? '')),
			'ignore_query_string' => in_array($query, ['ignore', 'pass'], true) ? 1 : 0,
			'enabled'             => $enabled ? 1 : 0,
		];
	}

	/**
	 * Group module and status by group ID.
	 *
	 * @return array<int, array{module: int, enabled: bool}>
	 */
	private function groups(string $table): array
	{
		global $wpdb;

		if (! $this->table_exists($table)) return [];

		$rows = $wpdb->get_results($this->table_query('SELECT id, module_id, status FROM %i', $table), ARRAY_A);
		$out  = [];

		foreach (is_array($rows) ? $rows : [] as $row) {
			$out[(int) $row['id']] = ['module' => (int) $row['module_id'], 'enabled' => $row['status'] === 'enabled'];
		}

		return $out;
	}

	/**
	 * Redirection's site-wide "query parameters" default, used when a redirect has none of its own.
	 */
	private function default_query_flag(): string
	{
		$options = get_option('redirection_options');

		return is_array($options) && ! empty($options['flag_query']) ? (string) $options['flag_query'] : 'exact';
	}

	private function items_table(): string
	{
		global $wpdb;

		return $wpdb->prefix . 'redirection_items';
	}
}
