<?php

namespace Mihdan\IndexNow\SEOCore\CLI;

use Mihdan\IndexNow\SEOCore\Importer\Runner;
use WP_CLI;

/**
 * Import SEO data from another SEO plugin, like Settings > Import.
 *
 * ## EXAMPLES
 *
 *     $ wp crawlwp import list
 *     $ wp crawlwp import run yoast
 */
class ImportCommand
{
	/**
	 * Show the SEO plugins CrawlWP can import from, and what each has.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function list_(array $args, array $assoc_args): void
	{
		$rows = array_map(static function ($row) {
			$row['available'] = $row['available'] ? 'yes' : 'no';
			$row['active']    = $row['active'] ? 'yes' : 'no';

			return $row;
		}, Runner::inventory());

		WP_CLI\Utils\format_items((string) ($assoc_args['format'] ?? 'table'), $rows, ['id', 'label', 'available', 'active', 'posts', 'terms', 'users', 'redirects']);
	}

	/**
	 * Import settings, posts, terms, users and redirects from an SEO plugin.
	 *
	 * The other plugin's data is only read, never changed. Safe to run again.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : The plugin to import from, as listed by `wp crawlwp import list`.
	 *
	 * [--overwrite]
	 * : Replace values already set in CrawlWP. By default they are kept.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp crawlwp import run rankmath --overwrite
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function run(array $args, array $assoc_args): void
	{
		$id     = $args[0];
		$source = Runner::source($id);

		if ($source === null) {
			WP_CLI::error(sprintf('Unknown source "%s". Run `wp crawlwp import list` to see them.', $id));
		}

		if (! $source->is_available()) {
			WP_CLI::error(sprintf('No %s data found on this site.', $source->label()));
		}

		$overwrite = ! empty($assoc_args['overwrite']);
		$stage     = Runner::first_stage();
		$offset    = 0;
		$totals    = array_fill_keys(Runner::STAGES, ['imported' => 0, 'skipped' => 0]);

		WP_CLI::log(sprintf('Importing from %s…', $source->label()));

		while (true) {
			$step = Runner::run_step($id, $stage, $offset, $overwrite);

			if (empty($step['ok'])) {
				WP_CLI::error((string) ($step['message'] ?? 'Import failed.'));
			}

			$totals[$step['stage']]['imported'] += $step['imported'];
			$totals[$step['stage']]['skipped']  += $step['skipped'];

			if ($step['next_stage'] !== $step['stage']) {
				WP_CLI::log(sprintf('  %s: %d imported, %d skipped', $step['stage'], $totals[$step['stage']]['imported'], $totals[$step['stage']]['skipped']));
			}

			if ($step['done']) break;

			/* Guard against a source that never moves forward. */
			if ($step['next_stage'] === $stage && $step['next_offset'] <= $offset) {
				WP_CLI::error(sprintf('The %s step stopped making progress at item %d.', $stage, $offset));
			}

			$stage  = $step['next_stage'];
			$offset = $step['next_offset'];
		}

		WP_CLI::success(sprintf('Imported from %s.', $source->label()));
	}
}
