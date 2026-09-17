<?php

declare(strict_types=1);

namespace Mihdan\IndexNow\SEOCore\Wizard;

use Mihdan\IndexNow\SEOCore\FeatureGate\FeatureGate;
use Mihdan\IndexNow\SEOCore\Importer\Runner;
use Mihdan\IndexNow\SEOCore\SiteInfoSettings\SiteInfoSettings;
use Mihdan\IndexNow\SEOCore\TitleMeta\Entities;
use Mihdan\IndexNow\SEOCore\TitleMeta\Options;
use Mihdan\IndexNow\SEOCore\TitleMeta\Variables;
use Mihdan\IndexNow\Utils;

class Wizard
{
	public const MENU_SLUG = 'crawlwp-setup-wizard';
	public const REDIRECT_TRANSIENT = 'crawlwp_seo_wizard_redirect';
	public const COMPLETED_OPTION = 'crawlwp_seo_wizard_completed';
	public const DISMISSED_OPTION = 'crawlwp_seo_wizard_dismissed';

	public function __construct()
	{
		add_action('admin_menu', [$this, 'register_menu_page'], 20);
		add_action('admin_init', [$this, 'maybe_redirect_to_wizard']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
		add_action('admin_post_crawlwp_enable_and_launch_wizard', [$this, 'handle_enable_and_launch']);

		// AJAX endpoints
		add_action('wp_ajax_crawlwp_wizard_save_step', [$this, 'ajax_save_step']);
		add_action('wp_ajax_crawlwp_wizard_generate_key', [$this, 'ajax_generate_key']);
		add_action('wp_ajax_crawlwp_wizard_deactivate_plugin', [$this, 'ajax_deactivate_plugin']);
		add_action('wp_ajax_crawlwp_wizard_dismiss_notice', [$this, 'ajax_dismiss_notice']);
		add_action('wp_ajax_crawlwp_wizard_finish', [$this, 'ajax_finish']);
	}

	public static function wizard_url(string $step = ''): string
	{
		$url = admin_url('admin.php?page=' . self::MENU_SLUG);

		if ($step !== '') {
			$url = add_query_arg('step', $step, $url);
		}

		return $url;
	}

	public function register_menu_page(): void
	{
		add_submenu_page(
			null,
			__('Getting Started Wizard', 'mihdan-index-now'),
			__('Setup Wizard', 'mihdan-index-now'),
			'manage_options',
			self::MENU_SLUG,
			[$this, 'render_page']
		);
	}

	public function maybe_redirect_to_wizard(): void
	{
		if (! get_transient(self::REDIRECT_TRANSIENT)) {
			return;
		}

		delete_transient(self::REDIRECT_TRANSIENT);

		if (
			! current_user_can('manage_options')
			|| wp_doing_ajax()
			|| wp_doing_cron()
		) {
			return;
		}

		$current_page = sanitize_text_field(wp_unslash($_GET['page'] ?? ''));

		if ($current_page === self::MENU_SLUG) {
			return;
		}

		wp_safe_redirect(self::wizard_url());
		exit;
	}

	public function handle_enable_and_launch(): void
	{
		check_admin_referer('crawlwp_enable_wizard');

		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'mihdan-index-now'));
		}

		FeatureGate::enable();

		wp_safe_redirect(self::wizard_url());
		exit;
	}

	public function maybe_show_admin_notice(): void
	{
		if (! current_user_can('manage_options') || ! FeatureGate::is_enabled()) {
			return;
		}

		if (get_option(self::COMPLETED_OPTION) || get_option(self::DISMISSED_OPTION)) {
			return;
		}

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;

		if (! $screen || strpos($screen->id, 'crawlwp') === false) {
			return;
		}

		if (strpos($screen->id, self::MENU_SLUG) !== false) {
			return;
		}

		$wizard_url = esc_url(self::wizard_url());
		$nonce      = wp_create_nonce('crawlwp_wizard_nonce');

		?>
		<div class="notice notice-info is-dismissible cwp-wizard-notice" data-nonce="<?php echo esc_attr($nonce); ?>">
			<p>
				<strong><?php esc_html_e('Welcome to CrawlWP On-Page SEO!', 'mihdan-index-now'); ?></strong>
				<?php esc_html_e('Complete the Getting Started Wizard to configure your basic SEO settings and import data from active SEO plugins.', 'mihdan-index-now'); ?>
			</p>
			<p>
				<a href="<?php echo $wizard_url; ?>" class="button button-primary">
					<?php esc_html_e('Launch Setup Wizard', 'mihdan-index-now'); ?>
				</a>
				<button type="button" class="button-link cwp-wizard-notice-dismiss" style="margin-left: 12px; vertical-align: middle;">
					<?php esc_html_e('I will set up manually', 'mihdan-index-now'); ?>
				</button>
			</p>
		</div>
		<script>
		jQuery(function($) {
			$(document).on('click', '.cwp-wizard-notice .notice-dismiss, .cwp-wizard-notice-dismiss', function(e) {
				var $notice = $(this).closest('.cwp-wizard-notice');
				$.post(ajaxurl, {
					action: 'crawlwp_wizard_dismiss_notice',
					nonce: $notice.data('nonce')
				});
				$notice.fadeTo(100, 0, function() { $notice.slideUp(100, function() { $notice.remove(); }); });
			});
		});
		</script>
		<?php
	}

	public function enqueue_assets(string $hook): void
	{
		if (strpos($hook, self::MENU_SLUG) === false) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'crawlwp-wizard',
			CRAWLWP_PLUGIN_URL . 'src/SEOCore/Wizard/assets/wizard.css',
			['dashicons'],
			CRAWLWP_VERSION
		);

		wp_enqueue_script(
			'crawlwp-wizard',
			CRAWLWP_PLUGIN_URL . 'src/SEOCore/Wizard/assets/wizard.js',
			['jquery'],
			CRAWLWP_VERSION,
			true
		);

		$sources = Runner::inventory();

		wp_localize_script('crawlwp-wizard', 'crawlwpWizard', [
			'ajaxUrl'       => admin_url('admin-ajax.php'),
			'nonce'         => wp_create_nonce('crawlwp_wizard_nonce'),
			'importerNonce' => wp_create_nonce('crawlwp_importer_nonce'),
			'dashboardUrl'  => admin_url('admin.php?page=crawlwp'),
			'settingsUrl'   => admin_url('admin.php?page=crawlwp&wposa-menu=crawlwp_title_meta'),
			'firstStage'    => Runner::first_stage(),
			'sources'       => $sources,
			'stageLabels'   => [
				'settings'  => __('Global Settings', 'mihdan-index-now'),
				'posts'     => __('Posts & Pages', 'mihdan-index-now'),
				'terms'     => __('Categories & Tags', 'mihdan-index-now'),
				'users'     => __('Authors', 'mihdan-index-now'),
				'redirects' => __('Redirects', 'mihdan-index-now'),
			],
			'i18n'          => [
				'running'         => __('Import in progress…', 'mihdan-index-now'),
				'imported'        => __('imported', 'mihdan-index-now'),
				'skipped'         => __('skipped', 'mihdan-index-now'),
				'done'            => __('Import complete!', 'mihdan-index-now'),
				'error'           => __('Import failed. Please try again.', 'mihdan-index-now'),
				'confirmDeact'    => __('Are you sure you want to deactivate this plugin? CrawlWP has successfully imported its SEO data.', 'mihdan-index-now'),
				'deactivating'    => __('Deactivating…', 'mihdan-index-now'),
				'deactivated'     => __('Deactivated successfully!', 'mihdan-index-now'),
				'saving'          => __('Saving…', 'mihdan-index-now'),
				'saved'           => __('Saved!', 'mihdan-index-now'),
				'chooseLogo'      => __('Choose Logo', 'mihdan-index-now'),
				'useLogo'         => __('Use this image', 'mihdan-index-now'),
				'generatingKey'   => __('Generating…', 'mihdan-index-now'),
				'copied'          => __('Copied!', 'mihdan-index-now'),
			],
		]);
	}

	public function ajax_save_step(): void
	{
		check_ajax_referer('crawlwp_wizard_nonce', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Unauthorized', 'mihdan-index-now')]);
		}

		$step = sanitize_text_field(wp_unslash($_POST['wizard_step'] ?? ''));

		if ($step === 'site_info') {
			$site_info = get_option('crawlwp_site_info', []);
			$site_info = is_array($site_info) ? $site_info : [];

			$site_type = sanitize_text_field(wp_unslash($_POST['site_type'] ?? 'organization'));
			$site_info['site_type'] = in_array($site_type, ['organization', 'person'], true) ? $site_type : 'organization';

			if (isset($_POST['site_name'])) {
				$site_info['site_name'] = sanitize_text_field(wp_unslash($_POST['site_name']));
			}
			if (isset($_POST['site_description'])) {
				$site_info['site_description'] = sanitize_text_field(wp_unslash($_POST['site_description']));
			}
			if (isset($_POST['logo'])) {
				$site_info['logo'] = esc_url_raw(wp_unslash($_POST['logo']));
			}

			update_option('crawlwp_site_info', $site_info);

			wp_send_json_success(['message' => __('Site Information saved.', 'mihdan-index-now')]);
		}

		if ($step === 'search_appearance') {
			$separator = sanitize_text_field(wp_unslash($_POST['separator'] ?? '-'));
			$home_tm   = get_option('crawlwp_tm_home', []);
			$home_tm   = is_array($home_tm) ? $home_tm : [];

			$home_tm['separator'] = $separator;

			if (! empty($_POST['home_title'])) {
				$home_tm['title'] = sanitize_text_field(wp_unslash($_POST['home_title']));
			}
			if (! empty($_POST['home_description'])) {
				$home_tm['description'] = sanitize_text_field(wp_unslash($_POST['home_description']));
			}

			update_option('crawlwp_tm_home', $home_tm);

			// Post type indexing
			$post_types  = get_post_types(['public' => true], 'objects');
			$indexed_pts = isset($_POST['indexed_post_types']) && is_array($_POST['indexed_post_types'])
				? array_map('sanitize_text_field', wp_unslash($_POST['indexed_post_types']))
				: ['post', 'page'];

			foreach ($post_types as $pt_name => $pt_obj) {
				if ($pt_name === 'attachment') {
					continue;
				}

				$opt_key = 'crawlwp_tm_pt_' . $pt_name;
				$pt_tm   = get_option($opt_key, []);
				$pt_tm   = is_array($pt_tm) ? $pt_tm : [];

				$pt_tm['noindex'] = in_array($pt_name, $indexed_pts, true) ? 'off' : 'on';
				update_option($opt_key, $pt_tm);
			}

			Options::flush_cache();

			wp_send_json_success(['message' => __('Search appearance settings saved.', 'mihdan-index-now')]);
		}

		if ($step === 'index_now') {
			$index_now_opts = get_option('crawlwp_index_now', []);
			$index_now_opts = is_array($index_now_opts) ? $index_now_opts : [];

			$enabled = isset($_POST['index_now_enable']) && (string) $_POST['index_now_enable'] === '1' ? 'on' : 'off';
			$index_now_opts['enable'] = $enabled;

			$api_key = sanitize_text_field(wp_unslash($_POST['api_key'] ?? ''));
			if ($api_key === '') {
				$api_key = Utils::generate_key();
			}
			$index_now_opts['api_key'] = $api_key;

			$allowed_engines = ['bing-index-now', 'index-now', 'yandex-index-now', 'seznam-index-now', 'naver-index-now'];
			$search_engine   = sanitize_text_field(wp_unslash($_POST['search_engine'] ?? 'bing-index-now'));
			if (in_array($search_engine, $allowed_engines, true)) {
				$index_now_opts['search_engine'] = $search_engine;
			}

			update_option('crawlwp_index_now', $index_now_opts);

			// Submission triggers and post types in crawlwp_general
			$general_opts = get_option('crawlwp_general', []);
			$general_opts = is_array($general_opts) ? $general_opts : [];

			$raw_pts = isset($_POST['submission_post_types']) && is_array($_POST['submission_post_types'])
				? array_map('sanitize_text_field', wp_unslash($_POST['submission_post_types']))
				: ['post', 'page'];

			$post_types_map = [];
			foreach ($raw_pts as $pt) {
				if (! empty($pt)) {
					$post_types_map[$pt] = $pt;
				}
			}
			$general_opts['post_types'] = $post_types_map;

			$general_opts['ping_on_post']         = isset($_POST['ping_on_post']) && (string) $_POST['ping_on_post'] === '1' ? 'on' : 'off';
			$general_opts['ping_on_post_updated'] = isset($_POST['ping_on_post_updated']) && (string) $_POST['ping_on_post_updated'] === '1' ? 'on' : 'off';

			update_option('crawlwp_general', $general_opts);

			wp_send_json_success(['message' => __('Indexing submission settings saved.', 'mihdan-index-now')]);
		}

		wp_send_json_error(['message' => __('Invalid step.', 'mihdan-index-now')]);
	}

	public function ajax_generate_key(): void
	{
		check_ajax_referer('crawlwp_wizard_nonce', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Unauthorized', 'mihdan-index-now')]);
		}

		$key     = Utils::generate_key();
		$key_url = trailingslashit(Utils::normalized_home_url()) . $key . '.txt';

		wp_send_json_success([
			'api_key' => $key,
			'key_url' => $key_url,
		]);
	}

	public function ajax_deactivate_plugin(): void
	{
		check_ajax_referer('crawlwp_wizard_nonce', 'nonce');

		if (! current_user_can('activate_plugins')) {
			wp_send_json_error(['message' => __('Unauthorized permission to deactivate plugins.', 'mihdan-index-now')]);
		}

		$source_id = sanitize_text_field(wp_unslash($_POST['source_id'] ?? ''));
		$file      = Runner::get_active_plugin_file($source_id);

		if (! $file) {
			wp_send_json_error(['message' => __('Plugin is not active or unrecognized.', 'mihdan-index-now')]);
		}

		if (! function_exists('deactivate_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		deactivate_plugins($file);

		wp_send_json_success([
			'message'   => __('Plugin deactivated successfully.', 'mihdan-index-now'),
			'source_id' => $source_id,
		]);
	}

	public function ajax_dismiss_notice(): void
	{
		check_ajax_referer('crawlwp_wizard_nonce', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error();
		}

		update_option(self::DISMISSED_OPTION, 1);
		wp_send_json_success();
	}

	public function ajax_finish(): void
	{
		check_ajax_referer('crawlwp_wizard_nonce', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error();
		}

		update_option(self::COMPLETED_OPTION, 1);
		wp_send_json_success();
	}

	public function render_page(): void
	{
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'mihdan-index-now'));
		}

		// Ensure on-page SEO is active if visiting the wizard directly
		if (! FeatureGate::is_enabled()) {
			FeatureGate::enable();
		}

		$inventory      = Runner::inventory();
		$active_sources = array_filter($inventory, static fn(array $src): bool => ! empty($src['active']));
		$avail_sources  = array_filter($inventory, static fn(array $src): bool => ! empty($src['available']));

		$site_info = get_option('crawlwp_site_info', []);
		$site_info = is_array($site_info) ? $site_info : [];

		$home_tm = get_option('crawlwp_tm_home', []);
		$home_tm = is_array($home_tm) ? $home_tm : [];

		$separators = Variables::separator_choices();
		$current_sep = $home_tm['separator'] ?? '-';

		$post_types = get_post_types(['public' => true], 'objects');
		unset($post_types['attachment']);

		// IndexNow settings & state
		$index_now_opts = get_option('crawlwp_index_now', []);
		$index_now_opts = is_array($index_now_opts) ? $index_now_opts : [];

		$general_opts   = get_option('crawlwp_general', []);
		$general_opts   = is_array($general_opts) ? $general_opts : [];

		$index_now_enabled = ($index_now_opts['enable'] ?? 'on') === 'on';
		$api_key           = ! empty($index_now_opts['api_key']) ? (string) $index_now_opts['api_key'] : Utils::generate_key();

		if (empty($index_now_opts['api_key'])) {
			$index_now_opts['api_key'] = $api_key;
			update_option('crawlwp_index_now', $index_now_opts);
		}

		$current_engine       = $index_now_opts['search_engine'] ?? 'bing-index-now';
		$ping_on_post         = ($general_opts['ping_on_post'] ?? 'on') === 'on';
		$ping_on_post_updated = ($general_opts['ping_on_post_updated'] ?? 'on') === 'on';

		$sub_post_types = isset($general_opts['post_types']) && is_array($general_opts['post_types'])
			? array_filter($general_opts['post_types'])
			: ['post' => 'post', 'page' => 'page'];

		$key_location = trailingslashit(Utils::normalized_home_url()) . $api_key . '.txt';

		$search_engines = [
			'bing-index-now'   => [
				'label' => __('Bing (Recommended)', 'mihdan-index-now'),
				'desc'  => __('Recommended. Submissions to Bing automatically notify all IndexNow partner search engines (Bing, Yandex, Seznam, Naver).', 'mihdan-index-now'),
				'badge' => __('Recommended', 'mihdan-index-now'),
			],
			'index-now'        => [
				'label' => __('IndexNow.org', 'mihdan-index-now'),
				'desc'  => __('Submits directly to the central IndexNow.org API endpoint.', 'mihdan-index-now'),
				'badge' => '',
			],
			'yandex-index-now' => [
				'label' => __('Yandex', 'mihdan-index-now'),
				'desc'  => __('Submits directly to the Yandex IndexNow endpoint.', 'mihdan-index-now'),
				'badge' => '',
			],
			'seznam-index-now' => [
				'label' => __('Seznam', 'mihdan-index-now'),
				'desc'  => __('Submits directly to Seznam.cz (Czech Republic).', 'mihdan-index-now'),
				'badge' => '',
			],
			'naver-index-now'  => [
				'label' => __('Naver', 'mihdan-index-now'),
				'desc'  => __('Submits directly to Naver search engine (South Korea).', 'mihdan-index-now'),
				'badge' => '',
			],
		];

		$current_step = sanitize_text_field(wp_unslash($_GET['step'] ?? 'welcome'));
		$allowed_steps = ['welcome', 'import', 'site_info', 'search_appearance', 'index_now', 'ready'];

		if (! in_array($current_step, $allowed_steps, true)) {
			$current_step = 'welcome';
		}

		include __DIR__ . '/views/wizard-page.php';
	}
}
