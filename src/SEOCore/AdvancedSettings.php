<?php

namespace Mihdan\IndexNow\SEOCore;

class AdvancedSettings
{
	public function __construct()
	{
		add_action('crawlwp_pre_setup_fields', [$this, 'advanced_settings_menu'], 5);
	}
	public function advanced_settings_menu($wposa)
	{
		$wposa->add_header_menu([
			'id'    => 'advanced_settings',
			'title' => __('Advanced', 'mihdan-index-now'),
		]);
	}
}
