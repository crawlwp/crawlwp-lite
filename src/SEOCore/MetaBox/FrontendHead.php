<?php

namespace Mihdan\IndexNow\SEOCore\MetaBox;

/**
 * Front end behaviour driven by the per-post SEO metabox.
 *
 * Meta tag output lives in {@see \Mihdan\IndexNow\SEOCore\TitleMeta\FrontendOutput},
 * which resolves the per-post values registered here together with the global
 * defaults configured under Title & Meta. Keeping a single writer avoids
 * duplicated title, description and Open Graph tags.
 */
class FrontendHead
{
	/**
	 * Status codes that are served as a response body instead of a redirect.
	 */
	private const BODY_STATUSES = [410, 451];

	public function __construct()
	{
		add_action('template_redirect', [$this, 'handle_redirect']);
	}

	/**
	 * Handle per-post redirects.
	 */
	public function handle_redirect(): void
	{
		if (! is_singular()) {
			return;
		}

		$post_id = get_queried_object_id();

		if (! $post_id) {
			return;
		}

		$redirect_url  = (string) MetaFields::get($post_id, MetaFields::REDIRECT_URL);
		$redirect_type = MetaFields::get($post_id, MetaFields::REDIRECT_TYPE, '301');

		$code = (int) $redirect_type;

		// 410/451 need no destination — the URL field may legitimately be empty.
		if (in_array($code, self::BODY_STATUSES, true)) {
			$this->render_status($code, $post_id);
		}

		if ($redirect_url === '') {
			return;
		}

		// Re-validate on output: absolute http(s) URL with a host, or a single-slash relative path.
		$redirect_url = MetaFields::sanitize_url($redirect_url);

		if ($redirect_url === '') {
			return;
		}

		if (! in_array($code, [301, 302, 307], true)) {
			$code = 301;
		}

		// Loop guard: never redirect a URL to itself.
		$target_url  = untrailingslashit($redirect_url[0] === '/' ? home_url($redirect_url) : $redirect_url);
		$request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
		$request_url = untrailingslashit((is_ssl() ? 'https://' : 'http://') . wp_parse_url(home_url(), PHP_URL_HOST) . $request_uri);

		if ($target_url === $request_url || $target_url === untrailingslashit((string) get_permalink($post_id))) {
			return;
		}

		wp_redirect($redirect_url, $code, 'CrawlWP');
		exit;
	}

	/**
	 * Render the response for a status code that needs no destination and stop.
	 *
	 * wp_die() is used instead of hand-rolled HTML so the output runs through
	 * the theme's / WordPress' own error template handling, and both the title
	 * and the body can be replaced by a theme or add-on.
	 *
	 * @param int $code    410 or 451.
	 * @param int $post_id The post carrying the status.
	 */
	private function render_status(int $code, int $post_id): void
	{
		nocache_headers();

		if ($code === 451) {
			$heading = __('451 Unavailable For Legal Reasons', 'mihdan-index-now');
			$body    = __('This content is unavailable for legal reasons.', 'mihdan-index-now');
		} else {
			$heading = __('410 Gone', 'mihdan-index-now');
			$body    = __('This content has been permanently removed.', 'mihdan-index-now');
		}

		/**
		 * Filters the title of the 410 Gone / 451 Unavailable page.
		 *
		 * The dynamic portion of the hook name, `$code`, is the status code.
		 *
		 * @param string $title   The page title.
		 * @param int    $post_id The post carrying the status.
		 */
		$title = (string) apply_filters("crawlwp_{$code}_title", $heading, $post_id);

		$default_message = '<h1>' . esc_html($heading) . '</h1><p>' . esc_html($body) . '</p>';

		/**
		 * Filters the body of the 410 Gone / 451 Unavailable page.
		 *
		 * The dynamic portion of the hook name, `$code`, is the status code.
		 * The message is passed to wp_die(), so it may contain HTML. Anything
		 * hooked here is responsible for escaping its own output.
		 *
		 * @param string $message The page body.
		 * @param int    $post_id The post carrying the status.
		 */
		$message = (string) apply_filters("crawlwp_{$code}_message", $default_message, $post_id);

		wp_die($message, $title, ['response' => $code]);
	}
}
