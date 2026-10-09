<?php

namespace Mihdan\IndexNow\SEOCore\RobotsSettings;

use Mihdan\IndexNow\Utils;
use Mihdan\IndexNow\Views\WPOSA;

/**
 * Registers the "Robots.txt" settings section under the "Advanced" tab.
 *
 * WordPress generates a virtual /robots.txt dynamically via the `robots_txt`
 * filter when no physical robots.txt file exists. This class lets site
 * administrators edit the full robots.txt content.
 *
 * The textarea is pre-populated with the full current robots.txt output
 * (WordPress default + all other plugins' additions via the robots_txt filter).
 * When saved, the textarea content replaces the entire /robots.txt output.
 */
class RobotsSettings
{
	/** Option/section id (without the crawlwp_ prefix). */
	const SECTION = 'robots';

	public function __construct()
	{
		add_action('crawlwp_setup_fields', [$this, 'settings_fields'], 35, 2);

		/*
		 * Replace the entire robots.txt output with the admin-saved content.
		 * Priority 99 ensures we run after all other plugins have added their
		 * directives (so the initial pre-population captured them all) and we
		 * can reliably override the final output once the admin has saved.
		 */
		add_filter('robots_txt', [$this, 'replace_robots_txt'], PHP_INT_MAX - 1, 2);
	}

	// -------------------------------------------------------------------------
	// Settings registration
	// -------------------------------------------------------------------------

	public function settings_fields(WPOSA $wposa, $settingsInstance): void
	{
		if ($wposa->get_active_header_menu() !== Utils::get_plugin_prefix() . '_advanced_settings') {
			return;
		}

		$wposa->add_section([
			'header_menu_id' => 'advanced_settings',
			'id'             => self::SECTION,
			'title'          => __('Robots.txt', 'mihdan-index-now'),
			'reset_button'   => true,
		]);

		$this->add_physical_file_warning($wposa);
		$this->add_robots_fields($wposa);
	}

	/**
	 * Warn when a physical robots.txt file shadows the virtual one.
	 *
	 * WordPress only serves the filtered /robots.txt when no real file exists in
	 * the site root, so every setting below would silently have no effect.
	 */
	private function add_physical_file_warning(WPOSA $wposa): void
	{
		$physical_file = self::get_physical_file_path();

		if ($physical_file === null) {
			return;
		}

		$notice = sprintf(
			'<div class="notice notice-warning inline" style="margin:0;padding:10px 12px;"><p><strong>%1$s</strong></p><p>%2$s</p></div>',
			esc_html__('A physical robots.txt file exists in your site root.', 'mihdan-index-now'),
			sprintf(
				/* translators: %s: absolute path to the robots.txt file. */
				esc_html__('Your web server serves %s directly, so none of the settings below affect what search engines see. Delete or rename that file to use the settings on this screen.', 'mihdan-index-now'),
				'<code>' . esc_html($physical_file) . '</code>'
			)
		);

		$wposa->add_field(self::SECTION, [
			'id'   => 'physical_file_warning',
			'type' => 'html',
			'name' => '',
			'desc' => $notice,
		]);
	}

	/**
	 * Whether a physical robots.txt file exists in the site root.
	 */
	public static function has_physical_file(): bool
	{
		return self::get_physical_file_path() !== null;
	}

	/**
	 * Absolute path of a physical robots.txt that shadows the virtual one, or
	 * null when there is none.
	 *
	 * The web server serves /robots.txt from the site root, which is the home
	 * path — that differs from ABSPATH when WordPress lives in a subdirectory —
	 * so both locations are checked.
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
			if (@is_file($root . 'robots.txt')) {
				return $root . 'robots.txt';
			}
		}

		return null;
	}

	// -------------------------------------------------------------------------
	// Fields
	// -------------------------------------------------------------------------

	private function add_robots_fields(WPOSA $wposa): void
	{
		$options = get_option('crawlwp_' . self::SECTION, []);
		$editing_enabled = self::is_editing_enabled();

		/* Enable editing toggle — disabled by default. */
		$wposa->add_field(self::SECTION, [
			'id'   => 'enable_editing',
			'type' => 'checkbox',
			'name' => __('Enable robots.txt editing', 'mihdan-index-now'),
			'desc' => __('Check this box to edit the robots.txt content directly. When unchecked, the textarea below is read-only and the default WordPress output is used.', 'mihdan-index-now'),
		]);

		/*
		 * Pre-populate the textarea with the full current robots.txt output
		 * (WordPress default + every other plugin's robots_txt additions),
		 * but only when no content has been saved yet. Once the admin saves,
		 * the stored value is used as-is.
		 */
		$saved_content   = $options['robots_content'] ?? '';
		$default_content = $saved_content !== '' ? $saved_content : $this->get_full_robots_txt();

		$content_desc = __('The full content of your virtual robots.txt file. Pre-filled with the current output (WordPress defaults plus any additions from other plugins). Edit as needed — whatever you save here becomes the complete /robots.txt file.', 'mihdan-index-now');

		if (self::has_physical_file()) {
			$content_desc .= ' <strong>' . esc_html__('This content is currently ignored because a physical robots.txt file exists in the site root.', 'mihdan-index-now') . '</strong>';
		}

		$wposa->add_field(self::SECTION, [
			'id'         => 'robots_content',
			'type'       => 'textarea',
			'name'       => __('robots.txt content', 'mihdan-index-now'),
			'default'    => $default_content,
			'rows'       => 15,
			'attributes' => $editing_enabled ? [] : ['readonly' => 'readonly'],
			'desc'       => $content_desc,
			/* robots.txt is plain text: never strip "<" or %XX sequences. */
			'sanitize_callback' => [WPOSA::class, 'sanitize_plain_textarea'],
		]);

		/* Small inline script to live-toggle the textarea readonly state. */
		add_action('admin_footer', function () {
			?>
			<script>
			(function () {
				var cb = document.getElementById('wposa-crawlwp_robots[enable_editing]');
				var ta = document.getElementById('crawlwp_robots[robots_content]');
				if (!cb || !ta) return;
				function sync() { ta.readOnly = !cb.checked; }
				cb.addEventListener('change', sync);
				sync();
			}());
			</script>
			<?php
		});
	}

	// -------------------------------------------------------------------------
	// Frontend output
	// -------------------------------------------------------------------------

	/**
	 * Replace the robots.txt output with the admin-saved content.
	 *
	 * When no content has been saved yet, the original output is returned
	 * unchanged so other plugins continue to work normally.
	 *
	 * @param string $output The current robots.txt content built by WordPress.
	 * @param bool   $public Whether the site is set to be public.
	 * @return string
	 */
	public function replace_robots_txt(string $output, bool $public): string
	{
		/*
		 * Only replace when the admin has explicitly enabled editing. An
		 * unticked checkbox is stored as 'off', so test for 'on' explicitly.
		 */
		if (! self::is_editing_enabled()) {
			return $output;
		}

		$options = get_option('crawlwp_' . self::SECTION, []);

		$content = isset($options['robots_content']) ? trim($options['robots_content']) : '';

		/**
		 * Filter the saved robots.txt content before it is output.
		 *
		 * @param string $content The full saved robots.txt content.
		 * @param bool   $public  Whether the site is public.
		 */
		$content = apply_filters('crawlwp_robots_txt', $content, $public);

		if ($content === '') {
			return $output;
		}

		return $content . "\n";
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build the full robots.txt output as WordPress would generate it —
	 * WordPress's own base content plus every other plugin's robots_txt
	 * additions — WITHOUT including our own replacement filter.
	 *
	 * We temporarily remove our filter, apply the filter chain, then restore
	 * it, so the preview never enters an infinite-loop or shows stale saved data.
	 *
	 * @return string
	 */
	private function get_full_robots_txt(): string
	{
		remove_filter('robots_txt', [$this, 'replace_robots_txt'], PHP_INT_MAX - 1);

		/*
		 * Replicate WordPress's do_robots() base output exactly:
		 * see wp-includes/functions.php::do_robots(). The prefill always
		 * mirrors a public site — a temporary "Discourage search engines"
		 * setting must not end up persisted as "Disallow: /".
		 */
		$output  = "User-agent: *\n";
		$output .= 'Disallow: ' . wp_parse_url(admin_url(), PHP_URL_PATH) . "\n";
		$output .= 'Allow: ' . wp_parse_url(admin_url('admin-ajax.php'), PHP_URL_PATH) . "\n";

		/* Let core (Sitemap line) and every other plugin add its lines. */
		$output = apply_filters('robots_txt', $output, true);

		add_filter('robots_txt', [$this, 'replace_robots_txt'], PHP_INT_MAX - 1, 2);

		return trim($output);
	}

	// -------------------------------------------------------------------------
	// Option reader
	// -------------------------------------------------------------------------

	/**
	 * Read a single robots option value.
	 *
	 * @param string $key     Field id.
	 * @param mixed  $default Default value when the key is absent.
	 * @return mixed
	 */
	public static function is_editing_enabled(): bool
	{
		return self::get('enable_editing', 'off') === 'on';
	}

	public static function get(string $key, $default = '')
	{
		$options = get_option('crawlwp_' . self::SECTION, []);
		return is_array($options) ? ($options[$key] ?? $default) : $default;
	}
}
