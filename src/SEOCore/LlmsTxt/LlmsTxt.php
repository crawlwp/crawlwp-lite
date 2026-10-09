<?php

namespace Mihdan\IndexNow\SEOCore\LlmsTxt;

use Mihdan\IndexNow\Utils;
use Mihdan\IndexNow\Views\WPOSA;

/**
 * Virtual /llms.txt for AI crawlers.
 */
class LlmsTxt
{
	const SECTION = 'llms_txt';

	public function __construct()
	{
		add_action('crawlwp_setup_fields', [$this, 'settings_fields'], 36, 2);
		add_action('init', [$this, 'add_rewrite']);
		add_filter('query_vars', [$this, 'query_vars']);
		add_action('parse_request', [$this, 'maybe_render'], 0);

		/*
		 * Flush the rewrite rules only when the settings are saved. Flushing on
		 * 'init' rebuilt every rewrite rule on a regular front-end request, which
		 * is one of the most expensive operations WordPress offers.
		 */
		add_action('update_option_crawlwp_' . self::SECTION, [$this, 'flush_rewrite']);
		add_action('add_option_crawlwp_' . self::SECTION, [$this, 'flush_rewrite']);
	}

	public function add_rewrite(): void
	{
		add_rewrite_rule('^llms\.txt$', 'index.php?crawlwp_llms_txt=1', 'top');
	}

	/**
	 * Rebuild the rewrite rules so /llms.txt resolves.
	 *
	 * Hooks: update_option_crawlwp_llms_txt, add_option_crawlwp_llms_txt.
	 */
	public function flush_rewrite(): void
	{
		flush_rewrite_rules(false);
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public function query_vars(array $vars): array
	{
		$vars[] = 'crawlwp_llms_txt';

		return $vars;
	}

	/**
	 * Serve /llms.txt, or a clean 404 when the feature is disabled.
	 *
	 * Runs on parse_request so it also works with Plain permalinks (no rewrite
	 * rule), by matching the request path directly.
	 *
	 * @param \WP $wp Current WordPress environment instance.
	 */
	public function maybe_render($wp): void
	{
		$is_llms = $wp instanceof \WP && (int) ($wp->query_vars['crawlwp_llms_txt'] ?? 0) === 1;

		if (! $is_llms && ! self::is_llms_txt_request()) {
			return;
		}

		if (self::get('enabled', 'on') === 'off') {
			/*
			 * Without this the request resolves to the front page and
			 * redirect_canonical() 301s it; answer with a plain 404 instead.
			 */
			if ($wp instanceof \WP) {
				$wp->query_vars = ['error' => '404'];
			}

			add_filter('redirect_canonical', '__return_false');

			return;
		}

		nocache_headers();
		header('Content-Type: text/plain; charset=UTF-8');
		echo $this->content();
		exit;
	}

	/**
	 * Whether the current request path is /llms.txt (relative to the home URL).
	 */
	private static function is_llms_txt_request(): bool
	{
		$path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

		if ($path === '') {
			return false;
		}

		$home_path = rtrim((string) wp_parse_url(home_url(), PHP_URL_PATH), '/');

		return $path === $home_path . '/llms.txt';
	}

	/**
	 * Absolute path of a physical llms.txt that the web server would serve
	 * instead of the virtual one, or null.
	 */
	public static function get_physical_file_path(): ?string
	{
		$roots = [];

		if (! function_exists('get_home_path') && file_exists(ABSPATH . 'wp-admin/includes/file.php')) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if (function_exists('get_home_path')) {
			$roots[] = trailingslashit(wp_normalize_path((string) get_home_path()));
		}

		$roots[] = trailingslashit(wp_normalize_path(ABSPATH));

		foreach (array_unique($roots) as $root) {
			if (@is_file($root . 'llms.txt')) {
				return $root . 'llms.txt';
			}
		}

		return null;
	}

	public function settings_fields(WPOSA $wposa, $settingsInstance): void
	{
		if ($wposa->get_active_header_menu() !== Utils::get_plugin_prefix() . '_advanced_settings') {
			return;
		}

		$wposa->add_section([
			'header_menu_id' => 'advanced_settings',
			'id'             => self::SECTION,
			'title'          => __('llms.txt', 'mihdan-index-now'),
			'desc'           => sprintf(
				/* translators: %s: llms.txt URL */
				__('A plain-text file at %s that helps AI crawlers understand this site.', 'mihdan-index-now'),
				'<code>' . esc_html(home_url('/llms.txt')) . '</code>'
			),
			'reset_button'   => true,
		]);

		$physical_file = self::get_physical_file_path();

		if ($physical_file !== null) {
			$wposa->add_field(self::SECTION, [
				'id'   => 'physical_file_warning',
				'type' => 'html',
				'name' => '',
				'desc' => sprintf(
					'<div class="notice notice-warning inline" style="margin:0;padding:10px 12px;"><p><strong>%1$s</strong></p><p>%2$s</p></div>',
					esc_html__('A physical llms.txt file exists in your site root.', 'mihdan-index-now'),
					sprintf(
						/* translators: %s: absolute path to the llms.txt file. */
						esc_html__('Your web server serves %s directly, so the settings below have no effect. Delete or rename that file to use the settings on this screen.', 'mihdan-index-now'),
						'<code>' . esc_html($physical_file) . '</code>'
					)
				),
			]);
		}

		$wposa->add_field(self::SECTION, [
			'id'      => 'enabled',
			'type'    => 'switch',
			'name'    => __('Enable llms.txt', 'mihdan-index-now'),
			'default' => 'on',
		]);

		$wposa->add_field(self::SECTION, [
			'id'                => 'content',
			'type'              => 'textarea',
			'name'              => __('llms.txt content', 'mihdan-index-now'),
			'rows'              => 12,
			'default'           => '',
			'placeholder'       => $this->default_content(),
			'desc'              => esc_html__('Leave empty to generate a default file from the site title, tagline and sitemap URL.', 'mihdan-index-now'),
			'sanitize_callback' => [$this, 'sanitize_content'],
		]);
	}

	/**
	 * Save-time sanitizer for the custom content.
	 *
	 * Keeps Markdown intact (including <https://…> autolinks and %XX
	 * sequences) and stores an empty value when the submission is just the
	 * generated default, so the file keeps tracking site changes.
	 *
	 * @param mixed $value Submitted value.
	 */
	public function sanitize_content($value): string
	{
		$value = WPOSA::sanitize_plain_textarea($value);

		if (trim($value) === trim(WPOSA::sanitize_plain_textarea($this->default_content()))) {
			return '';
		}

		return $value;
	}

	public function content(): string
	{
		$saved = trim((string) self::get('content', ''));

		if ($saved !== '') {
			return $saved . "\n";
		}

		return $this->default_content();
	}

	public function default_content(): string
	{
		$lines = [
			'# ' . get_bloginfo('name'),
			'> ' . get_bloginfo('description'),
			'',
			__('This site publishes web pages that may be used as context by language models.', 'mihdan-index-now'),
			'',
			'## Sitemap',
			$this->sitemap_url(),
			'',
			'## Home',
			home_url('/'),
		];

		return implode("\n", $lines) . "\n";
	}

	/**
	 * The sitemap index URL, honouring a custom sitemap base or permalink setup.
	 */
	private function sitemap_url(): string
	{
		$url = get_sitemap_url('index');

		if (is_string($url) && $url !== '') return $url;

		return home_url('/wp-sitemap.xml');
	}

	public static function get(string $field, $default = '')
	{
		$options = get_option('crawlwp_' . self::SECTION, []);

		return is_array($options) ? ($options[$field] ?? $default) : $default;
	}
}
