<?php

namespace Mihdan\IndexNow\SEOCore\Breadcrumbs;

use Mihdan\IndexNow\SEOCore\Breadcrumbs\BreadcrumbSettings;
use Mihdan\IndexNow\SEOCore\MetaBox\MetaFields;
use Mihdan\IndexNow\Utils;

/**
 * Builds and renders HTML breadcrumbs for every public front-end context.
 *
 * Usage:
 *   [crawlwp_breadcrumbs]                       — shortcode with defaults
 *   [crawlwp_breadcrumbs separator="/" label_home="Start" display_current="false"]
 *
 * Developers can filter the link list:
 *   add_filter('crawlwp_breadcrumbs_links', function($links) { … return $links; });
 *
 * Or adjust the args before parsing:
 *   add_filter('crawlwp_breadcrumbs_args', function($args) { … return $args; });
 */
class Breadcrumbs
{
	/** @var array Default arguments for the breadcrumb trail. */
	private $args = [];

	/** @var array Resolved ancestor link items [['url'=>…,'text'=>…], …] */
	private $links = [];

	/** @var string Display text for the current (last) crumb — not linked. */
	private $current = '';

	/** @var string Known URL of the current crumb, when it cannot be derived from the query. */
	private $current_url = '';

	/** @var bool Whether parse() has run for the current request. */
	private $is_parsed = false;

	public function __construct()
	{
		$this->args = [
			'separator'       => BreadcrumbSettings::get('separator', '›'),
			'taxonomy'        => BreadcrumbSettings::get('taxonomy', 'category'),
			'display_current' => BreadcrumbSettings::get('display_current', 'on') !== 'off' ? 'true' : 'false',
			'label_home'      => BreadcrumbSettings::get('label_home', __('Home', 'mihdan-index-now')),
			/* translators: %s = search query */
			'label_search'    => __('Search results for &#8220;%s&#8221;', 'mihdan-index-now'),
			'label_404'       => __('Page not found', 'mihdan-index-now'),
		];
	}

	public function is_enabled()
	{
		$enabled = BreadcrumbSettings::get('enabled', 'on') === 'on';

		return apply_filters('crawlwp_breadcrumbs_enabled', $enabled);
	}

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	public function setup(): void
	{
		if (!$this->is_enabled()) return;

		add_shortcode('crawlwp_breadcrumbs', [$this, 'render_shortcode']);
		add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);
	}

	/**
	 * Enqueue the default breadcrumb stylesheet.
	 */
	public function enqueue_styles(): void
	{
		$suffix = Utils::asset_suffix();

		wp_enqueue_style(
			'crawlwp-breadcrumbs',
			plugin_dir_url(__FILE__) . 'assets/breadcrumbs' . $suffix . '.css',
			[],
			'1.0.0'
		);
	}

	// -------------------------------------------------------------------------
	// Public render API
	// -------------------------------------------------------------------------

	/**
	 * Shortcode callback — [crawlwp_breadcrumbs].
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public function render_shortcode($atts): string
	{
		$this->args = wp_parse_args((array) $atts, $this->args);
		$this->parse();

		$links = $this->get_links();

		if (empty($links) && $this->current === '') {
			return '';
		}

		$sep = '<span class="cwp-bc__sep" aria-hidden="true">' . wp_kses((string) $this->args['separator'], self::separator_allowed_html()) . '</span>';

		$output = '<nav class="cwp-bc" aria-label="' . esc_attr__('Breadcrumbs', 'mihdan-index-now') . '">';

		$items = [];

		foreach ($links as $i => $link) {
			$class   = $i === 0 ? ' cwp-bc__item--first' : '';
			$items[] = sprintf(
				'<a href="%s" class="cwp-bc__item%s">%s</a>',
				esc_url($link['url']),
				$class,
				esc_html($link['text'])
			);
		}

		if ($this->current !== '' && $this->args['display_current'] !== 'false') {
			$items[] = sprintf(
				'<span class="cwp-bc__item cwp-bc__item--current" aria-current="page">%s</span>',
				esc_html($this->current)
			);
		}

		$output .= implode(' ' . $sep . ' ', $items);
		$output .= '</nav>';

		/**
		 * Filter the final rendered breadcrumb HTML.
		 *
		 * @param string $output The complete HTML string.
		 * @param array  $links  The resolved link array (ancestor items only).
		 * @param array  $args   The breadcrumb arguments.
		 */
		$output = (string) apply_filters('crawlwp_breadcrumbs_html', $output, $links, $this->args);

		return $output;
	}

	/**
	 * The HTML allowed inside the separator. The separator is a free-text
	 * setting, so entities (`&raquo;`), icon markup and inline SVG all need
	 * to survive — running it through esc_html() would print them literally.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private static function separator_allowed_html(): array
	{
		$allowed = [
			'span' => [
				'class'       => true,
				'style'       => true,
				'aria-hidden' => true,
			],
			'i'    => [
				'class'       => true,
				'style'       => true,
				'aria-hidden' => true,
			],
			'em'   => [
				'class' => true,
				'style' => true,
			],
			'svg'  => [
				'class'               => true,
				'style'               => true,
				'xmlns'               => true,
				'viewbox'             => true,
				'width'               => true,
				'height'              => true,
				'fill'                => true,
				'stroke'              => true,
				'stroke-width'        => true,
				'stroke-linecap'      => true,
				'stroke-linejoin'     => true,
				'role'                => true,
				'focusable'           => true,
				'aria-hidden'         => true,
				'preserveaspectratio' => true,
			],
			'g'    => [
				'fill'      => true,
				'stroke'    => true,
				'transform' => true,
			],
			'path' => [
				'd'               => true,
				'fill'            => true,
				'fill-rule'       => true,
				'clip-rule'       => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
			],
		];

		/**
		 * Filters the HTML allowed inside the breadcrumb separator.
		 *
		 * @param array $allowed Tag => attribute allowlist, in wp_kses() format.
		 */
		return (array) apply_filters('crawlwp_breadcrumbs_separator_allowed_html', $allowed);
	}

	// -------------------------------------------------------------------------
	// BreadcrumbList JSON-LD
	// -------------------------------------------------------------------------

	/**
	 * Returns a BreadcrumbList schema node for inclusion in the site graph,
	 * or null when there is nothing to emit (e.g. the homepage) or when
	 * the schema output is disabled in settings.
	 *
	 * @return array|null
	 */
	public function get_schema_node(): ?array
	{
		if (BreadcrumbSettings::get('schema_enabled', 'on') === 'off') {
			return null;
		}

		$this->parse();

		$links = $this->get_links();

		/* The current page is included as the last item when its URL can be
		 * resolved — see get_current_url().
		 */
		if (empty($links) && $this->current === '') {
			return null;
		}

		$list     = [];
		$position = 1;

		foreach ($links as $link) {
			$list[] = [
				'@type'    => 'ListItem',
				'position' => $position++,
				'name'     => $link['text'],
				'item'     => $link['url'],
			];
		}

		if ($this->current !== '') {
			$cur_url = $this->get_current_url();

			if ($cur_url !== '') {
				$list[] = [
					'@type'    => 'ListItem',
					'position' => $position,
					'name'     => $this->current,
					'item'     => $cur_url,
				];
			}
		}

		if (empty($list)) {
			return null;
		}

		/* The node describes this request, not the site: key it on the current
		 * page so every URL gets its own BreadcrumbList in the graph. Falls
		 * back to the homepage when the URL cannot be resolved.
		 */
		$base = $this->get_current_url();

		if ($base === '') {
			$base = home_url('/');
		}

		return [
			'@type'           => 'BreadcrumbList',
			'@id'             => $base . '#breadcrumb',
			'itemListElement' => $list,
		];
	}

	// -------------------------------------------------------------------------
	// Link list accessors
	// -------------------------------------------------------------------------

	/**
	 * Returns the filtered link array (ancestor items only, NOT the current page).
	 *
	 * @return array
	 */
	public function get_links(): array
	{
		return apply_filters('crawlwp_breadcrumbs_links', $this->links);
	}

	/**
	 * The canonical URL of the current (last) crumb, or an empty string when
	 * it cannot be resolved. Paginated requests store their URL up front —
	 * everything else is derived from the query, because get_permalink() is
	 * unreliable on non-singular requests.
	 */
	private function get_current_url(): string
	{
		if ($this->current_url !== '') {
			return $this->current_url;
		}

		if (is_singular()) {
			return (string) get_permalink();
		}

		if (is_home()) {
			return (string) get_permalink(get_option('page_for_posts'));
		}

		if (is_post_type_archive()) {
			return (string) get_post_type_archive_link(get_query_var('post_type'));
		}

		if (is_tax() || is_category() || is_tag()) {
			$term = get_queried_object();
			$link = $term ? get_term_link($term) : '';

			return is_string($link) ? $link : '';
		}

		if (is_author()) {
			return (string) get_author_posts_url(get_queried_object_id());
		}

		return '';
	}

	// -------------------------------------------------------------------------
	// Parsing — build $links and $current
	// -------------------------------------------------------------------------

	public function parse(): void
	{
		if ($this->is_parsed) {
			return;
		}

		/* Allow developers to change args before we start building. */
		$this->args = apply_filters('crawlwp_breadcrumbs_args', $this->args);

		if (is_front_page()) {
			/* Nothing to show on the actual homepage. */
			$this->is_parsed = true;
			return;
		}

		/* Every trail starts with Home. */
		$this->add_link(home_url('/'), $this->args['label_home']);

		if (is_home()) {
			/* Static blog page. */
			$this->current = (string) single_post_title('', false);
		} elseif (is_post_type_archive()) {
			$this->current = (string) post_type_archive_title('', false);
		} elseif (is_singular()) {
			$this->add_singular();
		} elseif (is_tax() || is_category() || is_tag()) {
			$term = get_queried_object();
			if ($term instanceof \WP_Term) {
				$taxonomy = get_taxonomy($term->taxonomy);
				if ($taxonomy && ! empty($taxonomy->object_type) && count($taxonomy->object_type) === 1) {
					$this->add_post_type_archive_link(reset($taxonomy->object_type));
				}
				$this->add_term_ancestors($term);
			}
			$this->current = (string) single_term_title('', false);
		} elseif (is_search()) {
			$this->current = sprintf($this->args['label_search'], get_search_query());
		} elseif (is_404()) {
			$this->current = $this->args['label_404'];
		} elseif (is_author()) {
			$author        = get_queried_object();
			$this->current = $author instanceof \WP_User ? $author->display_name : '';
		} elseif (is_date()) {
			$this->add_date_links();
		}

		$this->add_pagination();

		$this->is_parsed = true;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Paginated requests are a different document: `/category/news/page/2/`
	 * must not claim to be the page-1 trail, in the HTML output nor in the
	 * BreadcrumbList. Demote the current crumb to a link and make the page
	 * number the last item.
	 */
	private function add_pagination(): void
	{
		$paged = (int) get_query_var('paged');
		/* Multi-page singulars (<!--nextpage-->) use "page" instead of "paged". */
		$page  = (int) get_query_var('page');

		if ($paged < 2 && $page < 2) {
			return;
		}

		if ($this->current !== '') {
			$url = $this->get_current_url();

			if ($url !== '') {
				$this->add_link($url, $this->current);
			}
		}

		if ($paged > 1) {
			$number            = $paged;
			$pagenum_link      = get_pagenum_link($paged, false);
			$this->current_url = is_string($pagenum_link) ? $pagenum_link : '';
		} else {
			$number            = $page;
			$this->current_url = $this->get_singular_page_url($page);
		}

		/* translators: %s = page number */
		$this->current = sprintf(__('Page %s', 'mihdan-index-now'), number_format_i18n($number));
	}

	/**
	 * URL of a sub-page of a multi-page singular post, mirroring the way
	 * WordPress' own _wp_link_page() builds it.
	 */
	private function get_singular_page_url(int $page): string
	{
		$permalink = get_permalink();

		if (! is_string($permalink) || $permalink === '') {
			return '';
		}

		if ((string) get_option('permalink_structure') === '') {
			return (string) add_query_arg('page', $page, $permalink);
		}

		return trailingslashit($permalink) . user_trailingslashit((string) $page, 'single_paged');
	}

	private function add_singular(): void
	{
		$post = get_queried_object();

		if (! $post instanceof \WP_Post) {
			return;
		}

		/* An editor can override the crumb text in the SEO metabox. */
		$label = (string) MetaFields::get($post->ID, MetaFields::SCHEMA_BREADCRUMB);

		$this->current = $label !== '' ? $label : (string) single_post_title('', false);

		$this->add_post_type_archive_link($post->post_type);

		/* Hierarchical post types (pages): add ancestor pages. */
		if (is_post_type_hierarchical($post->post_type)) {
			$ancestors = array_reverse(get_post_ancestors($post));
			foreach ($ancestors as $ancestor_id) {
				$this->add_link((string) get_permalink($ancestor_id), (string) get_the_title($ancestor_id));
			}
		} else {
			/* Non-hierarchical: add the primary term and its ancestors. */
			$terms = get_the_terms($post, $this->args['taxonomy']);
			if (is_array($terms) && ! empty($terms)) {
				$term    = reset($terms);
				$primary = (int) MetaFields::get($post->ID, MetaFields::PRIMARY_CATEGORY, 0);

				if ($primary > 0) {
					foreach ($terms as $candidate) {
						if ((int) $candidate->term_id === $primary) {
							$term = $candidate;
							break;
						}
					}
				}

				$this->add_term_ancestors($term);
				$this->add_link((string) get_term_link($term), $term->name);
			}
		}
	}

	private function add_post_type_archive_link(string $post_type): void
	{
		if ($post_type === 'post') {
			/* Use the "Blog" page when a static front page is configured. */
			$blog_page_id = (int) get_option('page_for_posts');
			if (get_option('show_on_front') === 'page' && $blog_page_id) {
				$this->add_link((string) get_permalink($blog_page_id), (string) get_the_title($blog_page_id));
			}
			return;
		}

		$link = get_post_type_archive_link($post_type);
		$pto  = get_post_type_object($post_type);

		if ($link && $pto) {
			$this->add_link($link, $pto->labels->name);
		}
	}

	private function add_term_ancestors(\WP_Term $term): void
	{
		$ancestors = array_reverse(get_ancestors($term->term_id, $term->taxonomy, 'taxonomy'));
		foreach ($ancestors as $ancestor_id) {
			$ancestor = get_term($ancestor_id, $term->taxonomy);
			if ($ancestor instanceof \WP_Term) {
				$this->add_link((string) get_term_link($ancestor), $ancestor->name);
			}
		}
	}

	private function add_date_links(): void
	{
		global $wp_locale;

		$year  = (int) get_query_var('year');
		$month = (int) get_query_var('monthnum');
		$day   = (int) get_query_var('day');

		if (is_year()) {
			$this->current = (string) $year;
			return;
		}

		$month_label = $wp_locale->get_month($month);

		if (is_month()) {
			$this->add_link((string) get_year_link($year), (string) $year);
			$this->current = $month_label;
			return;
		}

		/* Daily archive. */
		$this->add_link((string) get_year_link($year), (string) $year);
		$this->add_link((string) get_month_link($year, $month), $month_label);
		$this->current = zeroise($day, 2);
	}

	private function add_link(string $url, string $text): void
	{
		$this->links[] = [
			'url'  => $url,
			'text' => $text,
		];
	}
}
