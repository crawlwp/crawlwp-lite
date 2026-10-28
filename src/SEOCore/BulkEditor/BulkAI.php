<?php

namespace Mihdan\IndexNow\SEOCore\BulkEditor;

use Mihdan\IndexNow\SEOCore\AI\Generator;
use Mihdan\IndexNow\SEOCore\AI\Requests;
use Mihdan\IndexNow\SEOCore\MetaBox\Assets;
use Mihdan\IndexNow\SEOCore\MetaBox\MetaFields;
use Mihdan\IndexNow\SEOCore\TitleMeta\Variables;

/**
 * AI writing in the Bulk Editor: one SEO title, meta description or image
 * alt text per request. "Write with AI" for many rows at once runs these
 * requests one after another from the browser.
 *
 * The result is returned to the table for review; nothing is saved here.
 */
class BulkAI
{
	/** {{ post.title }}, the token a generated title is put in place of. */
	const TITLE_TOKEN = '/\{\{\s*post\.title\s*\}\}/';

	public function __construct()
	{
		add_action('wp_ajax_crawlwp_bulk_editor_ai', [$this, 'ajax_generate']);
	}

	/**
	 * Whether "Write with AI" for many rows at once is available (CrawlWP Premium).
	 */
	public static function batch_enabled(): bool
	{
		/**
		 * Filter whether the Bulk Editor can write with AI for many rows at once.
		 *
		 * @param bool $enabled Default false; CrawlWP Premium turns it on.
		 */
		return (bool) apply_filters('crawlwp_bulk_ai_batch', false);
	}

	public function ajax_generate(): void
	{
		check_ajax_referer('crawlwp_bulk_editor_nonce', 'nonce');

		$id       = isset($_POST['id']) ? absint($_POST['id']) : 0;
		$field    = isset($_POST['field']) ? sanitize_key(wp_unslash($_POST['field'])) : '';
		$previous = isset($_POST['previous']) ? sanitize_textarea_field(wp_unslash($_POST['previous'])) : '';
		$batch    = ! empty($_POST['batch']) && self::batch_enabled();

		if (! $id || ! current_user_can('edit_post', $id)) {
			wp_send_json_error(['message' => __('You are not allowed to edit this item.', 'mihdan-index-now')], 403);
		}

		if (! in_array($field, ['seo_title', 'seo_description', 'alt'], true)) {
			wp_send_json_error(['message' => __('Unsupported field.', 'mihdan-index-now')]);
		}

		if (Requests::rate_limited($batch)) {
			wp_send_json_error(['message' => Requests::limit_message(), 'rateLimited' => true, 'dailyLimit' => Requests::limit_is_daily()], 429);
		}

		$text = $field === 'alt' ? $this->alt($id, $previous) : $this->post_field($id, $field, $previous);

		if (is_wp_error($text)) {
			$response = Requests::error_response($text);

			// Every other image would fail the same way: stop a "Write with AI" run.
			if ($text->get_error_code() === 'crawlwp_ai_no_vision') {
				$response['stop'] = true;
			}

			wp_send_json_error($response);
		}

		Requests::count_request($batch);

		wp_send_json_success(['text' => $text]);
	}

	/**
	 * @return string|\WP_Error
	 */
	private function alt(int $id, string $previous)
	{
		if (get_post_type($id) !== 'attachment') {
			return new \WP_Error('crawlwp_ai_not_image', __('This file is not an image.', 'mihdan-index-now'));
		}

		/**
		 * Filter the AI-written alt text for an image, e.g. to use another service.
		 *
		 * @param string $text          The alt text (empty by default).
		 * @param int    $attachment_id The image.
		 * @param string $previous      Its current alt text.
		 */
		$text = apply_filters('crawlwp_ai_generate_alt', '', $id, $previous);

		return ! empty($text) ? sanitize_text_field((string) $text) : (new Generator())->generate_alt($id, $previous);
	}

	/**
	 * SEO title or meta description, written like the SEO box does it: a
	 * title goes into the field's template (or the post type's) in place of
	 * {{ post.title }}, keeping the site name and separator around it.
	 *
	 * @return string|\WP_Error
	 */
	private function post_field(int $id, string $field, string $previous)
	{
		$post = get_post($id);

		if (! $post instanceof \WP_Post) {
			return new \WP_Error('crawlwp_ai_no_post', __('Post not found.', 'mihdan-index-now'));
		}

		$template = '';

		if ($field === 'seo_title') {
			$candidate = $previous !== '' ? $previous : Assets::title_meta_templates($post)['title'];
			$template  = preg_match(self::TITLE_TOKEN, $candidate) ? $candidate : '';
		}

		$context = [
			'post_id'  => $id,
			'title'    => (string) get_post_field('post_title', $id),
			'content'  => '',
			'keyword'  => (string) MetaFields::get($id, MetaFields::FOCUS_KEYWORD, ''),
			// Sent resolved, so the model rewrites the text instead of repeating variable tokens back.
			'previous' => $previous !== '' ? Variables::replace($previous, ['post' => $post]) : '',
			'reserved' => $template !== '' ? mb_strlen(Variables::replace((string) preg_replace(self::TITLE_TOKEN, '', $template), ['post' => $post])) : 0,
		];

		$ai_field = $field === 'seo_title' ? 'title' : 'description';

		/** This filter is documented in SEOCore/MetaBox/Assets.php. */
		$text = apply_filters('crawlwp_ai_generate_seo', '', $ai_field, $context);

		if (empty($text)) {
			$text = (new Generator())->generate($ai_field, $context);
		}

		if (is_wp_error($text)) {
			return $text;
		}

		$text = (string) $text;

		return $template !== '' ? self::into_template($template, $text) : $text;
	}

	/**
	 * Put a generated title in place of {{ post.title }} in a template.
	 */
	public static function into_template(string $template, string $text): string
	{
		return (string) preg_replace_callback(self::TITLE_TOKEN, static function () use ($text) {
			return $text;
		}, $template, 1);
	}
}
