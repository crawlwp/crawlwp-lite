<?php

namespace Mihdan\IndexNow\SEOCore\Breadcrumbs;

use Mihdan\IndexNow\Utils;
use Mihdan\IndexNow\Views\WPOSA;

/**
 * Registers the "Breadcrumbs" settings section under the "Advanced" tab.
 *
 * Option row: crawlwp_breadcrumbs_settings
 */
class BreadcrumbSettings
{
	/** Option/section id (without the crawlwp_ prefix). */
	const SECTION = 'breadcrumbs_settings';

	public function __construct()
	{
		add_action('crawlwp_setup_fields', [$this, 'settings_fields'], 19, 2);
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
			'title'          => __('Breadcrumbs', 'mihdan-index-now'),
		]);

		$this->add_general_fields($wposa);
	}

	// -------------------------------------------------------------------------
	// Fields
	// -------------------------------------------------------------------------

	private function add_general_fields(WPOSA $wposa): void
	{
		$wposa->add_field(self::SECTION, [
			'id'      => 'enabled',
			'type'    => 'switch',
			'name'    => __('Enable breadcrumbs', 'mihdan-index-now'),
			'desc'    => __('Register the [crawlwp_breadcrumbs] shortcode and enqueue the default stylesheet. Disable if your theme provides its own breadcrumbs.', 'mihdan-index-now'),
			'default' => 'on',
		]);

		$wposa->add_field(self::SECTION, [
			'id'      => 'label_home',
			'type'    => 'text',
			'name'    => __('Home label', 'mihdan-index-now'),
			'desc'    => __('Text used for the first breadcrumb item linking to the homepage.', 'mihdan-index-now'),
			'default' => __('Home', 'mihdan-index-now'),
		]);

		$wposa->add_field(self::SECTION, [
			'id'      => 'separator',
			'type'    => 'text',
			'name'    => __('Separator', 'mihdan-index-now'),
			'desc'    => __('Character or HTML placed between breadcrumb items. Default: &rsaquo; (›)', 'mihdan-index-now'),
			'default' => '›',
		]);

		$wposa->add_field(self::SECTION, [
			'id'      => 'display_current',
			'type'    => 'switch',
			'name'    => __('Show current page', 'mihdan-index-now'),
			'desc'    => __('Display the current page as the last (non-linked) breadcrumb item.', 'mihdan-index-now'),
			'default' => 'on',
		]);

		$wposa->add_field(self::SECTION, [
			'id'      => 'taxonomy',
			'type'    => 'text',
			'name'    => __('Primary taxonomy', 'mihdan-index-now'),
			'desc'    => __('Taxonomy slug used to build the trail for non-hierarchical post types (e.g. "category"). Leave blank for the default.', 'mihdan-index-now'),
			'default' => 'category',
		]);

		$wposa->add_field(self::SECTION, [
			'id'      => 'schema_enabled',
			'type'    => 'switch',
			'name'    => __('Include BreadcrumbList schema', 'mihdan-index-now'),
			'desc'    => __('Append a BreadcrumbList node to the site graph JSON-LD output on every page.', 'mihdan-index-now'),
			'default' => 'on',
		]);
	}

	// -------------------------------------------------------------------------
	// Option reader
	// -------------------------------------------------------------------------

	/**
	 * Read a breadcrumb setting value.
	 *
	 * @param string $field   Field id (e.g. 'enabled', 'separator').
	 * @param mixed  $default Default value when option is absent or empty.
	 * @return mixed
	 */
	public static function get(string $field, $default = '')
	{
		$options = get_option('crawlwp_' . self::SECTION, []);

		if (! is_array($options)) {
			return $default;
		}

		return (isset($options[$field]) && $options[$field] !== '') ? $options[$field] : $default;
	}
}
