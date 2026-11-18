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

		/*
		 * The missing-alt count is cached. A change to one image's alt text moves the cached
		 * numbers by one instead of throwing them away (the recount is a heavy query on a large
		 * media library); a new or deleted image forgets them.
		 */
		add_action('add_post_meta', [__CLASS__, 'note_alt_before_add'], 10, 2);
		add_action('update_post_meta', [__CLASS__, 'note_alt_before'], 10, 3);
		add_action('delete_post_meta', [__CLASS__, 'note_alt_before'], 10, 3);

		foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) {
			add_action($hook, [__CLASS__, 'adjust_count_on_meta'], 10, 3);
		}
		add_action('add_attachment', [__CLASS__, 'forget_count']);
		add_action('delete_attachment', [__CLASS__, 'forget_count']);
	}

	/** Cached missing-alt counts: one entry per scope (everyone's images, or one user's uploads). */
	const COUNT_TRANSIENT = 'crawlwp_missing_alt_counts';

	/** @var array<int,bool> Whether an image had no alt text before the change now being made. */
	private static $was_missing = [];

	/**
	 * @param int    $object_id
	 * @param string $meta_key
	 */
	public static function note_alt_before_add($object_id, $meta_key): void
	{
		self::note_alt_before(0, $object_id, $meta_key);
	}

	/**
	 * @param int|int[] $meta_id
	 * @param int       $object_id
	 * @param string    $meta_key
	 */
	public static function note_alt_before($meta_id, $object_id, $meta_key): void
	{
		if ($meta_key === self::META_KEY) {
			self::$was_missing[(int) $object_id] = (string) get_post_meta((int) $object_id, self::META_KEY, true) === '';
		}
	}

	/**
	 * @param int|int[] $meta_id
	 * @param int       $object_id
	 * @param string    $meta_key
	 */
	public static function adjust_count_on_meta($meta_id, $object_id, $meta_key): void
	{
		if ($meta_key !== self::META_KEY) {
			return;
		}

		$object_id = (int) $object_id;
		$counts    = get_transient(self::COUNT_TRANSIENT);

		if (! is_array($counts) || ! array_diff_key($counts, ['_at' => 1])) {
			unset(self::$was_missing[$object_id]);

			return;
		}

		$post = get_post($object_id);

		/* Not known what it was before, or not an image the count covers: recount next time. */
		if (! isset(self::$was_missing[$object_id]) || ! $post || $post->post_type !== 'attachment' || $post->post_status !== 'inherit' || strpos((string) $post->post_mime_type, 'image/') !== 0) {
			unset(self::$was_missing[$object_id]);

			if ($post && $post->post_type === 'attachment') {
				self::forget_count();
			}

			return;
		}

		$before = self::$was_missing[$object_id];
		$after  = (string) get_post_meta($object_id, self::META_KEY, true) === '';
		unset(self::$was_missing[$object_id]);

		if ($before === $after) {
			return;
		}

		$delta = $after ? 1 : -1;

		foreach (['all', 'u' . (int) $post->post_author] as $scope) {
			if (isset($counts[$scope])) {
				$counts[$scope] = max(0, (int) $counts[$scope] + $delta);
			}
		}

		/* count_missing() recounts once the '_at' time inside is 12 hours old, however often this re-stores it. */
		set_transient(self::COUNT_TRANSIENT, $counts, 12 * HOUR_IN_SECONDS);
	}

	public static function forget_count(): void
	{
		delete_transient(self::COUNT_TRANSIENT);
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
		/* The same scope query_args() applies: all images, or only the user's own uploads. */
		$scope  = current_user_can('edit_others_posts') ? 'all' : 'u' . get_current_user_id();
		$counts = get_transient(self::COUNT_TRANSIENT);
		$counts = is_array($counts) ? $counts : [];

		/* Counts nudged by adjust_count_on_meta() are re-stored, so their age is kept inside: recount after 12 hours. */
		if (isset($counts['_at']) && time() - (int) $counts['_at'] > 12 * HOUR_IN_SECONDS) {
			$counts = [];
		}

		if (isset($counts[$scope])) {
			return (int) $counts[$scope];
		}

		$query = new \WP_Query(self::query_args(true, '', 1, 1) + ['fields' => 'ids', 'no_found_rows' => false]);

		return self::store_count($counts, $scope, (int) $query->found_posts);
	}

	/**
	 * Remember a freshly counted number of images without alt text for a scope.
	 *
	 * @param array  $counts The cached entry as read (already emptied when too old).
	 * @param string $scope  'all' or 'u<user id>'.
	 * @param int    $count
	 */
	private static function store_count(array $counts, string $scope, int $count): int
	{
		/* Keep the entry small on sites with many uploaders. */
		if (count($counts) >= 50) {
			$counts = [];
		}

		$counts += ['_at' => time()];
		$counts[$scope] = $count;
		set_transient(self::COUNT_TRANSIENT, $counts, 12 * HOUR_IN_SECONDS);

		return $count;
	}

	public function ajax_list(): void
	{
		check_ajax_referer('crawlwp_bulk_editor_nonce', 'nonce');

		if (! current_user_can('upload_files') || ! \Mihdan\IndexNow\SEOCore\Permissions::can(\Mihdan\IndexNow\SEOCore\Permissions::BULK_EDITOR)) {
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

			/*
			 * It is also a fresh count, so it corrects the cached one: the hooks that keep the cache
			 * right only run in wp-admin, and uploads and alt text also change over REST (the block
			 * editor), WP-CLI and cron.
			 */
			$counts = get_transient(self::COUNT_TRANSIENT);
			$counts = is_array($counts) ? $counts : [];
			$scope  = current_user_can('edit_others_posts') ? 'all' : 'u' . get_current_user_id();

			if (! isset($counts[$scope]) || (int) $counts[$scope] !== $response['missing']) {
				self::store_count($counts, $scope, $response['missing']);
			}
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
				. 'aria-label="' . esc_attr(sprintf(/* translators: %s: file name */ __('Alt text for %s', 'mihdan-index-now'), $file)) . '" '
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
