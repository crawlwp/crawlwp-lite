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
	}

	public static function is_remove_data_on_uninstall(): bool
	{
		$options = get_option('crawlwp_' . self::SECTION, []);

		return is_array($options)
			&& ! empty($options['remove_plugin_data'])
			&& in_array($options['remove_plugin_data'], ['on', 'yes', 'true', true], true);
	}
}
