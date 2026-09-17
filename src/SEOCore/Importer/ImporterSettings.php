<?php

namespace Mihdan\IndexNow\SEOCore\Importer;

use Mihdan\IndexNow\Utils;
use Mihdan\IndexNow\Views\WPOSA;

/**
 * "Import SEO Data" settings section under Advanced.
 */
class ImporterSettings
{
	const SECTION = 'importer';

	public function __construct()
	{
		add_action('crawlwp_setup_fields', [$this, 'settings_fields'], 99, 2);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
		add_action('wp_ajax_crawlwp_import_inventory', [$this, 'ajax_inventory']);
		add_action('wp_ajax_crawlwp_import_step', [$this, 'ajax_step']);
	}

	public function settings_fields(WPOSA $wposa, $settingsInstance): void
	{
		if ($wposa->get_active_header_menu() !== Utils::get_plugin_prefix() . '_advanced_settings') {
			return;
		}

		$wposa->add_section([
			'header_menu_id' => 'advanced_settings',
			'id'             => self::SECTION,
			'title'          => __('Import SEO Data', 'mihdan-index-now'),
			'desc'           => __('Copy global settings, titles, descriptions, robots, canonicals, social images, author meta and redirects from another SEO plugin into CrawlWP. Existing CrawlWP values are kept unless you tick overwrite.', 'mihdan-index-now'),
		]);

		$wposa->add_field(self::SECTION, [
			'id'   => 'manager_ui',
			'type' => 'html',
			'name' => '',
			'desc' => $this->render_ui(),
		]);
	}

	public function enqueue_assets(string $hook): void
	{
		$screen = get_current_screen();

		if (! $screen || strpos($screen->id, 'crawlwp') === false) {
			return;
		}

		$assets_url = CRAWLWP_PLUGIN_URL . 'src/SEOCore/Importer/assets/';

		wp_enqueue_style('crawlwp-importer', $assets_url . 'importer.css', [], '1.0.0');
		wp_enqueue_script('crawlwp-importer', $assets_url . 'importer.js', ['jquery'], '1.0.0', true);

		wp_localize_script('crawlwp-importer', 'crawlwpImporter', [
			'ajaxUrl'    => admin_url('admin-ajax.php'),
			'nonce'      => wp_create_nonce('crawlwp_importer_nonce'),
			'firstStage' => Runner::first_stage(),
			'stages'     => Runner::STAGES,
			'i18n'       => [
				'running'   => __('Importing…', 'mihdan-index-now'),
				'done'      => __('Import complete.', 'mihdan-index-now'),
				'error'     => __('Import failed. Please try again.', 'mihdan-index-now'),
				'none'      => __('No importable data found from this plugin.', 'mihdan-index-now'),
				'confirm'   => __('Start importing SEO data from this plugin?', 'mihdan-index-now'),
				'imported'  => __('Imported', 'mihdan-index-now'),
				'skipped'   => __('Skipped', 'mihdan-index-now'),
				'posts'     => __('posts', 'mihdan-index-now'),
				'terms'     => __('terms', 'mihdan-index-now'),
				'users'     => __('authors', 'mihdan-index-now'),
				'redirects' => __('redirects', 'mihdan-index-now'),
			],
			'stageLabels' => [
				'settings'  => __('Global settings', 'mihdan-index-now'),
				'posts'     => __('Posts', 'mihdan-index-now'),
				'terms'     => __('Terms', 'mihdan-index-now'),
				'users'     => __('Authors', 'mihdan-index-now'),
				'redirects' => __('Redirects', 'mihdan-index-now'),
			],
		]);
	}

	public function ajax_inventory(): void
	{
		$this->guard();
		wp_send_json_success(['sources' => Runner::inventory()]);
	}

	public function ajax_step(): void
	{
		$this->guard();

		$source    = isset($_POST['source']) ? sanitize_key(wp_unslash($_POST['source'])) : '';
		$stage     = isset($_POST['stage']) ? sanitize_key(wp_unslash($_POST['stage'])) : Runner::first_stage();
		$offset    = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
		$overwrite = ! empty($_POST['overwrite']);

		if (! in_array($stage, Runner::STAGES, true)) {
			$stage = Runner::first_stage();
		}

		$result = Runner::run_step($source, $stage, $offset, $overwrite);

		if (empty($result['ok'])) {
			wp_send_json_error($result);
		}

		wp_send_json_success($result);
	}

	private function guard(): void
	{
		if (! current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Permission denied.', 'mihdan-index-now')], 403);
		}

		check_ajax_referer('crawlwp_importer_nonce', 'nonce');
	}

	private function render_ui(): string
	{
		ob_start();
		?>
		<div class="cwp-importer" id="cwpImporter">
			<div style="margin-bottom: 18px; padding: 12px 16px; background: #f0f6fc; border: 1px solid #c8d8f0; border-radius: 6px;">
				<p style="margin: 0 0 4px; font-weight: 600; color: #1d2327;">
					<?php esc_html_e('Prefer a guided walkthrough?', 'mihdan-index-now'); ?>
				</p>
				<p style="margin: 0 0 10px; font-size: 13px; color: #50575e;">
					<?php esc_html_e('The Getting Started Wizard walks you through importing from active SEO plugins and setting up basic site schema and search defaults.', 'mihdan-index-now'); ?>
				</p>
				<a href="<?php echo esc_url(\Mihdan\IndexNow\SEOCore\Wizard\Wizard::wizard_url()); ?>" class="button button-secondary">
					<?php esc_html_e('Launch Setup Wizard →', 'mihdan-index-now'); ?>
				</a>
			</div>
			<p class="description">
				<?php esc_html_e('Detected plugins appear below even if they are currently deactivated, as long as their data is still in the database.', 'mihdan-index-now'); ?>
			</p>
			<label class="cwp-importer-overwrite">
				<input type="checkbox" id="cwpImporterOverwrite" value="1">
				<?php esc_html_e('Overwrite existing CrawlWP values', 'mihdan-index-now'); ?>
			</label>
			<div class="cwp-importer-list" id="cwpImporterList">
				<p><?php esc_html_e('Loading sources…', 'mihdan-index-now'); ?></p>
			</div>
			<div class="cwp-importer-log" id="cwpImporterLog" style="display:none"></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
