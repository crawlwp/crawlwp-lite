<?php

namespace Mihdan\IndexNow;

use Mihdan\IndexNow\Logger\Logger;
use Mihdan\IndexNow\Migrations\Migrations;
use Mihdan\IndexNow\Providers\Bing\BingIndexNow;
use Mihdan\IndexNow\Providers\Bing\BingWebmaster;
use Mihdan\IndexNow\Providers\Google\GoogleWebmaster;
use Mihdan\IndexNow\Providers\IndexNow\IndexNow;
use Mihdan\IndexNow\Providers\Seznam\SeznamIndexNow;
use Mihdan\IndexNow\Providers\Naver\NaverIndexNow;
use Mihdan\IndexNow\Providers\Yandex\YandexIndexNow;
use Mihdan\IndexNow\Providers\Yandex\YandexWebmaster;
use Mihdan\IndexNow\SEOCore\FeatureGate\FeatureGate;
use Mihdan\IndexNow\SEOCore\Notifications\Notifications;
use Mihdan\IndexNow\SEOCore\SEOCoreInit;
use Mihdan\IndexNow\Views\Settings;
use Mihdan\IndexNow\Views\UpsellAdminPages;
use Mihdan\IndexNow\Views\WPOSA;
use WP_List_Table;
use WP_Site;

class Main
{
	/**
	 * DIC container.
	 *
	 * @var Container $container
	 */
	public $container;

	/**
	 * Settings instance.
	 *
	 * @var WPOSA $wposa
	 */
	private $wposa;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private $logger;

	public function __construct(Container $container)
	{
		$this->container = $container;
	}

	public function init()
	{
		$this->load_requirements();
		$this->setup_hooks();

		SEOCoreInit::get_instance();

		do_action('crawlwp/init', $this);
	}

	/**
	 * @param string $class_name
	 *
	 * @return mixed
	 * @throws \Exception
	 *
	 * @todo remove in future as this was a helper to prevent fatal error when upgrading
	 *
	 */
	public function make(string $class_name)
	{
		return $this->container->make($class_name);
	}

	private function load_requirements()
	{
		if (!function_exists('dbDelta')) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		BackgroundProcess\Dispatch::get_instance();

		$this->logger = $this->container->make(Logger::class);

		$this->container->set(
			WPOSA::class,
			function (Container $c) {
				return new WPOSA(
					Utils::get_plugin_name(),
					Utils::get_plugin_version(),
					Utils::get_plugin_slug(),
					Utils::get_plugin_prefix(),
					''
				);
			}
		);

		$this->wposa = $this->container->get(WPOSA::class);
		$this->wposa->setup_hooks();

		($this->container->make(Hooks::class))->setup_hooks();

		($this->container->make(Settings::class))->setup_hooks();
		($this->container->make(Cron::class))->setup_hooks();
		($this->container->make(YandexIndexNow::class))->setup_hooks();
		($this->container->make(BingIndexNow::class))->setup_hooks();
		($this->container->make(SeznamIndexNow::class))->setup_hooks();
		($this->container->make(NaverIndexNow::class))->setup_hooks();
		($this->container->make(IndexNow::class))->setup_hooks();

		add_action('plugins_loaded', function () {
			if (!defined('CRAWLWP_DETACH_LIBSODIUM')) {
				($this->container->make(UpsellAdminPages::class));
			}
		});

		$GLOBALS['CRAWLWP_YANDEX_WEBMASTER'] = $this->container->make(YandexWebmaster::class);
		$GLOBALS['CRAWLWP_BING_WEBMASTER'] = $this->container->make(BingWebmaster::class);
		$GLOBALS['CRAWLWP_GOOGLE_WEBMASTER'] = $this->container->make(GoogleWebmaster::class);

		$GLOBALS['CRAWLWP_YANDEX_WEBMASTER']->setup_hooks();
		$GLOBALS['CRAWLWP_BING_WEBMASTER']->setup_hooks();
		$GLOBALS['CRAWLWP_GOOGLE_WEBMASTER']->setup_hooks();
	}

	/**
	 * Setup hooks.
	 */
	public function setup_hooks()
	{
		add_filter('plugin_action_links', [$this, 'add_settings_link'], 10, 2);
		add_filter('set_screen_option_logs_per_page', [$this, 'set_screen_option'], 10, 3);
		add_action('admin_init', [$this, 'maybe_upgrade']);

		add_filter('removable_query_args', [$this, 'removable_query_args']);

		if (class_exists('\Mihdan\IndexNow\Dependencies\PAnD')) {
			// persist admin notice dismissal initialization
			add_action('admin_init', ['\Mihdan\IndexNow\Dependencies\PAnD', 'init']);
			add_action('wp_ajax_dismiss_admin_notice', ['\Mihdan\IndexNow\Dependencies\PAnD', 'dismiss_admin_notice']);
		}

		// Add last update column. Registered late so the filtered post types are used.
		add_action('admin_init', [$this, 'register_last_update_column']);

		register_activation_hook(CRAWLWP_FILE, [$this, 'activate_plugin']);
		register_deactivation_hook(CRAWLWP_FILE, [$this, 'deactivate_plugin']);

		// Multisite.
		add_filter('wpmu_drop_tables', [$this, 'drop_site_tables'], 10, 2);
		add_action('wp_insert_site', [$this, 'add_site_tables']);
	}

	public function register_last_update_column(): void
	{
		if ( ! Indexing::is_on('show_last_update_column', 'general')) {
			return;
		}

		foreach (Indexing::get_post_types() as $post_type) {
			add_filter("manage_{$post_type}_posts_columns", [$this, 'add_last_update_column']);
			add_action("manage_{$post_type}_posts_custom_column", [$this, 'add_last_update_column_content'], 10, 2);
		}

		add_action('admin_head', [$this, 'add_css_for_column']);
	}

	public function removable_query_args($args = [])
	{
		$args[] = 'settings-updated';
		$args[] = 'settings-added';
		$args[] = 'license';

		return $args;
	}

	/**
	 * Drop the plugin tables together with the core tables when a site is deleted.
	 *
	 * @param string[] $tables  Tables WordPress is about to drop.
	 * @param int      $site_id Site ID.
	 *
	 * @return string[]
	 */
	public function drop_site_tables($tables, $site_id = 0): array
	{
		global $wpdb;

		$prefix = $wpdb->get_blog_prefix((int)$site_id);

		foreach (['crawlwp_log', 'crawlwp_redirects', 'crawlwp_404_log', 'index_now_log'] as $table) {
			$tables[$table] = $prefix . $table;
		}

		return (array)$tables;
	}

	/**
	 * Add site tables when creating a site.
	 *
	 * @param WP_Site $new_site Site ID.
	 *
	 * @return void
	 */
	public function add_site_tables(WP_Site $new_site): void
	{
		switch_to_blog($new_site->id);
		$this->create_tables();
		restore_current_blog();
	}

	public function add_css_for_column(): void
	{
		?>
		<style>
			.column-crawlwp_last_update {
				width: 8em;
			}

			.column-crawlwp_last_update img {
				vertical-align: bottom;
			}
		</style>
		<?php
	}

	public function add_last_update_column(array $columns): array
	{
		$columns['crawlwp_last_update'] = sprintf(
			'<span class="dashicons dashicons-share" title="%s"></span>',
			__('CrawlWP: Last Index Submission Date', 'mihdan-index-now')
		);

		return $columns;
	}

	public function add_last_update_column_content(string $column_name, int $post_id): void
	{
		if ($column_name !== 'crawlwp_last_update') {
			return;
		}

		$last_update = (int)get_post_meta($post_id, Indexing::LAST_UPDATE_META, true);

		if ($last_update === 0) {
			return;
		}

		echo esc_html(date('d.m.Y H:i', $last_update));
	}

	/**
	 * Set screen option.
	 *
	 * @param string $status Status.
	 * @param string $option Option name.
	 * @param string $value Option value.
	 *
	 * @return int
	 */
	public function set_screen_option($status, $option, $value): int
	{
		return (int)$value;
	}

	/**
	 * Fired on plugin activate.
	 */
	public function activate_plugin($network_wide)
	{
		global $wpdb;

		if (is_multisite() && $network_wide) {
			$this->for_each_site(function () {
				$this->create_tables();
				$this->activate_site();
			});
		} else {
			$this->create_tables();
			$this->activate_site();
		}
	}

	/**
	 * Fired on plugin deactivation: clear this plugin's scheduled events.
	 */
	public function deactivate_plugin($network_wide)
	{
		if (is_multisite() && $network_wide) {
			$this->for_each_site([$this, 'clear_scheduled_events']);
		} else {
			$this->clear_scheduled_events();
		}
	}

	public function clear_scheduled_events(): void
	{
		$bg_identifier = 'wp_' . get_current_blog_id() . '_crawlwp_bg_process';

		$hooks = [
			Cron::EVENT_NAME,
			Hooks::DELAYED_POST_EVENT,
			Hooks::DELAYED_TERM_EVENT,
			'crawlwp_backfill_robots_index_meta',
			'crawlwp_redirects_flush_hits',
			'crawlwp_404_prune',
			$bg_identifier . '_cron',
			$bg_identifier . '_cron_custom_healthcheck',
		];

		foreach ($hooks as $hook) {
			wp_unschedule_hook($hook);
		}
	}

	/**
	 * Run a callback on every site of the network, paging through them.
	 */
	private function for_each_site(callable $callback): void
	{
		$offset = 0;

		do {
			$site_ids = get_sites(['fields' => 'ids', 'number' => 100, 'offset' => $offset]);

			foreach ($site_ids as $site_id) {
				switch_to_blog($site_id);
				$callback();
				restore_current_blog();
			}

			$offset += 100;
		} while (count($site_ids) === 100);
	}

	/**
	 * Per-site activation tasks.
	 *
	 * Persists the SEO feature-gate default once (so FeatureGate::is_enabled()
	 * stays a side-effect-free read on every later request) and rebuilds the
	 * rewrite rules the virtual llms.txt endpoint relies on.
	 */
	private function activate_site(): void
	{
		FeatureGate::maybe_persist_default();
		add_option(Notifications::KNOWN_POST_TYPES_KEY, Notifications::get_accessible_post_types(), '', false);

		// Flushing here would store the rules of the request's own site (and,
		// after switch_to_blog(), the wrong site); WordPress rebuilds them on the next load instead.
		delete_option('rewrite_rules');
	}

	private function create_tables(bool $upgrade = false)
	{
		global $wpdb;

		$table_name = $wpdb->prefix . 'crawlwp_log';
		$charset_collate = $wpdb->get_charset_collate();

		if ($upgrade || $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
			$sql = "CREATE TABLE {$wpdb->prefix}crawlwp_log (
    			log_id bigint(20) NOT NULL AUTO_INCREMENT,
    			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
    			level varchar(255) NOT NULL DEFAULT 'debug',
    			search_engine varchar(255) NOT NULL DEFAULT 'site',
    			direction varchar(255) NOT NULL DEFAULT 'incoming',
    			status_code INT(11) NOT NULL DEFAULT 0,
    			message text NOT NULL,
    			PRIMARY KEY (log_id)
				) {$charset_collate};";

			dbDelta($sql);

			Utils::set_db_version(Utils::get_plugin_version());
		}
	}

	public function maybe_upgrade()
	{
		DBUpdates::get_instance()->maybe_update();

		// Upgrades from a version that predates the feature gate never had the
		// option written; record the decision now instead of on every request.
		FeatureGate::maybe_persist_default();

		$db_version = Utils::get_db_version();
		$plugin_version = Utils::get_plugin_version();

		if (version_compare($db_version, $plugin_version, '<')) {
			$this->create_tables(true);
			flush_rewrite_rules();
		}
	}

	/**
	 * Render log menu page for dashboard.
	 */
	public function render_log_page()
	{
		?>
		<div class="wrap">
			<h2><?php esc_html_e(get_admin_page_title(), 'mihdan-index-now'); ?></h2>
			<form action="" method="post">
				<?php
				/**
				 * WP_List_table.
				 *
				 * @var WP_List_Table $table
				 */
				$table = $GLOBALS[CRAWLWP_PREFIX . '_log'];
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Add plugin action links
	 *
	 * @param array $actions Default actions.
	 * @param string $plugin_file Plugin file.
	 *
	 * @return array
	 */
	public function add_settings_link($actions, $plugin_file)
	{
		if (Utils::get_plugin_basename() === $plugin_file) {
			$actions[] = sprintf(
				'<a href="%s">%s</a>',
				admin_url('admin.php?page=' . Utils::get_plugin_slug()),
				esc_html__('Settings', 'mihdan-index-now')
			);
		}

		return $actions;
	}

	private function is_logging_enabled(): bool
	{
		return $this->wposa->get_option('enable', 'logs', 'on') === 'on';
	}
}
