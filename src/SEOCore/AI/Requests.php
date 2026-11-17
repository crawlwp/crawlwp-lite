<?php

namespace Mihdan\IndexNow\SEOCore\AI;

/**
 * Shared by every AI endpoint (SEO box, Bulk Editor): a per-user rate limit
 * and the error shown to the user.
 */
class Requests
{
	/**
	 * Per-user rate limit for AI generation: by default at most 20 generated
	 * values per 5 minutes, plus a site-wide daily cap of which one user may use
	 * at most half (so nobody can lock everyone else out). A slot is reserved when
	 * the limit is checked (so requests sent in parallel can't all slip through
	 * while the provider works) and given back at the end of the request unless
	 * count_request() confirmed it, so provider errors or missing content never
	 * lock a user out.
	 *
	 * @param bool $batch Whether the request is part of a "Write with AI" run in the Bulk Editor.
	 */
	public static function rate_limited(bool $batch = false): bool
	{
		/**
		 * Filter the number of AI generations allowed per user in each window.
		 *
		 * @param int  $limit Default 20. 0 or less turns the limit off.
		 * @param bool $batch Whether the request is part of a Bulk Editor "Write with AI" run.
		 */
		$limit = (int) apply_filters('crawlwp_ai_rate_limit', 20, $batch);

		/* Check and reserve under one lock, so requests sent at the same moment are all counted. */
		$locked = self::lock($batch);

		try {
			return self::check_and_reserve($limit, $batch);
		} finally {
			if ($locked) {
				self::unlock();
			}
		}
	}

	private static function check_and_reserve(int $limit, bool $batch): bool
	{
		self::$reason = '';

		if ($limit > 0 && (int) self::state($batch)['count'] >= $limit) {
			self::$reason = 'window';

			return true;
		}

		$daily = self::daily_limit();

		if ($daily > 0 && self::counter(self::daily_key()) >= $daily) {
			self::$reason = 'daily';

			return true;
		}

		$user_daily = self::user_daily_limit($daily);

		if ($user_daily > 0 && self::counter(self::user_daily_key()) >= $user_daily) {
			self::$reason = 'user_daily';

			return true;
		}

		self::add($batch, 1);

		if (! self::$pending) {
			register_shutdown_function([__CLASS__, 'refund_unused']);
		}

		self::$pending[] = $batch;

		return false;
	}

	/**
	 * Keep the slot rate_limited() reserved for a successful generation.
	 * Bulk Editor runs have their own counter, so a long run doesn't lock the
	 * AI buttons in the post editor.
	 */
	public static function count_request(bool $batch = false): void
	{
		$index = array_search($batch, self::$pending, true);

		if ($index !== false) {
			unset(self::$pending[$index]);

			return;
		}

		/* Called without checking the limit first: count it now. */
		self::locked_add($batch, 1);
	}

	/**
	 * Give back the slots of requests that didn't produce anything.
	 *
	 * @internal Runs at shutdown.
	 */
	public static function refund_unused(): void
	{
		foreach (self::$pending as $batch) {
			self::locked_add($batch, -1);
		}

		self::$pending = [];
	}

	/** @var bool[] Reserved slots not yet used, one entry per reservation (the batch flag). */
	private static $pending = [];

	/** @var string Why the last rate_limited() call said no: window, daily or user_daily. */
	private static $reason = '';

	/**
	 * Whether the limit rate_limited() just hit lasts until tomorrow (no point waiting minutes).
	 */
	public static function limit_is_daily(): bool
	{
		return in_array(self::$reason, ['daily', 'user_daily'], true);
	}

	/**
	 * The message for the limit rate_limited() just hit.
	 */
	public static function limit_message(): string
	{
		if (self::$reason === 'daily') {
			return __('This site has used today\'s AI generations. The limit resets at midnight UTC.', 'mihdan-index-now');
		}

		if (self::$reason === 'user_daily') {
			return __('You have used your share of today\'s AI generations on this site. It resets at midnight UTC.', 'mihdan-index-now');
		}

		return __('Too many AI requests. Please wait a few minutes and try again.', 'mihdan-index-now');
	}

	/**
	 * One user's daily allowance: half the site's cap by default, so a single
	 * user can't use it all up.
	 */
	private static function user_daily_limit(int $daily): int
	{
		/**
		 * Filter the number of AI generations one user may make per day.
		 *
		 * @param int $limit Default: half the site's daily limit. 0 or less turns it off.
		 * @param int $daily The site's daily limit.
		 */
		return (int) apply_filters('crawlwp_ai_user_daily_limit', $daily > 0 ? (int) ceil($daily / 2) : 0, $daily);
	}

	private static function user_daily_key(): string
	{
		return 'crawlwp_ai_daily_' . get_current_user_id() . '_' . gmdate('Ymd');
	}

	/**
	 * A daily counter. With a persistent object cache it is read and changed with
	 * wp_cache_incr()/wp_cache_decr(), which are atomic there. Without one it is a
	 * transient read and then written; lock() keeps two requests from doing that at
	 * the same moment.
	 */
	private static function counter(string $key): int
	{
		return wp_using_ext_object_cache() ? (int) wp_cache_get($key, 'crawlwp_ai') : (int) get_transient($key);
	}

	private static function bump(string $key, int $amount): void
	{
		if (wp_using_ext_object_cache()) {
			wp_cache_add($key, 0, 'crawlwp_ai', DAY_IN_SECONDS);
			$amount >= 0 ? wp_cache_incr($key, $amount, 'crawlwp_ai') : wp_cache_decr($key, -$amount, 'crawlwp_ai');

			return;
		}

		set_transient($key, max(0, (int) get_transient($key) + $amount), DAY_IN_SECONDS);
	}

	/**
	 * Generations allowed per day on the whole site, for every user together.
	 */
	private static function daily_limit(): int
	{
		/**
		 * Filter the number of AI generations the whole site may make per day.
		 *
		 * @param int $limit Default 2000. 0 or less turns the cap off.
		 */
		return (int) apply_filters('crawlwp_ai_daily_limit', 2000);
	}

	private static function daily_key(): string
	{
		return 'crawlwp_ai_daily_' . gmdate('Ymd');
	}

	private static function locked_add(bool $batch, int $amount): void
	{
		$locked = self::lock($batch);

		try {
			self::add($batch, $amount);
		} finally {
			if ($locked) {
				self::unlock();
			}
		}
	}

	/**
	 * A short database lock around reading and changing the counters. Without it two
	 * requests read the same count and both store count + 1, so one paid generation goes
	 * uncounted every time requests overlap. If the lock can't be had within a few seconds
	 * (or the database doesn't offer one) the request carries on as before, unlocked.
	 */
	private static function lock(bool $batch): bool
	{
		global $wpdb;

		if (! is_object($wpdb) || ! method_exists($wpdb, 'get_var') || ! method_exists($wpdb, 'prepare')) {
			return false;
		}

		$locked = (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::lock_name(), 3)) === '1'; // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ($locked) {
			/* This request may hold counts it read before another request changed them. */
			foreach ([self::key($batch), self::daily_key(), self::user_daily_key()] as $key) {
				self::forget($key);
			}
		}

		return $locked;
	}

	private static function unlock(): void
	{
		global $wpdb;

		$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name())); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** One lock per site (MySQL lock names are server-wide and at most 64 characters). */
	private static function lock_name(): string
	{
		global $wpdb;

		return 'crawlwp_ai_' . md5((defined('DB_NAME') ? DB_NAME : '') . '|' . (isset($wpdb->options) ? $wpdb->options : ''));
	}

	/**
	 * Drop this request's in-memory copy of a transient kept in the options table, so the
	 * next read comes from the database. With a persistent object cache reads are already shared.
	 */
	private static function forget(string $key): void
	{
		if (wp_using_ext_object_cache()) {
			return;
		}

		$names      = ['_transient_' . $key, '_transient_timeout_' . $key];
		$notoptions = wp_cache_get('notoptions', 'options');
		$changed    = false;

		foreach ($names as $name) {
			wp_cache_delete($name, 'options');

			if (is_array($notoptions) && isset($notoptions[$name])) {
				unset($notoptions[$name]);
				$changed = true;
			}
		}

		if ($changed) {
			wp_cache_set('notoptions', $notoptions, 'options');
		}
	}

	private static function add(bool $batch, int $amount): void
	{
		$state          = self::state($batch);
		$state['count'] = max(0, (int) $state['count'] + $amount);
		$remaining      = self::window() - (time() - (int) $state['start']);

		// The 5-minute window is a [count, start] pair, so it stays a transient (read, then written).
		set_transient(self::key($batch), $state, max(1, $remaining));

		self::bump(self::daily_key(), $amount);
		self::bump(self::user_daily_key(), $amount);
	}

	private static function key(bool $batch): string
	{
		return 'crawlwp_ai_rl_' . get_current_user_id() . ($batch ? '_batch' : '');
	}

	private static function window(): int
	{
		return (int) apply_filters('crawlwp_ai_rate_limit_window', 5 * MINUTE_IN_SECONDS);
	}

	/**
	 * Fixed window: [count, window start]. A missing/expired transient starts a fresh window.
	 */
	private static function state(bool $batch = false): array
	{
		$state = get_transient(self::key($batch));
		$now   = time();

		if (! is_array($state) || ! isset($state['count'], $state['start']) || ($now - (int) $state['start']) >= self::window()) {
			$state = ['count' => 0, 'start' => $now];
		}

		return $state;
	}

	/**
	 * Build the payload shown to the user when AI generation failed.
	 *
	 * Only errors that the user fixes by connecting a provider link to the
	 * Connectors screen; content and provider errors are shown as they are so
	 * the message stays truthful.
	 */
	public static function error_response(\WP_Error $error): array
	{
		$code    = $error->get_error_code();
		$message = $error->get_error_message();

		$connection_codes = ['crawlwp_ai_unavailable', 'crawlwp_ai_unsupported'];

		if (in_array($code, $connection_codes, true)) {
			Generator::forget_ready();

			return [
				'message'    => __('AI generation is unavailable. Connect an AI provider in WordPress under Settings → Connectors, then try again.', 'mihdan-index-now')
					. ($message !== '' ? "\n\n" . $message : ''),
				'connectUrl' => admin_url('options-connectors.php'),
			];
		}

		return [
			'message' => $message !== ''
				? $message
				: __('AI generation failed. Please try again.', 'mihdan-index-now'),
		];
	}
}
