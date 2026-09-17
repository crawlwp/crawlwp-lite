<?php

namespace Mihdan\IndexNow\SEOCore\Redirects;

class RedirectsManager
{
	/** Transient key for the cached redirect list used by the frontend. */
	const CACHE_KEY = 'crawlwp_redirects_cache';

	/** Transient expiry in seconds (5 minutes). */
	const CACHE_EXPIRY = 300;

	/** Maximum accepted length (in characters) of a regex from_url pattern. */
	const MAX_REGEX_LENGTH = 500;

	/** Non-autoloaded option buffering hit counts until they are flushed. */
	const HITS_BUFFER_OPTION = 'crawlwp_redirect_hits_buffer';

	/** Cron hook that flushes buffered hit counts into the database. */
	const HITS_FLUSH_HOOK = 'crawlwp_redirects_flush_hits';

	/** Flush the buffer to the DB once this many rules are pending. */
	const HITS_FLUSH_THRESHOLD = 25;

	/** Non-autoloaded option logging regex rules disabled at runtime. */
	const REGEX_ERRORS_OPTION = 'crawlwp_redirect_regex_errors';

	/** Rows fetched per batch when loading every enabled rule. */
	const FETCH_BATCH = 1000;

	/** Above this many enabled rules the admin UI shows a performance warning. */
	const LARGE_RULESET = 2000;

	/** Maximum number of hops followed when looking for a redirect chain/loop. */
	const MAX_CHAIN_HOPS = 5;

	/** Default rule priority (lower numbers run first). */
	const DEFAULT_PRIORITY = 10;

	/**
	 * Full table name (with WP prefix).
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Pending hit counts for the current request, keyed by rule ID.
	 *
	 * @var int[]
	 */
	private array $hit_buffer = [];

	/** Whether the shutdown flush for $hit_buffer is registered. */
	private bool $hit_buffer_hooked = false;

	/** Guards against registering the cron hook once per instance. */
	private static bool $cron_hooked = false;

	public function __construct()
	{
		global $wpdb;
		$this->table = $wpdb->prefix . 'crawlwp_redirects';

		if (!self::$cron_hooked) {
			self::$cron_hooked = true;

			add_action(self::HITS_FLUSH_HOOK, [$this, 'flush_hit_buffer']);

			if (!wp_next_scheduled(self::HITS_FLUSH_HOOK)) {
				wp_schedule_event(time() + 300, 'hourly', self::HITS_FLUSH_HOOK);
			}
		}
	}

	// -------------------------------------------------------------------------
	// CRUD
	// -------------------------------------------------------------------------

	/**
	 * Insert a new redirect row.
	 *
	 * @param array $data Redirect field values.
	 * @return int|false Inserted row ID, or false on failure.
	 */
	public function insert(array $data)
	{
		global $wpdb;

		if (is_wp_error($this->validate($data))) {
			return false;
		}

		$data = apply_filters('crawlwp_redirect_before_insert', $this->sanitize($data));
		$data['created_at'] = current_time('mysql', true);

		$result = $wpdb->insert($this->table, $data, $this->get_formats($data));

		if ($result) {
			$this->flush_cache();
			return $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * Update an existing redirect row.
	 *
	 * @param int   $id   Row ID.
	 * @param array $data Fields to update.
	 * @return bool
	 */
	public function update(int $id, array $data): bool
	{
		global $wpdb;

		if (is_wp_error($this->validate($data))) {
			return false;
		}

		$data = apply_filters('crawlwp_redirect_before_update', $this->sanitize($data), $id);

		$result = $wpdb->update($this->table, $data, ['id' => $id], $this->get_formats($data), ['%d']);

		if ($result !== false) {
			$this->flush_cache();
			return true;
		}

		return false;
	}

	/**
	 * Delete a redirect row.
	 *
	 * @param int $id Row ID.
	 * @return bool
	 */
	public function delete(int $id): bool
	{
		global $wpdb;

		$result = $wpdb->delete($this->table, ['id' => $id], ['%d']);

		if ($result !== false) {
			$this->flush_cache();
			return true;
		}

		return false;
	}

	/**
	 * Retrieve a single redirect by ID.
	 *
	 * @param int $id Row ID.
	 * @return object|null
	 */
	public function get(int $id)
	{
		global $wpdb;

		return $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM {$this->table} WHERE id = %d",
			$id
		));
	}

	/**
	 * Retrieve a list of redirects with optional filtering / pagination.
	 *
	 * @param array $args {
	 *   @type string $search      Full-text search against from_url / to_url / note.
	 *   @type string $status      'all' | 'active' | 'inactive'. Default 'all'.
	 *   @type int    $type        Redirect HTTP status code filter (0 = all).
	 *   @type int    $per_page    Rows per page. Default 20.
	 *   @type int    $page        1-based page number. Default 1.
	 *   @type string $orderby     Column name. Default 'id'.
	 *   @type string $order       'ASC' | 'DESC'. Default 'DESC'.
	 * }
	 * @return object[]
	 */
	public function get_all(array $args = []): array
	{
		global $wpdb;

		$defaults = [
			'search'   => '',
			'status'   => 'all',
			'type'     => 0,
			'per_page' => 20,
			'page'     => 1,
			'orderby'  => 'id',
			'order'    => 'DESC',
		];
		$args = array_merge($defaults, $args);

		list($where, $values) = $this->build_where($args);

		$allowed_cols = ['id', 'from_url', 'to_url', 'redirect_type', 'hits', 'created_at', 'last_accessed', 'priority'];

		$orderby = in_array($args['orderby'], $allowed_cols, true) ? $args['orderby'] : 'id';
		$order   = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

		// Always break ties on the primary key so pagination is stable.
		$order_sql = $orderby === 'id' ? "id {$order}" : "{$orderby} {$order}, id {$order}";

		$per_page = max(1, (int) $args['per_page']);
		$page     = max(1, (int) $args['page']);
		$offset   = ($page - 1) * $per_page;

		$sql = "SELECT * FROM {$this->table}{$where} ORDER BY {$order_sql} LIMIT %d OFFSET %d";
		$values[] = $per_page;
		$values[] = $offset;

		return $wpdb->get_results($wpdb->prepare($sql, $values));
	}

	/**
	 * Count redirects matching the given filters.
	 *
	 * @param array $args Same keys as get_all().
	 * @return int
	 */
	public function get_count(array $args = []): int
	{
		global $wpdb;

		list($where, $values) = $this->build_where($args);

		$sql = "SELECT COUNT(*) FROM {$this->table}{$where}";

		if (!empty($values)) {
			return (int) $wpdb->get_var($wpdb->prepare($sql, $values));
		}

		return (int) $wpdb->get_var($sql);
	}

	// -------------------------------------------------------------------------
	// Hit counting (buffered)
	// -------------------------------------------------------------------------

	/**
	 * Record a frontend hit.
	 *
	 * Redirected requests must not pay for a synchronous UPDATE, so hits are
	 * accumulated in memory, merged into a non-autoloaded option on shutdown
	 * (which still runs after wp_redirect() + exit) and written to the table
	 * by the hourly cron — or earlier, once enough rules are pending.
	 *
	 * @param int $id Row ID.
	 */
	public function record_hit(int $id): void
	{
		if ($id <= 0) {
			return;
		}

		if (!isset($this->hit_buffer[$id])) {
			$this->hit_buffer[$id] = 0;
		}

		$this->hit_buffer[$id]++;

		if (!$this->hit_buffer_hooked) {
			$this->hit_buffer_hooked = true;
			add_action('shutdown', [$this, 'persist_hit_buffer'], 0);
		}
	}

	/**
	 * Merge the in-memory hits of this request into the shared buffer.
	 *
	 * Hooked to `shutdown` and safe to call directly.
	 */
	public function persist_hit_buffer(): void
	{
		if (empty($this->hit_buffer)) {
			return;
		}

		$buffer = get_option(self::HITS_BUFFER_OPTION, []);
		$buffer = is_array($buffer) ? $buffer : [];

		foreach ($this->hit_buffer as $id => $count) {
			$buffer[$id] = (int) ($buffer[$id] ?? 0) + (int) $count;
		}

		$this->hit_buffer = [];

		update_option(self::HITS_BUFFER_OPTION, $buffer, false);

		if (count($buffer) >= self::HITS_FLUSH_THRESHOLD) {
			$this->flush_hit_buffer();
		}
	}

	/**
	 * Write the buffered hit counts to the database.
	 *
	 * Hooked to the hourly cron event.
	 */
	public function flush_hit_buffer(): void
	{
		global $wpdb;

		$buffer = get_option(self::HITS_BUFFER_OPTION, []);

		if (!is_array($buffer) || empty($buffer)) {
			return;
		}

		// Clear first: a failure must not replay the same counts forever.
		delete_option(self::HITS_BUFFER_OPTION);

		$now = current_time('mysql', true);

		foreach ($buffer as $id => $count) {
			$id    = (int) $id;
			$count = (int) $count;

			if ($id <= 0 || $count <= 0) {
				continue;
			}

			$wpdb->query($wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$this->table} SET hits = hits + %d, last_accessed = %s WHERE id = %d",
				$count,
				$now,
				$id
			));
		}
	}

	// -------------------------------------------------------------------------
	// Frontend rule set
	// -------------------------------------------------------------------------

	/**
	 * Get every enabled redirect grouped for fast frontend matching.
	 *
	 * The returned structure is cached in a transient:
	 *
	 *   [
	 *     'exact' => [ 'old-page' => [ row, … ], … ],  // keyed fast path
	 *     'rules' => [ row, … ],                       // regex / partial rules
	 *     'total' => int,
	 *   ]
	 *
	 * The `exact` map lets the processor resolve a plain path with a single
	 * array lookup, so regex rules are only evaluated when it misses.
	 *
	 * @return array
	 */
	public function get_enabled_grouped(): array
	{
		$cached = get_transient(self::CACHE_KEY);

		if (is_array($cached) && isset($cached['exact'], $cached['rules'])) {
			return $cached;
		}

		$rows    = $this->fetch_all_enabled();
		$grouped = ['exact' => [], 'rules' => [], 'total' => count($rows)];

		foreach ($rows as $row) {
			$key = $this->exact_lookup_key($row);

			if ($key === null) {
				$grouped['rules'][] = $row;
				continue;
			}

			$grouped['exact'][$key][] = $row;
		}

		set_transient(self::CACHE_KEY, $grouped, self::CACHE_EXPIRY);

		return $grouped;
	}

	/**
	 * Fetch every enabled rule, in batches, with no silent cap.
	 *
	 * @return object[]
	 */
	private function fetch_all_enabled(): array
	{
		global $wpdb;

		$order_sql = 'priority ASC, id ASC';

		$rows   = [];
		$offset = 0;

		do {
			$batch = $wpdb->get_results($wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$this->table} WHERE enabled = 1 ORDER BY {$order_sql} LIMIT %d OFFSET %d",
				self::FETCH_BATCH,
				$offset
			));

			if (empty($batch)) {
				break;
			}

			$rows = array_merge($rows, $batch);
			$offset += self::FETCH_BATCH;
		} while (count($batch) === self::FETCH_BATCH);

		return $rows;
	}

	/**
	 * Build the hashmap key for a rule that can use the exact fast path.
	 *
	 * Only plain, query-less "exact" rules qualify; everything else (regex,
	 * partial matches, query-bearing or foreign-host from_urls) is evaluated
	 * by the slow path so existing behaviour is preserved.
	 *
	 * @param object $row DB row.
	 * @return string|null Lookup key, or null when the rule is not eligible.
	 */
	private function exact_lookup_key(object $row): ?string
	{
		$match_type = (string) ($row->match_type ?? 'exact');

		if ($match_type !== '' && $match_type !== 'exact') {
			return null;
		}

		$from = (string) ($row->from_url ?? '');

		if ($from === '' || strpos($from, '?') !== false) {
			return null;
		}

		if (strncasecmp($from, 'http://', 7) === 0 || strncasecmp($from, 'https://', 8) === 0) {
			$host      = (string) wp_parse_url($from, PHP_URL_HOST);
			$home_host = (string) wp_parse_url(home_url(), PHP_URL_HOST);

			if ($host === '' || strcasecmp($host, $home_host) !== 0) {
				return null;
			}
		}

		$key = $this->path_match_key($from);

		return $key === '' ? null : $key;
	}

	/**
	 * Flush the frontend redirect cache.
	 */
	public function flush_cache(): void
	{
		delete_transient(self::CACHE_KEY);
	}

	/**
	 * Disable a rule that failed at runtime (e.g. a regex that blew the PCRE
	 * backtrack limit) and log the reason for the admin.
	 *
	 * @param int    $id      Row ID.
	 * @param string $reason  Human readable reason.
	 * @param string $pattern Offending pattern, when relevant.
	 */
	public function disable_rule_for_error(int $id, string $reason, string $pattern = ''): void
	{
		if ($id <= 0) {
			return;
		}

		global $wpdb;

		$wpdb->update($this->table, ['enabled' => 0], ['id' => $id], ['%d'], ['%d']);
		$this->flush_cache();

		$log = get_option(self::REGEX_ERRORS_OPTION, []);
		$log = is_array($log) ? $log : [];

		$log[$id] = [
			'reason'  => $reason,
			'pattern' => $pattern,
			'time'    => current_time('mysql', true),
		];

		// Keep the log small — it is diagnostic only.
		if (count($log) > 50) {
			$log = array_slice($log, -50, null, true);
		}

		update_option(self::REGEX_ERRORS_OPTION, $log, false);

		/**
		 * Fires when a redirect rule is automatically disabled at runtime.
		 *
		 * @param int    $id      Rule ID.
		 * @param string $reason  Reason the rule was disabled.
		 * @param string $pattern Offending pattern, when relevant.
		 */
		do_action('crawlwp_redirect_rule_auto_disabled', $id, $reason, $pattern);

		if (defined('WP_DEBUG') && WP_DEBUG) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(sprintf('CrawlWP: redirect rule #%d disabled — %s', $id, $reason));
		}
	}

	/**
	 * Runtime errors logged by disable_rule_for_error().
	 *
	 * @return array
	 */
	public function get_runtime_errors(): array
	{
		$log = get_option(self::REGEX_ERRORS_OPTION, []);

		return is_array($log) ? $log : [];
	}

	// -------------------------------------------------------------------------
	// Lookups
	// -------------------------------------------------------------------------

	/**
	 * Check whether a redirect from a given URL already exists.
	 *
	 * @param string $from_url   From URL to check.
	 * @param int    $exclude_id Row ID to ignore (the row being updated).
	 * @param string $match_type Match type of the rule being saved; regex
	 *                           patterns are compared verbatim because they
	 *                           are not URL-normalised on storage.
	 * @return bool
	 */
	public function exists_from_url(string $from_url, int $exclude_id = 0, string $match_type = 'exact'): bool
	{
		global $wpdb;

		// Normalise the same way it would be stored, so callers can pass a
		// raw path (with or without slashes) and still get a correct check.
		$from_url = $match_type === 'regex'
			? sanitize_text_field(trim($from_url))
			: $this->normalize_home_relative($from_url);

		if ($from_url === '') {
			return false;
		}

		$count = $wpdb->get_var($wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM {$this->table} WHERE from_url = %s AND id <> %d",
			$from_url,
			$exclude_id
		));

		return (int) $count > 0;
	}

	/**
	 * Retrieve a single redirect by its stored from_url.
	 *
	 * @param string $from_url From URL (raw path or full URL; normalised before lookup).
	 * @return object|null
	 */
	public function get_by_from_url(string $from_url)
	{
		global $wpdb;

		$from_url = $this->normalize_home_relative($from_url);

		if ($from_url === '') {
			return null;
		}

		return $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM {$this->table} WHERE from_url = %s AND match_type = 'exact' ORDER BY id ASC LIMIT 1",
			$from_url
		));
	}

	/**
	 * Find an enabled exact rule whose from_url matches a home-relative key.
	 *
	 * Used by the chain/loop detector. Stored values are bare path segments,
	 * but slash variants are matched too for legacy rows. MySQL collations are
	 * case-insensitive by default, matching the runtime comparison.
	 *
	 * @param string $key        Home-relative match key (no leading slash).
	 * @param int    $exclude_id Row ID to ignore.
	 * @return object|null
	 */
	private function find_enabled_exact_rule(string $key, int $exclude_id = 0)
	{
		global $wpdb;

		if ($key === '') {
			return null;
		}

		return $wpdb->get_row($wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$this->table}
			 WHERE enabled = 1 AND match_type = 'exact' AND id <> %d
			   AND from_url IN (%s, %s, %s, %s)
			 ORDER BY id ASC LIMIT 1",
			$exclude_id,
			$key,
			'/' . $key,
			$key . '/',
			'/' . $key . '/'
		));
	}

	// -------------------------------------------------------------------------
	// Validation
	// -------------------------------------------------------------------------

	/**
	 * Validate redirect data before it is written to the database.
	 *
	 * Only the keys present in $data are validated, so partial updates
	 * (e.g. toggling `enabled`) pass through untouched.
	 *
	 * @param array $data Raw data (same shape accepted by insert()/update()).
	 * @return true|\WP_Error
	 */
	public function validate(array $data)
	{
		$clean = $this->sanitize($data);

		// Regex source patterns: bounded length, bounded complexity, and must
		// compile with the fixed "#" delimiter used by the frontend processor.
		if (isset($clean['from_url'], $clean['match_type']) && $clean['match_type'] === 'regex') {
			$pattern = $clean['from_url'];

			if ($pattern === '') {
				return new \WP_Error('crawlwp_redirect_invalid_regex', __('From URL is required.', 'mihdan-index-now'));
			}

			if (strlen($pattern) > self::MAX_REGEX_LENGTH) {
				return new \WP_Error(
					'crawlwp_redirect_regex_too_long',
					/* translators: %d: maximum number of characters. */
					sprintf(__('Regex pattern is too long (maximum %d characters).', 'mihdan-index-now'), self::MAX_REGEX_LENGTH)
				);
			}

			$regex = '#' . str_replace('#', '\#', $pattern) . '#i';

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if (@preg_match($regex, '') === false) {
				return new \WP_Error('crawlwp_redirect_invalid_regex', __('Regex pattern is invalid and could not be compiled.', 'mihdan-index-now'));
			}

			$risk = self::describe_regex_risk($pattern);

			if ($risk !== '') {
				return new \WP_Error('crawlwp_redirect_regex_too_complex', $risk);
			}
		}

		// Destination: site-relative path or absolute http(s) URL only. An empty
		// value is allowed here because 410/451 rules have no destination — but
		// a non-empty raw value that sanitisation reduced to "" (e.g. a
		// javascript: URL stripped by esc_url_raw()) is rejected.
		if (isset($data['to_url'], $clean['to_url']) && trim((string) $data['to_url']) !== '' && $clean['to_url'] === '') {
			return new \WP_Error(
				'crawlwp_redirect_invalid_to_url',
				__('To URL must be a site-relative path starting with "/" or an absolute http(s) URL.', 'mihdan-index-now')
			);
		}

		if (isset($clean['to_url']) && $clean['to_url'] !== '' && !$this->is_valid_destination($clean['to_url'])) {
			return new \WP_Error(
				'crawlwp_redirect_invalid_to_url',
				__('To URL must be a site-relative path starting with "/" or an absolute http(s) URL.', 'mihdan-index-now')
			);
		}

		return true;
	}

	/**
	 * Detect catastrophic-backtracking constructs in a user-supplied pattern.
	 *
	 * A length limit does nothing against "(a+)+$", so quantified groups whose
	 * body itself repeats or alternates are refused outright, together with
	 * absurd repetition counts and patterns stuffed with quantifiers.
	 *
	 * @param string $pattern Raw pattern body (no delimiters).
	 * @return string Empty string when the pattern is acceptable, otherwise an
	 *                admin-facing error message.
	 */
	public static function describe_regex_risk(string $pattern): string
	{
		// Drop escaped characters so "\(" / "\+" are not mistaken for syntax.
		$stripped = preg_replace('/\\\\./', 'x', $pattern);
		$stripped = is_string($stripped) ? $stripped : $pattern;

		// Character classes cannot nest quantifiers — flatten them as atoms.
		$flat = preg_replace('/\[[^\]]*\]/', 'c', $stripped);
		$flat = is_string($flat) ? $flat : $stripped;

		if (preg_match('/\{\s*\d*\s*,\s*\}/', $flat) && preg_match_all('/[*+]/', $flat) > 0) {
			return __('Regex pattern is too complex: unbounded repetition combined with other quantifiers can hang the site.', 'mihdan-index-now');
		}

		// Absurd repetition counts.
		if (preg_match('/\{\s*(\d+)\s*(?:,\s*(\d+)?\s*)?\}/', $flat, $m)) {
			$low  = (int) $m[1];
			$high = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : $low;

			if ($low > 100 || $high > 100) {
				return __('Regex pattern is too complex: repetition counts above 100 are not allowed.', 'mihdan-index-now');
			}
		}

		if (preg_match_all('/[*+?]|\{\s*\d/', $flat) > 12) {
			return __('Regex pattern is too complex: it uses too many quantifiers.', 'mihdan-index-now');
		}

		$length = strlen($flat);

		for ($i = 0; $i < $length; $i++) {
			if ($flat[$i] !== '(') {
				continue;
			}

			$depth = 0;
			$close = -1;

			for ($j = $i; $j < $length; $j++) {
				if ($flat[$j] === '(') {
					$depth++;
				} elseif ($flat[$j] === ')') {
					$depth--;

					if ($depth === 0) {
						$close = $j;
						break;
					}
				}
			}

			if ($close === -1) {
				break;
			}

			$next = $flat[$close + 1] ?? '';

			// The group is not repeated — harmless on its own.
			if ($next !== '*' && $next !== '+' && $next !== '{') {
				continue;
			}

			$body = substr($flat, $i + 1, $close - $i - 1);

			// A repeated group whose body can itself match a variable number of
			// characters (nested quantifier) or overlapping alternatives is the
			// classic catastrophic-backtracking shape.
			if (preg_match('/[*+{]/', $body) || strpos($body, '|') !== false) {
				return __('Regex pattern is too complex: nested or repeated quantifiers (for example "(a+)+") can hang the site and are not allowed.', 'mihdan-index-now');
			}
		}

		return '';
	}

	/**
	 * Refuse redirect chains and multi-hop loops (A→B, B→A, …).
	 *
	 * Resolves the destination against the stored exact rules for up to
	 * MAX_CHAIN_HOPS hops. Regex / partial rules cannot be resolved statically
	 * and are therefore not followed.
	 *
	 * @param string $from_url   Proposed source.
	 * @param string $to_url     Proposed destination.
	 * @param int    $exclude_id Row being edited (ignored while walking).
	 * @return true|\WP_Error
	 */
	public function check_redirect_chain(string $from_url, string $to_url, int $exclude_id = 0)
	{
		$from_key = $this->path_match_key($from_url);
		$to_key   = $this->path_match_key($to_url);

		// An external (or empty) destination cannot be resolved locally.
		if ($to_key === '') {
			return true;
		}

		if ($from_key !== '' && $from_key === $to_key) {
			return new \WP_Error(
				'crawlwp_redirect_self_loop',
				__('The From and To URLs resolve to the same location, which would create a redirect loop.', 'mihdan-index-now')
			);
		}

		$seen = [$from_key => true, $to_key => true];
		$key  = $to_key;

		for ($hop = 0; $hop < self::MAX_CHAIN_HOPS; $hop++) {
			$rule = $this->find_enabled_exact_rule($key, $exclude_id);

			if (!$rule) {
				return true;
			}

			$next = $this->path_match_key((string) $rule->to_url);

			if ($next === '') {
				return true;
			}

			if ($next === $from_key || isset($seen[$next])) {
				return new \WP_Error(
					'crawlwp_redirect_loop',
					/* translators: %s: URL that closes the redirect loop. */
					sprintf(__('This redirect would create a loop: the destination is already redirected back to "%s".', 'mihdan-index-now'), '/' . $next)
				);
			}

			$seen[$next] = true;
			$key         = $next;
		}

		return new \WP_Error(
			'crawlwp_redirect_chain_too_long',
			/* translators: %d: maximum number of hops. */
			sprintf(__('This redirect would create a chain longer than %d hops. Point it at the final destination instead.', 'mihdan-index-now'), self::MAX_CHAIN_HOPS)
		);
	}

	/**
	 * Check whether a destination URL is acceptable for storage.
	 *
	 * Accepts a path beginning with a single "/" (not "//" or "/\") or an
	 * absolute http/https URL with a host. Capture-group placeholders such
	 * as "$1" are permitted inside otherwise valid values.
	 *
	 * @param string $url Sanitised destination.
	 * @return bool
	 */
	private function is_valid_destination(string $url): bool
	{
		if ($url === '') {
			return false;
		}

		if ($url[0] === '/') {
			return !(isset($url[1]) && ($url[1] === '/' || $url[1] === '\\'));
		}

		$parsed = wp_parse_url($url);

		if (!is_array($parsed) || empty($parsed['scheme']) || empty($parsed['host'])) {
			return false;
		}

		return in_array(strtolower($parsed['scheme']), ['http', 'https'], true);
	}

	/**
	 * Whether a destination points at a host other than this site.
	 *
	 * @param string $url Destination URL.
	 * @return bool
	 */
	public function is_external_destination(string $url): bool
	{
		$host = (string) wp_parse_url($url, PHP_URL_HOST);

		if ($host === '') {
			return false;
		}

		return strcasecmp($host, (string) wp_parse_url(home_url(), PHP_URL_HOST)) !== 0;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Sanitize a data array before DB write.
	 *
	 * @param array $data Raw data.
	 * @return array Sanitized data.
	 */
	private function sanitize(array $data): array
	{
		$clean = [];

		if (isset($data['from_url'])) {
			$match_type_hint = $data['match_type'] ?? 'exact';

			// Regex patterns must never be passed through URL sanitisation:
			// esc_url_raw()/esc_url() silently strips characters that are
			// invalid in a URL — such as ^ $ ( ) [ ] \ — which are exactly
			// the characters a regex pattern relies on. Doing so previously
			// corrupted every saved regex rule into a broken pattern.
			if ($match_type_hint === 'regex') {
				$clean['from_url'] = sanitize_text_field(trim($data['from_url']));
			} else {
				$clean['from_url'] = $this->normalize_home_relative(trim($data['from_url']));
			}
		}

		if (isset($data['to_url'])) {
			$clean['to_url'] = $this->normalize_to_url((string) $data['to_url']);
		}

		if (isset($data['redirect_type'])) {
			$allowed = [301, 302, 307, 410, 451];
			$type = (int) $data['redirect_type'];
			$clean['redirect_type'] = in_array($type, $allowed, true) ? $type : 301;
		}

		if (isset($data['match_type'])) {
			$allowed_match_types = ['exact', 'regex', 'contains', 'starts_with', 'ends_with'];
			$clean['match_type'] = in_array($data['match_type'], $allowed_match_types, true) ? $data['match_type'] : 'exact';
		}

		if (isset($data['note'])) {
			$clean['note'] = sanitize_textarea_field($data['note']);
		}

		if (isset($data['ignore_query_string'])) {
			$clean['ignore_query_string'] = (int) (bool) $data['ignore_query_string'];
		}

		if (isset($data['enabled'])) {
			$clean['enabled'] = (int) (bool) $data['enabled'];
		}

		if (isset($data['priority'])) {
			$clean['priority'] = max(0, min(999, (int) $data['priority']));
		}

		if (isset($data['allow_external'])) {
			$clean['allow_external'] = (int) (bool) $data['allow_external'];
		}

		return $clean;
	}

	/**
	 * The site origin (scheme + host, no trailing slash).
	 *
	 * @return string
	 */
	private function home_origin(): string
	{
		$parsed = wp_parse_url(untrailingslashit(home_url()));

		return isset($parsed['scheme'], $parsed['host'])
			? $parsed['scheme'] . '://' . $parsed['host']
			: '';
	}

	/**
	 * The path home_url() is rooted at, without trailing slash ("" or "/blog").
	 *
	 * @return string
	 */
	private function home_path(): string
	{
		return untrailingslashit((string) wp_parse_url(home_url(), PHP_URL_PATH));
	}

	/**
	 * Reduce a URL to a home-relative value: strips the site origin *and* the
	 * path home_url() is rooted at, so subdirectory installs behave like
	 * root installs.
	 *
	 * @param string $url Full URL or path.
	 * @return string Value starting with "/" (or "" when nothing is left).
	 */
	private function home_relative_path(string $url): string
	{
		$url = trim($url);

		if ($url === '') {
			return '';
		}

		$origin = $this->home_origin();

		if ($origin !== '' && stripos($url, $origin) === 0) {
			$url = substr($url, strlen($origin));
		}

		if ($url === '') {
			return '/';
		}

		if ($url[0] !== '/') {
			$url = '/' . $url;
		}

		// Subdirectory installs: "/blog/old-page" is stored/compared as
		// "/old-page" so a rule written by the admin matches the request.
		$home_path = $this->home_path();

		if ($home_path !== '' && $home_path !== '/') {
			if (stripos($url, $home_path . '/') === 0) {
				$url = substr($url, strlen($home_path));
			} elseif (strcasecmp($url, $home_path) === 0) {
				$url = '/';
			}
		}

		if ($url === '' || $url[0] !== '/') {
			$url = '/' . $url;
		}

		return $url;
	}

	/**
	 * The single normalisation used for matching.
	 *
	 * Applied to both the stored from_url and the incoming request so the two
	 * sides can never disagree (previously the stored value kept the
	 * subdirectory path while the request key had it stripped, and no rule on
	 * a site installed at /blog ever matched).
	 *
	 * @param string $url Full URL or path (may include a query string).
	 * @return string Lower-cased, decoded, slash-trimmed key.
	 */
	public function path_match_key(string $url): string
	{
		$url = $this->home_relative_path($url);

		if ($url === '') {
			return '';
		}

		// Foreign hosts have no home-relative representation.
		if (strncasecmp($url, '/http://', 8) === 0 || strncasecmp($url, '/https://', 9) === 0) {
			return '';
		}

		$url = urldecode($url);

		return strtolower(trim($url, '/'));
	}

	/**
	 * Normalise a "from" URL for storage: home-relative, sanitised, and
	 * reduced to a bare path segment (e.g. "old-page" or "old-page/?foo=bar").
	 *
	 * @param string $url Raw user input (full URL or relative path).
	 * @return string
	 */
	private function normalize_home_relative(string $url): string
	{
		if (trim($url) === '') {
			return '';
		}

		$url = $this->home_relative_path($url);

		// Sanitize while the leading slash is still in place — esc_url_raw()
		// (via esc_url()) only treats a value as a relative path when it starts
		// with "/"; otherwise it assumes it's a scheme-less absolute URL and
		// prepends "http://" to it. Stripping the leading slash BEFORE
		// sanitizing would turn "/old-page" into "http://old-page".
		$url = esc_url_raw($url, ['http', 'https', '']);

		// Always strip the leading slash so the stored value is a bare path
		// segment, regardless of whether a query string is present.
		$url = ltrim($url, '/');

		// No query string: also strip a trailing slash.
		if (strpos($url, '?') === false) {
			$url = rtrim($url, '/');
		}

		return $url;
	}

	/**
	 * Normalise a "to" URL destination before DB storage.
	 *
	 * When the destination does not include the site's home URL (e.g. "hello-post",
	 * "/hello-post", or "hello-post/"), it is converted to an absolute URL rooted
	 * at home_url() with trailing slash applied according to WordPress permalink rules.
	 *
	 * External URLs (with a different host) are preserved and sanitised.
	 *
	 * @param string $url Destination input.
	 * @return string Normalised destination URL.
	 */
	private function normalize_to_url(string $url): string
	{
		$url = trim($url);

		if ($url === '') {
			return '';
		}

		// Reject protocol-relative ("//") and backslash tricks ("/\") immediately.
		if (isset($url[1]) && $url[0] === '/' && ($url[1] === '/' || $url[1] === '\\')) {
			return '';
		}

		// Check for non-http(s) scheme (e.g. javascript:, data:, etc.).
		$scheme = (string) wp_parse_url($url, PHP_URL_SCHEME);
		if ($scheme !== '' && !in_array(strtolower($scheme), ['http', 'https'], true)) {
			return '';
		}

		$home_host   = (string) wp_parse_url(home_url(), PHP_URL_HOST);
		$target_host = (string) wp_parse_url($url, PHP_URL_HOST);

		// External destination: host is specified and differs from our home host.
		if ($target_host !== '' && strcasecmp($target_host, $home_host) !== 0) {
			return esc_url_raw($url);
		}

		$parsed = wp_parse_url($url);
		if (!is_array($parsed)) {
			return '';
		}

		$path = $parsed['path'] ?? '/';
		if ($path === '') {
			$path = '/';
		}

		// Strip home subfolder path if WordPress is installed in a subdirectory.
		$home_path = $this->home_path();
		if ($home_path !== '') {
			if (stripos($path, $home_path) === 0) {
				$path = substr($path, strlen($home_path));
			} elseif (stripos($path, ltrim($home_path, '/')) === 0) {
				$path = substr($path, strlen(ltrim($home_path, '/')));
			}
		}

		if ($path === '' || $path[0] !== '/') {
			$path = '/' . $path;
		}

		// Add trailing slash for non-root paths unless an extension is present (e.g. .pdf, .jpg).
		$basename = basename($path);
		$has_ext  = (strpos($basename, '.') !== false && !str_ends_with($path, '/'));

		if ($path !== '/' && !$has_ext) {
			$path = trailingslashit($path);
		}

		if (!empty($parsed['query'])) {
			$path .= '?' . $parsed['query'];
		}

		if (!empty($parsed['fragment'])) {
			$path .= '#' . $parsed['fragment'];
		}

		return home_url($path);
	}

	/**
	 * Build the WHERE clause and prepared values array for a query.
	 *
	 * @param array $args Query args.
	 * @return array {0: string WHERE clause, 1: array values}
	 */
	private function build_where(array $args): array
	{
		global $wpdb;

		$conditions = [];
		$values     = [];

		if (!empty($args['search'])) {
			$search = '%' . $wpdb->esc_like($args['search']) . '%';
			$conditions[] = "(from_url LIKE %s OR to_url LIKE %s OR note LIKE %s)";
			$values[] = $search;
			$values[] = $search;
			$values[] = $search;
		}

		if (isset($args['status']) && $args['status'] !== 'all') {
			if ($args['status'] === 'active') {
				$conditions[] = "enabled = 1";
			} elseif ($args['status'] === 'inactive') {
				$conditions[] = "enabled = 0";
			}
		}

		if (!empty($args['type'])) {
			$conditions[] = "redirect_type = %d";
			$values[]     = (int) $args['type'];
		}

		$where = !empty($conditions) ? ' WHERE ' . implode(' AND ', $conditions) : '';

		return [$where, $values];
	}

	/**
	 * Return sprintf format specifiers for an array of DB values.
	 *
	 * @param array $data Associative data array.
	 * @return string[]
	 */
	private function get_formats(array $data): array
	{
		$int_fields = ['redirect_type', 'ignore_query_string', 'enabled', 'hits', 'priority', 'allow_external'];
		$formats    = [];

		foreach (array_keys($data) as $key) {
			$formats[] = in_array($key, $int_fields, true) ? '%d' : '%s';
		}

		return $formats;
	}
}
