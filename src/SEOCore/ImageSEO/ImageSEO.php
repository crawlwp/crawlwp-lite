<?php

namespace Mihdan\IndexNow\SEOCore\ImageSEO;

use Mihdan\IndexNow\Utils;
use Mihdan\IndexNow\Views\WPOSA;

/**
 * Auto-fill missing image alt attributes from the attachment title or filename.
 */
class ImageSEO
{
	const SECTION = 'image_seo';

	public function __construct()
	{
		add_action('crawlwp_setup_fields', [$this, 'settings_fields'], 41, 2);
		add_filter('wp_get_attachment_image_attributes', [$this, 'fill_alt'], 10, 2);
		add_action('add_attachment', [$this, 'on_upload']);
	}

	public function settings_fields(WPOSA $wposa, $settingsInstance): void
	{
		if ($wposa->get_active_header_menu() !== Utils::get_plugin_prefix() . '_advanced_settings') {
			return;
		}

		$wposa->add_section([
			'header_menu_id' => 'advanced_settings',
			'id'             => self::SECTION,
			'title'          => __('Image SEO', 'mihdan-index-now'),
			'desc'           => __('Automatically fill missing alt text from the image title or file name.', 'mihdan-index-now'),
		]);

		$wposa->add_field(self::SECTION, [
			'id'      => 'auto_alt',
			'type'    => 'switch',
			'name'    => __('Auto-fill missing alt text', 'mihdan-index-now'),
			'default' => 'on',
		]);
	}

	/**
	 * @param array<string,string> $attr
	 * @param \WP_Post             $attachment
	 * @return array<string,string>
	 */
	public function fill_alt($attr, $attachment)
	{
		if (self::get('auto_alt', 'on') === 'off') {
			return $attr;
		}

		if (! is_array($attr)) {
			return $attr;
		}

		$alt = isset($attr['alt']) ? trim((string) $attr['alt']) : '';

		if ($alt !== '') {
			return $attr;
		}

		$attr['alt'] = self::suggest($attachment instanceof \WP_Post ? $attachment : null);

		return $attr;
	}

	public function on_upload(int $attachment_id): void
	{
		if (self::get('auto_alt', 'on') === 'off') {
			return;
		}

		$existing = (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true);

		if ($existing !== '') {
			return;
		}

		$post = get_post($attachment_id);

		if ($post) {
			update_post_meta($attachment_id, '_wp_attachment_image_alt', self::suggest($post));
		}
	}

	public static function suggest(?\WP_Post $attachment): string
	{
		if (! $attachment) {
			return '';
		}

		$title = trim((string) $attachment->post_title);

		if ($title !== '' && ! preg_match('/^[a-f0-9-]{8,}$/i', $title)) {
			return $title;
		}

		$file = get_attached_file($attachment->ID);

		if (! is_string($file) || $file === '') {
			return $title;
		}

		$base = pathinfo($file, PATHINFO_FILENAME);
		$base = preg_replace('/[-_]+/', ' ', (string) $base);

		return ucwords(trim((string) $base));
	}

	public static function get(string $field, $default = '')
	{
		$options = get_option('crawlwp_' . self::SECTION, []);

		return is_array($options) ? ($options[$field] ?? $default) : $default;
	}
}
