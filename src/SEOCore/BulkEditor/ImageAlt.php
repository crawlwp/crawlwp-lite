<?php

namespace Mihdan\IndexNow\SEOCore\BulkEditor;

/**
 * "Image alt text" view of the Bulk Editor: list images with their alt text
 * and save edits. Alt text is the core `_wp_attachment_image_alt` meta.
 */
class ImageAlt
{
	const META_KEY = '_wp_attachment_image_alt';

	/** Recommended maximum length (characters). */
	const MAX = 125;

	public function __construct()
	{
		add_action('wp_ajax_crawlwp_bulk_editor_images', [$this, 'ajax_list']);
	}

	/**
	 * Query args for images, optionally only those without alt text.
	 *
	 * An empty alt counts as missing here: in the Media Library an empty alt
	 * can mean "decorative", but this is a review screen and nothing is saved
	 * without the user clicking Save.
	 */
	public static function query_args(bool $missing_only, string $search = '', int $per_page = 20, int $page = 1): array
	{
		$args = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		if ($search !== '') {
			$args['s'] = $search;
		}

		/* Users who can't edit other people's posts only see (and can only edit) their own uploads. */
		if (! current_user_can('edit_others_posts')) {
			$args['author'] = get_current_user_id();
		}

		if ($missing_only) {
			$args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				['key' => self::META_KEY, 'compare' => 'NOT EXISTS'],
				['key' => self::META_KEY, 'value' => '', 'compare' => '='],
			];
		}

		return $args;
	}

	/**
	 * Number of images without alt text, for the view's badge.
	 */
	public static function count_missing(): int
	{
		$query = new \WP_Query(self::query_args(true, '', 1, 1) + ['fields' => 'ids', 'no_found_rows' => false]);

		return (int) $query->found_posts;
	}

	public function ajax_list(): void
	{
		check_ajax_referer('crawlwp_bulk_editor_nonce', 'nonce');

		if (! current_user_can('upload_files')) {
			wp_send_json_error(['message' => __('Permission denied.', 'mihdan-index-now')]);
		}

		// Just the number of images without alt text, for the view's badge.
		if (! empty($_POST['count_only'])) {
			wp_send_json_success(['missing' => self::count_missing()]);
		}

		$filter   = sanitize_key(wp_unslash($_POST['filter'] ?? 'missing_alt'));
		$search   = sanitize_text_field(wp_unslash($_POST['search'] ?? ''));
		$per_page = max(1, min(200, (int) ($_POST['per_page'] ?? 20)));
		$page     = max(1, (int) ($_POST['page'] ?? 1));

		$query = new \WP_Query(self::query_args($filter !== 'all', $search, $per_page, $page));

		$response = [
			'html'        => self::render_rows($query->posts),
			'total'       => (int) $query->found_posts,
			'total_label' => sprintf(
				/* translators: %s: number of images. */
				_n('Total %s image.', 'Total %s images.', (int) $query->found_posts, 'mihdan-index-now'),
				number_format_i18n((int) $query->found_posts)
			),
			'page'        => $page,
			'pages'       => max(1, (int) $query->max_num_pages),
		];

		/* The missing-alt list's total is the badge count; other views don't run a second query for it. */
		if ($filter !== 'all' && $search === '') {
			$response['missing'] = (int) $query->found_posts;
		}

		wp_send_json_success($response);
	}

	/**
	 * Save alt text for the given rows: [['id' => 1, 'alt' => '...'], ...].
	 *
	 * @return array{saved: int[], failed: int[]}
	 */
	public static function save(array $rows): array
	{
		$saved  = [];
		$failed = [];

		foreach ($rows as $row) {
			$id = isset($row['id']) ? (int) $row['id'] : 0;

			if (! $id || ! wp_attachment_is_image($id) || ! current_user_can('edit_post', $id)) {
				$failed[] = $id;
				continue;
			}

			$alt = isset($row['alt']) ? sanitize_text_field(wp_unslash($row['alt'])) : '';

			update_post_meta($id, self::META_KEY, $alt);

			$saved[] = $id;
		}

		return ['saved' => $saved, 'failed' => $failed];
	}

	/**
	 * @param \WP_Post[] $images
	 */
	public static function render_rows(array $images): string
	{
		if (empty($images)) {
			return '<tr class="cwp-bulk-no-items"><td colspan="4">' . esc_html__('No images found.', 'mihdan-index-now') . '</td></tr>';
		}

		$ai   = BulkEditorSettings::ai_button('alt');
		$html = '';

		foreach ($images as $image) {
			$id     = (int) $image->ID;
			$alt    = (string) get_post_meta($id, self::META_KEY, true);
			$thumb  = wp_get_attachment_image_url($id, 'thumbnail');
			$file   = wp_basename((string) get_attached_file($id));
			$parent = (int) $image->post_parent;
			$edit   = get_edit_post_link($id, 'raw');
			$view   = wp_get_attachment_url($id);

			/* The parent's title only when the user may see that post (drafts and private posts stay hidden). */
			if ($parent && get_post($parent) && current_user_can('read_post', $parent)) {
				$parent_link = get_edit_post_link($parent, 'raw');
				$used        = sprintf(
					/* translators: %s: post title */
					esc_html__('Attached to: %s', 'mihdan-index-now'),
					$parent_link
						? '<a href="' . esc_url($parent_link) . '" target="_blank" rel="noopener">' . esc_html(get_the_title($parent) ?: __('(no title)', 'mihdan-index-now')) . '</a>'
						: esc_html(get_the_title($parent))
				);
			} else {
				$used = esc_html__('Not attached to a post', 'mihdan-index-now');
			}

			$html .= '<tr data-id="' . $id . '">';
			$html .= '<td class="cwp-bulk-col-check"><input type="checkbox" class="cwp-bulk-check" aria-label="' . esc_attr(sprintf(/* translators: %s: file name */ __('Select %s', 'mihdan-index-now'), $file)) . '"></td>';
			$html .= '<td class="cwp-bulk-col-image"><div class="cwp-bulk-image">'
				. ($thumb ? '<img src="' . esc_url($thumb) . '" alt="" loading="lazy" width="56" height="56">' : '<span class="cwp-bulk-image__none"></span>')
				. '<div><strong class="cwp-bulk-post-title">' . esc_html($file) . '</strong>'
				. '<span class="cwp-bulk-image__used">' . $used . '</span>'
				. '<div class="cwp-bulk-row-actions">'
				. ($edit ? '<a href="' . esc_url($edit) . '" target="_blank" rel="noopener">' . esc_html__('Edit', 'mihdan-index-now') . '</a>' : '')
				. ($edit && $view ? ' | ' : '')
				. ($view ? '<a href="' . esc_url($view) . '" target="_blank" rel="noopener">' . esc_html__('View', 'mihdan-index-now') . '</a>' : '')
				. '</div></div></div></td>';
			$html .= '<td class="cwp-bulk-col-alt" colspan="2"><div class="cwp-bulk-field">'
				. '<textarea class="cwp-bulk-input cwp-bulk-input--alt" rows="2" data-field="alt" '
				. 'placeholder="' . esc_attr__('Describe the image for people who can’t see it', 'mihdan-index-now') . '" '
				. 'data-original="' . esc_attr($alt) . '">' . esc_textarea($alt) . '</textarea>'
				. $ai
				. '</div>'
				. '<span class="cwp-bulk-count" data-max="' . self::MAX . '" data-length="' . mb_strlen($alt) . '"></span>'
				. '</td>';
			$html .= '</tr>';
		}

		return $html;
	}
}
