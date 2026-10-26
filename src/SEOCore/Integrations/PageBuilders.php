<?php

namespace Mihdan\IndexNow\SEOCore\Integrations;

/**
 * Lets the SEO box analysis and the automatic meta description read posts
 * built with Bricks, Divi or Avada.
 *
 * Both keep a post's text outside what the editor shows: Bricks in post meta,
 * Divi 5 inside the settings of its blocks. Both are edited in their own
 * front-end builder, so the content can't change while the edit screen is
 * open, and a plain HTML copy made here when the screen loads is enough.
 * (Divi 4 and Avada keep the text in the post content, wrapped in
 * shortcodes; the metabox script unwraps those as the user types.)
 */
class PageBuilders
{
	/** Bricks' meta key for the page content (BRICKS_DB_PAGE_CONTENT). */
	private const BRICKS_CONTENT = '_bricks_page_content_2';

	public function __construct()
	{
		add_filter('crawlwp_metabox_localize_data', [$this, 'localize'], 10, 2);
		add_filter('crawlwp_post_content_for_text', [$this, 'content_for_text'], 10, 2);
	}

	/**
	 * The text of a builder page for the automatic meta description.
	 *
	 * strip_shortcodes() removes Divi's and Avada's shortcodes together with
	 * the text inside them, so only their tags are removed here; Bricks and
	 * Divi 5 content comes from {@see self::for_post()}.
	 *
	 * @param string        $content
	 * @param \WP_Post|null $post
	 */
	public function content_for_text($content, $post = null): string
	{
		$content = (string) $content;

		if ($post instanceof \WP_Post) {
			$builder = self::for_post($post);

			if ($builder !== null) {
				/* A space between elements, so a heading's last word doesn't run into the next paragraph. */
				return str_replace('><', '> <', $builder['html']);
			}
		}

		return (string) preg_replace('/\[\/?(?:et_pb|fusion)_[^\]]*\]/', ' ', $content);
	}

	/**
	 * @param array         $data
	 * @param \WP_Post|null $post
	 */
	public function localize($data, $post = null): array
	{
		$data = (array) $data;

		if ($post instanceof \WP_Post) {
			$builder = self::for_post($post);

			if ($builder !== null) {
				$data['builder'] = $builder;
			}
		}

		return $data;
	}

	/**
	 * The builder a post is made with, and its content as plain HTML.
	 *
	 * @return array{name:string, html:string}|null Null for posts the editor content describes fully.
	 */
	public static function for_post(\WP_Post $post): ?array
	{
		$builder = null;

		if (get_post_meta($post->ID, '_bricks_editor_mode', true) === 'bricks') {
			$elements = get_post_meta($post->ID, self::BRICKS_CONTENT, true);
			$builder  = is_array($elements) && $elements !== [] ? ['name' => 'Bricks', 'html' => self::bricks_html($elements)] : null;
		} elseif (strpos($post->post_content, '<!-- wp:divi/') !== false) {
			$builder = ['name' => 'Divi', 'html' => self::divi5_html(parse_blocks($post->post_content))];
		}

		/**
		 * Filter the page builder content the SEO box analyses.
		 *
		 * @param array{name:string, html:string}|null $builder Null when the editor content is used.
		 * @param \WP_Post                             $post    The post being edited.
		 */
		return apply_filters('crawlwp_page_builder_content', $builder, $post);
	}

	// -------------------------------------------------------------------------
	// Bricks
	// -------------------------------------------------------------------------

	/**
	 * Bricks elements (a flat list linked by parent and children) as HTML, in page order.
	 *
	 * @param array<int, array<string, mixed>> $elements
	 */
	public static function bricks_html(array $elements): string
	{
		$by_id = [];
		$roots = [];

		foreach ($elements as $element) {
			if (! is_array($element) || empty($element['id'])) {
				continue;
			}

			$by_id[(string) $element['id']] = $element;

			if (empty($element['parent'])) {
				$roots[] = (string) $element['id'];
			}
		}

		$html = '';
		$seen = [];
		$walk = static function (string $id) use (&$walk, &$html, &$seen, $by_id): void {
			if (! isset($by_id[$id]) || isset($seen[$id])) {
				return;
			}

			$seen[$id] = true;
			$html     .= self::bricks_element($by_id[$id]);

			foreach ((array) ($by_id[$id]['children'] ?? []) as $child) {
				$walk((string) $child);
			}
		};

		foreach ($roots as $id) {
			$walk($id);
		}

		return $html;
	}

	/**
	 * One Bricks element's own content (its children are added after it).
	 *
	 * @param array<string, mixed> $element
	 */
	private static function bricks_element(array $element): string
	{
		$settings = (array) ($element['settings'] ?? []);
		$text     = isset($settings['text']) && is_string($settings['text']) ? $settings['text'] : '';

		switch ((string) ($element['name'] ?? '')) {
			case 'heading':
				/* Bricks' heading element defaults to h3. */
				$tag = self::heading_tag((string) ($settings['tag'] ?? 'h3'), 'h3');

				return $text !== '' ? "<{$tag}>" . wp_kses_post($text) . "</{$tag}>" : '';

			case 'text':
			case 'rich-text':
				return $text !== '' ? wpautop(wp_kses_post($text)) : '';

			case 'text-basic':
				return $text !== '' ? '<p>' . wp_kses_post($text) . '</p>' : '';

			case 'button':
			case 'text-link':
				$url = self::bricks_link($settings['link'] ?? null);

				return $text === '' ? '' : ($url !== ''
					? '<p><a href="' . esc_url($url) . '">' . esc_html(wp_strip_all_tags($text)) . '</a></p>'
					: '<p>' . esc_html(wp_strip_all_tags($text)) . '</p>');

			case 'image':
				$image = (array) ($settings['image'] ?? []);
				$id    = (int) ($image['id'] ?? 0);
				$url   = (string) ($image['url'] ?? ($id > 0 ? wp_get_attachment_url($id) : ''));
				/* A custom alt text, else the one saved on the image in the media library. */
				$alt = isset($settings['altText']) ? (string) $settings['altText'] : ($id > 0 ? (string) get_post_meta($id, '_wp_attachment_image_alt', true) : '');

				return $url !== '' ? '<img src="' . esc_url($url) . '" alt="' . esc_attr($alt) . '">' : '';
		}

		/* Other elements: keep any plain text setting they have. */
		return $text !== '' ? '<p>' . wp_kses_post($text) . '</p>' : '';
	}

	/**
	 * The address a Bricks link setting points to.
	 *
	 * @param mixed $link e.g. ['type' => 'external', 'url' => '…'] or ['type' => 'internal', 'postId' => 12].
	 */
	private static function bricks_link($link): string
	{
		if (! is_array($link)) {
			return '';
		}

		if (($link['type'] ?? '') === 'internal' && ! empty($link['postId'])) {
			return (string) get_permalink((int) $link['postId']);
		}

		return isset($link['url']) && is_string($link['url']) ? $link['url'] : '';
	}

	// -------------------------------------------------------------------------
	// Divi 5
	// -------------------------------------------------------------------------

	/**
	 * Divi 5 blocks as HTML: each module's text, which Divi keeps in
	 * `<field>.innerContent.desktop.value` of the block's settings.
	 *
	 * @param array<int, array<string, mixed>> $blocks From parse_blocks().
	 */
	public static function divi5_html(array $blocks): string
	{
		$html = '';

		foreach ($blocks as $block) {
			$name  = (string) ($block['blockName'] ?? '');
			$attrs = (array) ($block['attrs'] ?? []);

			if (strpos($name, 'divi/') === 0) {
				$html .= self::divi5_module(substr($name, 5), $attrs);
			} elseif (empty($block['innerBlocks'])) {
				/* A core block placed between Divi modules: its saved HTML (not rendered, so no queries). */
				$html .= (string) ($block['innerHTML'] ?? '');
			}

			if (! empty($block['innerBlocks'])) {
				$html .= self::divi5_html($block['innerBlocks']);
			}
		}

		return $html;
	}

	/**
	 * @param array<string, mixed> $attrs
	 */
	private static function divi5_module(string $module, array $attrs): string
	{
		$html = '';

		foreach ($attrs as $field => $value) {
			$content = is_array($value) ? ($value['innerContent']['desktop']['value'] ?? null) : null;

			if ($content === null) {
				continue;
			}

			/* A button's or link's value holds its text and address. */
			if (is_array($content)) {
				$text = (string) ($content['text'] ?? '');
				$url  = (string) ($content['linkUrl'] ?? ($content['url'] ?? ''));

				if ($text !== '') {
					$html .= $url !== '' ? '<p><a href="' . esc_url($url) . '">' . esc_html($text) . '</a></p>' : '<p>' . esc_html($text) . '</p>';
				}

				continue;
			}

			$content = trim((string) $content);

			if ($content === '') {
				continue;
			}

			/* The title of a heading module (and of blurbs, toggles, …) is a heading. */
			if ($field === 'title') {
				$tag   = self::heading_tag(self::find_key($value, 'headingLevel') ?? '', $module === 'heading' ? 'h2' : 'h3');
				$html .= "<{$tag}>" . wp_kses_post($content) . "</{$tag}>";
			} else {
				$html .= wpautop(wp_kses_post($content));
			}
		}

		return $html;
	}

	/**
	 * First string value stored under $key anywhere inside $data.
	 *
	 * @param mixed $data
	 */
	private static function find_key($data, string $key): ?string
	{
		if (! is_array($data)) {
			return null;
		}

		foreach ($data as $k => $v) {
			if ($k === $key && is_string($v)) {
				return $v;
			}

			$found = self::find_key($v, $key);

			if ($found !== null) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * A valid h1–h6 tag, or the fallback.
	 */
	private static function heading_tag(string $tag, string $fallback): string
	{
		$tag = strtolower(trim($tag));

		return preg_match('/^h[1-6]$/', $tag) ? $tag : $fallback;
	}
}
