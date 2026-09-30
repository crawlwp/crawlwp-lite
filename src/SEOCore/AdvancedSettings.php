<?php

namespace Mihdan\IndexNow\SEOCore;

use Mihdan\IndexNow\Utils;
use Mihdan\IndexNow\Views\WPOSA;

class AdvancedSettings
{
	public const SECTION = 'advanced';

	public function __construct()
	{
		add_action('crawlwp_pre_setup_fields', [$this, 'advanced_settings_menu'], -25);
		add_action('crawlwp_setup_fields', [$this, 'settings_fields'], 80, 2);
	}

	public function advanced_settings_menu(WPOSA $wposa): void
	{
		$wposa->add_header_menu([
			'id'    => 'advanced_settings',
			'title' => __('Settings', 'mihdan-index-now'),
		]);
	}

	/**
	 * @param WPOSA $wposa
	 * @param mixed $settingsInstance
	 */
	public function settings_fields(WPOSA $wposa, $settingsInstance): void
	{
		if ($wposa->get_active_header_menu() !== Utils::get_plugin_prefix() . '_advanced_settings') {
			return;
		}

		$wposa->add_section([
			'header_menu_id' => 'advanced_settings',
			'id'             => self::SECTION,
			'title'          => __('Advanced', 'mihdan-index-now'),
		]);

		$wposa->add_field(self::SECTION, [
			'id'      => 'remove_plugin_data',
			'type'    => 'switch',
			'name'    => __('Remove Data on Uninstall', 'mihdan-index-now'),
			'desc'    => __('Check this box if you would like CrawlWP to completely remove all of its data and database tables when the plugin is deleted.', 'mihdan-index-now'),
			'default' => 'off',
			'std'     => 'off',
		]);

		$wposa->add_field(self::SECTION, [
			'id'      => 'disable_seo_features',
			'type'    => 'switch',
			'name'    => __('Disable SEO Features', 'mihdan-index-now'),
			'desc'    => __('Check this box to turn off all on-page SEO features (meta tags, schema, sitemaps, redirects, etc.) while keeping instant indexing active. Useful when another SEO plugin handles on-page SEO.', 'mihdan-index-now'),
			'default' => 'off',
			'std'     => 'off',
		]);
	}

	public static function is_remove_data_on_uninstall(): bool
	{
		return self::is_switch_on('remove_plugin_data');
	}

	public static function is_seo_features_disabled(): bool
	{
		return self::is_switch_on('disable_seo_features');
	}

	private static function is_switch_on($field): bool
	{
		$options = get_option('crawlwp_' . self::SECTION, []);

		return is_array($options)
			&& ! empty($options[$field])
			&& in_array($options[$field], ['on', 'yes', 'true', true], true);
	}
}
