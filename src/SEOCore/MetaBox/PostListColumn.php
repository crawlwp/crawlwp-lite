<?php

namespace Mihdan\IndexNow\SEOCore\MetaBox;

/**
 * Adds an "SEO" column to WordPress post list tables for all public post types.
 *
 * The cell renders a compact "signal strip" — one lettered, colour-coded
 * segment per SEO signal (T D K I F S, see SeoSignals) with an accessible
 * hover/focus popover explaining each one — plus a thin gauge for the overall
 * score. Also injects SEO title + meta description fields into the native
 * Quick Edit panel.
 *
 * Score is calculated from:
 *  - SEO title length (0–60 chars ideal)
 *  - SEO description length (0–160 chars ideal)
 *  - Presence of a focus keyword
 *  - Whether the post is set to noindex
 */
class PostListColumn
{
	/** Title length thresholds (characters). */
	private const TITLE_MIN = SeoSignals::TITLE_MIN;
	private const TITLE_MAX = SeoSignals::TITLE_MAX;

	/** Description length thresholds (characters). */
	private const DESC_MIN  = SeoSignals::DESC_MIN;
	private const DESC_MAX  = SeoSignals::DESC_MAX;

	/** Nonce action for Quick Edit saves. */
	const QUICK_EDIT_NONCE = 'crawlwp_quick_edit_seo';

	public function __construct()
	{
		add_action('init', [$this, 'register_hooks'], 20);

		/*
		 * Persist the score to post meta whenever a post is saved.
		 * Priority 20 ensures MetaFields::save() (priority 10)
		 * has already written the fresh meta values.
		 */
		add_action('save_post', [$this, 'persist_score'], 20);

		/*
		 * Persist the SEO signals too, so the post list does not have to scan
		 * the content of every row. Priority 25 runs after the meta and the
		 * score have been written.
		 */
		add_action('save_post', [$this, 'persist_signals'], 25);

		/* Save SEO fields submitted via Quick Edit. */
		add_action('save_post', [$this, 'save_quick_edit'], 15);
	}

	/**
	 * Register column hooks after post types are registered.
	 */
	public function register_hooks(): void
	{
		foreach ($this->get_public_post_types() as $post_type) {
			add_filter("manage_{$post_type}_posts_columns",          [$this, 'add_column']);
			add_action("manage_{$post_type}_posts_custom_column",    [$this, 'render_column'], 10, 2);
			add_filter("manage_edit-{$post_type}_sortable_columns",  [$this, 'add_sortable_column']);
		}

		/* Inject our fields into the Quick Edit panel (fires once per column). */
		add_action('quick_edit_custom_box', [$this, 'render_quick_edit_fields'], 10, 2);

		/* Sort support. */
		add_filter('request', [$this, 'sort_query']);

		add_action('admin_head',   [$this, 'print_styles']);
		add_action('admin_footer', [$this, 'print_quick_edit_js']);
	}

	/**
	 * Return slugs of all public post types.
	 *
	 * @return string[]
	 */
	private function get_public_post_types(): array
	{
		$types = get_post_types(['public' => true], 'names');

		/* Remove attachments — they don't have SEO metaboxes. */
		unset($types['attachment']);

		return array_values($types);
	}

	// -------------------------------------------------------------------------
	// Column registration
	// -------------------------------------------------------------------------

	/**
	 * Append the SEO score column after the title column.
	 *
	 * @param string[] $columns
	 * @return string[]
	 */
	public function add_column(array $columns): array
	{
		$result = [];

		foreach ($columns as $key => $label) {
			$result[$key] = $label;

			/* Insert after the title column. */
			if ($key === 'title') {
				$result['crawlwp_seo_score'] = esc_html__('SEO', 'mihdan-index-now');
			}
		}

		/* Fallback: if there was no title column, append at end. */
		if (! isset($result['crawlwp_seo_score'])) {
			$result['crawlwp_seo_score'] = esc_html__('SEO', 'mihdan-index-now');
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Column rendering
	// -------------------------------------------------------------------------

	/**
	 * Output the SEO score cell for a given post.
	 *
	 * @param string $column_name
	 * @param int    $post_id
	 */
	public function render_column(string $column_name, int $post_id): void
	{
		if ($column_name !== 'crawlwp_seo_score') {
			return;
		}

		$post = get_post($post_id);

		if (! $post instanceof \WP_Post) {
			return;
		}

		$cached = get_post_meta($post_id, MetaFields::SEO_SCORE, true);
		$score  = ($cached !== '' && $cached !== false) ? (float) $cached : $this->calculate_score($post_id);
		$state  = $this->score_state($score, $post_id);
		$label  = $this->state_label($state, $score);

		/* Store current SEO values in data attributes so Quick Edit JS can pre-fill the fields. */
		$seo_title = (string) MetaFields::get($post_id, MetaFields::SEO_TITLE, '');
		$seo_desc  = (string) MetaFields::get($post_id, MetaFields::SEO_DESCRIPTION, '');

		$signals = SeoSignals::cached($post);

		echo '<div class="cwp-seobar" data-cwp-seo-title="' . esc_attr($seo_title) . '" data-cwp-seo-desc="' . esc_attr($seo_desc) . '">';

		/* Signal strip. */
		echo '<div class="cwp-seobar__strip" role="list">';

		foreach ($signals as $signal) {
			$this->render_signal($signal);
		}

		echo '</div>';

		/* Overall score gauge. */
		$rounded = (int) round($score);
		/* translators: 1: score, 2: score label */
		$score_aria = sprintf(__('CrawlWP SEO score: %1$d/100 — %2$s', 'mihdan-index-now'), $rounded, $label);

		if ($state === 'noindex') {
			$score_detail = __('This post is set to noindex, so the score is capped. Search engines are asked not to list it.', 'mihdan-index-now');
		} else {
			$score_detail = __('Calculated by the CrawlWP SEO analysis (title, description, focus keyword, readability and more). Open the post to see the full checklist and improve it.', 'mihdan-index-now');
		}

		printf(
			'<div class="cwp-seobar__score is-%1$s" tabindex="0" aria-label="%2$s">' .
			'<span class="cwp-seobar__track"><span class="cwp-seobar__fill" style="width:%3$d%%"></span></span>' .
			'<span class="cwp-seobar__num">%4$s</span>' .
			'<span class="cwp-seobar__tip cwp-seobar__tip--score" role="tooltip">' .
			'<strong>%5$s <em class="is-%1$s">%6$s</em></strong>' .
			'<span class="cwp-seobar__summary">%7$s</span>' .
			'<span class="cwp-seobar__detail">%8$s</span>' .
			'</span>' .
			'</div>',
			esc_attr($state),
			esc_attr($score_aria),
			$rounded,
			$state === 'noindex' ? esc_html__('noindex', 'mihdan-index-now') : esc_html((string) $rounded),
			esc_html__('CrawlWP SEO score', 'mihdan-index-now'),
			/* translators: %d: score out of 100 */
			esc_html(sprintf(__('%d/100', 'mihdan-index-now'), $rounded)),
			esc_html($label),
			esc_html($score_detail)
		);

		echo '</div>';
	}

	/**
	 * One lettered segment of the signal strip with its popover.
	 */
	private function render_signal(array $signal): void
	{
		$state   = in_array($signal['state'], [SeoSignals::GOOD, SeoSignals::WARN, SeoSignals::BAD], true) ? $signal['state'] : SeoSignals::NEUTRAL;
		$label   = (string) ($signal['label'] ?? $signal['letter']);
		$summary = (string) ($signal['summary'] ?? '');
		$detail  = (string) ($signal['detail'] ?? '');

		/* translators: 1: signal label, 2: state label, 3: summary */
		$aria = sprintf(__('%1$s: %2$s. %3$s', 'mihdan-index-now'), $label, SeoSignals::state_label($state), $summary);

		printf(
			'<span class="cwp-seobar__sig is-%1$s" role="listitem" tabindex="0" aria-label="%2$s" data-signal="%3$s">' .
			'<span class="cwp-seobar__letter">%4$s</span>' .
			'<span class="cwp-seobar__tip" role="tooltip">' .
			'<strong>%5$s <em class="is-%1$s">%6$s</em></strong>' .
			'<span class="cwp-seobar__summary">%7$s</span>%8$s' .
			'</span>' .
			'</span>',
			esc_attr($state),
			esc_attr($aria),
			esc_attr((string) ($signal['id'] ?? '')),
			esc_html((string) $signal['letter']),
			esc_html($label),
			esc_html(SeoSignals::state_label($state)),
			esc_html($summary),
			$detail !== '' ? '<span class="cwp-seobar__detail">' . esc_html($detail) . '</span>' : ''
		);
	}

	// -------------------------------------------------------------------------
	// Quick Edit — render fields
	// -------------------------------------------------------------------------

	/**
	 * Inject SEO title + meta description fields into the Quick Edit panel.
	 *
	 * @param string $column_name
	 * @param string $post_type
	 */
	public function render_quick_edit_fields(string $column_name, string $post_type): void
	{
		if ($column_name !== 'crawlwp_seo_score') {
			return;
		}

		/* Only for registered public post types. */
		if (! in_array($post_type, $this->get_public_post_types(), true)) {
			return;
		}

		wp_nonce_field(self::QUICK_EDIT_NONCE, 'cwp_quick_edit_nonce');
		?>
		<fieldset class="inline-edit-col-right cwp-quick-edit-seo">
			<div class="inline-edit-col">
				<h4 class="cwp-quick-edit-seo__heading"><?php esc_html_e('CrawlWP SEO', 'mihdan-index-now'); ?></h4>
				<label class="cwp-quick-edit-seo__label">
					<span class="title"><?php esc_html_e('SEO Title', 'mihdan-index-now'); ?></span>
					<input type="text"
						name="cwp_quick_edit_seo_title"
						class="cwp-quick-edit-seo__title"
						value=""
						maxlength="200"
						placeholder="<?php esc_attr_e('Leave blank to use global template', 'mihdan-index-now'); ?>" />
				</label>
				<label class="cwp-quick-edit-seo__label">
					<span class="title"><?php esc_html_e('Meta Description', 'mihdan-index-now'); ?></span>
					<textarea
						name="cwp_quick_edit_seo_desc"
						class="cwp-quick-edit-seo__desc"
						rows="3"
						maxlength="500"
						placeholder="<?php esc_attr_e('Leave blank to use global template', 'mihdan-index-now'); ?>"></textarea>
				</label>
			</div>
		</fieldset>
		<?php
	}

	// -------------------------------------------------------------------------
	// Quick Edit — save fields
	// -------------------------------------------------------------------------

	/**
	 * Save SEO fields submitted via the Quick Edit panel.
	 *
	 * WordPress fires save_post for Quick Edit submissions; the nonce we embed
	 * in the panel lets us distinguish our submission from other save_post calls.
	 *
	 * @param int $post_id
	 */
	public function save_quick_edit(int $post_id): void
	{
		/* Only act when our nonce is present (i.e. Quick Edit was used). */
		$nonce = $_POST['cwp_quick_edit_nonce'] ?? '';
		if (! wp_verify_nonce($nonce, self::QUICK_EDIT_NONCE)) {
			return;
		}

		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		if (! current_user_can('edit_post', $post_id)) {
			return;
		}

		$seo_title = isset($_POST['cwp_quick_edit_seo_title'])
			? sanitize_text_field(wp_unslash($_POST['cwp_quick_edit_seo_title']))
			: '';

		$seo_desc = isset($_POST['cwp_quick_edit_seo_desc'])
			? sanitize_textarea_field(wp_unslash($_POST['cwp_quick_edit_seo_desc']))
			: '';

		/*
		 * An empty value keeps an empty meta row instead of deleting it, so the
		 * Bulk Editor's "missing title/description" filters can use an indexed
		 * comparison. See MetaFields::ALWAYS_STORED.
		 */
		MetaFields::save_optional($post_id, MetaFields::SEO_TITLE, $seo_title);
		MetaFields::save_optional($post_id, MetaFields::SEO_DESCRIPTION, $seo_desc);
	}

	// -------------------------------------------------------------------------
	// Score persistence
	// -------------------------------------------------------------------------

	/**
	 * Calculate and persist the SEO score whenever a post is saved.
	 *
	 * Runs at priority 20 on save_post so MetaFields::save() (priority 10)
	 * has already written the fresh meta values.
	 *
	 * @param int $post_id
	 */
	public function persist_score(int $post_id): void
	{
		/* Skip autosaves and revisions. */
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		if (wp_is_post_revision($post_id)) {
			return;
		}

		/*
		 * Only persist the JS-calculated score (richer analysis: keyword density,
		 * readability, heading structure, etc.).  When the JS score is absent the
		 * cached meta is deleted so the post list always shows a fresh PHP-computed
		 * approximation rather than a stale value from a previous save.
		 */
		$submitted = isset($_POST[MetaFields::SEO_SCORE]) ? trim($_POST[MetaFields::SEO_SCORE]) : '';

		if ($submitted !== '' && is_numeric($submitted)) {
			/* Verify the metabox nonce — only our own form submissions carry this. */
			$nonce = isset($_POST[MetaFields::NONCE_NAME]) ? $_POST[MetaFields::NONCE_NAME] : '';
			if (! wp_verify_nonce($nonce, MetaFields::NONCE_ACTION)) {
				return;
			}

			if (! current_user_can('edit_post', $post_id)) {
				return;
			}

			$score = (float) $submitted;
			// Clamp to [0, 100] so a tampered value can't break the display.
			$score = max(0.0, min(100.0, $score));
			update_post_meta($post_id, MetaFields::SEO_SCORE, (string) $score);
		} else {
			/* No JS score submitted — remove any stale cached value so the list
			   always falls back to the live PHP approximation. */
			delete_post_meta($post_id, MetaFields::SEO_SCORE);
		}
	}

	/**
	 * Recompute the cached SEO signals whenever a post is saved.
	 *
	 * Without this the post list would have to run strip_shortcodes() and
	 * wp_strip_all_tags() over the full content of every row it renders.
	 *
	 * @param int $post_id
	 */
	public function persist_signals(int $post_id): void
	{
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		if (wp_is_post_revision($post_id)) {
			return;
		}

		if (wp_is_post_autosave($post_id)) {
			return;
		}

		SeoSignals::persist($post_id);
	}

	// -------------------------------------------------------------------------
	// Score calculation
	// -------------------------------------------------------------------------

	/**
	 * Calculate a 0–100 SEO score for a post.
	 * The score is the average of three sub-scores:
	 *  1. SEO title quality (40 pts max)
	 *  2. SEO description quality (40 pts max)
	 *  3. Focus keyword present (20 pts max)
	 * Noindex posts are capped at 10.
	 *
	 * @param int $post_id
	 * @return float 0–100
	 */
	private function calculate_score(int $post_id): float
	{
		/* Noindex: bail early with a low score. */
		$robots_index = MetaFields::get($post_id, MetaFields::ROBOTS_INDEX, 'index');

		if ($robots_index === 'noindex') {
			return 10.0;
		}

		$title       = (string) MetaFields::get($post_id, MetaFields::SEO_TITLE, '');
		$description = (string) MetaFields::get($post_id, MetaFields::SEO_DESCRIPTION, '');
		$keyword     = (string) MetaFields::get($post_id, MetaFields::FOCUS_KEYWORD, '');

		$title_score = $this->length_score(mb_strlen($title), self::TITLE_MIN, self::TITLE_MAX);
		$desc_score  = $this->length_score(mb_strlen($description), self::DESC_MIN, self::DESC_MAX);
		$kw_score    = ($keyword !== '') ? 100.0 : 0.0;

		/* Weighted: title 40%, description 40%, keyword 20%. */
		$score = ($title_score * 0.4) + ($desc_score * 0.4) + ($kw_score * 0.2);
		$score = round($score, 1);

		/**
		 * Filter the calculated SEO score for a post.
		 *
		 * @param float $score   The computed score (0–100).
		 * @param int   $post_id The post ID.
		 */
		return (float) apply_filters('crawlwp_post_seo_score', $score, $post_id);
	}

	/**
	 * Map a character count to a 0–100 quality score for a length-sensitive field.
	 *
	 * @param int $length  Actual character count (0 when empty / global template in use).
	 * @param int $min     Minimum recommended length.
	 * @param int $max     Maximum recommended length.
	 * @return float 0–100
	 */
	private function length_score(int $length, int $min, int $max): float
	{
		if ($length === 0) {
			/*
			 * Empty means the global Title & Meta template is used, not that it
			 * is blank.  Award a neutral 50 so it does not drag the score down
			 * severely, but signal there is room to customise.
			 */
			return 50.0;
		}

		if ($length >= $min && $length <= $max) {
			return 100.0;
		}

		if ($length < $min) {
			/* Linearly scale from 0 (length=0) to 100 (length=min). */
			return round(($length / $min) * 100, 1);
		}

		/* Too long: scale down from 100 (length=max) toward 0 as length grows. */
		$over   = $length - $max;
		$score  = max(0, 100 - ($over / $max) * 100);

		return round($score, 1);
	}

	/**
	 * Map a numeric score to a named state.
	 *
	 * 'noindex' is driven by the robots meta the editor actually saved, not by
	 * the score — a low score is 'poor', not 'noindex'.
	 *
	 * @param float $score   0–100
	 * @param int   $post_id Post to read the robots meta from.
	 * @return string  'good' | 'ok' | 'poor' | 'noindex'
	 */
	private function score_state(float $score, int $post_id): string
	{
		if (MetaFields::get($post_id, MetaFields::ROBOTS_INDEX, 'index') === 'noindex') {
			return 'noindex';
		}

		if ($score >= 70) {
			return 'good';
		}

		if ($score >= 40) {
			return 'ok';
		}

		return 'poor';
	}

	/**
	 * Human-readable label for a state.
	 *
	 * @param string $state
	 * @param float  $score
	 * @return string
	 */
	private function state_label(string $state, float $score): string
	{
		switch ($state) {
			case 'noindex':
				return __('No Index', 'mihdan-index-now');
			case 'good':
				return __('Good', 'mihdan-index-now');
			case 'ok':
				return __('OK', 'mihdan-index-now');
			default:
				return __('Poor', 'mihdan-index-now');
		}
	}

	// -------------------------------------------------------------------------
	// Sort support
	// -------------------------------------------------------------------------

	/**
	 * Declare the SEO score column as sortable so WordPress renders the
	 * sort arrows in the column header.
	 *
	 * @param array $sortable_columns
	 * @return array
	 */
	public function add_sortable_column(array $sortable_columns): array
	{
		$sortable_columns['crawlwp_seo_score'] = 'crawlwp_seo_score';
		return $sortable_columns;
	}

	/**
	 * Allow sorting by the SEO score column.
	 *
	 * Sorts numerically on the persisted `_crawlwp_seo_score` meta. Posts that
	 * have no stored score are NOT dropped from the list: the OR'd
	 * EXISTS / NOT EXISTS clauses make WP_Meta_Query LEFT JOIN, so every post
	 * is returned. We order on the NOT EXISTS clause because its join is
	 * restricted to our meta key (the EXISTS join is not, and would pick an
	 * arbitrary meta row after GROUP BY); unscored posts sort as NULL — top
	 * when ascending, bottom when descending.
	 *
	 * @param array $vars
	 * @return array
	 */
	public function sort_query(array $vars): array
	{
		if (
			!is_admin() ||
			! isset($vars['orderby']) ||
			$vars['orderby'] !== 'crawlwp_seo_score'
		) {
			return $vars;
		}

		$order = (isset($vars['order']) && strtoupper((string) $vars['order']) === 'ASC') ? 'ASC' : 'DESC';

		$score_query = [
			'relation'           => 'OR',
			'cwp_seo_score'      => [
				'key'     => MetaFields::SEO_SCORE,
				'compare' => 'EXISTS',
				'type'    => 'DECIMAL(6,2)',
			],
			'cwp_seo_score_none' => [
				'key'     => MetaFields::SEO_SCORE,
				'compare' => 'NOT EXISTS',
				'type'    => 'DECIMAL(6,2)',
			],
		];

		if (! empty($vars['meta_query']) && is_array($vars['meta_query'])) {
			$vars['meta_query'] = [
				'relation' => 'AND',
				$vars['meta_query'],
				$score_query,
			];
		} else {
			$vars['meta_query'] = $score_query;
		}

		unset($vars['meta_key'], $vars['meta_value']);

		$vars['orderby'] = [
			'cwp_seo_score_none' => $order,
			'date'               => 'DESC',
		];

		return $vars;
	}

	// -------------------------------------------------------------------------
	// Inline CSS + JS
	// -------------------------------------------------------------------------

	/**
	 * Print minimal inline styles for the SEO score column and Quick Edit fields.
	 * Only emitted on post list screens.
	 */
	public function print_styles(): void
	{
		$screen = get_current_screen();

		if (! $screen || $screen->base !== 'edit') {
			return;
		}

		?>
		<style id="cwp-seo-score-styles">
			/* SEO signal strip column — CrawlWP */
			.wp-list-table .column-crawlwp_seo_score { width: 190px; }

			.cwp-seobar {
				display: flex;
				flex-direction: column;
				gap: 5px;
				width: 100%;
				max-width: 190px;
				cursor: default;
			}

			/* --- state palette (shared by segments and gauge) --- */
			.cwp-seobar .is-good    { --cwp-sig: #00a32a; --cwp-sig-dark: #007a20; }
			.cwp-seobar .is-warn    { --cwp-sig: #dba617; --cwp-sig-dark: #9a7400; }
			.cwp-seobar .is-bad     { --cwp-sig: #d63638; --cwp-sig-dark: #a72628; }
			.cwp-seobar .is-neutral { --cwp-sig: #5b7fa6; --cwp-sig-dark: #3f5d7d; }
			.cwp-seobar .is-ok      { --cwp-sig: #dba617; --cwp-sig-dark: #9a7400; }
			.cwp-seobar .is-poor    { --cwp-sig: #d63638; --cwp-sig-dark: #a72628; }
			.cwp-seobar .is-noindex { --cwp-sig: #8c8f94; --cwp-sig-dark: #646970; }

			/* --- the strip --- */
			.cwp-seobar__strip {
				display: flex;
				width: 100%;
				border-radius: 4px;
				overflow: visible;
				box-shadow: 0 0 0 1px rgba(0, 0, 0, .06);
			}

			.cwp-seobar__sig {
				position: relative;
				display: inline-flex;
				align-items: center;
				justify-content: center;
				flex: 1 1 0;
				min-width: 0;
				height: 22px;
				padding: 0 1px;
				background: var(--cwp-sig);
				color: #fff;
				font-size: 11px;
				font-weight: 600;
				line-height: 1;
				letter-spacing: .02em;
				box-shadow: inset -1px 0 0 rgba(255, 255, 255, .28);
				outline: none;
				transition: filter .12s ease;
			}

			.cwp-seobar__sig:first-child { border-radius: 4px 0 0 4px; }
			.cwp-seobar__sig:last-child  { border-radius: 0 4px 4px 0; box-shadow: none; }

			.cwp-seobar__sig:hover,
			.cwp-seobar__sig:focus-visible {
				filter: brightness(.92);
				z-index: 2;
			}

			.cwp-seobar__sig:focus-visible {
				box-shadow: 0 0 0 2px #fff, 0 0 0 4px var(--cwp-sig-dark);
				border-radius: 3px;
			}

			/* --- popover --- */
			.cwp-seobar__tip {
				position: absolute;
				top: calc(100% + 7px);
				left: 50%;
				transform: translateX(-50%);
				width: 260px;
				padding: 9px 11px 10px;
				background: #1d2327;
				color: #f0f0f1;
				font-size: 12px;
				font-weight: 400;
				line-height: 1.45;
				text-align: left;
				letter-spacing: 0;
				border-radius: 4px;
				box-shadow: 0 4px 14px rgba(0, 0, 0, .25);
				opacity: 0;
				visibility: hidden;
				pointer-events: none;
				z-index: 9999;
				transition: opacity .12s ease;
			}

			.cwp-seobar__tip::before {
				content: "";
				position: absolute;
				bottom: 100%;
				left: 50%;
				margin-left: -6px;
				border: 6px solid transparent;
				border-bottom-color: #1d2327;
			}

			.cwp-seobar__sig:hover .cwp-seobar__tip,
			.cwp-seobar__sig:focus-visible .cwp-seobar__tip,
			.cwp-seobar__score:hover .cwp-seobar__tip,
			.cwp-seobar__score:focus-visible .cwp-seobar__tip {
				opacity: 1;
				visibility: visible;
			}

			/* keep the popover on-screen for the first / last segments */
			.cwp-seobar__sig:first-child .cwp-seobar__tip { left: 0; transform: none; }
			.cwp-seobar__sig:first-child .cwp-seobar__tip::before { left: 11px; margin-left: 0; }
			.cwp-seobar__sig:last-child .cwp-seobar__tip { left: auto; right: 0; transform: none; }
			.cwp-seobar__sig:last-child .cwp-seobar__tip::before { left: auto; right: 11px; margin-left: 0; }

			.cwp-seobar__tip strong {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 8px;
				margin-bottom: 3px;
				font-size: 12px;
				color: #fff;
			}

			.cwp-seobar__tip em {
				font-style: normal;
				font-size: 10px;
				font-weight: 600;
				text-transform: uppercase;
				letter-spacing: .04em;
				padding: 1px 6px;
				border-radius: 9px;
				background: var(--cwp-sig);
				color: #fff;
			}

			.cwp-seobar__summary { display: block; }

			.cwp-seobar__detail {
				display: block;
				margin-top: 4px;
				padding-top: 4px;
				border-top: 1px solid rgba(255, 255, 255, .12);
				color: #c3c4c7;
				font-size: 11.5px;
			}

			/* --- score gauge --- */
			.cwp-seobar__score {
				position: relative;
				display: flex;
				align-items: center;
				gap: 6px;
				padding: 2px 0;
				border-radius: 3px;
				outline: none;
			}

			.cwp-seobar__score:hover,
			.cwp-seobar__score:focus-visible { z-index: 2; }

			.cwp-seobar__score:focus-visible { box-shadow: 0 0 0 2px var(--cwp-sig-dark); }

			.cwp-seobar__tip--score { left: 0; transform: none; }
			.cwp-seobar__tip--score::before { left: 11px; margin-left: 0; }

			.cwp-seobar__track {
				flex: 1 1 auto;
				height: 4px;
				border-radius: 2px;
				background: #e0e0e0;
				overflow: hidden;
			}

			.cwp-seobar__fill {
				display: block;
				height: 100%;
				border-radius: 2px;
				background: var(--cwp-sig);
				transition: width .2s ease;
			}

			.cwp-seobar__num {
				flex: 0 0 auto;
				min-width: 18px;
				font-size: 11px;
				font-weight: 600;
				font-variant-numeric: tabular-nums;
				text-align: right;
				color: var(--cwp-sig-dark);
			}

			/* Quick Edit SEO fields */
			.cwp-quick-edit-seo__heading {
				margin-bottom: 2px;
				padding: 0;
			}

			.cwp-quick-edit-seo__label {
				display: flex;
				align-items: baseline;
				margin-bottom: 8px;
			}

			.cwp-quick-edit-seo__label .title {
				min-width: 110px;
				padding-right: 8px;
				font-size: 12px;
				color: #646970;
			}

			.cwp-quick-edit-seo__title,
			.cwp-quick-edit-seo__desc {
				flex: 1;
				width: 100%;
				box-sizing: border-box;
				font-size: 13px;
			}
		</style>
		<?php
	}

	/**
	 * Print the JS that pre-fills our Quick Edit fields from the data attributes
	 * stored on the score span in the list row.
	 * Only emitted on post list screens.
	 */
	public function print_quick_edit_js(): void
	{
		$screen = get_current_screen();

		if (! $screen || $screen->base !== 'edit') {
			return;
		}

		?>
		<script id="cwp-seo-quick-edit-js">
		(function($) {
			'use strict';

			$(document).ready(function() {
				if (typeof inlineEditPost === 'undefined') { return; }

				/* Store a reference to the original open() method. */
				var _originalOpen = inlineEditPost.open;

				/* Override open() so we can pre-fill our fields each time the row expands. */
				inlineEditPost.open = function(id) {
					/* Call the original first so WP sets up the row. */
					_originalOpen.apply(this, arguments);

					/* Resolve the numeric post ID (WP may pass the TR id string). */
					var postId = (typeof id === 'string') ? id.replace(/[^0-9]/g, '') : String(id);
					if (!postId) { return; }

					/* Read stored values from the data attributes on the signal bar. */
					var $score = $('#post-' + postId + ' .cwp-seobar');
					if (!$score.length) { return; }

					var seoTitle = $score.data('cwp-seo-title') || '';
					var seoDesc  = $score.data('cwp-seo-desc')  || '';

					/* Find the Quick Edit row that WP just revealed and fill our fields. */
					var $editRow = $('#edit-' + postId);
					$editRow.find('.cwp-quick-edit-seo__title').val(seoTitle);
					$editRow.find('.cwp-quick-edit-seo__desc').val(seoDesc);
				};
			});
		}(jQuery));
		</script>
		<?php
	}
}
