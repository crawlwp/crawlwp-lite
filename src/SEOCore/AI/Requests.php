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
	 * values per 5 minutes. Only successful generations count (see
	 * count_request()), so provider errors or missing content never lock a
	 * user out.
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

		if ($limit <= 0) {
			return false;
		}

		return (int) self::state($batch)['count'] >= $limit;
	}

	/**
	 * Count a successful generation. Bulk Editor runs have their own counter,
	 * so a long run doesn't lock the AI buttons in the post editor.
	 */
	public static function count_request(bool $batch = false): void
	{
		$state = self::state($batch);

		$state['count'] = (int) $state['count'] + 1;
		$remaining      = self::window() - (time() - (int) $state['start']);

		set_transient(self::key($batch), $state, max(1, $remaining));
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
