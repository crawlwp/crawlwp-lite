<?php

namespace Mihdan\IndexNow\SEOCore\SocialSettings;

use Mihdan\IndexNow\SEOCore\SettingsFieldsTrait;
use Mihdan\IndexNow\Utils;
use Mihdan\IndexNow\Views\WPOSA;

/**
 * Registers the "Social Networks" settings section under the "Advanced" tab.
 *
 * Option row: crawlwp_social
 * All fields are stored as crawlwp_social[field_id].
 */
class SocialSettings
{
	use SettingsFieldsTrait;

	/** Option/section id (without the crawlwp_ prefix). */
	const SECTION = 'social';

	public function __construct()
	{
		add_action('crawlwp_setup_fields', [$this, 'settings_fields'], 10, 2);
	}

	public function settings_fields(WPOSA $wposa, $settingsInstance): void
	{
		if ($wposa->get_active_header_menu() !== Utils::get_plugin_prefix() . '_advanced_settings') {
			return;
		}

		$wposa->add_section([
			'header_menu_id' => 'advanced_settings',
			'id'             => self::SECTION,
			'title'          => __('Social Networks', 'mihdan-index-now'),
		]);

		$this->add_general_fields($wposa);
		$this->add_facebook_fields($wposa);
		$this->add_twitter_fields($wposa);
		$this->add_postdates_fields($wposa);
	}

	// -------------------------------------------------------------------------
	// General
	// -------------------------------------------------------------------------

	private function add_general_fields(WPOSA $wposa): void
	{
		$this->add_heading(
			$wposa,
			self::SECTION,
			'heading_general',
			__('Social Meta Tags Settings', 'mihdan-index-now'),
			__('Output various meta tags for social site integration, among other third-party services.', 'mihdan-index-now')
		);

		$wposa->add_field(self::SECTION, [
			'id'      => 'og_tags',
			'type'    => 'switch',
			'name'    => __('Output Open Graph meta tags?', 'mihdan-index-now'),
			'default' => 'on',
			'desc'    => $this->description(__('Facebook, Twitter, Pinterest and many other social sites make use of these meta tags.', 'mihdan-index-now')),
		]);

		$wposa->add_field(self::SECTION, [
			'id'      => 'twitter_tags',
			'type'    => 'switch',
			'name'    => __('Output X/Twitter Card meta tags?', 'mihdan-index-now'),
			'default' => 'on',
			'desc'    => $this->description(__('X (formerly Twitter), Discord, LinkedIn, and some other networks make use of these meta tags.', 'mihdan-index-now')),
		]);

		$this->add_heading(
			$wposa,
			self::SECTION,
			'heading_social_title',
			__('Social Title Settings', 'mihdan-index-now'),
			__('Most social sites and third-party services automatically include the website URL inside their embeds. When the site title is described well in the site URL, including it in the social title will be redundant.', 'mihdan-index-now')
		);

		$wposa->add_field(self::SECTION, [
			'id'      => 'social_title_rem_additions',
			'type'    => 'switch',
			'name'    => __('Remove site title from generated social titles?', 'mihdan-index-now'),
			'default' => 'off',
			'desc'    => $this->description(__('When enabled, the site title will be stripped from the social title. When you provide a custom social title in Title & Meta, this setting has no effect.', 'mihdan-index-now')),
		]);

		$this->add_heading(
			$wposa,
			self::SECTION,
			'heading_social_image',
			__('Social Image Settings', 'mihdan-index-now'),
			__('A social image can be displayed when a link to your website is shared. It is a great way to grab attention.', 'mihdan-index-now')
		);

		$wposa->add_field(self::SECTION, [
			'id'   => 'social_image_fallback',
			'type' => 'image',
			'name' => __('Social Image Fallback', 'mihdan-index-now'),
			'desc' => esc_html__('When no image is available from the page or term, this fallback image will be used instead. Recommended size: 1200×630 px.', 'mihdan-index-now'),
		]);
	}

	// -------------------------------------------------------------------------
	// Facebook
	// -------------------------------------------------------------------------

	private function add_facebook_fields(WPOSA $wposa): void
	{
		$this->add_heading(
			$wposa,
			self::SECTION,
			'heading_facebook',
			__('Facebook Integration Settings', 'mihdan-index-now'),
			__('Facebook post sharing works mostly through Open Graph. Configure the Facebook page URL in Site Information (under Advanced settings).', 'mihdan-index-now')
		);

		$wposa->add_field(self::SECTION, [
			'id'          => 'facebook_author',
			'type'        => 'text',
			'name'        => __('Facebook Author Fallback Page', 'mihdan-index-now'),
			'placeholder' => 'https://www.facebook.com/YourPersonalProfile',
			'desc'        => esc_html__('Used as the article:author Open Graph tag. Authors can override this on their profile page.', 'mihdan-index-now'),
		]);

		$wposa->add_field(self::SECTION, [
			'id'          => 'fb_app_id',
			'type'        => 'text',
			'name'        => __('Facebook App ID', 'mihdan-index-now'),
			'placeholder' => '123456789012345',
			'desc'        => esc_html__('Your Facebook App ID. When set, the fb:app_id meta tag is added to every page, enabling Facebook Insights and improving link-preview attribution. Create an app at developers.facebook.com.', 'mihdan-index-now'),
		]);
	}

	// -------------------------------------------------------------------------
	// X / Twitter
	// -------------------------------------------------------------------------

	private function add_twitter_fields(WPOSA $wposa): void
	{
		$this->add_heading(
			$wposa,
			self::SECTION,
			'heading_twitter',
			__('X (Twitter) Integration Settings', 'mihdan-index-now'),
			__('Sharing posts on X (formerly Twitter) works mostly via Twitter Cards and may fall back to use Open Graph. However, you can also link your Business and Personal X pages, among various other options.', 'mihdan-index-now')
		);

		$wposa->add_field(self::SECTION, [
			'id'      => 'twitter_card',
			'type'    => 'radio',
			'name'    => __('Default Twitter Card Type', 'mihdan-index-now'),
			'default' => 'summary_large_image',
			'options' => [
				'summary'             => 'summary',
				'summary_large_image' => 'summary_large_image',
			],
			'desc'    => esc_html__('When you share a link on X (formerly Twitter), an image can appear on the side (summary) or as a large cover (summary_large_image). The card type also affects images in Discord embeds.', 'mihdan-index-now'),
		]);

		$this->add_heading(
			$wposa,
			self::SECTION,
			'heading_twitter_attribution',
			__('Card and Content Attribution', 'mihdan-index-now'),
			__('X (formerly Twitter) claims users will be able to follow and view the profiles of attributed accounts directly from the card when these fields are filled in.', 'mihdan-index-now')
		);

		$wposa->add_field(self::SECTION, [
			'id'          => 'twitter_creator',
			'type'        => 'text',
			'name'        => __('Author Fallback X Profile', 'mihdan-index-now'),
			'placeholder' => '@your-personal-username',
			'desc'        => esc_html__('Authors can override this option on their profile page. Used as the twitter:creator meta tag.', 'mihdan-index-now'),
		]);
	}

	// -------------------------------------------------------------------------
	// Post Dates
	// -------------------------------------------------------------------------

	private function add_postdates_fields(WPOSA $wposa): void
	{
		$this->add_heading(
			$wposa,
			self::SECTION,
			'heading_postdates',
			__('Post Date Settings', 'mihdan-index-now'),
			__("Some social sites output the shared post's publishing and modified data in the sharing snippet.", 'mihdan-index-now')
		);

		$wposa->add_field(self::SECTION, [
			'id'      => 'post_publish_time',
			'type'    => 'switch',
			'name'    => __('Add article:published_time to posts?', 'mihdan-index-now'),
			'default' => 'on',
			'desc'    => $this->description(__('Outputs the article:published_time Open Graph property for posts.', 'mihdan-index-now')),
		]);

		$wposa->add_field(self::SECTION, [
			'id'      => 'post_modify_time',
			'type'    => 'switch',
			'name'    => __('Add article:modified_time to posts?', 'mihdan-index-now'),
			'default' => 'on',
			'desc'    => $this->description(__('Outputs the article:modified_time Open Graph property for posts.', 'mihdan-index-now')),
		]);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Read a social setting value.
	 *
	 * @param string $field   Field id (e.g. 'og_tags').
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
}
