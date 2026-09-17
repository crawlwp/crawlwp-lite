<?php
/**
 * Settings class.
 *
 * @package mihdan-index-now
 */

namespace Mihdan\IndexNow\Views;

use Mihdan\IndexNow\Logger\Logger;
use Mihdan\IndexNow\SEOCore\FeatureGate\FeatureGate;
use Mihdan\IndexNow\SEOCore\Wizard\Wizard;
use Mihdan\IndexNow\Utils;

/**
 * Class Settings.
 */
class Settings
{
	/**
	 * Logger instance.
	 *
	 * @var Logger $logger
	 */
	private $logger;
	/**
	 * WP_OSA instance.
	 *
	 * @var WPOSA $wposa
	 */
	public $wposa;

	/**
	 * Array of post types.
	 *
	 * @var array $post_types
	 */
	private $post_types;

	/**
	 * @var array $taxonomies
	 */
	private $taxonomies;

	/**
	 * Constructor.
	 *
	 * @param WPOSA $wposa WPOSA instance.
	 */
	public function __construct(Logger $logger, WPOSA $wposa)
	{
		$this->logger = $logger;
		$this->wposa  = $wposa;
	}

	/**
	 * Setup vars.
	 */
	public function setup_vars()
	{
		$args = array(
			'public' => true,
		);

		$this->post_types = wp_list_pluck(get_post_types($args, 'objects'), 'label', 'name');
		$this->taxonomies = wp_list_pluck(get_taxonomies($args, 'objects'), 'label', 'name');
	}

	/**
	 * Get post types.
	 *
	 * @return array
	 */
	private function get_post_types(): array
	{
		return $this->post_types;
	}

	/**
	 * Get taxonomies.
	 *
	 * @return array
	 */
	private function get_taxonomies(): array
	{
		return $this->taxonomies;
	}

	/**
	 * Setup hooks.
	 */
	public function setup_hooks()
	{
		add_action('init', [$this, 'setup_vars'], 100);
		add_action('init', [$this, 'setup_fields'], 101);
		add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));

		add_action('wpposa_load_menu_hook', function ($sub_menu_slug, $plugin_slug) {
			if ($plugin_slug == CRAWLWP_SLUG && Utils::_GET_var('wposa-menu') == CRAWLWP_PREFIX . '_log') {
				$GLOBALS[CRAWLWP_PREFIX . '_log'] = new Log_List_Table($this->logger, $this->wposa);
			}
		}, 10, 2);

		add_filter('admin_body_class', function ($classes) {
			global $wp_version;

			if (version_compare($wp_version, '6.9.4', '>')) {
				$classes .= ' cwpWP7higher';
			}

			return $classes;
		});
	}

	public function admin_enqueue_scripts()
	{
		wp_enqueue_script('plugin_install');
		wp_enqueue_script('updates');
		//wp_enqueue_style( 'list-tables' );
		add_thickbox();
	}

	/**
	 * Setup setting fields.
	 *
	 * @link https://yandex.com/support/webmaster/indexnow/key.html
	 */
	public function setup_fields()
	{
		if (wp_doing_ajax()) return;

		$priority = FeatureGate::is_enabled() ? 6 : 1;

		add_action('crawlwp_pre_setup_fields', function ($wposa) {
			$wposa->add_header_menu([
				'id'    => 'index_settings',
				'title' => __('Indexing', 'mihdan-index-now'),
			]);
		}, $priority);

		do_action('crawlwp_pre_setup_fields', $this->wposa, $this);

		$this->wposa->sub_page_title = esc_html__('Settings', 'mihdan-index-now');

		$this->wposa->add_header_menu([
			'id'    => 'api_settings',
			'title' => __('API Settings', 'mihdan-index-now'),
		]);

		do_action('crawlwp_setup_fields_before_log', $this->wposa, $this);

		$this->wposa->add_header_menu([
			'id'    => 'log',
			'title' => __('Log', 'mihdan-index-now'),
		]);

		do_action('crawlwp_setup_fields', $this->wposa, $this);

		if ( ! defined('CRAWLWP_DETACH_LIBSODIUM')) {

			$this->wposa->add_sidebar_card(
				[
					'id'    => 'upsell_card',
					'title' => __('Get CrawlWP Premium', 'mihdan-index-now'),
					'desc'  => (function () {

						$upgrade_url    = 'https://crawlwp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=crawlwp-settings-page-sidebar-card';
						$learn_more_url = 'https://crawlwp.com/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=crawlwp-settings-page-sidebar-card';

						$pro_features = [
							esc_html__('Google Search Insights', 'mihdan-index-now'),
							esc_html__('Bing Search Insights', 'mihdan-index-now'),
							esc_html__('Yandex Search Insights', 'mihdan-index-now'),
							esc_html__('Auto Indexing', 'mihdan-index-now'),
							esc_html__('Index Status Insights', 'mihdan-index-now'),
							esc_html__('Index History', 'mihdan-index-now'),
							esc_html__('Keyword Tracking', 'mihdan-index-now'),
							esc_html__('SEO Stats & Index Email Report', 'mihdan-index-now'),
							esc_html__('Video, HTML and Custom URL Sitemaps', 'mihdan-index-now'),
							esc_html__('Internal Linking', 'mihdan-index-now'),
							esc_html__('WPML, Polylang, TranslatePress Sitemap Integrations', 'mihdan-index-now'),
						];

						$svg = '<svg class="h-5 w-5 text-green-500" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18" fill="none"><g clip-path="url(#clip0_6404_1763)"><path d="M12.9955 5.64817L7.93251 12.4574L4.99665 10.2744" stroke="#2271b1" stroke-width="1.5"></path></g><defs><clipPath id="clip0_6404_1763"><rect width="18" height="18" rx="9" fill="white"></rect></clipPath></defs></svg>';

						$html = sprintf(
							'<p>%s</p>',
							esc_html__('Boost and monitor the visibility of your WordPress website on search engines with CrawlWP Premium.', 'mihdan-index-now')
						);

						$html .= '<ul class="cwp-premium-sidebar-upsell-ul">';
						foreach ($pro_features as $feature) {
							$html .= sprintf('<li class="cwp-premium-sidebar-upsell-li">%s <span>%s</span></li>', $svg, $feature);
						}
						$html .= '</ul>';

						$html .= sprintf(
							'<div class="cwp-premium-sidebar-upsell-cta"><a target="_blank" href="%s" class="button-primary">%s</a> <a target="_blank" href="%s">%s</a></div>',
							$upgrade_url, esc_html__('Upgrade Now', 'mihdan-index-now'),
							$learn_more_url, esc_html__('Learn more', 'mihdan-index-now')
						);

						return $html;

					})(),
				]
			);
		}

		if (class_exists(FeatureGate::class) && FeatureGate::is_enabled() && class_exists(Wizard::class)) {
			$this->wposa->add_sidebar_card(
				[
					'id'    => 'setup_wizard',
					'title' => __('Setup Wizard', 'mihdan-index-now'),
					'desc'  => sprintf(
						'<p>%s</p><div style="margin-top: 12px;"><a href="%s" class="button button-secondary cwp-relaunch-wizard-btn">%s</a></div>',
						esc_html__('Need to configure your SEO settings again, re-import data from another plugin, or adjust Indexing settings?', 'mihdan-index-now'),
						esc_url(Wizard::wizard_url()),
						esc_html__('Relaunch Setup Wizard', 'mihdan-index-now')
					),
				]
			);
		}

		$this->wposa->add_sidebar_card(
			[
				'id'    => 'rtfm',
				'title' => __('Do you need help?', 'mihdan-index-now'),
				'desc'  => __('<p>Here are some available options to help solve your problems.</p><ul><li><a href="https://crawlwp.com" target="_blank">Plugin home page</a></li><li><a href="https://wordpress.org/support/plugin/mihdan-index-now/" target="_blank">Support forums</a></li><li><a href="https://github.com/crawlwp/crawlwp-lite/" target="_blank">Issue tracker</a></li></ul>', 'mihdan-index-now'),
			]
		);

		if ($this->wposa->get_active_header_menu() == Utils::get_plugin_prefix() . '_index_settings') {

			$this->wposa->add_section(
				array(
					'header_menu_id' => 'index_settings',
					'id'             => 'general',
					'title'          => __('General', 'mihdan-index-now'),
				)
			);

			$this->wposa->add_field(
				'general',
				array(
					'id'      => 'post_types',
					'type'    => 'multicheck',
					'name'    => __('Post Types', 'mihdan-index-now'),
					'options' => $this->get_post_types(),
					'default' => ['post' => 'post'],
					'desc'    => esc_html__('Select the custom post types that can be submitted to Search Engines for indexing.', 'mihdan-index-now')
				)
			);

			$this->wposa->add_field(
				'general',
				array(
					'id'      => 'taxonomies',
					'type'    => 'multicheck',
					'name'    => __('Taxonomies', 'mihdan-index-now'),
					'options' => $this->get_taxonomies(),
					'default' => ['category' => 'category'],
					'desc'    => esc_html__('Select the taxonomies that can be submitted to Search Engines for indexing.', 'mihdan-index-now')
				)
			);

			$this->wposa->add_field(
				'general',
				array(
					'id'   => 'ping_when',
					'type' => 'html',
					'name' => __('Notify Search Engines when', 'mihdan-index-now'),
				)
			);

			$this->wposa->add_field(
				'general',
				array(
					'id'      => 'ping_on_post',
					'type'    => 'switch',
					'name'    => __('Post added', 'mihdan-index-now'),
					'default' => 'on',
				)
			);

			$this->wposa->add_field(
				'general',
				array(
					'id'      => 'ping_on_post_updated',
					'type'    => 'switch',
					'name'    => __('Post updated', 'mihdan-index-now'),
					'default' => 'off',
				)
			);

			$this->wposa->add_field(
				'general',
				array(
					'id'   => 'ping_on_term',
					'type' => 'switch',
					'name' => __('Term added', 'mihdan-index-now'),
				)
			);

			$this->wposa->add_field(
				'general',
				array(
					'id'      => 'disable_for_bulk_edit',
					'type'    => 'switch',
					'name'    => __('Disable for Bulk Edit', 'mihdan-index-now'),
					'default' => 'on',
				)
			);

			$this->wposa->add_field(
				'general',
				array(
					'id'      => 'show_last_update_column',
					'type'    => 'switch',
					'name'    => __('Show last update column', 'mihdan-index-now'),
					'default' => 'on',
				)
			);

			$this->wposa->add_field(
				'general',
				array(
					'id'      => 'ping_delay',
					'type'    => 'select',
					'name'    => __('Ping Delay', 'mihdan-index-now'),
					'desc'    => __('Delay between notifications for a single URL', 'mihdan-index-now'),
					'options' => [
						60   => __('1 minute', 'mihdan-index-now'),
						120  => __('2 minutes', 'mihdan-index-now'),
						300  => __('5 minutes', 'mihdan-index-now'),
						600  => __('10 minutes', 'mihdan-index-now'),
						1200 => __('20 minutes', 'mihdan-index-now'),
						1800 => __('30 minutes', 'mihdan-index-now'),
						3600 => __('60 minutes', 'mihdan-index-now'),
					],
				)
			);

			$this->wposa->add_section(
				array(
					'header_menu_id' => 'index_settings',
					'id'             => 'index_now',
					'title'          => __('IndexNow', 'mihdan-index-now'),
					'desc'           => __('IndexNow is an easy way for websites owners to instantly inform search engines about latest content changes on their website. In its simplest form, IndexNow is a simple ping so that search engines know that a URL and its content has been added, updated, or deleted, allowing search engines to quickly reflect this change in their search results.', 'mihdan-index-now'),
				)
			);

			$this->wposa->add_field(
				'index_now',
				array(
					'id'      => 'enable',
					'type'    => 'switch',
					'name'    => __('Enable', 'mihdan-index-now'),
					'default' => 'on',
				)
			);

			$this->wposa->add_field(
				'index_now',
				array(
					'id'          => 'api_key',
					'type'        => 'text',
					'name'        => __('API Key', 'mihdan-index-now'),
					'placeholder' => __('Set the API key', 'mihdan-index-now'),
					'default'     => $this->get_index_now_api_key(),
					'help_tab'    => 'https://crawlwp.com/article/setting-up-search-engine-indexing-for-wordpress/?utm_source=wp_dashboard&utm_medium=indexing_settings_page&utm_campaign=indexnow#wordpress-indexing-via-indexnow',
				)
			);

			$this->wposa->add_field(
				'index_now',
				array(
					'id'       => 'search_engine',
					'type'     => 'radio',
					'name'     => __('Search Engine', 'mihdan-index-now'),
					'default'  => 'bing-index-now',
					'help_tab' => 'https://crawlwp.com/article/setting-up-search-engine-indexing-for-wordpress/?utm_source=wp_dashboard&utm_medium=indexing_settings_page&utm_campaign=indexnow#wordpress-indexing-via-indexnow',
					'desc'     => __('You only need to select one search engine because with the IndexNow protocol, the selected one will notify the others.', 'mihdan-index-now'),
					'options'  => [
						'bing-index-now'   => __('Bing', 'mihdan-index-now'),
						'index-now'        => __('IndexNow', 'mihdan-index-now'),
						'yandex-index-now' => __('Yandex', 'mihdan-index-now'),
						'seznam-index-now' => __('Seznam', 'mihdan-index-now'),
						'naver-index-now'  => __('Naver', 'mihdan-index-now')
					],
				)
			);

			$this->wposa->add_section(
				array(
					'header_menu_id' => 'index_settings',
					'id'             => 'bing_webmaster',
					'title'          => __('Bing', 'mihdan-index-now'),
					'desc'           => sprintf(__('Enable to use Bing API to request indexing. %sBing supports the IndexNow protocol. You might not need to enable this if IndexNow is active.%s', 'mihdan-index-now'), '<strong>', '</strong>'),
				)
			);

			if ( ! $GLOBALS['CRAWLWP_BING_WEBMASTER']->is_connected()) {
				$this->wposa->add_field(
					'bing_webmaster',
					array(
						'type' => 'html',
						'desc' => sprintf(
							'<div class="notice notice-warning inline"><p>' . __('This setting will not work  because Bing API is not configured. Go to %sAPI Settings%s to set it up.', 'mihdan-index-now') . '</p></div>',
							'<a target="_blank" href="' . CRAWLWP_API_SETTINGS_URL . '">', '</a>'
						),
					)
				);
			}

			$this->wposa->add_field(
				'bing_webmaster',
				array(
					'id'   => 'enable',
					'type' => 'switch',
					'name' => __('Enable', 'mihdan-index-now'),
				)
			);

			$this->wposa->add_section(
				array(
					'header_menu_id' => 'index_settings',
					'id'             => 'google_webmaster',
					'title'          => __('Google', 'mihdan-index-now'),
					'desc'           => __('Enable to use Google Instant Indexing API to request indexing', 'mihdan-index-now')
				)
			);

			if ( ! $GLOBALS['CRAWLWP_GOOGLE_WEBMASTER']->is_connected()) {
				$this->wposa->add_field(
					'google_webmaster',
					array(
						'type' => 'html',
						'desc' => sprintf(
							'<div class="notice notice-warning inline"><p>' . __('This setting will not work  because Google API is not configured. Go to %sAPI Settings%s to set it up.', 'mihdan-index-now') . '</p></div>',
							'<a target="_blank" href="' . CRAWLWP_API_SETTINGS_URL . '">', '</a>'
						),
					)
				);
			}

			$this->wposa->add_field(
				'google_webmaster',
				array(
					'id'   => 'enable',
					'type' => 'switch',
					'name' => __('Enable', 'mihdan-index-now'),
				)
			);

			$this->wposa->add_section(
				array(
					'header_menu_id' => 'index_settings',
					'id'             => 'yandex_webmaster',
					'title'          => __('Yandex', 'mihdan-index-now'),
					'desc'           => sprintf(__('Enable to use Yandex API to request indexing. %sYandex supports the IndexNow protocol. You might not need to enable this if IndexNow is active.%s', 'mihdan-index-now'), '<strong>', '</strong>'),
				)
			);

			if ( ! $GLOBALS['CRAWLWP_YANDEX_WEBMASTER']->is_connected()) {
				$this->wposa->add_field(
					'yandex_webmaster',
					array(
						'type' => 'html',
						'desc' => sprintf(
							'<div class="notice notice-warning inline"><p>' . __('This setting will not work  because Yandex API is not configured. Go to %sAPI Settings%s to set it up.', 'mihdan-index-now') . '</p></div>',
							'<a target="_blank" href="' . CRAWLWP_API_SETTINGS_URL . '">', '</a>'
						),
					)
				);
			}

			$this->wposa->add_field(
				'yandex_webmaster',
				array(
					'id'   => 'enable',
					'type' => 'switch',
					'name' => __('Enable', 'mihdan-index-now'),
				)
			);

			$this->wposa->add_section(

				array(
					'header_menu_id' => 'index_settings',
					'id'             => 'logs',
					'title'          => __('Logs', 'mihdan-index-now'),
					'desc'           => __('Module for logging incoming request from search engine and outgoing request from site.', 'mihdan-index-now'),
				)
			);

			$this->wposa->add_field(
				'logs',
				array(
					'id'      => 'enable',
					'type'    => 'switch',
					'name'    => __('Enable', 'mihdan-index-now'),
					'default' => 'on',
				)
			);

			$this->wposa->add_field(
				'logs',
				array(
					'id'      => 'key_logging',
					'type'    => 'switch',
					'name'    => __('IndexNow logging', 'mihdan-index-now'),
					'default' => 'on',
				)
			);

			$this->wposa->add_field(
				'logs',
				array(
					'id'      => 'outgoing_requests',
					'type'    => 'switch',
					'name'    => __('Outgoing requests', 'mihdan-index-now'),
					'default' => 'on',
				)
			);

			$this->wposa->add_field(
				'logs',
				array(
					'id'      => 'lifetime',
					'type'    => 'number',
					'name'    => __('Lifetime', 'mihdan-index-now'),
					'default' => 7,
					'desc'    => __('Logs lifetime in days', 'mihdan-index-now'),
				)
			);

			$this->wposa->add_section(
				array(
					'header_menu_id' => 'index_settings',
					'id'             => 'plugins',
					'reset_button'   => false,
					'title'          => __('Other Plugins', 'mihdan-index-now'),
					'desc'           => __('You can also install our other useful plugins.', 'mihdan-index-now'),
				)
			);

			$this->wposa->add_field(
				'plugins',
				array(
					'id'   => 'plugins',
					'type' => 'html',
					'name' => '',
					'desc' => function () {

						$transient = Utils::get_plugin_slug() . '-plugins';
						$cached    = get_transient($transient);

						if (false !== $cached) {
							return $cached;
						}

						require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

						$args = array(
							'per_page' => 100,
							'page'     => 1,
							'author'   => 'properfraction',
							'fields'   => array(
								'downloaded'        => true,
								'rating'            => true,
								'ratings'           => false,
								'description'       => false,
								'short_description' => true,
								'donate_link'       => false,
								'tags'              => true,
								'sections'          => false,
								'added'             => false,
								'last_updated'      => false,
								'compatibility'     => false,
								'tested'            => false,
								'requires'          => false,
								'downloadlink'      => true,
								'banners'           => true,
							),
						);

						$result = plugins_api('query_plugins', $args);

						if (empty($result->info['results'])) {
							return __('HTTP error', 'mihdan-index-now');
						}

						ob_start();

						?>
						<div class="wposa-plugins">
							<?php
							foreach ($result->plugins as $plugin) {

								if (is_object($plugin)) {
									continue;
								}

								$plugin = (array)$plugin;

								if (in_array($plugin['slug'], ['one-user-avatar', 'mihdan-index-now'])) {
									continue;
								}

								$info_url    = add_query_arg(
									[
										'tab'       => 'plugin-information',
										'plugin'    => $plugin['slug'],
										'TB_iframe' => 'true',
										'width'     => '800',
										'height'    => '550',
									],
									admin_url('plugin-install.php')
								);
								$install_url = add_query_arg(
									[
										'action'   => 'install-plugin',
										'plugin'   => $plugin['slug'],
										'_wpnonce' => wp_create_nonce('install-plugin_' . $plugin['slug']),
									],
									admin_url('update.php')
								);
								?>
								<div class="wposa-plugins__item wposa-plugin">
									<div class="wposa-plugin__content">
										<div class="wposa-plugin__icon">
											<a href="<?php echo esc_url($info_url); ?>"
											   class="thickbox open-plugin-details-modal"> <img
													src="<?php echo esc_url($plugin['icons']['1x'] ?? $plugin['icons']['default']); ?>"
													width="100" height="100"> </a>
											<?php
											wp_star_rating(
												array(
													'rating' => $plugin['rating'],
													'type'   => 'percent',
													'number' => $plugin['num_ratings'],
												)
											);
											?>
										</div>
										<div class="wposa-plugin__data">
											<div class="wposa-plugin__name">
												<a href="<?php echo esc_url($info_url); ?>"
												   class="thickbox open-plugin-details-modal"><?php echo esc_html($plugin['name']); ?></a>
											</div>
											<div class="wposa-plugin__description">
												<?php echo esc_html($plugin['short_description']); ?>
											</div>
										</div>
									</div>
									<div class="wposa-plugin__footer">
										<div class="wposa-plugin__install">
											<a href="<?php echo esc_url($install_url); ?>" class="install-now button"><?php esc_html_e('Click to Install Now', 'mihdan-index-now'); ?></a>
										</div>
									</div>
								</div>
								<?php
							}

							?>
						</div>
						<?php
						$content = ob_get_clean();
						set_transient($transient, $content, 1 * DAY_IN_SECONDS);

						return $content;
					},
				)
			);
		}

		if ($this->wposa->get_active_header_menu() == Utils::get_plugin_prefix() . '_api_settings') {
			/** API settings */

			$this->wposa->add_section(
				array(
					'header_menu_id' => 'api_settings',
					'id'             => 'google_webmaster',
					'title'          => __('Google API', 'mihdan-index-now')
				)
			);

			$this->wposa->add_field(
				'google_webmaster',
				array(
					'id'       => 'json_key',
					'type'     => 'textarea',
					'name'     => __('Google JSON Key', 'mihdan-index-now'),
					'desc'     => __('Paste the Service Account JSON key file contents you obtained from Google Cloud Console in the field.', 'mihdan-index-now'),
					'help_tab' => 'https://crawlwp.com/article/integrating-wordpress-with-google-search/?utm_source=wp_dashboard&utm_medium=api_settings_page&utm_campaign=google_api'
				)
			);

			$this->wposa->add_section(
				array(
					'header_menu_id' => 'api_settings',
					'id'             => 'bing_webmaster',
					'title'          => __('Bing API', 'mihdan-index-now')
				)
			);

			$this->wposa->add_field(
				'bing_webmaster',
				array(
					'id'       => 'api_key',
					'type'     => 'text',
					'name'     => __('API Key', 'mihdan-index-now'),
					'help_tab' => 'https://crawlwp.com/article/integrating-wordpress-with-bing/?utm_source=wp_dashboard&utm_medium=api_settings_page&utm_campaign=bing_api',
				)
			);

			$this->wposa->add_section(
				array(
					'header_menu_id' => 'api_settings',
					'id'             => 'yandex_webmaster',
					'title'          => __('Yandex API', 'mihdan-index-now')
				)
			);

			$this->wposa->add_field(
				'yandex_webmaster',
				array(
					'id'       => 'client_id',
					'type'     => 'text',
					'help_tab' => 'https://crawlwp.com/article/integrating-wordpress-with-yandex/?utm_source=wp_dashboard&utm_medium=api_settings_page&utm_campaign=yandex_api',
					'name'     => __('ClientID', 'mihdan-index-now')
				)
			);

			$this->wposa->add_field(
				'yandex_webmaster',
				array(
					'id'       => 'client_secret',
					'type'     => 'password',
					'help_tab' => 'https://crawlwp.com/article/integrating-wordpress-with-yandex/?utm_source=wp_dashboard&utm_medium=api_settings_page&utm_campaign=yandex_api',
					'name'     => __('Client secret', 'mihdan-index-now')
				)
			);

			$this->wposa->add_field(
				'yandex_webmaster',
				array(
					'id'       => '',
					'type'     => 'text',
					'readonly' => true,
					'help_tab' => 'https://crawlwp.com/article/integrating-wordpress-with-yandex/?utm_source=wp_dashboard&utm_medium=api_settings_page&utm_campaign=yandex_api',
					'name'     => __('Redirect URI', 'mihdan-index-now'),
					'value'    => CRAWLWP_API_SETTINGS_URL
				)
			);

			if ($this->wposa->get_option('access_token', 'yandex_webmaster')) {

				$this->wposa->add_field(
					'yandex_webmaster',
					array(
						'id'      => 'host_id',
						'type'    => 'select',
						'name'    => __('Host ID', 'mihdan-index-now'),
						'options' => ['' => '&mdash;&mdash;&mdash;'] + $this->get_yandex_webmaster_host_ids()
					)
				);
			}

			if (
				$this->wposa->get_option('client_id', 'yandex_webmaster') &&
				$this->wposa->get_option('client_secret', 'yandex_webmaster')
			) {
				$this->wposa->add_field(
					'yandex_webmaster',
					array(
						'id'          => 'button_get_token',
						'type'        => 'button',
						'name'        => __('Token', 'mihdan-index-now'),
						'placeholder' => $this->wposa->get_option('access_token', 'yandex_webmaster')
							? __('Update Token', 'mihdan-index-now')
							: __('Get Token', 'mihdan-index-now'),
						'desc'        => $this->wposa->get_option('access_token', 'yandex_webmaster')
							? __('Expires In', 'mihdan-index-now') . ': ' . date('d.m.Y', $this->wposa->get_option('expires_in', 'yandex_webmaster'))
							: '',
					)
				);
			}

			// Note: access_token, refresh_token, expires_in, user_id and host_ids are
			// intentionally NOT rendered as form fields. They are stored by the
			// Yandex OAuth flow and preserved on save because the save handler merges
			// posted values over the stored option (keys not posted are kept).
		}


		if ($this->wposa->get_active_header_menu() == Utils::get_plugin_prefix() . '_log') {

			$this->wposa->enable_blank_mode();

			$this->wposa->remove_all_sidebar_cards();

			$this->wposa->add_section(
				array(
					'header_menu_id' => 'log',
					'id'             => 'log',
					'title'          => __('Indexing Log', 'mihdan-index-now'),
				)
			);

			$this->wposa->add_field(
				'log',
				[
					'id'   => 'pages',
					'type' => 'html',
					'name' => 'name',
					'desc' => function () {

						ob_start();
						echo '<form action="" method="post">';
						/**
						 * WP_List_table.
						 *
						 * @var \WP_List_Table $table
						 */
						$table = $GLOBALS[CRAWLWP_PREFIX . '_log'];
						$table->display();

						echo '</form>';

						return ob_get_clean();
					}
				]
			);
		}
	}

	/**
	 * Return the persisted IndexNow API key, generating and saving it once when missing.
	 *
	 * @return string
	 */
	private function get_index_now_api_key(): string
	{
		$api_key = (string)$this->wposa->get_option('api_key', 'index_now', '');

		if ($api_key === '') {
			$api_key = Utils::generate_key();
			$this->wposa->set_option('api_key', $api_key, 'index_now');
		}

		return $api_key;
	}

	public function get_yandex_webmaster_host_ids()
	{
		$result = [];

		// Stored as a plain array; maybe_unserialize keeps backward compatibility
		// with previously serialized values.
		$ids = maybe_unserialize($this->wposa->get_option('host_ids', 'yandex_webmaster'));

		if (is_array($ids)) {
			foreach ($ids as $id) {
				$result[$id] = $id;
			}
		}

		return $result;
	}
}
