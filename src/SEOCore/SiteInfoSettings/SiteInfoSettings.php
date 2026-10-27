<?php

namespace Mihdan\IndexNow\SEOCore\SiteInfoSettings;

use Mihdan\IndexNow\SEOCore\SettingsFieldsTrait;
use Mihdan\IndexNow\Utils;
use Mihdan\IndexNow\Views\WPOSA;

/**
 * Registers the "Site Information" settings section under the "Advanced" tab.
 *
 * The data stored here is used to populate the site-wide WebSite and
 * Organization / Person JSON-LD graph nodes output in the <head>.
 *
 * Option row: crawlwp_site_info
 * All fields are stored as crawlwp_site_info[field_id].
 */
class SiteInfoSettings
{
	use SettingsFieldsTrait;

	/** Option/section id (without the crawlwp_ prefix). */
	const SECTION = 'site_info';

	public function __construct()
	{
		add_action('crawlwp_setup_fields', [$this, 'settings_fields'], 5, 2);
	}

	public function settings_fields(WPOSA $wposa, $settingsInstance): void
	{
		if ($wposa->get_active_header_menu() !== Utils::get_plugin_prefix() . '_advanced_settings') {
			return;
		}

		$wposa->add_section([
			'header_menu_id' => 'advanced_settings',
			'id'             => self::SECTION,
			'title'          => __('Site Information', 'mihdan-index-now'),
		]);

		$this->add_site_type_fields($wposa);
		$this->add_identity_fields($wposa);
		$this->add_social_profiles_fields($wposa);
	}

	// -------------------------------------------------------------------------
	// Site type
	// -------------------------------------------------------------------------

	private function add_site_type_fields(WPOSA $wposa): void
	{
		$this->add_heading(
			$wposa,
			self::SECTION,
			'heading_site_type',
			__('Site Representation', 'mihdan-index-now'),
			__('Tell search engines how to represent this website in structured data. This determines whether a Person or Organization node is emitted in the JSON-LD graph.', 'mihdan-index-now')
		);

		$wposa->add_field(self::SECTION, [
			'id'      => 'site_type',
			'type'    => 'select',
			'name'    => __('This website represents a…', 'mihdan-index-now'),
			'default' => 'organization',
			'options' => [
				'organization' => __('Organization / Company', 'mihdan-index-now'),
				'person'       => __('Individual / Person', 'mihdan-index-now'),
			],
			'desc'    => esc_html__('Choose "Organization / Company" for businesses and brands, or "Individual / Person" for personal sites and blogs.', 'mihdan-index-now'),
		]);
	}

	// -------------------------------------------------------------------------
	// Identity
	// -------------------------------------------------------------------------

	private function add_identity_fields(WPOSA $wposa): void
	{
		$this->add_heading(
			$wposa,
			self::SECTION,
			'heading_identity',
			__('Organization / Person Identity', 'mihdan-index-now'),
			__('These details are used to build the Organization or Person node in the JSON-LD graph. Leave fields empty to use the WordPress site defaults.', 'mihdan-index-now')
		);

		$wposa->add_field(self::SECTION, [
			'id'          => 'site_name',
			'type'        => 'text',
			'name'        => __('Name', 'mihdan-index-now'),
			'placeholder' => get_bloginfo('name'),
			'desc'        => esc_html__('The name of the organization or person. Defaults to the WordPress site title.', 'mihdan-index-now'),
		]);

		$wposa->add_field(self::SECTION, [
			'id'          => 'site_description',
			'type'        => 'text',
			'name'        => __('Description / Tagline', 'mihdan-index-now'),
			'placeholder' => get_bloginfo('description'),
			'desc'        => esc_html__('Short description used in the WebSite schema node. Defaults to the WordPress tagline.', 'mihdan-index-now'),
		]);

		$wposa->add_field(self::SECTION, [
			'id'   => 'logo',
			'type' => 'image',
			'name' => __('Logo', 'mihdan-index-now'),
			'desc' => esc_html__('Logo image for the Organization or Person node. Recommended: square image, at least 112×112 px.', 'mihdan-index-now'),
		]);

		$wposa->add_field(self::SECTION, [
			'id'      => 'search_action',
			'type'    => 'switch',
			'name'    => __('Enable Sitelinks Search Box?', 'mihdan-index-now'),
			'default' => 'on',
			'desc'    => $this->description(__('Outputs a SearchAction potentialAction on the WebSite node so Google may display a sitelinks search box in search results.', 'mihdan-index-now')),
		]);
	}

	// -------------------------------------------------------------------------
	// Social profiles (sameAs)
	// -------------------------------------------------------------------------

	private function add_social_profiles_fields(WPOSA $wposa): void
	{
		$this->add_heading(
			$wposa,
			self::SECTION,
			'heading_social_profiles',
			__('Social Profiles', 'mihdan-index-now'),
			__('Enter the social profile URLs for this site. They are added as the sameAs property on the Organization or Person node, which helps search engines connect your site to your social presence.', 'mihdan-index-now')
		);

		$wposa->add_field(self::SECTION, [
			'id'          => 'profile_facebook',
			'type'        => 'text',
			'name'        => __('Facebook Page URL', 'mihdan-index-now'),
			'placeholder' => 'https://www.facebook.com/YourPage',
		]);

		$wposa->add_field(self::SECTION, [
			'id'          => 'profile_x',
			'type'        => 'text',
			'name'        => __('X (Twitter) URL', 'mihdan-index-now'),
			'placeholder' => 'https://x.com/YourHandle',
		]);

		$wposa->add_field(self::SECTION, [
			'id'   => 'profile_additional',
			'type' => 'textarea',
			'name' => __('Additional Profile URLs', 'mihdan-index-now'),
			'rows' => 5,
			'desc' => esc_html__('Enter one URL per line. Any additional social or professional profile URLs (e.g. Instagram, LinkedIn, YouTube) to include in the sameAs property.', 'mihdan-index-now'),
		]);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Read a site info setting value.
	 *
	 * @param string $field   Field id (e.g. 'site_type').
	 * @param mixed  $default Default value when option is absent.
	 *
	 * @return mixed
	 */
	public static function get(string $field, $default = '')
	{
		$options = get_option('crawlwp_' . self::SECTION, []);

		if (! is_array($options)) {
			return $default;
		}

		return $options[$field] ?? $default;
	}

	/**
	 * Return all configured social / professional profile URLs as a flat array,
	 * suitable for the sameAs JSON-LD property.
	 *
	 * @return string[]
	 */
	public static function get_same_as(): array
	{
		$urls = [];

		/* Primary: Facebook and X. */
		foreach (['profile_facebook', 'profile_x'] as $field) {
			$url = esc_url_raw(trim((string) self::get($field, '')));

			if ($url !== '') {
				$urls[] = $url;
			}
		}

		/* Additional: one URL per line from the textarea. */
		$additional = (string) self::get('profile_additional', '');

		foreach (explode("\n", $additional) as $line) {
			$url = esc_url_raw(trim($line));

			if ($url !== '') {
				$urls[] = $url;
			}
		}

		return $urls;
	}
}
