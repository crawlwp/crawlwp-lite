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
			'reset_button'   => true,
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

		if ($alt !== '' || ! $attachment instanceof \WP_Post) {
			return $attr;
		}

		/* Images explicitly marked as decorative keep their empty alt. */
		if (
			(isset($attr['role']) && in_array(strtolower((string) $attr['role']), ['presentation', 'none'], true))
			|| (isset($attr['aria-hidden']) && strtolower((string) $attr['aria-hidden']) === 'true')
		) {
			return $attr;
		}

		/*
		 * Only fill images whose alt text was never set. Once the alt meta row
		 * exists (even empty, i.e. cleared on purpose in the Media Library to
		 * mark the image as decorative) the stored value is respected.
		 */
		if (metadata_exists('post', $attachment->ID, '_wp_attachment_image_alt')) {
			return $attr;
		}

		$suggested = self::suggest($attachment);

		if ($suggested !== '') {
			$attr['alt'] = $suggested;
		}

		return $attr;
	}

	public function on_upload(int $attachment_id): void
	{
		if (self::get('auto_alt', 'on') === 'off' || ! wp_attachment_is_image($attachment_id)) {
			return;
		}

		$existing = (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true);

		if ($existing !== '') {
			return;
		}

		$post = get_post($attachment_id);

		if (! $post) {
			return;
		}

		$suggested = self::suggest($post);

		/* No usable text (e.g. "IMG_4021"): leave the alt unset rather than storing "". */
		if ($suggested !== '') {
			update_post_meta($attachment_id, '_wp_attachment_image_alt', $suggested);
		}
	}

	public static function suggest(?\WP_Post $attachment): string
	{
		if (! $attachment) {
			return '';
		}

		$title = self::clean_name((string) $attachment->post_title);

		if ($title !== '') {
			return $title;
		}

		$file = get_attached_file($attachment->ID);

		if (! is_string($file) || $file === '') {
			return '';
		}

		return self::clean_name((string) pathinfo($file, PATHINFO_FILENAME));
	}

	/**
	 * Turn a title/file name into human-readable alt text.
	 *
	 * "red-rose_garden" → "Red rose garden"; camera names such as "IMG_4021",
	 * "DSC_0042" or hash-like names yield an empty string.
	 */
	public static function clean_name(string $name): string
	{
		$name = trim(wp_strip_all_tags($name));

		if ($name === '' || preg_match('/^[a-f0-9-]{8,}$/i', $name)) {
			return '';
		}

		/* Looks like a file name (no spaces): treat dashes/underscores as spaces. */
		if (! preg_match('/\s/u', $name)) {
			/* Drop WordPress size / "-scaled" / "-rotated" / "-e123456" suffixes. */
			$name = (string) preg_replace('/(?:-\d+x\d+|-scaled|-rotated|-e\d{10,})+$/i', '', $name);
			$name = (string) preg_replace('/[-_]+/', ' ', $name);
		}

		/* Strip camera / phone prefixes followed by a counter or timestamp. */
		$name = (string) preg_replace('/^(?:IMG|DSC[NF]?|DCIM|PXL|MVIMG|CIMG|GOPR|GX|DJI|SAM|PANO|VID)[\s_-]*\d[\d\s_-]*/i', '', $name);

		$name = trim((string) preg_replace('/\s+/u', ' ', $name));

		/* Nothing descriptive left (only digits / punctuation). */
		if (! preg_match('/\p{L}{2,}/u', $name)) {
			return '';
		}

		return function_exists('mb_strtoupper')
			? mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1)
			: ucfirst($name);
	}

	public static function get(string $field, $default = '')
	{
		$options = get_option('crawlwp_' . self::SECTION, []);

		return is_array($options) ? ($options[$field] ?? $default) : $default;
	}
}
