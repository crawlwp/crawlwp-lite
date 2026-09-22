<?php

declare(strict_types=1);

namespace Mihdan\IndexNow\SEOCore;

use Mihdan\IndexNow\Views\WPOSA;

class AdvancedSettings
{
	public function __construct()
	{
		add_action('crawlwp_pre_setup_fields', [$this, 'advanced_settings_menu'], -15);
	}

	public function advanced_settings_menu(WPOSA $wposa): void
	{
		$wposa->add_header_menu([
			'id'    => 'advanced_settings',
			'title' => __('Settings', 'mihdan-index-now'),
		]);
	}
}
