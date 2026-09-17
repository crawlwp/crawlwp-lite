<?php

namespace Mihdan\IndexNow\SEOCore\SiteVerification;

use Mihdan\IndexNow\Utils;

class SiteVerificationFrontendOutput
{
	/** Print the tags on the homepage / front page only (default). */
	public const SCOPE_FRONT_PAGE = 'front_page';

	/** Print the tags on every frontend page. */
	public const SCOPE_SITE_WIDE = 'site_wide';

	public function __construct()
	{
		add_action('wp_head', [$this, 'output'], 1);
	}

	public function output()
	{
		if ( ! $this->should_output()) {
			return;
		}

		$verification_data = [
			'google' => [
				'meta_name' => 'google-site-verification',
				'value'     => Utils::get_setting_data('site_verification', 'google')
			],
			'bing' => [
				'meta_name' => 'msvalidate.01',
				'value'     => Utils::get_setting_data('site_verification', 'bing')
			],
			'yandex' => [
				'meta_name' => 'yandex-verification',
				'value'     => Utils::get_setting_data('site_verification', 'yandex')
			],
			'baidu' => [
				'meta_name' => 'baidu-site-verification',
				'value'     => Utils::get_setting_data('site_verification', 'baidu')
			],
			'pinterest' => [
				'meta_name' => 'p:domain_verify',
				'value'     => Utils::get_setting_data('site_verification', 'pinterest')
			],
		];

		foreach ($verification_data as $data) {
			if ( ! empty($data['value'])) {
				echo sprintf('<meta name="%s" content="%s" />%s', esc_attr($data['meta_name']), esc_attr($data['value']), PHP_EOL);
			}
		}
	}

	/**
	 * Whether the verification tags belong in the current request's <head>.
	 *
	 * Webmaster tools only read the homepage, so that remains the default.
	 * Some setups (a verification service checking an inner URL, a headless
	 * or subdirectory install) need them everywhere.
	 */
	private function should_output(): bool
	{
		/**
		 * Filter where the site verification meta tags are printed.
		 *
		 * @param string $scope self::SCOPE_FRONT_PAGE (default) prints them on
		 *                      the homepage / front page only;
		 *                      self::SCOPE_SITE_WIDE prints them on every page.
		 */
		$scope = (string) apply_filters('crawlwp_site_verification_scope', self::SCOPE_FRONT_PAGE);

		if ($scope === self::SCOPE_SITE_WIDE) {
			return true;
		}

		return is_home() || is_front_page();
	}
}
