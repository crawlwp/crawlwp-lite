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
	/** The page's plain text, remembered until the post changes: ['modified' => post_modified_gmt, 'text' => …]. */
	public const TEXT_META = '_crawlwp_builder_text';

	/** Shortcode content beyond this is not scanned (and very long builder HTML is not sent to the editor script). */
	private const MAX_BYTES = 300000;

	/** Bricks' meta key for the page content (BRICKS_DB_PAGE_CONTENT). */
	private const BRICKS_CONTENT = '_bricks_page_content_2';

	public function __construct()
	{
		add_filter('crawlwp_metabox_localize_data', [$this, 'localize'], 10, 2);
		add_filter('crawlwp_post_content_for_text', [$this, 'content_for_text'], 10, 2);

		/* Bricks saves its content as post meta, which may not change the post's modified date. */
		foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) {
			add_action($hook, [$this, 'forget_text'], 10, 3);
		}
	}

	/**
	 * @param mixed $meta_id
	 * @param mixed $post_id
	 * @param mixed $meta_key
	 */
	public function forget_text($meta_id, $post_id, $meta_key): void
	{
		if ($meta_key === self::BRICKS_CONTENT) {
			delete_post_meta((int) $post_id, self::TEXT_META);
		}
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

		if (! $post instanceof \WP_Post) {
			return self::shortcodes_to_text($content);
		}

		/*
		 * The description runs on page views, but only needs the first words:
		 * build the text once per change of the post and keep it (a page's
		 * builder HTML can take tens of milliseconds and a query per image).
		 */
		$saved = get_post_meta($post->ID, self::TEXT_META, true);

		if (is_array($saved) && ($saved['modified'] ?? '') === $post->post_modified_gmt && isset($saved['text']) && is_string($saved['text'])) {
			return $saved['text'];
		}

		$builder = self::for_post($post);

		if ($builder !== null) {
			/* A space between elements, so a heading's last word doesn't run into the next paragraph. */
			$text = str_replace('><', '> <', $builder['html']);
		} elseif (strpos($content, '[et_pb_') !== false || strpos($content, '[fusion_') !== false) {
			$text = self::shortcodes_to_text($content);
		} else {
			return $content; // Not a builder page: nothing to remember.
		}

		$text = wp_trim_words(wp_strip_all_tags(strip_shortcodes($text)), 100, '');

		if (! wp_is_post_revision($post) && $post->post_status !== 'auto-draft') {
			update_post_meta($post->ID, self::TEXT_META, ['modified' => $post->post_modified_gmt, 'text' => $text]);
		}

		return $text;
	}

	/**
	 * Divi 4 or Avada content with the shortcode tags turned into text.
	 */
	private static function shortcodes_to_text(string $content): string
	{
		if (strpos($content, '[et_pb_') === false && strpos($content, '[fusion_') === false) {
			return $content;
		}

		/* Only the start matters for a description, and it keeps the patterns below fast. */
		$content = substr($content, 0, self::MAX_BYTES);

		/* Avada: an image frame holds an image URL and a code block base64-encoded markup, neither of them text. */
		$content = self::drop_tags_with_content($content, ['fusion_imageframe', 'fusion_code']);

		/* Divi 4 keeps headings and button labels in the shortcode's settings, as the metabox script reads them. */
		$content = preg_replace_callback(
			'/\[et_pb_(heading|blurb|cta|button)\b([^\]]*)\]/',
			static function (array $m): string {
				$attrs = shortcode_parse_atts($m[2]);
				$attrs = is_array($attrs) ? $attrs : [];
				$text  = [];

				foreach ($m[1] === 'button' ? ['button_text'] : ['title', 'button_text'] as $key) {
					if (isset($attrs[$key]) && is_string($attrs[$key]) && $attrs[$key] !== '') {
						/* Divi stores " [ ] inside settings as %22 %91 %93. */
						$text[] = str_replace(['%22', '%91', '%93'], ['"', '[', ']'], $attrs[$key]);
					}
				}

				return ' ' . implode('. ', $text) . ' ';
			},
			$content
		) ?? $content;

		/* A pattern that hits PCRE's limits returns null: keep what we have rather than an empty description. */
		return preg_replace('/\[\/?(?:et_pb|fusion)_[^\]]*\]/', ' ', $content) ?? $content;
	}

	/**
	 * Remove [tag]…[/tag] blocks, content included. A linear scan: an unclosed
	 * tag ends the search instead of making a pattern re-scan the rest each time.
	 *
	 * @param string[] $tags
	 */
	private static function drop_tags_with_content(string $content, array $tags): string
	{
		foreach ($tags as $tag) {
			$out = '';
			$pos = 0;

			while (($start = strpos($content, '[' . $tag, $pos)) !== false) {
				$next = $content[$start + strlen($tag) + 1] ?? '';

				/* [fusion_code_x] is another shortcode. */
				if ($next !== ']' && $next !== ' ') {
					$out .= substr($content, $pos, $start + 1 - $pos);
					$pos  = $start + 1;
					continue;
				}

				$end = strpos($content, '[/' . $tag . ']', $start);

				if ($end === false) {
					break;
				}

				$out .= substr($content, $pos, $start - $pos) . ' ';
				$pos  = $end + strlen($tag) + 3;
			}

			$content = $out . substr($content, $pos);
		}

		return $content;
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
				/* Very large pages: the start is enough for the analysis and keeps the editor page light. */
				if (strlen($builder['html']) > self::MAX_BYTES) {
					$cut             = strrpos(substr($builder['html'], 0, self::MAX_BYTES), '>');
					$builder['html'] = substr($builder['html'], 0, $cut === false ? self::MAX_BYTES : $cut + 1);
				}

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
		$builder = apply_filters('crawlwp_page_builder_content', $builder, $post);

		return is_array($builder) && isset($builder['html']) && is_string($builder['html'])
			? ['name' => self::str($builder['name'] ?? ''), 'html' => $builder['html']]
			: null;
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
			if (! is_array($element) || self::str($element['id'] ?? '') === '') {
				continue;
			}

			$by_id[self::str($element['id'])] = $element;

			if (empty($element['parent'])) {
				$roots[] = self::str($element['id']);
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

			foreach ((is_array($by_id[$id]['children'] ?? null) ? $by_id[$id]['children'] : []) as $child) {
				$walk(self::str($child));
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
		$settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];
		$text     = isset($settings['text']) && is_string($settings['text']) ? $settings['text'] : '';

		switch (self::str($element['name'] ?? '')) {
			case 'heading':
				/* Bricks' heading element defaults to h3. */
				$tag = self::heading_tag(self::str($settings['tag'] ?? 'h3'), 'h3');

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
				$image = is_array($settings['image'] ?? null) ? $settings['image'] : [];
				$id    = (int) self::str($image['id'] ?? 0);
				$url   = self::str($image['url'] ?? '') ?: ($id > 0 ? (string) wp_get_attachment_url($id) : '');
				/* A custom alt text, else the one saved on the image in the media library. */
				$alt = isset($settings['altText']) ? self::str($settings['altText']) : ($id > 0 ? (string) get_post_meta($id, '_wp_attachment_image_alt', true) : '');

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
			$name  = self::str($block['blockName'] ?? '');
			$attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];

			if (strpos($name, 'divi/') === 0) {
				$html .= self::divi5_module(substr($name, 5), $attrs);
			} elseif (empty($block['innerBlocks'])) {
				/* A core block placed between Divi modules: its saved HTML (not rendered, so no queries). */
				$html .= self::str($block['innerHTML'] ?? '');
			}

			if (! empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
				$html .= self::divi5_html($block['innerBlocks']);
			}
		}

		return $html;
	}

	/**
	 * Default tag of each Divi 5 module's title (its module.json tagName), for titles without a heading level.
	 */
	private const DIVI5_TITLE_TAGS = [
		'heading'          => 'h1',
		'fullwidth-header' => 'h1',
		'contact-form'     => 'h1',
		'blurb'            => 'h4',
		'countdown-timer'  => 'h4',
		'toggle'           => 'h5',
		'accordion-item'   => 'h5',
		'number-counter'   => 'h3',
		'circle-counter'   => 'h3',
		'timeline-item'    => 'h3',
		'map-pin'          => 'h3',
	];

	/**
	 * One Divi 5 module. Each field keeps its value in `<field>.innerContent.desktop.value`:
	 * a string (text, HTML), or an object such as a button's {text, linkUrl},
	 * an image's {src, alt} or a blurb title's {text}.
	 *
	 * @param array<string, mixed> $attrs
	 */
	private static function divi5_module(string $module, array $attrs): string
	{
		$html = '';

		foreach ($attrs as $field => $value) {
			$content = is_array($value) ? ($value['innerContent']['desktop']['value'] ?? null) : null;

			if ($content === null || $content === '' || $content === []) {
				continue;
			}

			$is_title = $field === 'title';
			$link     = '';

			if (is_array($content)) {
				if (! empty($content['src'])) {
					$html .= '<img src="' . esc_url(self::str($content['src'])) . '" alt="' . esc_attr(self::str($content['alt'] ?? '')) . '">';
					continue;
				}

				$link    = self::str($content['linkUrl'] ?? '');
				$content = self::str($content['text'] ?? '');
			}

			$content = trim(self::str($content));

			if ($content === '') {
				continue;
			}

			if ($is_title) {
				$tag     = self::heading_tag(self::find_key($value, 'headingLevel') ?? '', self::DIVI5_TITLE_TAGS[$module] ?? 'h2');
				$content = $link !== '' ? '<a href="' . esc_url($link) . '">' . wp_kses_post($content) . '</a>' : wp_kses_post($content);
				$html   .= "<{$tag}>{$content}</{$tag}>";
			} elseif ($link !== '') {
				$html .= '<p><a href="' . esc_url($link) . '">' . esc_html(wp_strip_all_tags($content)) . '</a></p>';
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
	 * A builder setting as a string; arrays and objects (bad or unexpected data) become ''.
	 *
	 * @param mixed $value
	 */
	private static function str($value): string
	{
		return is_scalar($value) ? (string) $value : '';
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
