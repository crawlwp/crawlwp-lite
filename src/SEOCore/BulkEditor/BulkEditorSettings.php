<?php

namespace Mihdan\IndexNow\SEOCore\BulkEditor;

use Mihdan\IndexNow\SEOCore\MetaBox\MetaFields;
use Mihdan\IndexNow\SEOCore\MetaBox\SeoSignals;
use Mihdan\IndexNow\SEOCore\TitleMeta\Entities;
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
			'desc'           => __('Edit the SEO title and meta description of many posts at once, right from this table — no need to open each post individually.', 'mihdan-index-now'),
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
			'1.0.0'
		);

		wp_enqueue_script(
			'crawlwp-bulk-editor',
			$assets_url . 'bulk-editor.js',
			['jquery'],
			'1.0.0',
			true
		);

		wp_localize_script('crawlwp-bulk-editor', 'crawlwpBulkEditor', [
			'ajaxUrl'   => admin_url('admin-ajax.php'),
			'nonce'     => wp_create_nonce('crawlwp_bulk_editor_nonce'),
			'variables' => $this->get_variables(),
			'i18n'      => [
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

			/* Empty values keep an empty meta row so the "missing" filters above
			   can use an indexed comparison. */
			MetaFields::save_optional($id, MetaFields::SEO_TITLE, $title);
			MetaFields::save_optional($id, MetaFields::SEO_DESCRIPTION, $desc);

			/* The title/description feed the post list SEO signals. */
			SeoSignals::flush($id);

			$saved[] = $id;
		}

		if (empty($saved)) {
			wp_send_json_error([
				'message' => __('Failed to save changes.', 'mihdan-index-now'),
				'failed'  => $failed,
			]);
		}

		$message = sprintf(
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
		<div class="cwp-bulk-wrap" id="cwp-bulk-wrap">

			<?php /* ---- Toolbar ---- */ ?>
			<div class="cwp-bulk-toolbar">
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
					<input type="search" id="cwp-bulk-search" class="cwp-bulk-search"
					       placeholder="<?php esc_attr_e('Search…', 'mihdan-index-now'); ?>" />
					<button type="button" class="cwp-bulk-btn cwp-bulk-btn--primary" id="cwp-bulk-save-btn" disabled>
						<?php esc_html_e('Save Table Changes', 'mihdan-index-now'); ?>
					</button>
				</div>
			</div>

			<?php /* ---- Table ---- */ ?>
			<table class="cwp-bulk-table" id="cwp-bulk-table">
				<thead>
					<tr>
						<th class="cwp-bulk-col-post"><?php esc_html_e('Post', 'mihdan-index-now'); ?></th>
						<th class="cwp-bulk-col-title"><?php esc_html_e('SEO Title', 'mihdan-index-now'); ?></th>
						<th class="cwp-bulk-col-desc"><?php esc_html_e('Meta Description', 'mihdan-index-now'); ?></th>
					</tr>
				</thead>
				<tbody id="cwp-bulk-tbody">
					<tr class="cwp-bulk-loading-row">
						<td colspan="3"><?php esc_html_e('Loading…', 'mihdan-index-now'); ?></td>
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

			<div class="cwp-bulk-notice" id="cwp-bulk-notice" hidden></div>

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
			return '<tr class="cwp-bulk-no-items"><td colspan="3">' . esc_html__('No posts found.', 'mihdan-index-now') . '</td></tr>';
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
				. '<button type="button" class="cwp-bulk-vars" aria-haspopup="true" aria-expanded="false" title="' . esc_attr__('Insert variable', 'mihdan-index-now') . '">&hellip;</button>'
				. '</div>'
				. '<span class="cwp-bulk-count" data-max="' . self::TITLE_MAX . '"></span>'
				. '</td>';

			$html .= '<td class="cwp-bulk-col-desc">'
				. '<div class="cwp-bulk-field">'
				. '<textarea class="cwp-bulk-input cwp-bulk-input--desc" rows="3" data-field="seo_description" '
				. 'placeholder="' . esc_attr__('Leave blank to use the global template', 'mihdan-index-now') . '" '
				. 'data-original="' . esc_attr($desc) . '">' . esc_textarea($desc) . '</textarea>'
				. '<button type="button" class="cwp-bulk-vars" aria-haspopup="true" aria-expanded="false" title="' . esc_attr__('Insert variable', 'mihdan-index-now') . '">&hellip;</button>'
				. '</div>'
				. '<span class="cwp-bulk-count" data-max="' . self::DESC_MAX . '"></span>'
				. '</td>';

			$html .= '</tr>';
		}

		return $html;
	}
}
