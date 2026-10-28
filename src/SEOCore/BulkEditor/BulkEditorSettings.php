<?php

namespace Mihdan\IndexNow\SEOCore\BulkEditor;

use Mihdan\IndexNow\SEOCore\AI\Generator;
use Mihdan\IndexNow\SEOCore\MetaBox\MetaFields;
use Mihdan\IndexNow\SEOCore\MetaBox\PostListColumn;
use Mihdan\IndexNow\SEOCore\MetaBox\SeoSignals;
use Mihdan\IndexNow\SEOCore\TitleMeta\Entities;
use Mihdan\IndexNow\SEOCore\TitleMeta\Variables;
use Mihdan\IndexNow\Utils;
use Mihdan\IndexNow\Views\WPOSA;

/**
 * "SEO Bulk Editor" — edit the SEO title and meta description of many posts
 * at once from a single spreadsheet-like table.
 *
 * Registers a "Bulk Editor" section under the "Advanced" settings tab.
 * Renders a custom AJAX-powered table with a post type filter, a
 * "missing title/description" filter, search, inline-editable SEO Title /
 * Meta Description cells, and a single "Save Changes" action that persists
 * every edited row in one request.
 *
 * AJAX actions (all require edit_posts + nonce):
 *   crawlwp_bulk_editor_list — fetch table HTML + pagination
 *   crawlwp_bulk_editor_save — persist edited rows
 *   crawlwp_bulk_editor_preview — length of a value once its variables are resolved
 *   crawlwp_bulk_editor_images — image rows for the "Image alt text" view (ImageAlt)
 *   crawlwp_bulk_editor_ai — write one field with AI (BulkAI)
 */
class BulkEditorSettings
{
	/** Section / option id (without the crawlwp_ prefix). */
	const SECTION = 'bulk_editor';

	/** Recommended length hints (characters) — mirrors PostListColumn. */
	const TITLE_MAX = 60;
	const DESC_MAX  = 160;

	public function __construct()
	{
		add_action('crawlwp_setup_fields', [$this, 'settings_fields'], 90, 2);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

		add_action('wp_ajax_crawlwp_bulk_editor_list', [$this, 'ajax_list']);
		add_action('wp_ajax_crawlwp_bulk_editor_save', [$this, 'ajax_save']);
		add_action('wp_ajax_crawlwp_bulk_editor_preview', [$this, 'ajax_preview']);

		new BulkAI();
		new ImageAlt();
	}

	/**
	 * The "AI" button next to a field, or '' when no AI provider is connected
	 * (Settings > Connectors), since it could only ever fail.
	 */
	public static function ai_button(string $field): string
	{
		static $ready = null;

		if ($ready === null) {
			$ready = Generator::is_ready();
		}

		if (! $ready) {
			return '';
		}

		return '<button type="button" class="cwp-bulk-ai" data-ai-field="' . esc_attr($field) . '" title="' . esc_attr__('Write with AI', 'mihdan-index-now') . '">'
			. self::ai_icon(12)
			. esc_html__('AI', 'mihdan-index-now')
			. '</button>';
	}

	public static function ai_icon(int $size): string
	{
		return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2l2.09 6.26L20 10l-5.91 1.74L12 18l-2.09-6.26L4 10l5.91-1.74z"/><path d="M19 2l.87 2.61L22.5 5.5l-2.63.89L19 9l-.87-2.61L15.5 5.5l2.63-.89z"/></svg>';
	}

	// -------------------------------------------------------------------------
	// Settings registration
	// -------------------------------------------------------------------------

	public function settings_fields(WPOSA $wposa, $settingsInstance): void
	{
		if ($wposa->get_active_header_menu() !== Utils::get_plugin_prefix() . '_advanced_settings') {
			return;
		}

		$wposa->add_section([
			'header_menu_id' => 'advanced_settings',
			'id'             => self::SECTION,
			'title'          => __('Bulk Editor', 'mihdan-index-now'),
			'desc'           => __('Edit the SEO title and meta description of many posts, and the alt text of many images, at once, right from this table — no need to open each one individually.', 'mihdan-index-now'),
		]);

		$wposa->add_field(self::SECTION, [
			'id'   => 'manager_ui',
			'type' => 'html',
			'name' => '',
			'desc' => $this->render_ui(),
		]);
	}

	// -------------------------------------------------------------------------
	// Asset enqueueing
	// -------------------------------------------------------------------------

	/**
	 * Enqueue JS and CSS on CrawlWP admin pages only.
	 */
	public function enqueue_assets(string $hook): void
	{
		$screen = get_current_screen();

		if (! $screen || strpos($screen->id, 'crawlwp') === false) {
			return;
		}

		$assets_url = CRAWLWP_PLUGIN_URL . 'src/SEOCore/BulkEditor/assets/';

		wp_enqueue_style(
			'crawlwp-bulk-editor',
			$assets_url . 'bulk-editor.css',
			[],
			'1.1.0'
		);

		wp_enqueue_script(
			'crawlwp-bulk-editor',
			$assets_url . 'bulk-editor.js',
			['jquery'],
			'1.1.0',
			true
		);

		wp_localize_script('crawlwp-bulk-editor', 'crawlwpBulkEditor', [
			'ajaxUrl'   => admin_url('admin-ajax.php'),
			'nonce'     => wp_create_nonce('crawlwp_bulk_editor_nonce'),
			'variables' => $this->get_variables(),
			'aiReady'    => Generator::is_ready(),
			'batchAI'    => BulkAI::batch_enabled(),
			'upgradeUrl' => 'https://crawlwp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=crawlwp-bulk-ai',
			'connectUrl' => admin_url('options-connectors.php'),
			'aiIcon'     => self::ai_icon(18),
			'i18n'      => [
				'post'            => __('Post', 'mihdan-index-now'),
				'seoTitle'        => __('SEO Title', 'mihdan-index-now'),
				'metaDescription' => __('Meta Description', 'mihdan-index-now'),
				'image'           => __('Image', 'mihdan-index-now'),
				'altText'         => __('Alt text', 'mihdan-index-now'),
				'noImages'        => __('No images found.', 'mihdan-index-now'),
				/* translators: %s: number of images without alt text. */
				'missingAlt'      => __('%s missing', 'mihdan-index-now'),
				'aiWriting'       => __('Writing…', 'mihdan-index-now'),
				'aiLookingImage'  => __('Looking at the image…', 'mihdan-index-now'),
				'aiNotSaved'      => __('AI · not saved', 'mihdan-index-now'),
				'aiError'         => __('AI generation failed. Please try again.', 'mihdan-index-now'),
				'aiNotReady'      => __('Connect an AI provider in WordPress under Settings → Connectors to write with AI.', 'mihdan-index-now'),
				'aiConnect'       => __('Open Connectors', 'mihdan-index-now'),
				'aiPremium'       => __('Writing many titles, descriptions or alt texts at once is part of CrawlWP Premium. You can still use the AI button on each field.', 'mihdan-index-now'),
				'aiUpgrade'       => __('Upgrade to CrawlWP Premium', 'mihdan-index-now'),
				'aiDialogTitle'   => __('Write with AI', 'mihdan-index-now'),
				'aiWrite'         => __('Write', 'mihdan-index-now'),
				'aiFor'           => __('For', 'mihdan-index-now'),
				'aiExisting'      => __('Fields that already have text', 'mihdan-index-now'),
				'aiKeep'          => __('Leave them as they are', 'mihdan-index-now'),
				'aiRewrite'       => __('Rewrite them too', 'mihdan-index-now'),
				'aiTitles'        => __('SEO titles', 'mihdan-index-now'),
				'aiDescriptions'  => __('Meta descriptions', 'mihdan-index-now'),
				'aiAlts'          => __('Alt text', 'mihdan-index-now'),
				/* translators: %d: number of selected rows. */
				'aiSelected'      => __('The %d selected rows on this page', 'mihdan-index-now'),
				/* translators: %d: number of rows on this page. */
				'aiAllOnPage'     => __('All %d rows on this page', 'mihdan-index-now'),
				'aiPageHint'      => __('Show up to 100 rows per page to do more at once.', 'mihdan-index-now'),
				/* translators: %d: number of AI requests. */
				'aiNote'          => __('Up to %d requests to your AI provider (Settings → Connectors). The text is filled in for you to review. Nothing is saved until you click Save Table Changes.', 'mihdan-index-now'),
				/* translators: %d: number of fields. */
				'aiStart'         => __('Write %d fields', 'mihdan-index-now'),
				'aiNothing'       => __('Nothing to write: the chosen fields already have text. Choose "Rewrite them too" to replace it.', 'mihdan-index-now'),
				'cancel'          => __('Cancel', 'mihdan-index-now'),
				'stop'            => __('Stop', 'mihdan-index-now'),
				'aiProgressPosts' => __('Writing SEO titles and descriptions…', 'mihdan-index-now'),
				'aiProgressAlt'   => __('Writing alt text…', 'mihdan-index-now'),
				/* translators: 1: fields done, 2: total fields. */
				'aiProgressCount' => __('%1$d of %2$d', 'mihdan-index-now'),
				/* translators: 1: fields written, 2: fields that failed. */
				'aiDone'          => __('Wrote %1$d fields. %2$d could not be written. Review the text, then click Save Table Changes.', 'mihdan-index-now'),
				'aiStopped'       => __('Stopped. The text written so far is in the table, not saved yet.', 'mihdan-index-now'),
				'aiRateLimited'   => __('Stopped: too many AI requests for now. Wait a few minutes, then run it again for the rest.', 'mihdan-index-now'),
				'saving'          => __('Saving…', 'mihdan-index-now'),
				'saved'           => __('All changes saved.', 'mihdan-index-now'),
				'saveError'       => __('Some changes could not be saved. Please try again.', 'mihdan-index-now'),
				/* translators: %d: number of rows that could not be saved. */
				'rowsFailed'      => __('%d row(s) could not be saved — they are highlighted in the table.', 'mihdan-index-now'),
				'noItems'         => __('No posts found.', 'mihdan-index-now'),
				'confirmLeave'    => __('You have unsaved changes. Leave anyway?', 'mihdan-index-now'),
				'saveChanges'     => __('Save Table Changes', 'mihdan-index-now'),
				'insertVariable'  => __('Insert variable', 'mihdan-index-now'),
				'searchVariables' => __('Search variables…', 'mihdan-index-now'),
				'noVariables'     => __('No matching variables.', 'mihdan-index-now'),
				'loading'         => __('Loading…', 'mihdan-index-now'),
				'error'           => __('Something went wrong. Please try again.', 'mihdan-index-now'),
				'confirmDiscard'  => __('You have unsaved changes in the table. Discard them?', 'mihdan-index-now'),
				'resolvedLength'  => __('Length once variables are replaced with this post’s values.', 'mihdan-index-now'),
			],
		]);
	}

	/**
	 * Variable tokens offered by the "Insert variable" dropdown on the
	 * SEO Title / Meta Description cells — the same set available in the
	 * per-post metabox, so a template typed here behaves identically.
	 *
	 * @return array<int, array{token:string, desc:string}>
	 */
	private function get_variables(): array
	{
		return [
			['token' => 'post.title', 'desc' => __('Post title', 'mihdan-index-now')],
			['token' => 'site.title', 'desc' => __('Site name', 'mihdan-index-now')],
			['token' => 'sep', 'desc' => __('Separator', 'mihdan-index-now')],
			['token' => 'post.category', 'desc' => __('Primary category', 'mihdan-index-now')],
			['token' => 'post.auto_description', 'desc' => __('Post excerpt', 'mihdan-index-now')],
			['token' => 'current.year', 'desc' => __('Current year', 'mihdan-index-now')],
			['token' => 'post.author', 'desc' => __('Author name', 'mihdan-index-now')],
		];
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * Return rendered table rows for the current filters/page.
	 */
	public function ajax_list(): void
	{
		check_ajax_referer('crawlwp_bulk_editor_nonce', 'nonce');

		if (! current_user_can('edit_posts')) {
			wp_send_json_error(['message' => __('Permission denied.', 'mihdan-index-now')]);
		}

		$post_type = sanitize_key($_POST['post_type'] ?? '');
		$search    = sanitize_text_field($_POST['search'] ?? '');
		$filter    = sanitize_key($_POST['filter'] ?? 'all');
		$per_page  = max(1, min(200, (int) ($_POST['per_page'] ?? 20)));
		$page      = max(1, (int) ($_POST['page'] ?? 1));

		$post_types = $this->get_post_types();

		if ($post_type === '' || ! in_array($post_type, $post_types, true)) {
			$post_type = $post_types;
		}

		$args = [
			'post_type'           => $post_type,
			'post_status'         => ['publish', 'future', 'draft', 'pending', 'private'],
			'posts_per_page'      => $per_page,
			'paged'               => $page,
			'orderby'             => 'title',
			'order'               => 'ASC',
			'ignore_sticky_posts' => true,
			'perm'                => 'editable',
		];

		if ($search !== '') {
			$args['s'] = $search;
		}

		if ($filter === 'missing_title') {
			$args['meta_query'] = $this->missing_meta_query(MetaFields::SEO_TITLE); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		} elseif ($filter === 'missing_description') {
			$args['meta_query'] = $this->missing_meta_query(MetaFields::SEO_DESCRIPTION); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$query = new \WP_Query($args);

		wp_send_json_success([
			'html'  => $this->render_table_rows($query->posts),
			'total' => (int) $query->found_posts,
			'total_label' => sprintf(
				/* translators: %s: number of items. */
				_n('Total %s item.', 'Total %s items.', (int) $query->found_posts, 'mihdan-index-now'),
				number_format_i18n((int) $query->found_posts)
			),
			'page'  => $page,
			'pages' => max(1, (int) $query->max_num_pages),
		]);
	}

	/**
	 * Persist edited rows submitted from the table.
	 */
	public function ajax_save(): void
	{
		check_ajax_referer('crawlwp_bulk_editor_nonce', 'nonce');

		if (! current_user_can('edit_posts')) {
			wp_send_json_error(['message' => __('Permission denied.', 'mihdan-index-now')]);
		}

		$rows = $_POST['rows'] ?? [];

		if (! is_array($rows) || empty($rows)) {
			wp_send_json_error(['message' => __('Nothing to save.', 'mihdan-index-now')]);
		}

		if (sanitize_key(wp_unslash($_POST['type'] ?? '')) === 'images') {
			$this->send_save_result(ImageAlt::save($rows), true);
		}

		$saved  = [];
		$failed = [];

		foreach ($rows as $row) {
			$id = isset($row['id']) ? (int) $row['id'] : 0;

			if (! $id || ! get_post($id) || ! current_user_can('edit_post', $id)) {
				$failed[] = $id;
				continue;
			}

			$title = isset($row['seo_title']) ? sanitize_text_field(wp_unslash($row['seo_title'])) : '';
			$desc  = isset($row['seo_description']) ? sanitize_textarea_field(wp_unslash($row['seo_description'])) : '';

			$changed = $title !== (string) get_post_meta($id, MetaFields::SEO_TITLE, true)
				|| $desc !== (string) get_post_meta($id, MetaFields::SEO_DESCRIPTION, true);

			/* Empty values keep an empty meta row so the "missing" filters above
			   can use an indexed comparison. */
			MetaFields::save_optional($id, MetaFields::SEO_TITLE, $title);
			MetaFields::save_optional($id, MetaFields::SEO_DESCRIPTION, $desc);

			/* The title/description feed the post list SEO signals. */
			SeoSignals::flush($id);

			/* The stored editor score is now stale; the post list falls back to the estimate. */
			if ($changed) {
				PostListColumn::invalidate_score($id);
			}

			$saved[] = $id;
		}

		$this->send_save_result(['saved' => $saved, 'failed' => $failed], false);
	}

	/**
	 * @param array{saved: int[], failed: int[]} $result
	 */
	private function send_save_result(array $result, bool $images): void
	{
		$saved  = $result['saved'];
		$failed = $result['failed'];

		if (empty($saved)) {
			wp_send_json_error([
				'message' => __('Failed to save changes.', 'mihdan-index-now'),
				'failed'  => $failed,
			]);
		}

		$message = $images
			? sprintf(
				/* translators: %d: number of images updated. */
				_n('%d image updated.', '%d images updated.', count($saved), 'mihdan-index-now'),
				count($saved)
			)
			: sprintf(
				/* translators: %d: number of posts updated. */
				_n('%d post updated.', '%d posts updated.', count($saved), 'mihdan-index-now'),
				count($saved)
			);

		if (! empty($failed)) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of rows that could not be saved. */
				_n(
					'%d row could not be saved — you may not be allowed to edit it.',
					'%d rows could not be saved — you may not be allowed to edit them.',
					count($failed),
					'mihdan-index-now'
				),
				count($failed)
			);
		}

		wp_send_json_success([
			'message' => $message,
			'saved'   => $saved,
			'failed'  => $failed,
		]);
	}

	/**
	 * Length of an SEO title/description once its variables are resolved
	 * against the given post, for the live character counter.
	 */
	public function ajax_preview(): void
	{
		check_ajax_referer('crawlwp_bulk_editor_nonce', 'nonce');

		$id   = isset($_POST['id']) ? (int) $_POST['id'] : 0;
		$post = $id > 0 ? get_post($id) : null;

		if (! $post instanceof \WP_Post || ! current_user_can('edit_post', $id)) {
			wp_send_json_error(['message' => __('Permission denied.', 'mihdan-index-now')]);
		}

		$text = isset($_POST['text']) ? sanitize_textarea_field(wp_unslash($_POST['text'])) : '';

		wp_send_json_success(['length' => $this->resolved_length($text, $post)]);
	}

	/**
	 * Character count of a value as search engines will see it.
	 */
	private function resolved_length(string $text, \WP_Post $post): int
	{
		if (strpos($text, '{{') === false) {
			return mb_strlen($text);
		}

		return mb_strlen(Variables::replace($text, ['post' => $post]));
	}

	/**
	 * Meta query matching posts with no SEO title / meta description.
	 *
	 * Every save through this plugin stores an empty string for these keys
	 * (see MetaFields::ALWAYS_STORED), so the common case is an indexed
	 * `meta_value = ''` comparison. The NOT EXISTS clause is only needed for
	 * legacy posts that were never saved since and can be switched off on
	 * sites that have backfilled their meta.
	 *
	 * @return array<mixed>
	 */
	private function missing_meta_query(string $key): array
	{
		$clauses = [
			['key' => $key, 'value' => '', 'compare' => '='],
		];

		/**
		 * Filters whether the "missing title/description" filters also match
		 * legacy posts that have no meta row at all.
		 *
		 * Disabling this drops the slow NOT EXISTS sub-query.
		 *
		 * @param bool   $enabled Defaults to true.
		 * @param string $key     The meta key being filtered on.
		 */
		if (apply_filters('crawlwp_bulk_editor_legacy_missing_fallback', true, $key)) {
			$clauses[] = ['key' => $key, 'compare' => 'NOT EXISTS'];
		}

		if (count($clauses) === 1) {
			return $clauses;
		}

		return array_merge(['relation' => 'OR'], $clauses);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * @return string[]
	 */
	private function get_post_types(): array
	{
		return array_map(
			static function ($post_type) {
				return $post_type->name;
			},
			Entities::post_types()
		);
	}

	// -------------------------------------------------------------------------
	// HTML rendering
	// -------------------------------------------------------------------------

	/**
	 * Render the full Bulk Editor UI (toolbar + table shell + pagination).
	 *
	 * @return string HTML.
	 */
	private function render_ui(): string
	{
		$post_types = Entities::post_types();

		ob_start();
		?>
		<div class="cwp-bulk-wrap<?php echo BulkAI::batch_enabled() ? ' cwp-bulk-wrap--batch' : ''; ?>" id="cwp-bulk-wrap">

			<?php /* ---- Views ---- */ ?>
			<div class="cwp-bulk-views" role="tablist">
				<button type="button" class="cwp-bulk-view is-active" role="tab" aria-selected="true" data-view="posts"><?php esc_html_e('Posts and pages', 'mihdan-index-now'); ?></button>
				<?php if (current_user_can('upload_files')) : ?>
					<button type="button" class="cwp-bulk-view" role="tab" aria-selected="false" data-view="images">
						<?php esc_html_e('Image alt text', 'mihdan-index-now'); ?>
						<?php /* Filled in by the script once the Bulk Editor loads: counting can be slow on large media libraries. */ ?>
						<b class="cwp-bulk-view__badge" id="cwp-bulk-missing-alt" style="display:none"></b>
					</button>
				<?php endif; ?>
			</div>

			<?php /* ---- Toolbar ---- */ ?>
			<div class="cwp-bulk-toolbar">
				<select id="cwp-bulk-image-filter" class="cwp-bulk-select" style="display:none">
					<option value="missing_alt"><?php esc_html_e('Missing alt text', 'mihdan-index-now'); ?></option>
					<option value="all"><?php esc_html_e('All images', 'mihdan-index-now'); ?></option>
				</select>

				<select id="cwp-bulk-post-type" class="cwp-bulk-select">
					<option value=""><?php esc_html_e('All post types', 'mihdan-index-now'); ?></option>
					<?php foreach ($post_types as $post_type) : ?>
						<option value="<?php echo esc_attr($post_type->name); ?>">
							<?php echo esc_html($post_type->labels->name); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<select id="cwp-bulk-filter" class="cwp-bulk-select">
					<option value="all"><?php esc_html_e('All posts', 'mihdan-index-now'); ?></option>
					<option value="missing_title"><?php esc_html_e('Missing SEO title', 'mihdan-index-now'); ?></option>
					<option value="missing_description"><?php esc_html_e('Missing meta description', 'mihdan-index-now'); ?></option>
				</select>

				<div class="cwp-bulk-toolbar__right">
					<button type="button" class="cwp-bulk-btn cwp-bulk-btn--ai<?php echo BulkAI::batch_enabled() ? '' : ' is-locked'; ?>" id="cwp-bulk-ai-btn">
						<?php echo self::ai_icon(14); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG ?>
						<?php esc_html_e('Write with AI', 'mihdan-index-now'); ?>
						<?php if (! BulkAI::batch_enabled()) : ?><span class="cwp-bulk-pro"><?php esc_html_e('Premium', 'mihdan-index-now'); ?></span><?php endif; ?>
					</button>
					<input type="search" id="cwp-bulk-search" class="cwp-bulk-search"
					       placeholder="<?php esc_attr_e('Search…', 'mihdan-index-now'); ?>" />
					<button type="button" class="cwp-bulk-btn cwp-bulk-btn--primary" id="cwp-bulk-save-btn" disabled>
						<?php esc_html_e('Save Table Changes', 'mihdan-index-now'); ?>
					</button>
				</div>
			</div>

			<div class="cwp-bulk-notice" id="cwp-bulk-notice" hidden></div>
			<div class="cwp-bulk-progress" id="cwp-bulk-progress" style="display:none" role="status" aria-live="polite">
				<span class="cwp-bulk-progress__label"></span>
				<span class="cwp-bulk-progress__bar"><i></i></span>
				<button type="button" class="cwp-bulk-progress__stop"><?php esc_html_e('Stop', 'mihdan-index-now'); ?></button>
			</div>

			<?php /* ---- Table ---- */ ?>
			<table class="cwp-bulk-table" id="cwp-bulk-table">
				<thead id="cwp-bulk-thead">
					<tr>
						<th class="cwp-bulk-col-check"><input type="checkbox" class="cwp-bulk-check-all" aria-label="<?php esc_attr_e('Select all rows', 'mihdan-index-now'); ?>"></th>
						<th class="cwp-bulk-col-post"><?php esc_html_e('Post', 'mihdan-index-now'); ?></th>
						<th class="cwp-bulk-col-title"><?php esc_html_e('SEO Title', 'mihdan-index-now'); ?></th>
						<th class="cwp-bulk-col-desc"><?php esc_html_e('Meta Description', 'mihdan-index-now'); ?></th>
					</tr>
				</thead>
				<tbody id="cwp-bulk-tbody">
					<tr class="cwp-bulk-loading-row">
						<td colspan="4"><?php esc_html_e('Loading…', 'mihdan-index-now'); ?></td>
					</tr>
				</tbody>
			</table>

			<?php /* ---- Pagination ---- */ ?>
			<div class="cwp-bulk-pagination" id="cwp-bulk-pagination">
				<label>
					<select id="cwp-bulk-per-page" class="cwp-bulk-select cwp-bulk-select--sm">
						<option value="20">20</option>
						<option value="50">50</option>
						<option value="100">100</option>
					</select>
					<?php esc_html_e('items per page', 'mihdan-index-now'); ?>
				</label>
				<span id="cwp-bulk-total-info"></span>
				<span class="cwp-bulk-page-links" id="cwp-bulk-page-links"></span>
			</div>



		</div><!-- .cwp-bulk-wrap -->
		<?php
		return ob_get_clean();
	}

	/**
	 * Render table row HTML for an array of WP_Post objects.
	 *
	 * @param \WP_Post[] $posts
	 * @return string HTML <tr> elements.
	 */
	private function render_table_rows(array $posts): string
	{
		if (empty($posts)) {
			return '<tr class="cwp-bulk-no-items"><td colspan="4">' . esc_html__('No posts found.', 'mihdan-index-now') . '</td></tr>';
		}

		$html = '';

		foreach ($posts as $post) {
			$id      = (int) $post->ID;
			$title   = (string) MetaFields::get($id, MetaFields::SEO_TITLE, '');
			$desc    = (string) MetaFields::get($id, MetaFields::SEO_DESCRIPTION, '');
			$pt_obj  = get_post_type_object($post->post_type);
			$pt_name = $pt_obj ? $pt_obj->labels->singular_name : $post->post_type;
			$edit    = get_edit_post_link($id, 'raw');
			$view    = get_permalink($id);

			$html .= '<tr data-id="' . $id . '">';

			$html .= '<td class="cwp-bulk-col-check"><input type="checkbox" class="cwp-bulk-check" aria-label="' . esc_attr(sprintf(/* translators: %s: post title */ __('Select %s', 'mihdan-index-now'), get_the_title($id))) . '"></td>';

			$html .= '<td class="cwp-bulk-col-post">'
				. '<strong class="cwp-bulk-post-title">' . esc_html(get_the_title($id) !== '' ? get_the_title($id) : __('(no title)', 'mihdan-index-now')) . '</strong>'
				. '<span class="cwp-bulk-post-type">' . esc_html($pt_name) . '</span>'
				. '<div class="cwp-bulk-row-actions">'
				. ($edit ? '<a href="' . esc_url($edit) . '" target="_blank" rel="noopener">' . esc_html__('Edit', 'mihdan-index-now') . '</a>' : '')
				. ($edit && $view ? ' | ' : '')
				. ($view ? '<a href="' . esc_url($view) . '" target="_blank" rel="noopener">' . esc_html__('View', 'mihdan-index-now') . '</a>' : '')
				. '</div>'
				. '</td>';

			$html .= '<td class="cwp-bulk-col-title">'
				. '<div class="cwp-bulk-field">'
				. '<textarea class="cwp-bulk-input cwp-bulk-input--title" rows="2" data-field="seo_title" '
				. 'placeholder="' . esc_attr__('Leave blank to use the global template', 'mihdan-index-now') . '" '
				. 'data-original="' . esc_attr($title) . '">' . esc_textarea($title) . '</textarea>'
				. self::ai_button('seo_title')
				. '<button type="button" class="cwp-bulk-vars" aria-haspopup="true" aria-expanded="false" title="' . esc_attr__('Insert variable', 'mihdan-index-now') . '">&hellip;</button>'
				. '</div>'
				. '<span class="cwp-bulk-count" data-max="' . self::TITLE_MAX . '" data-length="' . $this->resolved_length($title, $post) . '"></span>'
				. '</td>';

			$html .= '<td class="cwp-bulk-col-desc">'
				. '<div class="cwp-bulk-field">'
				. '<textarea class="cwp-bulk-input cwp-bulk-input--desc" rows="3" data-field="seo_description" '
				. 'placeholder="' . esc_attr__('Leave blank to use the global template', 'mihdan-index-now') . '" '
				. 'data-original="' . esc_attr($desc) . '">' . esc_textarea($desc) . '</textarea>'
				. self::ai_button('seo_description')
				. '<button type="button" class="cwp-bulk-vars" aria-haspopup="true" aria-expanded="false" title="' . esc_attr__('Insert variable', 'mihdan-index-now') . '">&hellip;</button>'
				. '</div>'
				. '<span class="cwp-bulk-count" data-max="' . self::DESC_MAX . '" data-length="' . $this->resolved_length($desc, $post) . '"></span>'
				. '</td>';

			$html .= '</tr>';
		}

		return $html;
	}
}
