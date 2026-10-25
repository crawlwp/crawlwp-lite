<?php

namespace Mihdan\IndexNow\SEOCore\CLI;

use Mihdan\IndexNow\SEOCore\FeatureGate\FeatureGate;
use Mihdan\IndexNow\SEOCore\Redirects\RedirectsManager;
use WP_CLI;

/**
 * Manage redirects.
 *
 * ## EXAMPLES
 *
 *     $ wp crawlwp redirect list
 *     $ wp crawlwp redirect add /old-page/ /new-page/
 *     $ wp crawlwp redirect list --format=csv > redirects.csv
 *     $ wp crawlwp redirect import redirects.csv
 */
class RedirectCommand
{
	private const LIST_FIELDS = ['id', 'from_url', 'to_url', 'redirect_type', 'match_type', 'hits', 'enabled'];

	/** Columns read by `import`, in the order `list --format=csv` writes them. */
	private const CSV_FIELDS = ['from_url', 'to_url', 'redirect_type', 'match_type', 'note', 'enabled'];

	/**
	 * List redirects.
	 *
	 * ## OPTIONS
	 *
	 * [--search=<text>]
	 * : Only redirects whose From URL, To URL or note contains this text.
	 *
	 * [--status=<status>]
	 * : Filter by status.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - active
	 *   - inactive
	 * ---
	 *
	 * [--type=<code>]
	 * : Only this status code (301, 302, 307, 410 or 451).
	 *
	 * [--fields=<fields>]
	 * : Comma-separated columns. Also: note, ignore_query_string, priority, created_at, last_accessed.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 *   - ids
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function list_(array $args, array $assoc_args): void
	{
		$manager = new RedirectsManager();
		$filters = [
			'search' => (string) ($assoc_args['search'] ?? ''),
			'status' => (string) ($assoc_args['status'] ?? 'all'),
			'type'   => (int) ($assoc_args['type'] ?? 0),
		];
		$format  = (string) ($assoc_args['format'] ?? 'table');
		$fields  = isset($assoc_args['fields']) ? explode(',', (string) $assoc_args['fields']) : self::LIST_FIELDS;

		if ($format === 'csv' && ! isset($assoc_args['fields'])) {
			$fields = array_merge(['id'], self::CSV_FIELDS);
		}

		$rows = [];

		for ($page = 1; ; $page++) {
			$batch = $manager->get_all($filters + ['per_page' => 500, 'page' => $page, 'order' => 'ASC']);
			$rows  = array_merge($rows, array_map(static function ($row) { return (array) $row; }, $batch));

			if (count($batch) < 500) break;
		}

		WP_CLI\Utils\format_items($format, $format === 'ids' ? array_column($rows, 'id') : $rows, $fields);
	}

	/**
	 * Add a redirect. Checked the same way as in the Redirects screen.
	 *
	 * ## OPTIONS
	 *
	 * <from>
	 * : Path to redirect, e.g. /old-page/. A pattern when --match=regex.
	 *
	 * [<to>]
	 * : Where to send visitors: a path on this site or a full URL. Not needed for 410 and 451.
	 *
	 * [--type=<code>]
	 * : Status code.
	 * ---
	 * default: 301
	 * options:
	 *   - 301
	 *   - 302
	 *   - 307
	 *   - 410
	 *   - 451
	 * ---
	 *
	 * [--match=<match>]
	 * : How <from> is matched.
	 * ---
	 * default: exact
	 * options:
	 *   - exact
	 *   - starts_with
	 *   - ends_with
	 *   - contains
	 *   - regex
	 * ---
	 *
	 * [--note=<note>]
	 * : Note shown in the Redirects screen.
	 *
	 * [--disabled]
	 * : Add it switched off.
	 *
	 * [--porcelain]
	 * : Output only the new redirect's ID.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp crawlwp redirect add /old-page/ /new-page/
	 *     $ wp crawlwp redirect add /discontinued/ --type=410
	 *     $ wp crawlwp redirect add '^/blog/(\d+)/(.*)$' '/news/$2' --match=regex
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function add(array $args, array $assoc_args): void
	{
		$result = self::insert(new RedirectsManager(), [
			'from_url'      => $args[0],
			'to_url'        => $args[1] ?? '',
			'redirect_type' => (int) ($assoc_args['type'] ?? 301),
			'match_type'    => (string) ($assoc_args['match'] ?? 'exact'),
			'note'          => (string) ($assoc_args['note'] ?? ''),
			'enabled'       => empty($assoc_args['disabled']) ? 1 : 0,
		]);

		if (is_wp_error($result)) {
			WP_CLI::error($result->get_error_message());
		}

		if (! empty($assoc_args['porcelain'])) {
			WP_CLI::line((string) $result);

			return;
		}

		WP_CLI::success(sprintf('Added redirect %d.', $result));
		self::warn_if_off();
	}

	/**
	 * Delete redirects.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Redirect IDs.
	 *
	 * @param string[] $args
	 */
	public function delete(array $args): void
	{
		$manager = new RedirectsManager();
		$deleted = 0;

		foreach ($args as $id) {
			if ($manager->get((int) $id) && $manager->delete((int) $id)) {
				$deleted++;
			} else {
				WP_CLI::warning(sprintf('Redirect %s not found.', $id));
			}
		}

		$deleted ? WP_CLI::success(sprintf('Deleted %d redirect(s).', $deleted)) : WP_CLI::error('Nothing deleted.');
	}

	/**
	 * Add redirects from a CSV file.
	 *
	 * The first row names the columns: from_url and to_url, plus optional
	 * redirect_type, match_type, note and enabled. A file written by
	 * `wp crawlwp redirect list --format=csv` works as it is. Rows whose
	 * From URL already has a redirect are skipped.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the CSV file.
	 *
	 * [--dry-run]
	 * : Check every row without adding anything.
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function import(array $args, array $assoc_args): void
	{
		$file   = $args[0];
		$handle = is_readable($file) ? fopen($file, 'r') : false;

		if (! $handle) {
			WP_CLI::error(sprintf('Cannot read %s.', $file));
		}

		$header = fgetcsv($handle, 0, ',', '"', '\\');
		$header = is_array($header) ? array_map(static function ($h) { return strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))); }, $header) : [];

		if (! in_array('from_url', $header, true)) {
			WP_CLI::error('The first row must name the columns, including from_url and to_url.');
		}

		$manager = new RedirectsManager();
		$dry     = ! empty($assoc_args['dry-run']);
		$added   = 0;
		$skipped = 0;
		$line    = 1;

		while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
			$line++;

			if ($row === [null] || count(array_filter($row, 'strlen')) === 0) continue;

			$values = array_intersect_key(array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), '')), array_flip(self::CSV_FIELDS));
			$data   = [
				'from_url'      => (string) ($values['from_url'] ?? ''),
				'to_url'        => (string) ($values['to_url'] ?? ''),
				'redirect_type' => (int) (($values['redirect_type'] ?? '') ?: 301),
				'match_type'    => (string) (($values['match_type'] ?? '') ?: 'exact'),
				'note'          => (string) ($values['note'] ?? ''),
				'enabled'       => ($values['enabled'] ?? '') === '0' ? 0 : 1,
			];

			$result = self::insert($manager, $data, $dry);

			if (is_wp_error($result)) {
				WP_CLI::warning(sprintf('Line %d (%s): %s', $line, $data['from_url'], $result->get_error_message()));
				$skipped++;
			} else {
				$added++;
			}
		}

		fclose($handle);

		WP_CLI::success(sprintf($dry ? '%d redirect(s) would be added, %d skipped.' : 'Added %d redirect(s), skipped %d.', $added, $skipped));

		if (! $dry && $added) self::warn_if_off();
	}

	/**
	 * The Redirects screen's checks, then insert.
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return int|\WP_Error New ID (0 on a dry run).
	 */
	private static function insert(RedirectsManager $manager, array $data, bool $dry = false)
	{
		$type = (int) $data['redirect_type'];

		if (! in_array($type, [301, 302, 307, 410, 451], true)) {
			return new \WP_Error('type', sprintf('Unknown status code %d.', $type));
		}

		if (! in_array($data['match_type'], ['exact', 'regex', 'contains', 'starts_with', 'ends_with'], true)) {
			return new \WP_Error('match', sprintf('Unknown match type "%s".', $data['match_type']));
		}

		if (trim($data['from_url']) === '') {
			return new \WP_Error('from', 'From URL is required.');
		}

		$gone = $type === 410 || $type === 451;

		if (! $gone && trim($data['to_url']) === '') {
			return new \WP_Error('to', 'To URL is required for this status code.');
		}

		if ($gone) $data['to_url'] = '';

		$valid = $manager->validate($data);

		if (is_wp_error($valid)) return $valid;

		if ($manager->exists_from_url($data['from_url'], 0, $data['match_type'])) {
			return new \WP_Error('exists', 'A redirect for this From URL already exists.');
		}

		if (! $gone) {
			$chain = $manager->check_redirect_chain($data['from_url'], $data['to_url']);

			if (is_wp_error($chain)) return $chain;
		}

		if ($dry) return 0;

		/* Whoever runs WP-CLI has full access, like an administrator ticking "Allow external". */
		$data['allow_external'] = $gone ? 0 : (int) $manager->is_external_destination($data['to_url']);
		$data['priority']       = RedirectsManager::DEFAULT_PRIORITY;
		$data['ignore_query_string'] = 1;

		$id = $manager->insert($data);

		return $id ? (int) $id : new \WP_Error('db', 'Could not save the redirect.');
	}

	private static function warn_if_off(): void
	{
		if (! FeatureGate::is_enabled()) {
			WP_CLI::warning('CrawlWP\'s SEO features are turned off, so redirects don\'t run until you turn them on.');
		}
	}
}
