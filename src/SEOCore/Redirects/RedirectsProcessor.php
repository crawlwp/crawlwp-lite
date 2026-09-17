<?php

namespace Mihdan\IndexNow\SEOCore\Redirects;

/**
 * Frontend redirect processor.
 *
 * Runs on every page request, matches the current URL against enabled
 * redirects stored in the database, and performs the appropriate HTTP
 * response (redirect, 410 Gone, 451 Unavailable).
 *
 * Supported match types: exact, regex, contains, starts_with, ends_with.
 *
 * Uses a transient cache (via RedirectsManager::get_enabled_grouped()) so the
 * DB is only hit once per cache window rather than on every page load, and an
 * exact-path hashmap so regex rules are only evaluated when that lookup misses.
 */
class RedirectsProcessor
{
	/** Maximum accepted length (in characters) of a regex from_url pattern. */
	const MAX_REGEX_LENGTH = 500;

	/**
	 * PCRE backtrack limit applied while a user-supplied pattern runs.
	 *
	 * Deliberately far below the PHP default (1,000,000) so a catastrophic
	 * pattern aborts with PREG_BACKTRACK_LIMIT_ERROR in microseconds instead
	 * of pinning a CPU core. The rule is then auto-disabled.
	 */
	const BACKTRACK_LIMIT = 100000;

	/** @var RedirectsManager */
	private RedirectsManager $manager;

	/**
	 * Hosts temporarily allow-listed for the redirect currently being sent.
	 *
	 * @var string[]
	 */
	private array $runtime_allowed_hosts = [];

	public function __construct(RedirectsManager $manager)
	{
		$this->manager = $manager;

		/**
		 * Filters the action hook used to process redirects.
		 *
		 * @param string $hook Action hook name. Default 'wp'.
		 */
		$hook = (string) apply_filters('crawlwp_redirect_hook', 'wp');

		/**
		 * Filters the priority of the redirect processing action hook.
		 *
		 * @param int $priority Hook priority. Default -1.
		 */
		$priority = (int) apply_filters('crawlwp_redirect_priority', -1);

		add_action($hook, [$this, 'process'], $priority);
		add_filter('allowed_redirect_hosts', [$this, 'filter_allowed_redirect_hosts']);
	}

	/**
	 * Match the current request against stored redirects and respond.
	 *
	 * Hooked to wp at priority -1 by default so CrawlWP fires before
	 * theme templates or template-redirect handlers.
	 */
	public function process(): void
	{
		if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
			return;
		}

		// Never interfere with sitemaps, feeds, robots.txt or REST API requests.
		if (!empty(get_query_var('sitemap')) || is_feed() || is_robots()) {
			return;
		}

		if (defined('REST_REQUEST') && REST_REQUEST) {
			return;
		}

		$redirects = $this->manager->get_enabled_grouped();

		if (empty($redirects['exact']) && empty($redirects['rules'])) {
			return;
		}

		$request_uri   = $_SERVER['REQUEST_URI'] ?? '/';
		$request_path  = urldecode((string) parse_url($request_uri, PHP_URL_PATH));
		$request_query = parse_url($request_uri, PHP_URL_QUERY);

		if (!is_string($request_query)) {
			$request_query = null;
		}

		// One shared normalisation for both sides of the comparison, so a
		// subdirectory install ("/blog") matches its stored rules.
		$request_path_key = $this->manager->path_match_key($request_path);
		$request_uri_key  = $this->manager->path_match_key($request_uri);

		// Fast path: a keyed lookup of plain from_urls. Regex and partial
		// rules are only walked when this misses.
		$exact_candidates = $redirects['exact'][$request_path_key] ?? [];

		foreach ([$exact_candidates, $redirects['rules']] as $rule_set) {
			foreach ($rule_set as $redirect) {
				$to_url = '';

				if (!$this->matches(
					$redirect,
					$request_path,
					$request_uri,
					$request_query,
					$request_path_key,
					$request_uri_key,
					$to_url
				)) {
					continue;
				}

				$type = (int) $redirect->redirect_type;

				// Content-deleted and legally-unavailable pages just send a status.
				if ($type === 410 || $type === 451) {
					$this->manager->record_hit((int) $redirect->id);
					$this->send_gone($redirect, $type);
				}

				// Standard redirects — only follow a destination that passes
				// validation. A rejected destination must never abort the whole
				// rule set: keep evaluating the remaining (lower priority) rules.
				$to_url = $this->validate_destination($to_url, $request_uri, $redirect);

				if ($to_url === '') {
					continue;
				}

				$this->allow_rule_host($redirect, $to_url);

				// Final gate: wp_validate_redirect() enforces the host allow
				// list (see filter_allowed_redirect_hosts()).
				$safe_url = wp_validate_redirect($to_url, '');

				if ($safe_url === '') {
					continue;
				}

				$this->manager->record_hit((int) $redirect->id);

				wp_safe_redirect($safe_url, $type, 'CrawlWP');
				exit;
			}
		}
	}

	/**
	 * Send a 410 / 451 response with a real, filterable body.
	 *
	 * @param object $redirect Matched rule.
	 * @param int    $type     410 or 451.
	 */
	private function send_gone(object $redirect, int $type): void
	{
		nocache_headers();

		$default = $type === 451
			? __('This content is unavailable for legal reasons.', 'mihdan-index-now')
			: __('This content has been permanently removed.', 'mihdan-index-now');

		$title = $type === 451
			? __('Unavailable For Legal Reasons', 'mihdan-index-now')
			: __('Gone', 'mihdan-index-now');

		/**
		 * Filters the body shown for a 410 / 451 redirect rule.
		 *
		 * Return a full HTML document (or a WP_Error) to replace the default
		 * wp_die() template entirely.
		 *
		 * @param string $message  Message body.
		 * @param int    $type     HTTP status code (410 or 451).
		 * @param object $redirect Matched redirect rule.
		 */
		$message = apply_filters('crawlwp_redirect_gone_message', $default, $type, $redirect);

		/**
		 * Filters the page title shown for a 410 / 451 redirect rule.
		 *
		 * @param string $title    Page title.
		 * @param int    $type     HTTP status code (410 or 451).
		 * @param object $redirect Matched redirect rule.
		 */
		$title = apply_filters('crawlwp_redirect_gone_title', $title, $type, $redirect);

		wp_die($message, $title, ['response' => $type, 'exit' => true]);
	}

	/**
	 * Add the hosts opted into by the matched rule and by the
	 * `crawlwp_redirect_allowed_hosts` filter to WordPress' allow list.
	 *
	 * @param string[] $hosts Hosts wp_validate_redirect() accepts.
	 * @return string[]
	 */
	public function filter_allowed_redirect_hosts($hosts): array
	{
		$hosts = is_array($hosts) ? $hosts : [];

		// wp_validate_redirect() compares hosts strictly, so register the
		// administrator's original casing alongside the normalised form.
		return array_values(array_unique(array_merge(
			$hosts,
			$this->allowed_external_hosts(false),
			$this->allowed_external_hosts(),
			$this->runtime_allowed_hosts
		)));
	}

	/**
	 * Hosts an administrator has allow-listed for external redirects.
	 *
	 * Redirects are same-host only by default. Rules saved with the
	 * "Allow external destination" flag are permitted individually; this
	 * filter allows site-wide allow-listing instead.
	 *
	 * Note on upgrades: rules that already pointed at another host before the
	 * flag existed were granted allow_external = 1 by the schema upgrade in
	 * RedirectsManager, so working external redirects keep working without
	 * needing this filter.
	 *
	 * @param bool $normalize Whether to lower-case the host names.
	 * @return string[] Host names.
	 */
	private function allowed_external_hosts(bool $normalize = true): array
	{
		/**
		 * Filters the hosts CrawlWP may redirect to.
		 *
		 * @param string[] $hosts Host names (no scheme), e.g. ['example.com'].
		 */
		$hosts = apply_filters('crawlwp_redirect_allowed_hosts', []);

		if (!is_array($hosts)) {
			return [];
		}

		$clean = [];

		foreach ($hosts as $host) {
			$host = trim((string) $host);

			if ($host === '') {
				continue;
			}

			$clean[] = $normalize ? strtolower($host) : $host;
		}

		return $clean;
	}

	/**
	 * Temporarily allow the destination host of a rule flagged as external.
	 *
	 * @param object $redirect Matched rule.
	 * @param string $url      Resolved destination.
	 */
	private function allow_rule_host(object $redirect, string $url): void
	{
		if ((int) ($redirect->allow_external ?? 0) !== 1) {
			return;
		}

		$host = (string) wp_parse_url($url, PHP_URL_HOST);

		if ($host === '') {
			return;
		}

		// wp_validate_redirect() compares the host strictly, so register the
		// value as written as well as its lower-cased form.
		foreach ([$host, strtolower($host)] as $variant) {
			if (!in_array($variant, $this->runtime_allowed_hosts, true)) {
				$this->runtime_allowed_hosts[] = $variant;
			}
		}
	}

	/**
	 * Validate a resolved redirect destination before it is used.
	 *
	 * Accepts site-relative paths beginning with a single "/" and absolute
	 * http(s) URLs on this site's host. Rejects protocol-relative ("//"),
	 * backslash tricks ("/\\"), non-http schemes (javascript:, data:, …), any
	 * destination identical to the current request (self-redirect loop) and —
	 * unless the rule opts in or the host is allow-listed — every external
	 * host (open-redirect guard).
	 *
	 * @param string      $url         Resolved destination URL.
	 * @param string      $request_uri Current request URI (path + query).
	 * @param object|null $redirect    Matched rule, when available.
	 * @return string The destination when safe, empty string otherwise.
	 */
	private function validate_destination(string $url, string $request_uri = '', ?object $redirect = null): string
	{
		$url = trim($url);

		if ($url === '') {
			return '';
		}

		// Reject control characters / whitespace which browsers may strip.
		if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
			return '';
		}

		if ($url[0] === '/') {
			// Site-relative path: must not be protocol-relative or use a backslash.
			if (isset($url[1]) && ($url[1] === '/' || $url[1] === '\\')) {
				return '';
			}
		} else {
			$parsed = wp_parse_url($url);

			if (!is_array($parsed) || empty($parsed['scheme']) || empty($parsed['host'])) {
				return '';
			}

			if (!in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
				return '';
			}

			// Open-redirect guard: same host by default.
			if ($this->manager->is_external_destination($url) && !$this->is_external_allowed($parsed['host'], $redirect)) {
				return '';
			}
		}

		// Self-redirect loop guard: compare the destination against the current
		// request, resolving site-relative values to the site origin so a path
		// and its absolute equivalent are treated as the same location.
		if ($request_uri !== '') {
			$home   = wp_parse_url(home_url());
			$origin = isset($home['scheme'], $home['host']) ? $home['scheme'] . '://' . $home['host'] : '';

			$current = untrailingslashit($origin . $request_uri);
			$target  = untrailingslashit(($url[0] === '/') ? $origin . $url : $url);

			if ($url === $request_uri || strcasecmp($target, $current) === 0) {
				return '';
			}
		}

		return $url;
	}

	/**
	 * Whether an external host may be redirected to.
	 *
	 * @param string      $host     Destination host.
	 * @param object|null $redirect Matched rule, when available.
	 * @return bool
	 */
	private function is_external_allowed(string $host, ?object $redirect): bool
	{
		$host = strtolower(trim($host));

		if ($host === '') {
			return false;
		}

		if ($redirect !== null && (int) ($redirect->allow_external ?? 0) === 1) {
			return true;
		}

		return in_array($host, $this->allowed_external_hosts(), true);
	}

	// -------------------------------------------------------------------------
	// Matching logic
	// -------------------------------------------------------------------------

	/**
	 * Test whether a redirect rule matches the current request.
	 *
	 * Sets $to_url (passed by reference) to the final destination URL when
	 * a match is found.
	 *
	 * @param object      $redirect            DB row object.
	 * @param string      $request_path        Decoded request path (no query string).
	 * @param string      $request_with_query  Full request URI including query string.
	 * @param string|null $request_query       Raw query string from the request.
	 * @param string      $request_path_key    Normalised request path key.
	 * @param string      $request_uri_key     Normalised full request URI key.
	 * @param string      $to_url              Resolved destination URL (output).
	 * @return bool
	 */
	private function matches(
		object $redirect,
		string $request_path,
		string $request_with_query,
		?string $request_query,
		string $request_path_key,
		string $request_uri_key,
		string &$to_url
	): bool {
		$from       = $redirect->from_url;
		$match_type = $redirect->match_type;
		$ignore_qs  = (bool) $redirect->ignore_query_string;
		$raw_to     = $redirect->to_url;
		$subject    = $ignore_qs ? $request_path : $request_with_query;

		switch ($match_type) {
			case 'regex':
				$matched = $this->match_regex($from, $subject, $raw_to, $to_url, $redirect);
				break;
			case 'contains':
				$matched = $this->match_contains($from, $subject, $raw_to, $to_url);
				break;
			case 'starts_with':
				$matched = $this->match_starts_with($from, $subject, $raw_to, $to_url);
				break;
			case 'ends_with':
				$matched = $this->match_ends_with($from, $subject, $raw_to, $to_url);
				break;
			default:
				$matched = $this->match_exact($from, $ignore_qs, $request_path_key, $request_uri_key, $raw_to, $to_url);
				break;
		}

		if (!$matched) return false;

		// When ignore_qs is disabled and the stored rule has no query string,
		// the match stripped the request query for comparison purposes. Now
		// append it to the destination so users land on the equivalent URL.
		if (!$ignore_qs && $request_query !== null && $request_query !== '') {
			$from_has_query = strpos(urldecode($from), '?') !== false;

			if (!$from_has_query) {
				// A fragment must stay last: "/page#section" + "foo=bar" is
				// "/page?foo=bar#section", never "/page#section?foo=bar".
				$fragment = '';
				$hash_pos = strpos($to_url, '#');

				if ($hash_pos !== false) {
					$fragment = substr($to_url, $hash_pos);
					$to_url   = substr($to_url, 0, $hash_pos);
				}

				$separator = ($to_url !== '' && strpos($to_url, '?') !== false) ? '&' : '?';
				$to_url   .= $separator . $request_query . $fragment;
			}
		}

		return true;
	}

	/**
	 * Perform an exact-match comparison.
	 *
	 * Both sides go through RedirectsManager::path_match_key(), so the stored
	 * value and the request are normalised identically (origin stripped, home
	 * subdirectory stripped, decoded, lower-cased, slashes trimmed).
	 *
	 * When the stored from_url contains a query string, the full URI (path +
	 * query) is compared. When from_url has no query string, only the path
	 * portion of the request is compared — regardless of the ignore_qs setting
	 * — so a rule like "/old-page/" correctly matches "/old-page/?foo=bar".
	 *
	 * @param string $from             Stored from_url.
	 * @param bool   $ignore_qs        Whether the rule ignores query strings.
	 * @param string $request_path_key Normalised request path key.
	 * @param string $request_uri_key  Normalised full request URI key.
	 * @param string $raw_to           Stored to_url.
	 * @param string $to_url           Resolved destination (output).
	 * @return bool
	 */
	private function match_exact(
		string $from,
		bool $ignore_qs,
		string $request_path_key,
		string $request_uri_key,
		string $raw_to,
		string &$to_url
	): bool {
		$from_key = $this->manager->path_match_key($from);

		if ($from_key === '') {
			// Legacy rows may store an absolute URL on another host (e.g. the
			// site moved domain); fall back to comparing its path.
			$path = (string) wp_parse_url($from, PHP_URL_PATH);

			if ($path === '') {
				return false;
			}

			$query    = (string) wp_parse_url($from, PHP_URL_QUERY);
			$from_key = $this->manager->path_match_key($path . ($query !== '' ? '?' . $query : ''));

			if ($from_key === '') {
				return false;
			}
		}

		$from_has_query = strpos($from_key, '?') !== false;

		// Path-only rules always compare against the path. Query-bearing rules
		// compare against the path when ignore_qs is on, otherwise the full URI.
		$subject = $from_has_query
			? ($ignore_qs ? $request_path_key : $request_uri_key)
			: $request_path_key;

		if ($from_key !== $subject) {
			return false;
		}

		$to_url = $raw_to;
		return true;
	}

	/**
	 * Perform a case-insensitive regex match with optional capture-group
	 * substitution in to_url.
	 *
	 * The pattern runs under a reduced pcre.backtrack_limit; a rule whose
	 * pattern aborts (catastrophic backtracking, recursion limit, bad UTF-8)
	 * is automatically disabled and logged so it cannot keep burning CPU on
	 * every request.
	 *
	 * @param string      $pattern  Pattern stored in from_url.
	 * @param string      $subject  Current request path or full URI.
	 * @param string      $raw_to   Stored to_url (may contain $1, $2, …).
	 * @param string      $to_url   Resolved destination (output).
	 * @param object|null $redirect Matched rule (used to disable it on failure).
	 * @return bool
	 */
	private function match_regex(string $pattern, string $subject, string $raw_to, string &$to_url, ?object $redirect = null): bool
	{
		if ($pattern === '' || strlen($pattern) > self::MAX_REGEX_LENGTH) {
			return false;
		}

		// Always wrap the stored pattern in fixed "#" delimiters. User-supplied
		// delimiters and modifiers are never honoured — a path-like pattern such
		// as "/old-page/" is therefore treated as a plain pattern body rather
		// than an already-delimited regex. "#" is used instead of "/" because
		// URL paths are full of literal slashes. The "i" modifier keeps regex
		// rules as case-insensitive as every other match type.
		$regex = '#' . str_replace('#', '\#', $pattern) . '#i';

		$previous_limit = ini_get('pcre.backtrack_limit');

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky
		@ini_set('pcre.backtrack_limit', (string) self::BACKTRACK_LIMIT);

		// Invalid patterns fail preg_match (false); non-matches return 0.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$matched = @preg_match($regex, $subject, $matches);
		$error   = preg_last_error();

		if ($previous_limit !== false) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky
			@ini_set('pcre.backtrack_limit', (string) $previous_limit);
		}

		if ($matched === false || $error !== PREG_NO_ERROR) {
			$this->handle_regex_failure($redirect, $pattern, $error);

			return false;
		}

		if ($matched !== 1) {
			return false;
		}

		// Replace $1, $2 … capture group placeholders in the destination.
		$resolved = preg_replace_callback('/\$(\d+)/', static function ($m) use ($matches) {
			$idx = (int) $m[1];
			return $matches[$idx] ?? '';
		}, $raw_to);

		// preg_replace_callback returns null on error; treat that as no match.
		if ($resolved === null) {
			return false;
		}

		// A capture group must never be able to change the destination host
		// (e.g. "http://$1" with a request of "/old/evil.com").
		$raw_host      = (string) wp_parse_url($raw_to, PHP_URL_HOST);
		$resolved_host = (string) wp_parse_url($resolved, PHP_URL_HOST);

		if ($raw_host !== $resolved_host) {
			return false;
		}

		$to_url = $resolved;

		return true;
	}

	/**
	 * Disable a regex rule that failed at runtime.
	 *
	 * @param object|null $redirect Matched rule.
	 * @param string      $pattern  Offending pattern.
	 * @param int         $error    preg_last_error() value.
	 */
	private function handle_regex_failure(?object $redirect, string $pattern, int $error): void
	{
		if ($redirect === null || empty($redirect->id)) {
			return;
		}

		if ($error === PREG_BACKTRACK_LIMIT_ERROR) {
			$reason = __('The regex pattern exceeded the PCRE backtrack limit (catastrophic backtracking) and was disabled automatically.', 'mihdan-index-now');
		} elseif ($error === PREG_RECURSION_LIMIT_ERROR) {
			$reason = __('The regex pattern exceeded the PCRE recursion limit and was disabled automatically.', 'mihdan-index-now');
		} else {
			$reason = __('The regex pattern could not be executed and was disabled automatically.', 'mihdan-index-now');
		}

		$this->manager->disable_rule_for_error((int) $redirect->id, $reason, $pattern);
	}

	/**
	 * Perform a case-insensitive "contains" match — the rule matches when
	 * $from appears anywhere within the request subject.
	 *
	 * @param string $from Stored from_url (substring to look for).
	 * @param string $subject Current request path or full URI.
	 * @param string $raw_to Stored to_url.
	 * @param string $to_url Resolved destination (output).
	 * @return bool
	 */
	private function match_contains(string $from, string $subject, string $raw_to, string &$to_url): bool
	{
		$from = urldecode($from);

		if ($from === '' || stripos($subject, $from) === false) {
			return false;
		}

		$to_url = $raw_to;
		return true;
	}

	/**
	 * Perform a case-insensitive "starts with" match.
	 *
	 * @param string $from Stored from_url.
	 * @param string $subject Current request path or full URI.
	 * @param string $raw_to Stored to_url.
	 * @param string $to_url Resolved destination (output).
	 * @return bool
	 */
	private function match_starts_with(string $from, string $subject, string $raw_to, string &$to_url): bool
	{
		// The stored from_url may lack a leading slash (bare path segment), so
		// strip the leading slash from the request subject too before comparing.
		$from     = ltrim(urldecode($from), '/');
		$from_len = strlen($from);

		if ($from_len === 0) {
			return false;
		}

		$subject = ltrim($subject, '/');

		if (strlen($subject) < $from_len || strncasecmp($subject, $from, $from_len) !== 0) {
			return false;
		}

		$to_url = $raw_to;
		return true;
	}

	/**
	 * Perform a case-insensitive "ends with" match.
	 *
	 * @param string $from Stored from_url.
	 * @param string $subject Current request path or full URI.
	 * @param string $raw_to Stored to_url.
	 * @param string $to_url Resolved destination (output).
	 * @return bool
	 */
	private function match_ends_with(string $from, string $subject, string $raw_to, string &$to_url): bool
	{
		// The stored from_url may lack a trailing slash (bare path segment), so
		// strip the trailing slash from the request subject too before comparing.
		$from     = rtrim(urldecode($from), '/');
		$from_len = strlen($from);

		if ($from_len === 0) {
			return false;
		}

		$subject = rtrim($subject, '/');

		if (strlen($subject) < $from_len || substr_compare($subject, $from, -$from_len, $from_len, true) !== 0) {
			return false;
		}

		$to_url = $raw_to;
		return true;
	}
}
