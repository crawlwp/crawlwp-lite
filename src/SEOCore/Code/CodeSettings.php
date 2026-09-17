<?php

namespace Mihdan\IndexNow\SEOCore\Code;

use Mihdan\IndexNow\Utils;
use Mihdan\IndexNow\Views\WPOSA;

/**
 * Header / footer scripts and basic tracking IDs (GA4, GTM).
 */
class CodeSettings
{
	const SECTION = 'code';

	/**
	 * Fields whose value is printed verbatim in the frontend markup.
	 *
	 * @var string[]
	 */
	private const RAW_CODE_FIELDS = ['head', 'body', 'footer'];

	public function __construct()
	{
		add_action('crawlwp_setup_fields', [$this, 'settings_fields'], 18, 2);
		add_filter('wposa_submitted_data', [$this, 'gate_raw_code_fields'], 10, 2);
		add_action('wp_head', [$this, 'output_head'], 99);
		add_action('wp_body_open', [$this, 'output_body'], 1);
		add_action('wp_footer', [$this, 'output_footer'], 99);
	}

	public function settings_fields(WPOSA $wposa, $settingsInstance): void
	{
		if ($wposa->get_active_header_menu() !== Utils::get_plugin_prefix() . '_advanced_settings') {
			return;
		}

		$wposa->add_section([
			'header_menu_id' => 'advanced_settings',
			'id'             => self::SECTION,
			'title'          => __('Header & Footer Code', 'mihdan-index-now'),
			'desc'           => __('Insert tracking scripts (GA4, GTM) or arbitrary HTML in the site head, after &lt;body&gt;, or in the footer.', 'mihdan-index-now'),
		]);

		$wposa->add_field(self::SECTION, [
			'id'   => 'ga4_id',
			'type' => 'text',
			'name' => __('GA4 measurement ID', 'mihdan-index-now'),
			'desc' => esc_html__('e.g. G-XXXXXXXXXX. Leave empty to skip the Google tag.', 'mihdan-index-now'),
		]);

		$wposa->add_field(self::SECTION, [
			'id'   => 'gtm_id',
			'type' => 'text',
			'name' => __('Google Tag Manager ID', 'mihdan-index-now'),
			'desc' => esc_html__('e.g. GTM-XXXXXXX.', 'mihdan-index-now'),
		]);

		$wposa->add_field(self::SECTION, [
			'id'                => 'head',
			'type'              => 'textarea',
			'name'              => __('Scripts in &lt;head&gt;', 'mihdan-index-now'),
			'rows'              => 6,
			'desc'              => $this->raw_code_notice(__('Printed verbatim inside &lt;head&gt; on every page of your site.', 'mihdan-index-now')),
			'sanitize_callback' => [$this, 'sanitize_head'],
		]);

		$wposa->add_field(self::SECTION, [
			'id'                => 'body',
			'type'              => 'textarea',
			'name'              => __('Scripts after &lt;body&gt;', 'mihdan-index-now'),
			'rows'              => 4,
			'desc'              => $this->raw_code_notice(__('Printed verbatim immediately after the opening &lt;body&gt; tag on every page of your site.', 'mihdan-index-now')),
			'sanitize_callback' => [$this, 'sanitize_body'],
		]);

		$wposa->add_field(self::SECTION, [
			'id'                => 'footer',
			'type'              => 'textarea',
			'name'              => __('Scripts in footer', 'mihdan-index-now'),
			'rows'              => 6,
			'desc'              => $this->raw_code_notice(__('Printed verbatim in the footer on every page of your site.', 'mihdan-index-now')),
			'sanitize_callback' => [$this, 'sanitize_footer'],
		]);
	}

	/**
	 * Field copy that spells out the risk of the raw code fields, and whether
	 * the current user is allowed to change them.
	 */
	private function raw_code_notice(string $placement): string
	{
		$notice = $placement . ' ' . esc_html__('It is never filtered or escaped, so anything you paste here — including <script> tags — runs for every visitor. Only paste code you fully trust.', 'mihdan-index-now');

		if ( ! self::current_user_can_edit_code()) {
			$notice .= ' <strong>' . esc_html__('Your account cannot save unfiltered HTML, so changes to this field are ignored.', 'mihdan-index-now') . '</strong>';
		}

		return $notice;
	}

	/**
	 * Whether the current user may store unescaped markup.
	 *
	 * On a single site this is every administrator; on multisite only network
	 * administrators hold `unfiltered_html`.
	 */
	public static function current_user_can_edit_code(): bool
	{
		return current_user_can('unfiltered_html');
	}

	/**
	 * Discard submitted values for the raw code fields when the user lacks
	 * `unfiltered_html`.
	 *
	 * WPOSA merges the sanitised submission over the stored option, so simply
	 * removing the keys keeps whatever is already saved.
	 *
	 * @param array  $submitted_data Posted values for this section.
	 * @param string $name           Option name being saved.
	 *
	 * @return array
	 */
	public function gate_raw_code_fields($submitted_data, $name)
	{
		if ($name !== 'crawlwp_' . self::SECTION || ! is_array($submitted_data)) {
			return $submitted_data;
		}

		if (self::current_user_can_edit_code()) {
			return $submitted_data;
		}

		foreach (self::RAW_CODE_FIELDS as $field) {
			unset($submitted_data[$field]);
		}

		return $submitted_data;
	}

	/**
	 * @param mixed $value
	 */
	public function sanitize_head($value): string
	{
		return $this->sanitize_code_field('head', $value);
	}

	/**
	 * @param mixed $value
	 */
	public function sanitize_body($value): string
	{
		return $this->sanitize_code_field('body', $value);
	}

	/**
	 * @param mixed $value
	 */
	public function sanitize_footer($value): string
	{
		return $this->sanitize_code_field('footer', $value);
	}

	/**
	 * Sanitize raw code field input.
	 *
	 * Allows arbitrary HTML and script tags when the current user has unfiltered_html capability.
	 * Retains the stored value if the user lacks unfiltered_html capability.
	 *
	 * @param string $field
	 * @param mixed  $value
	 */
	private function sanitize_code_field(string $field, $value): string
	{
		if ( ! is_scalar($value)) {
			return '';
		}

		if ( ! self::current_user_can_edit_code()) {
			return (string) self::get($field, '');
		}

		return (string) $value;
	}

	public function output_head(): void
	{
		$gtm = trim((string) self::get('gtm_id', ''));
		$ga4 = trim((string) self::get('ga4_id', ''));

		if ($gtm !== '' && preg_match('/^GTM-[A-Z0-9]+$/i', $gtm)) {
			echo "<!-- CrawlWP GTM -->\n";
			echo "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','" . esc_js($gtm) . "');</script>\n";
		}

		if ($ga4 !== '' && preg_match('/^G-[A-Z0-9]+$/i', $ga4)) {
			echo "<!-- CrawlWP GA4 -->\n";
			echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . esc_attr($ga4) . '"></script>' . "\n";
			echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','" . esc_js($ga4) . "');</script>\n";
		}

		echo self::get('head', '');
	}

	public function output_body(): void
	{
		$gtm = trim((string) self::get('gtm_id', ''));

		if ($gtm !== '' && preg_match('/^GTM-[A-Z0-9]+$/i', $gtm)) {
			echo '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . esc_attr($gtm) . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>';
		}

		echo self::get('body', '');
	}

	public function output_footer(): void
	{
		echo self::get('footer', '');
	}

	public static function get(string $field, $default = '')
	{
		$options = get_option('crawlwp_' . self::SECTION, []);

		return is_array($options) ? ($options[$field] ?? $default) : $default;
	}
}
