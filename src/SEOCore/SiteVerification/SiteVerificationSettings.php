<?php

namespace Mihdan\IndexNow\SEOCore\SiteVerification;

use Mihdan\IndexNow\Utils;
use Mihdan\IndexNow\Views\WPOSA;

class SiteVerificationSettings
{
	/**
	 * Pulls the content attribute out of a pasted <meta> tag.
	 *
	 * The character class stops at the closing quote, so extra attributes
	 * (data-*, id, nonce…) on either side of `content` are not captured.
	 */
	private const META_TAG_REGEX = '/<meta\b[^>]*?\bcontent=["\']([^"\']+)["\'][^>]*>/i';

	/** Characters allowed in a stored verification code — everything else is stripped. */
	private const CODE_DISALLOWED_REGEX = '/[^A-Za-z0-9_\-=.:]+/';

	public function __construct()
	{
		add_action('crawlwp_setup_fields', [$this, 'advanced_settings_fields'], 10, 2);

		add_filter('wposa_submitted_data', [$this, 'sanitize_site_verification_data'], 10, 2);
	}

	public function advanced_settings_fields(WPOSA $wposa, $settingsInstance)
	{
		if ($wposa->get_active_header_menu() === Utils::get_plugin_prefix() . '_advanced_settings') {

			do_action('crawlwp_before_advanced_settings_fields', $wposa);

			$wposa->add_section([
				'header_menu_id' => 'advanced_settings',
				'id'             => 'site_verification',
				'title'          => __('Site Verification', 'mihdan-index-now'),
				'desc'           => esc_html__('To verify your website with tools such as Google Search Console, Bing Webmaster Tools, and Yandex Webmaster Tools, you need to add a verification meta tag to your site. These options will help you seamlessly integrate the required codes.', 'mihdan-index-now'),
			]);

			$providers = [
				'google'    => [
					'name'     => __('Google Verification Code', 'mihdan-index-now'),
					'meta_tag' => '<meta name="google-site-verification" content="%ssite-verification-code%s"/>',
					'help_tab' => 'https://crawlwp.com/?p=763&utm_source=wp_dashboard&utm_medium=site_verification_page&utm_campaign=google',
				],
				'bing'      => [
					'name'     => __('Bing Verification Code', 'mihdan-index-now'),
					'meta_tag' => '<meta name="msvalidate.01" content="%ssite-verification-code%s" />',
					'help_tab' => 'https://crawlwp.com/?p=768&utm_source=wp_dashboard&utm_medium=site_verification_page&utm_campaign=bing',
				],
				'yandex'    => [
					'name'     => __('Yandex Verification Code', 'mihdan-index-now'),
					'meta_tag' => '<meta name="yandex-verification" content="%ssite-verification-code%s" />',
					'help_tab' => 'https://crawlwp.com/?p=770&utm_source=wp_dashboard&utm_medium=site_verification_page&utm_campaign=yandex',
				],
				'baidu'     => [
					'name'     => __('Baidu Verification Code', 'mihdan-index-now'),
					'meta_tag' => '<meta name="baidu-site-verification" content="%ssite-verification-code%s" />',
					'help_tab' => 'https://crawlwp.com/?p=772&utm_source=wp_dashboard&utm_medium=site_verification_page&utm_campaign=baidu',
				],
				'pinterest' => [
					'name'     => __('Pinterest Verify Code', 'mihdan-index-now'),
					'meta_tag' => '<meta name="p:domain_verify" content="%ssite-verification-code%s" />',
					'help_tab' => 'https://crawlwp.com/?p=776&utm_source=wp_dashboard&utm_medium=site_verification_page&utm_campaign=pinterest',
				],
			];

			foreach ($providers as $id => $provider) {
				$wposa->add_field(
					'site_verification',
					[
						'id'       => $id,
						'type'     => 'text',
						'name'     => $provider['name'],
						'desc'     => '<code>' . sprintf(esc_html($provider['meta_tag']), '<span style="color:#a11">', '</strong>') . '</code>',
						'help_tab' => $provider['help_tab'],
					]
				);
			}

			do_action('crawlwp_after_advanced_settings_fields', $wposa);
		}
	}


	public function sanitize_site_verification_data($submitted_data, $name)
	{
		if ($name === 'crawlwp_site_verification') {
			$providers = ['google', 'bing', 'yandex', 'baidu', 'pinterest'];

			foreach ($providers as $provider) {
				if (isset($submitted_data[$provider])) {
					$code = trim((string) wp_unslash($submitted_data[$provider]));

					/* Accept a pasted <meta> tag and pull out the content attribute. */
					$code = (string) preg_replace(self::META_TAG_REGEX, '$1', $code);

					/* Whether pasted as a tag or a bare code, only safe characters may be stored. */
					$submitted_data[$provider] = (string) preg_replace(self::CODE_DISALLOWED_REGEX, '', $code);
				}
			}
		}

		return $submitted_data;
	}
}
