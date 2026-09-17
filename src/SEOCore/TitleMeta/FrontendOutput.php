<?php

namespace Mihdan\IndexNow\SEOCore\TitleMeta;

use Mihdan\IndexNow\SEOCore\Breadcrumbs\Breadcrumbs;
use Mihdan\IndexNow\SEOCore\MetaBox\MetaFields;
use Mihdan\IndexNow\SEOCore\Schema\Graph;
use Mihdan\IndexNow\SEOCore\SiteInfoSettings\SiteInfoSettings;
use Mihdan\IndexNow\SEOCore\SocialSettings\SocialSettings;
use Mihdan\IndexNow\SEOCore\SocialSettings\UserProfile;
use Mihdan\IndexNow\SEOCore\TermSEO\TermFields;

/**
 * Emits the document title and meta tags for every front end request.
 *
 * Values are resolved with the following precedence:
 *
 *  1. Per-post values saved from the SEO metabox (singular requests only).
 *  2. The global default stored for the matching entity screen.
 *  3. The default template registered in {@see Entities}.
 */
class FrontendOutput
{
	/**
	 * JSON flags for every JSON-LD payload printed inside a <script> tag.
	 *
	 * Kept as an alias of the canonical value on {@see Graph}, which owns the
	 * printing, so the two can never drift apart.
	 */
	public const JSON_LD_FLAGS = Graph::JSON_LD_FLAGS;

	/**
	 * Memoised resolution for the current request.
	 *
	 * @var array|null|false Null until resolved, false when nothing applies.
	 */
	private $resolved = null;

	/**
	 * Shared Breadcrumbs instance — built once per request.
	 *
	 * @var Breadcrumbs|null
	 */
	private $breadcrumbs = null;

	/**
	 * Memoised BreadcrumbList node for the current request.
	 *
	 * @var array|null|false Null until resolved, false when nothing applies.
	 */
	private $breadcrumb_node = null;

	/**
	 * Request level url => attachment id map.
	 *
	 * `attachment_url_to_postid()` runs an uncached database query, so every
	 * lookup is remembered for the rest of the request.
	 *
	 * @var array<string,int>
	 */
	private static $attachment_ids = [];

	public function __construct()
	{
		add_filter('pre_get_document_title', [$this, 'filter_document_title'], 15);
		add_filter('wp_robots', [$this, 'filter_robots'], 20);
		add_action('wp_head', [$this, 'output'], 1);
		add_action('wp_head', [$this, 'output_pagination_links'], 1);
		add_action('wp_head', [$this, 'output_hreflang_links'], 1);
		add_action('wp_head', [$this, 'output_site_graph'], 2);
		add_action('template_redirect', [$this, 'unhook_core_canonical']);
	}

	/**
	 * We print our own canonical link, so core must not print a second one.
	 */
	public function unhook_core_canonical(): void
	{
		$data = $this->resolve();

		if ($data !== false && $data['canonical'] !== '') {
			remove_action('wp_head', 'rel_canonical');
		}
	}

	/**
	 * Feed our directives into the single robots tag rendered by core.
	 *
	 * Core directives (e.g. `max-image-preview:large`) are kept unless the
	 * plugin sets the same directive or a conflicting one.
	 *
	 * @param array $robots Directives keyed by name.
	 */
	public function filter_robots($robots)
	{
		$data = $this->resolve();

		if ($data === false || empty($data['robots'])) {
			return $robots;
		}

		$directives = is_array($robots) ? $robots : [];

		/* Mutually exclusive pairs — drop whatever core decided, we own these. */
		$conflicts = [
			'index'    => 'noindex',
			'noindex'  => 'index',
			'follow'   => 'nofollow',
			'nofollow' => 'follow',
		];

		foreach ($data['robots'] as $directive) {
			$directive = (string) $directive;
			$value     = true;

			if (strpos($directive, ':') !== false) {
				[$directive, $value] = explode(':', $directive, 2);
			}

			unset($directives[$directive]);

			if (isset($conflicts[$directive])) {
				unset($directives[$conflicts[$directive]]);
			}

			$directives[$directive] = $value;
		}

		return $directives;
	}

	/**
	 * Feed WordPress our title so themes supporting `title-tag` render it.
	 */
	public function filter_document_title($title)
	{
		$data = $this->resolve();

		if ($data === false || $data['title'] === '') {
			return $title;
		}

		return $data['title'];
	}

	/**
	 * Output every meta tag we own.
	 */
	public function output(): void
	{
		$data = $this->resolve();

		if ($data === false) {
			return;
		}

		echo "\n" . '<!-- CrawlWP SEO  (https://crawlwp.com/) -->' . "\n";

		/*
		 * Only print the title ourselves when nothing in core will do it, which
		 * is the case for classic themes that do not declare `title-tag`.
		 */
		if ($data['title'] !== '' && ! $this->core_renders_title()) {
			echo '<title>' . esc_html($data['title']) . '</title>' . "\n";
		}

		if ($data['description'] !== '') {
			echo '<meta name="description" content="' . esc_attr($data['description']) . '" />' . "\n";
		}

		/* Core renders the robots tag from our `wp_robots` filter. */
		if (! empty($data['robots']) && ! has_action('wp_head', 'wp_robots')) {
			echo '<meta name="robots" content="' . esc_attr(implode(', ', $data['robots'])) . '" />' . "\n";
		}

		if ($data['canonical'] !== '') {
			echo '<link rel="canonical" href="' . esc_url($data['canonical']) . '" />' . "\n";
		}

		if (SocialSettings::get('og_tags', 'on') !== 'off') {
			$this->output_open_graph($data);
		}

		if (SocialSettings::get('twitter_tags', 'on') !== 'off') {
			$this->output_twitter_card($data);
		}

		$this->collect_schema($data);

		echo '<!-- /CrawlWP SEO -->' . "\n";
	}

	/**
	 * Whether WordPress itself will render a title tag for this request.
	 *
	 * Block themes render it unconditionally through
	 * `_block_template_render_title_tag`, while classic themes only do so when
	 * they declare `title-tag` support. Either way the markup is built from
	 * `wp_get_document_title()`, which our `pre_get_document_title` filter owns.
	 */
	private function core_renders_title(): bool
	{
		if (has_action('wp_head', '_block_template_render_title_tag')) {
			return true;
		}

		return current_theme_supports('title-tag') && has_action('wp_head', '_wp_render_title_tag');
	}

	/**
	 * Resolve the current request into a ready-to-print payload.
	 *
	 * @return array|false
	 */
	private function resolve()
	{
		if ($this->resolved !== null) {
			return $this->resolved;
		}

		$this->resolved = false;

		if (is_admin() || is_feed() || is_trackback() || is_robots()) {
			return $this->resolved;
		}

		$target = $this->match_entity();

		if ($target === null) {
			return $this->resolved;
		}

		[$entity_key, $prefix, $context] = $target;

		$post = $context['post'] ?? null;
		$post = $post instanceof \WP_Post ? $post : null;

		/*
		 * The blog posts index carries the "Posts page" object, but that page is
		 * not the content being rendered: the index is a collection of posts.
		 * It therefore lives in its own context key so it can never turn the
		 * index into an article, while still feeding the metabox values.
		 */
		$posts_page = $context['posts_page'] ?? null;
		$posts_page = $posts_page instanceof \WP_Post ? $posts_page : null;

		/* The post carrying the metabox values for this request, if any. */
		$meta_post = $post ?? $posts_page;

		$term = $context['term'] ?? null;
		$term = $term instanceof \WP_Term ? $term : null;

		/* Author archives may carry per-user overrides saved on the profile screen. */
		$user = $context['user'] ?? null;
		$user = $user instanceof \WP_User ? $user : null;

		/* Singular / term / author requests may carry per-object overrides. */
		if ($meta_post !== null) {
			$overrides = $this->post_overrides($meta_post->ID);
		} elseif ($term !== null) {
			$overrides = $this->term_overrides($term->term_id);
		} elseif ($user !== null) {
			$overrides = $this->user_overrides($user->ID);
		} else {
			$overrides = [];
		}

		$title = Variables::replace(
			$overrides['title'] ?? $this->template($entity_key, $prefix . 'title'),
			$context
		);

		/**
		 * Filter the resolved meta title for the current request.
		 *
		 * @param string $title      The resolved title string.
		 * @param string $entity_key The matched entity key (e.g. 'pt_post', 'home').
		 * @param array  $context    The resolution context array.
		 */
		$title = (string) apply_filters('crawlwp_meta_title', $title, $entity_key, $context);

		$description = Variables::replace(
			$overrides['description'] ?? $this->template($entity_key, $prefix . 'description'),
			$context
		);

		/**
		 * Filter the resolved meta description for the current request.
		 *
		 * @param string $description The resolved description string.
		 * @param string $entity_key  The matched entity key.
		 * @param array  $context     The resolution context array.
		 */
		$description = (string) apply_filters('crawlwp_meta_description', $description, $entity_key, $context);

		/* Unified social override: one title/description shared by OG and X/Twitter. */
		$social_title = Variables::replace(
			$overrides['og_title'] ?? (string) Options::get($entity_key, 'social_title', ''),
			$context
		);

		$social_description = Variables::replace(
			$overrides['og_description'] ?? (string) Options::get($entity_key, 'social_description', ''),
			$context
		);

		/* When "Remove site title from social titles" is on and no custom social title is set,
		 * strip the separator + site name from the generated title for social use. */
		$og_title_base       = $social_title !== '' ? $social_title : $this->maybe_strip_site_name($title);
		$og_description_base = $social_description !== '' ? $social_description : $description;

		/* X/Twitter can carry its own copy; it falls back to the OG values. */
		$x_title = isset($overrides['x_title'])
			? Variables::replace($overrides['x_title'], $context)
			: '';

		$x_description = isset($overrides['x_description'])
			? Variables::replace($overrides['x_description'], $context)
			: '';

		$x_title_base       = $x_title !== '' ? $x_title : $og_title_base;
		$x_description_base = $x_description !== '' ? $x_description : $og_description_base;

		/* The unpaginated address of this request — pagination is applied on top. */
		$canonical_base = $this->canonical_base($meta_post, $overrides);
		$canonical      = empty($overrides['canonical_url'])
			? $this->paginate_url($canonical_base)
			: $canonical_base;

		/**
		 * Filter the canonical URL for the current request.
		 *
		 * @param string $canonical   The resolved canonical URL.
		 * @param string $entity_key  The matched entity key.
		 * @param array  $context     The resolution context array.
		 */
		$canonical = (string) apply_filters('crawlwp_canonical_url', $canonical, $entity_key, $context);

		/*
		 * Only a real singular request describes a single piece of content. The
		 * blog index and the static front page are collections, so they must not
		 * emit og:type=article, article:* tags or an Article node.
		 */
		$is_article = $post !== null && is_singular() && ! is_home() && ! is_front_page();

		$og_image = $this->image($entity_key, $prefix, 'og_image', $meta_post, $term);
		$x_image  = $this->image($entity_key, $prefix, 'x_image', $meta_post, $term);

		$this->resolved = [
			'entity'         => $entity_key,
			'prefix'         => $prefix,
			'context'        => $context,
			'post'           => $post,
			'posts_page'     => $posts_page,
			'meta_post'      => $meta_post,
			'title'          => $title,
			'description'    => $description,
			'robots'         => $this->robots($entity_key, $prefix, $meta_post, $term, $user),
			'canonical'      => $canonical,
			'canonical_base' => $canonical_base,
			'og_title'       => $og_title_base,
			'og_description' => $og_description_base,
			'og_image'       => $og_image['url'],
			'og_image_id'    => $og_image['id'],
			'x_title'        => $x_title_base,
			'x_description'  => $x_description_base,
			'x_image'        => $x_image['url'],
			'x_image_id'     => $x_image['id'],
			'og_type'        => $is_article ? 'article' : 'website',
		];

		return $this->resolved;
	}

	/**
	 * Map the current query onto an entity, a field prefix and a variable context.
	 *
	 * @return array{0: string, 1: string, 2: array}|null
	 */
	private function match_entity(): ?array
	{
		if (is_404()) {
			return ['not_found', '', []];
		}

		if (is_search()) {
			return ['search', '', []];
		}

		if (is_front_page()) {
			$context = [];

			if (is_page()) {
				$page = get_queried_object();

				if ($page instanceof \WP_Post) {
					$context['post']      = $page;
					$context['post_type'] = get_post_type_object($page->post_type);
				}
			}

			return ['home', '', $context];
		}

		/* Blog posts index when a static front page is in use. */
		if (is_home()) {
			$context        = ['post_type' => get_post_type_object('post')];
			$page_for_posts = (int) get_option('page_for_posts');

			if ($page_for_posts > 0) {
				$posts_page = get_post($page_for_posts);

				if ($posts_page instanceof \WP_Post) {
					/* Never `post`: the index itself is not that page. */
					$context['posts_page'] = $posts_page;
				}
			}

			return [
				Entities::post_type_key('post'),
				'archive_',
				$context,
			];
		}

		if (is_singular()) {
			$post = get_queried_object();

			if (! $post instanceof \WP_Post) {
				return null;
			}

			$entity_key = Entities::post_type_key($post->post_type);

			if (Entities::get($entity_key) === null) {
				return null;
			}

			return [
				$entity_key,
				'',
				[
					'post'      => $post,
					'post_type' => get_post_type_object($post->post_type),
				],
			];
		}

		if (is_post_type_archive()) {
			$post_type = $this->queried_post_type();

			if ($post_type === null) {
				return null;
			}

			$entity_key = Entities::post_type_key($post_type->name);

			if (Entities::get($entity_key) === null) {
				return null;
			}

			return [$entity_key, 'archive_', ['post_type' => $post_type]];
		}

		if (is_category() || is_tag() || is_tax()) {
			$term = get_queried_object();

			if (! $term instanceof \WP_Term) {
				return null;
			}

			$entity_key = Entities::taxonomy_key($term->taxonomy);

			if (Entities::get($entity_key) === null) {
				return null;
			}

			return [$entity_key, '', ['term' => $term]];
		}

		if (is_author()) {
			$user = get_queried_object();

			return ['author', '', $user instanceof \WP_User ? ['user' => $user] : []];
		}

		if (is_date()) {
			return ['date', '', []];
		}

		return null;
	}

	/**
	 * Stored template for a field, falling back to the registered default.
	 */
	private function template(string $entity_key, string $field): string
	{
		return (string) Options::get(
			$entity_key,
			$field,
			Entities::default_value($entity_key, $field)
		);
	}

	/**
	 * Non-empty per-post metabox values, keyed like the global fields.
	 *
	 * The two "use the SEO title/description" and "use the Facebook values"
	 * toggles disable their fields in the editor, so a previously saved value
	 * is never re-submitted. Honour the flags here, otherwise that stale value
	 * would keep winning on the frontend.
	 */
	private function post_overrides(int $post_id): array
	{
		$map = [
			'title'          => MetaFields::SEO_TITLE,
			'description'    => MetaFields::SEO_DESCRIPTION,
			'og_title'       => MetaFields::OG_TITLE,
			'og_description' => MetaFields::OG_DESCRIPTION,
			'x_title'        => MetaFields::X_TITLE,
			'x_description'  => MetaFields::X_DESCRIPTION,
			'canonical_url'  => MetaFields::CANONICAL_URL,
		];

		if (MetaFields::get($post_id, MetaFields::OG_SYNC) === '1') {
			unset($map['og_title'], $map['og_description']);
		}

		if (MetaFields::get($post_id, MetaFields::X_SYNC) === '1') {
			unset($map['x_title'], $map['x_description']);
		}

		$overrides = [];

		foreach ($map as $field => $meta_key) {
			$value = MetaFields::get($post_id, $meta_key);

			if ($value !== '' && $value !== null) {
				$overrides[$field] = (string) $value;
			}
		}

		return $overrides;
	}

	/**
	 * Per-term overrides stored as term meta.
	 *
	 * @return array<string,string>
	 */
	private function term_overrides(int $term_id): array
	{
		$map = [
			'title'          => MetaFields::SEO_TITLE,
			'description'    => MetaFields::SEO_DESCRIPTION,
			'og_title'       => MetaFields::OG_TITLE,
			'og_description' => MetaFields::OG_DESCRIPTION,
			'x_title'        => MetaFields::X_TITLE,
			'x_description'  => MetaFields::X_DESCRIPTION,
			'canonical_url'  => MetaFields::CANONICAL_URL,
		];

		$overrides = [];

		foreach ($map as $field => $meta_key) {
			$value = TermFields::get($term_id, $meta_key);

			if ($value !== '' && $value !== null) {
				$overrides[$field] = (string) $value;
			}
		}

		return $overrides;
	}

	/**
	 * Per-user overrides stored as user meta.
	 *
	 * The importer migrates the per-author SEO meta of Yoast, Rank Math,
	 * AIOSEO, SEOPress and Slim SEO into the very same `_crawlwp_*` keys the
	 * post metabox uses, and the profile screen writes them too — so the author
	 * archive reads them here before falling back to the `tm_author` templates.
	 *
	 * @return array<string,string>
	 */
	private function user_overrides(int $user_id): array
	{
		$map = [
			'title'         => MetaFields::SEO_TITLE,
			'description'   => MetaFields::SEO_DESCRIPTION,
			'canonical_url' => MetaFields::CANONICAL_URL,
		];

		$overrides = [];

		foreach ($map as $field => $meta_key) {
			$value = UserProfile::get($user_id, $meta_key);

			if ($value !== '') {
				$overrides[$field] = $value;
			}
		}

		return $overrides;
	}

	/**
	 * Build the robots directive list from the entity defaults and, when
	 * available, the per-post, per-term or per-user values.
	 */
	private function robots(string $entity_key, string $prefix, ?\WP_Post $post, ?\WP_Term $term = null, ?\WP_User $user = null): array
	{
		$directives = [];

		$noindex  = $this->is_noindexed($entity_key, $prefix);
		$nofollow = self::directive_enabled($entity_key, $prefix, 'nofollow');

		if ($post !== null) {
			$post_index  = MetaFields::get($post->ID, MetaFields::ROBOTS_INDEX);
			$post_follow = MetaFields::get($post->ID, MetaFields::ROBOTS_FOLLOW);

			if ($post_index !== '') {
				$noindex = $post_index === 'noindex';
			}

			if ($post_follow !== '') {
				$nofollow = $post_follow === 'nofollow';
			}
		} elseif ($term !== null) {
			/* Mirrors the post logic: an explicit per-term value wins both ways,
			 * so "index" can lift a taxonomy level noindex default. */
			$term_index  = (string) TermFields::get($term->term_id, MetaFields::ROBOTS_INDEX);
			$term_follow = (string) TermFields::get($term->term_id, MetaFields::ROBOTS_FOLLOW);

			if ($term_index !== '') {
				$noindex = $term_index === 'noindex';
			}

			if ($term_follow !== '') {
				$nofollow = $term_follow === 'nofollow';
			}
		} elseif ($user !== null) {
			/* Mirrors the post and term logic: an explicit per-user value wins
			 * both ways, so "index" can lift the author archive noindex default. */
			$user_index  = UserProfile::get($user->ID, MetaFields::ROBOTS_INDEX);
			$user_follow = UserProfile::get($user->ID, MetaFields::ROBOTS_FOLLOW);

			if ($user_index !== '') {
				$noindex = $user_index === 'noindex';
			}

			if ($user_follow !== '') {
				$nofollow = $user_follow === 'nofollow';
			}
		}

		$directives[] = $noindex ? 'noindex' : 'index';
		$directives[] = $nofollow ? 'nofollow' : 'follow';

		if (self::directive_enabled($entity_key, $prefix, 'noarchive')) {
			$directives[] = 'noarchive';
		}

		if ($post !== null) {
			$advanced = MetaFields::get($post->ID, MetaFields::ROBOTS_ADVANCED, []);

			if (is_array($advanced)) {
				foreach ($advanced as $directive) {
					if (in_array($directive, ['noarchive', 'nosnippet', 'noimageindex', 'notranslate'], true)) {
						$directives[] = $directive;
					}
				}
			}

			$directives = array_merge($directives, $this->preview_directives($post));
		}

		$directives = array_values(array_unique($directives));

		/**
		 * Filter the robots directives for the current request.
		 *
		 * @param string[] $directives  The directive list.
		 * @param string   $entity_key  The matched entity key.
		 */
		return (array) apply_filters('crawlwp_robots_directives', $directives, $entity_key);
	}

	/**
	 * Per-post snippet and image preview limits.
	 *
	 * The metabox lets an editor cap how much of a post Google may show in the
	 * result snippet and how large the image preview may be. Both map onto the
	 * `max-snippet:` / `max-image-preview:` robots directives.
	 *
	 * @return string[]
	 */
	private function preview_directives(\WP_Post $post): array
	{
		$directives = [];

		$snippet = (string) MetaFields::get($post->ID, MetaFields::MAX_SNIPPET);

		if ($snippet === 'none') {
			$directives[] = 'max-snippet:0';
		} elseif ($snippet !== '' && is_numeric($snippet)) {
			$directives[] = 'max-snippet:' . (int) $snippet;
		}

		$image = (string) MetaFields::get($post->ID, MetaFields::MAX_IMAGE);

		if (in_array($image, ['none', 'standard', 'large'], true)) {
			$directives[] = 'max-image-preview:' . $image;
		}

		return $directives;
	}

	/**
	 * Whether an entity is hidden from search results.
	 *
	 * An explicitly saved value always wins over the registered default, which
	 * matters for the search and 404 screens that ship with noindex enabled.
	 */
	public static function is_noindexed(string $entity_key, string $prefix = ''): bool
	{
		/*
		 * The singular and the archive screen of a post type both expose their
		 * own "Hide from search results" switch, so the singular value must not
		 * leak into the archive.
		 */
		return self::directive_enabled($entity_key, $prefix, 'noindex', false);
	}

	/**
	 * Whether a robots switch is enabled for an entity screen.
	 *
	 * Archive screens store their values under the `archive_` prefix. Every
	 * directive is looked up the same way: the prefixed stored value first, the
	 * prefixed registered default next, and only then the unprefixed value — so
	 * a registered `archive_noindex` default is honoured, and a directive that
	 * has no archive specific field still inherits the entity level switch.
	 *
	 * @param string $entity_key           Entity key, e.g. `pt_post`.
	 * @param string $prefix               Field prefix, e.g. `archive_`.
	 * @param string $directive            Directive name, e.g. `nofollow`.
	 * @param bool   $inherit_stored_value Whether the unprefixed stored value applies.
	 */
	private static function directive_enabled(string $entity_key, string $prefix, string $directive, bool $inherit_stored_value = true): bool
	{
		$stored = Options::all($entity_key);

		if ($prefix !== '') {
			$field = $prefix . $directive;

			if (isset($stored[$field]) && $stored[$field] !== '') {
				return $stored[$field] === 'on';
			}

			$default = Entities::default_value($entity_key, $field);

			if ($default !== '') {
				return $default === 'on';
			}

			if (! $inherit_stored_value) {
				return Entities::default_value($entity_key, $directive, 'off') === 'on';
			}
		}

		if (isset($stored[$directive]) && $stored[$directive] !== '') {
			return $stored[$directive] === 'on';
		}

		return Entities::default_value($entity_key, $directive, 'off') === 'on';
	}

	/**
	 * Unpaginated canonical URL for the current request.
	 *
	 * Pagination is layered on top by {@see self::paginate_url()} so the
	 * paginated address is always derived from the URL we resolved, never from
	 * the raw request — which may carry unrelated query arguments.
	 */
	private function canonical_base(?\WP_Post $post, array $overrides): string
	{
		if (! empty($overrides['canonical_url'])) {
			return $overrides['canonical_url'];
		}

		if (is_front_page()) {
			$url = home_url('/');
		} elseif ($post !== null) {
			$url = (string) get_permalink($post);
		} elseif (is_category() || is_tag() || is_tax()) {
			$term = get_queried_object();

			if ($term instanceof \WP_Term) {
				$link = get_term_link($term);
				$url  = is_wp_error($link) ? '' : (string) $link;
			} else {
				$url = '';
			}
		} elseif (is_author()) {
			$user = get_queried_object();
			$url  = $user instanceof \WP_User ? (string) get_author_posts_url($user->ID) : '';
		} elseif (is_post_type_archive()) {
			$post_type = $this->queried_post_type();
			$url       = $post_type !== null ? (string) (get_post_type_archive_link($post_type->name) ?: '') : '';
		} elseif (is_home()) {
			$page_for_posts = (int) get_option('page_for_posts');
			$url            = $page_for_posts ? (string) get_permalink($page_for_posts) : home_url('/');
		} elseif (is_date()) {
			$url = $this->date_archive_url();
		} else {
			$url = '';
		}

		return $url;
	}

	/**
	 * Permalink of the year, month or day archive being requested.
	 *
	 * Built from the query vars because date archives have no queried object,
	 * and the loop may be empty.
	 */
	private function date_archive_url(): string
	{
		$year  = (int) get_query_var('year');
		$month = (int) get_query_var('monthnum');
		$day   = (int) get_query_var('day');

		if ($year <= 0) {
			return '';
		}

		if ($month > 0 && $day > 0) {
			return (string) get_day_link($year, $month, $day);
		}

		if ($month > 0) {
			return (string) get_month_link($year, $month);
		}

		return (string) get_year_link($year);
	}

	/**
	 * Apply the requested page to a resolved base URL.
	 *
	 * Handles both archive pagination (`paged`) and multipage singulars split
	 * with `<!--nextpage-->` (`page`).
	 */
	private function paginate_url(string $url): string
	{
		if ($url === '') {
			return '';
		}

		if (is_singular()) {
			return $this->page_url($url, (int) get_query_var('page'));
		}

		return $this->paged_url($url, (int) get_query_var('paged'));
	}

	/**
	 * The `page/N` variant of an archive URL.
	 */
	private function paged_url(string $url, int $page): string
	{
		if ($page < 2 || $url === '') {
			return $url;
		}

		[$base, $query] = $this->split_query_string($url);

		global $wp_rewrite;

		if (! is_object($wp_rewrite) || ! $wp_rewrite->using_permalinks()) {
			return add_query_arg('paged', $page, $base . $query);
		}

		return user_trailingslashit(
			trailingslashit($base) . $wp_rewrite->pagination_base . '/' . $page,
			'paged'
		) . $query;
	}

	/**
	 * The `N` variant of a multipage singular URL.
	 */
	private function page_url(string $url, int $page): string
	{
		if ($page < 2 || $url === '') {
			return $url;
		}

		[$base, $query] = $this->split_query_string($url);

		global $wp_rewrite;

		if (! is_object($wp_rewrite) || ! $wp_rewrite->using_permalinks()) {
			return add_query_arg('page', $page, $base . $query);
		}

		return user_trailingslashit(trailingslashit($base) . $page, 'single_paged') . $query;
	}

	/**
	 * Split a URL into its path part and its (leading `?` included) query string.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function split_query_string(string $url): array
	{
		$parts = explode('?', $url, 2);
		$query = isset($parts[1]) && $parts[1] !== '' ? '?' . $parts[1] : '';

		return [$parts[0], $query];
	}

	/**
	 * Emit rel=prev and rel=next pagination links.
	 *
	 * Active on paged archives (archives, taxonomies, author, date, …) and on
	 * multipage singulars split with `<!--nextpage-->`. Both are built from the
	 * canonical base we resolved, not from the raw request.
	 */
	public function output_pagination_links(): void
	{
		if (is_admin() || is_feed() || is_trackback() || is_robots()) {
			return;
		}

		$data = $this->resolve();
		$base = $data !== false ? (string) $data['canonical_base'] : '';

		if ($base === '') {
			return;
		}

		if (is_singular()) {
			$this->output_singular_pagination_links($data, $base);

			return;
		}

		$paged     = (int) max(1, get_query_var('paged'));
		$max_pages = isset($GLOBALS['wp_query']) ? (int) $GLOBALS['wp_query']->max_num_pages : 1;

		if ($paged > 1) {
			echo '<link rel="prev" href="' . esc_url($this->paged_url($base, $paged - 1)) . '" />' . "\n";
		}

		if ($paged < $max_pages) {
			echo '<link rel="next" href="' . esc_url($this->paged_url($base, $paged + 1)) . '" />' . "\n";
		}
	}

	/**
	 * rel=prev / rel=next for a post split into several pages.
	 *
	 * @param array  $data The resolved page data.
	 * @param string $base The unpaginated permalink.
	 */
	private function output_singular_pagination_links(array $data, string $base): void
	{
		$post = $data['post'];

		if (! $post instanceof \WP_Post) {
			return;
		}

		$numpages = count(explode('<!--nextpage-->', (string) $post->post_content));

		if ($numpages < 2) {
			return;
		}

		$page = (int) max(1, get_query_var('page'));

		if ($page > 1) {
			echo '<link rel="prev" href="' . esc_url($this->page_url($base, $page - 1)) . '" />' . "\n";
		}

		if ($page < $numpages) {
			echo '<link rel="next" href="' . esc_url($this->page_url($base, $page + 1)) . '" />' . "\n";
		}
	}

	/**
	 * Emit hreflang alternates for the current request.
	 *
	 * This layer has no translation data of its own: multilingual integrations
	 * (WPML, Polylang, TranslatePress, …) supply the alternates through the
	 * `crawlwp_hreflang_links` filter. Nothing is printed while it is empty.
	 */
	public function output_hreflang_links(): void
	{
		if (is_admin() || is_feed() || is_trackback() || is_robots()) {
			return;
		}

		$data = $this->resolve();

		/**
		 * Filter the hreflang alternates for the current request.
		 *
		 * Accepts either a map of language code => URL, e.g.
		 * `['en-US' => 'https://example.com/', 'x-default' => '…']`, or a list of
		 * `['hreflang' => …, 'href' => …]` pairs. Empty by default, in which
		 * case no tag is printed.
		 *
		 * @param array       $links The hreflang alternates.
		 * @param array|false $data  The resolved page data, false when nothing applies.
		 */
		$links = (array) apply_filters('crawlwp_hreflang_links', [], $data);

		foreach ($links as $key => $link) {
			if (is_array($link)) {
				$lang = (string) ($link['hreflang'] ?? $link['lang'] ?? $key);
				$href = (string) ($link['href'] ?? $link['url'] ?? '');
			} else {
				$lang = (string) $key;
				$href = (string) $link;
			}

			if ($lang === '' || $href === '') {
				continue;
			}

			echo '<link rel="alternate" hreflang="' . esc_attr($lang) . '" href="' . esc_url($href) . '" />' . "\n";
		}
	}

	/**
	 * Resolve a social image, honouring the per-post value, then the global
	 * default, then the featured image.
	 *
	 * The attachment id is threaded through with the URL, so consumers that
	 * need the attachment metadata (dimensions, alt text) do not have to walk
	 * back from the URL with `attachment_url_to_postid()`.
	 *
	 * @return array{id: int, url: string} Attachment id (0 when unknown) and URL ('' when unresolved).
	 */
	private function image(string $entity_key, string $prefix, string $field, ?\WP_Post $post, ?\WP_Term $term = null): array
	{
		$meta_key = $field === 'og_image' ? MetaFields::OG_IMAGE : MetaFields::X_IMAGE;

		if ($post !== null) {
			$image_id = (int) MetaFields::get($post->ID, $meta_key, 0);

			if ($image_id > 0) {
				$url = wp_get_attachment_image_url($image_id, 'full');

				if ($url) {
					return ['id' => $image_id, 'url' => (string) $url];
				}
			}
		}

		if ($term !== null) {
			$image_id = (int) TermFields::get($term->term_id, $meta_key, 0);

			if ($image_id > 0) {
				$url = wp_get_attachment_image_url($image_id, 'full');

				if ($url) {
					return ['id' => $image_id, 'url' => (string) $url];
				}
			}
		}

		/* Global defaults are stored as URLs by the WPOSA image field. */
		$global = (string) Options::get($entity_key, $prefix . $field, '');

		if ($global !== '') {
			[$global_id, $global_url] = $this->resolve_image_setting($global);

			if ($global_url !== '') {
				return ['id' => $global_id, 'url' => $global_url];
			}

			return ['id' => 0, 'url' => $global];
		}

		if ($post !== null) {
			/* The featured image id is already known — keep it. */
			$thumbnail_id = (int) get_post_thumbnail_id($post->ID);

			if ($thumbnail_id > 0) {
				$url = wp_get_attachment_image_url($thumbnail_id, 'full');

				if ($url) {
					return ['id' => $thumbnail_id, 'url' => (string) $url];
				}
			}
		}

		return ['id' => 0, 'url' => ''];
	}

	/**
	 * When "Remove site title from generated social titles" is enabled and the
	 * provided title ends with " {sep} {site name}", strip that suffix.
	 * If the setting is off, return the title unchanged.
	 *
	 * @param string $title The fully resolved page title.
	 */
	private function maybe_strip_site_name(string $title): string
	{
		if (SocialSettings::get('social_title_rem_additions', 'off') !== 'on') {
			return $title;
		}

		$site_name = trim((string) get_bloginfo('name'));

		if ($site_name === '') {
			return $title;
		}

		$decoded_site = html_entity_decode($site_name, ENT_QUOTES, 'UTF-8');
		$encoded_site = htmlspecialchars($decoded_site, ENT_QUOTES, 'UTF-8');

		$quoted_variants = array_unique([
			preg_quote($site_name, '/'),
			preg_quote($decoded_site, '/'),
			preg_quote($encoded_site, '/'),
		]);

		$site_pattern = '(?:' . implode('|', $quoted_variants) . ')';

		/* Strip trailing " {sep} {site name}" or " {site name}" suffix. */
		$cleaned = preg_replace('/(?:\s*[\p{P}\p{S}]+\s*|\s+)' . $site_pattern . '\s*$/ui', '', $title);

		/* If no suffix was stripped, also check if the title starts with "{site name} {sep} ". */
		if ($cleaned === $title) {
			$cleaned = preg_replace('/^\s*' . $site_pattern . '(?:\s*[\p{P}\p{S}]+\s*|\s+)/ui', '', $title);
		}

		if ($cleaned !== null && trim($cleaned) !== '') {
			return trim($cleaned);
		}

		return $title;
	}

	/**
	 * Resolve a stored image setting into an attachment ID and a URL.
	 *
	 * The media picker stores the image URL, while older installs may hold an
	 * attachment ID — accept both.
	 *
	 * No database lookup is performed for URL values: the id stays 0 and callers
	 * that really need it resolve it lazily through
	 * {@see self::attachment_id_from_url()}.
	 *
	 * @param mixed $value Stored option value.
	 *
	 * @return array{0: int, 1: string} Attachment ID (0 when unknown) and URL ('' when unresolved).
	 */
	private function resolve_image_setting($value): array
	{
		if (is_array($value)) {
			$value = $value['url'] ?? $value['id'] ?? '';
		}

		$value = trim((string) $value);

		if ($value === '') {
			return [0, ''];
		}

		if (is_numeric($value)) {
			$id  = (int) $value;
			$url = $id > 0 ? (string) (wp_get_attachment_image_url($id, 'full') ?: '') : '';

			return [$url !== '' ? $id : 0, $url];
		}

		$url = esc_url_raw($value);

		if ($url === '') {
			return [0, ''];
		}

		return [0, $url];
	}

	/**
	 * Attachment id behind an image URL, remembered for the whole request.
	 *
	 * `attachment_url_to_postid()` is an uncached database query, so it is only
	 * used when the id could not be threaded through from the featured image or
	 * the metabox, and never twice for the same URL.
	 */
	private function attachment_id_from_url(string $url): int
	{
		if ($url === '') {
			return 0;
		}

		if (! isset(self::$attachment_ids[$url])) {
			self::$attachment_ids[$url] = (int) attachment_url_to_postid($url);
		}

		return self::$attachment_ids[$url];
	}

	private function output_open_graph(array $data): void
	{
		/* Use entity image first; fall back to global social image fallback. */
		$og_image    = $data['og_image'];
		$og_image_id = (int) ($data['og_image_id'] ?? 0);

		if ($og_image === '') {
			[$og_image_id, $og_image] = $this->resolve_image_setting(SocialSettings::get('social_image_fallback', ''));
		}

		/* Build OG tags array — keyed by property name. */
		$og_tags = [
			'og:locale'    => str_replace('-', '_', get_locale()),
			'og:type'      => $data['og_type'],
			'og:title'     => $data['og_title'],
			'og:description' => $data['og_description'],
			'og:url'       => $data['canonical'],
			'og:site_name' => get_bloginfo('name'),
		];

		if ($og_image !== '') {
			$og_tags['og:image'] = $og_image;

			/* Image dimensions — the id is usually already known. */
			$img_id = $og_image_id > 0 ? $og_image_id : $this->attachment_id_from_url($og_image);

			if ($img_id > 0) {
				$img_meta = wp_get_attachment_metadata($img_id);

				if (is_array($img_meta) && isset($img_meta['width'], $img_meta['height'])) {
					$og_tags['og:image:width']  = (string) (int) $img_meta['width'];
					$og_tags['og:image:height'] = (string) (int) $img_meta['height'];
				}
			}

			/* Alt text: metabox field → attachment alt. */
			$img_alt = '';

			if ($data['meta_post'] instanceof \WP_Post) {
				$img_alt = (string) MetaFields::get($data['meta_post']->ID, MetaFields::OG_IMAGE_ALT, '');
			}

			if ($img_alt === '' && $img_id > 0) {
				$img_alt = (string) get_post_meta($img_id, '_wp_attachment_image_alt', true);
			}

			if ($img_alt !== '') {
				$og_tags['og:image:alt'] = $img_alt;
			}
		}

		/* article:author for posts — per-author Facebook URL overrides the global fallback. */
		if ($data['og_type'] === 'article' && $data['post'] instanceof \WP_Post) {
			$author_id       = (int) $data['post']->post_author;
			$facebook_author = $author_id > 0 ? UserProfile::get($author_id, UserProfile::META_FACEBOOK) : '';

			if ($facebook_author === '') {
				$facebook_author = (string) SocialSettings::get('facebook_author', '');
			}

			if ($facebook_author !== '') {
				$og_tags['article:author'] = $facebook_author;
			}
		}

		/* article:published_time / article:modified_time for posts. */
		if ($data['og_type'] === 'article' && $data['post'] instanceof \WP_Post) {

			if (SocialSettings::get('post_publish_time', 'on') !== 'off') {
				$pub = get_the_date('c', $data['post']);

				if ($pub) {
					$og_tags['article:published_time'] = $pub;
				}
			}

			if (SocialSettings::get('post_modify_time', 'on') !== 'off') {
				$mod = get_the_modified_date('c', $data['post']);

				if ($mod) {
					$og_tags['article:modified_time'] = $mod;
					/* og:updated_time is an alias recognised by some crawlers as a fallback. */
					$og_tags['og:updated_time'] = $mod;
				}
			}

			/* article:section — primary category name for standard posts. */
			if ($data['post']->post_type === 'post') {
				$categories = get_the_category($data['post']->ID);

				if (! empty($categories)) {
					$og_tags['article:section'] = get_cat_name($categories[0]->cat_ID);
				}

				/* article:tag — one tag per taxonomy tag term. */
				$tags = get_the_tags($data['post']->ID);

				if (is_array($tags) && ! empty($tags)) {
					$og_tags['article:tag'] = array_map(function ($tag) {
						return $tag->name;
					}, $tags);
				}
			}
		}

		/* fb:app_id — added before the filter runs so it can be removed there. */
		$fb_app_id = (string) SocialSettings::get('fb_app_id', '');

		if ($fb_app_id !== '') {
			$og_tags['fb:app_id'] = $fb_app_id;
		}

		/**
		 * Filter the Open Graph meta tags array before output.
		 *
		 * Array is keyed by property name (e.g. 'og:title'). Remove a key to
		 * suppress that tag; add a key to emit a new one. Values are escaped
		 * with esc_attr() before printing (URLs additionally with esc_url()).
		 *
		 * @param array $og_tags Associative array of OG property => content.
		 * @param array $data    The resolved page data.
		 */
		$og_tags = (array) apply_filters('crawlwp_open_graph_tags', $og_tags, $data);

		/* Print each tag; use esc_url for URL properties. */
		$url_props = ['og:url', 'og:image', 'article:author'];

		foreach ($og_tags as $property => $content) {
			if ($content === '' || $content === null) {
				continue;
			}

			/* article:tag can be an array — emit one tag per value. */
			if (is_array($content)) {
				foreach ($content as $single) {
					echo '<meta property="' . esc_attr($property) . '" content="' . esc_attr((string) $single) . '" />' . "\n";
				}
				continue;
			}

			$content_attr = in_array($property, $url_props, true)
				? esc_url((string) $content)
				: esc_attr((string) $content);

			echo '<meta property="' . esc_attr($property) . '" content="' . $content_attr . '" />' . "\n";
		}
	}

	private function output_twitter_card(array $data): void
	{
		/* Global default card type from Social Networks settings; per-post override takes precedence. */
		$card_type = SocialSettings::get('twitter_card', 'summary_large_image') === 'summary' ? 'summary' : 'summary_large_image';

		if ($data['post'] instanceof \WP_Post) {
			$stored = MetaFields::get($data['post']->ID, MetaFields::X_CARD_TYPE, '');

			if ($stored === 'summary') {
				$card_type = 'summary';
			} elseif ($stored === 'summary_large_image') {
				$card_type = 'summary_large_image';
			}
		}

		/* Global site @username: read from Site Information profile_x field. */
		$twitter_site_url = (string) SiteInfoSettings::get('profile_x', '');
		$twitter_site     = '';

		if ($twitter_site_url !== '') {
			if (str_starts_with($twitter_site_url, '@')) {
				$twitter_site = $twitter_site_url;
			} else {
				$parsed = parse_url($twitter_site_url, PHP_URL_PATH);
				$handle = $parsed ? ltrim(trim($parsed, '/'), '@') : '';

				if ($handle !== '') {
					$twitter_site = '@' . $handle;
				}
			}
		}

		/* Use entity image; fall back to OG image and then global fallback. */
		if ($data['x_image'] !== '') {
			$x_image    = $data['x_image'];
			$x_image_id = (int) ($data['x_image_id'] ?? 0);
		} else {
			$x_image    = $data['og_image'];
			$x_image_id = (int) ($data['og_image_id'] ?? 0);
		}

		if ($x_image === '') {
			[$x_image_id, $x_image] = $this->resolve_image_setting(SocialSettings::get('social_image_fallback', ''));
		}

		/* twitter:creator — per-post → author profile → global setting. */
		$creator = '';

		if ($data['post'] instanceof \WP_Post) {
			$creator = (string) MetaFields::get($data['post']->ID, MetaFields::X_CREATOR);

			if ($creator === '') {
				$author_id = (int) $data['post']->post_author;
				$creator   = $author_id > 0 ? (string) UserProfile::get($author_id, UserProfile::META_TWITTER) : '';
			}

			if ($creator === '') {
				$creator = (string) SocialSettings::get('twitter_creator', '');
			}
		}

		/* Build Twitter card tags array — keyed by name attribute. */
		$twitter_tags = [
			'twitter:card'        => $card_type,
			'twitter:site'        => $twitter_site,
			'twitter:title'       => $data['x_title'],
			'twitter:description' => $data['x_description'],
			'twitter:creator'     => $creator,
		];

		if ($x_image !== '') {
			$twitter_tags['twitter:image'] = $x_image;

			/* Alt text: metabox OG image alt → attachment alt. */
			$img_alt = '';

			if ($data['meta_post'] instanceof \WP_Post) {
				$img_alt = (string) MetaFields::get($data['meta_post']->ID, MetaFields::OG_IMAGE_ALT, '');
			}

			if ($img_alt === '') {
				$img_id = $x_image_id > 0 ? $x_image_id : $this->attachment_id_from_url($x_image);

				if ($img_id > 0) {
					$img_alt = (string) get_post_meta($img_id, '_wp_attachment_image_alt', true);
				}
			}

			if ($img_alt !== '') {
				$twitter_tags['twitter:image:alt'] = $img_alt;
			}
		}

		/**
		 * Filter the X/Twitter card meta tags array before output.
		 *
		 * Array is keyed by tag name (e.g. 'twitter:title'). Remove a key to
		 * suppress that tag; add a key to emit a new one.
		 *
		 * @param array $twitter_tags Associative array of twitter:name => content.
		 * @param array $data         The resolved page data.
		 */
		$twitter_tags = (array) apply_filters('crawlwp_twitter_card_tags', $twitter_tags, $data);

		/* Print each tag; use esc_url for the image URL. */
		$url_names = ['twitter:image'];

		foreach ($twitter_tags as $name => $content) {
			if ($content === '' || $content === null) {
				continue;
			}

			$content_attr = in_array($name, $url_names, true)
				? esc_url((string) $content)
				: esc_attr((string) $content);

			echo '<meta name="' . esc_attr($name) . '" content="' . $content_attr . '" />' . "\n";
		}
	}

	/**
	 * Collect the JSON-LD nodes describing the current request.
	 *
	 * Nodes are pushed into {@see Graph}, which prints the nodes of every
	 * producer inside a single `@graph` script tag later in wp_head. Every node
	 * carries a stable `@id` so the WebPage, Article, WebSite, Organization and
	 * BreadcrumbList nodes can reference each other.
	 *
	 * For singular posts: resolves schema type from the two-select model (page_type +
	 * article_type). When page_type is a WebPage subtype and article_type is set
	 * (not 'none'), the effective @type is the article type — matching Yoast/Rank Math.
	 * Legacy single-select values stored in SCHEMA_TYPE are used when new fields are absent.
	 *
	 * For non-singular pages: emits a minimal WebPage subtype (CollectionPage,
	 * SearchResultsPage, ProfilePage) when the page is indexable.
	 */
	private function collect_schema(array $data): void
	{
		/* Skip noindexed pages — no value in structured data for them. */
		if (! empty($data['robots']) && in_array('noindex', $data['robots'], true)) {
			return;
		}

		$post       = $data['post'];
		$lang       = get_bloginfo('language');
		$webpage_id = $this->schema_id('webpage');

		if (! $post instanceof \WP_Post) {
			/* Non-singular schema: determine appropriate WebPage subtype. */
			if (is_home() || is_post_type_archive() || is_category() || is_tag() || is_tax() || is_date()) {
				$schema_type = 'CollectionPage';
			} elseif (is_author()) {
				$schema_type = 'ProfilePage';
			} elseif (is_search()) {
				$schema_type = 'SearchResultsPage';
			} else {
				return;
			}

			$schema = [
				'@context'   => 'https://schema.org',
				'@type'      => $schema_type,
				'@id'        => $webpage_id,
				'url'        => $data['canonical'],
				'name'       => $data['title'],
				'isPartOf'   => ['@id' => $this->schema_website_id()],
				'inLanguage' => $lang,
			];

			if ($data['canonical'] === '') {
				unset($schema['url']);
			}

			if ($data['description'] !== '') {
				$schema['description'] = $data['description'];
			}

			if (($breadcrumb = $this->breadcrumb_node()) !== null) {
				$schema['breadcrumb'] = ['@id' => $breadcrumb['@id']];
			}

			/** This filter is documented below in the singular branch. */
			$schema = (array) apply_filters('crawlwp_schema_data', $schema, null);

			Graph::add_node($schema);

			return;
		}

		/* --- resolve page type --- */
		$page_type = (string) MetaFields::get($post->ID, MetaFields::SCHEMA_PAGE_TYPE, '');

		if ($page_type === '') {
			/* Fall back to global setting, then entity default. */
			$global_page = (string) Options::get($data['entity'], 'schema_page_type', '');
			$page_type   = $global_page !== '' ? $global_page : 'WebPage';
		}

		if ($page_type === 'none') {
			return; // "None" selected for page type — suppress all structured data.
		}

		/* --- resolve article type --- */
		$article_type = (string) MetaFields::get($post->ID, MetaFields::SCHEMA_ARTICLE_TYPE, '');

		if ($article_type === '') {
			/* Check legacy single-select value. Page types all end in "Page",
			 * so only the other values name an article type. */
			$legacy = (string) MetaFields::get($post->ID, MetaFields::SCHEMA_TYPE, '');

			if ($legacy !== '' && $legacy !== 'none' && strpos($legacy, 'Page') === false) {
				$article_type = $legacy;
			} else {
				$article_type = (string) Options::get($data['entity'], 'schema_article_type', '');

				if ($article_type === '') {
					/* Backwards compatibility with the single-select option that
					 * used to be stored as `schema_type`. Page types all end in
					 * "Page", so anything else is an article type. */
					$legacy_option = (string) Options::get($data['entity'], 'schema_type', '');

					if ($legacy_option !== '' && $legacy_option !== 'none' && strpos($legacy_option, 'Page') === false) {
						$article_type = $legacy_option;
					}
				}

				if ($article_type === '') {
					/* Only blog posts are articles by default; pages and other
					 * post types fall back to their page type alone. */
					$article_type = Entities::default_value(
						$data['entity'],
						'schema_article_type',
						$post->post_type === 'post' ? 'Article' : 'none'
					);
				}
			}
		}

		/* --- determine the effective @type ---
		 * When article_type is not 'none', it overrides the page type,
		 * so e.g. "WebPage + Article" becomes @type Article.
		 * This mirrors Yoast SEO behaviour. */
		$schema_type = ($article_type !== '' && $article_type !== 'none') ? $article_type : $page_type;

		if ($schema_type === '' || $schema_type === 'none') {
			return;
		}

		$author      = get_userdata((int) $post->post_author);
		$author_name = $author ? $author->display_name : '';
		$is_article  = $article_type !== '' && $article_type !== 'none';

		/* --- the WebPage node the rest of the graph hangs off --- */
		$webpage_node = [
			'@type'         => $page_type,
			'@id'           => $webpage_id,
			'url'           => $data['canonical'],
			'name'          => $data['title'],
			'isPartOf'      => ['@id' => $this->schema_website_id()],
			'inLanguage'    => $lang,
			'datePublished' => get_the_date('c', $post),
			'dateModified'  => get_the_modified_date('c', $post),
		];

		if ($data['canonical'] === '') {
			unset($webpage_node['url']);
		}

		if ($data['description'] !== '') {
			$webpage_node['description'] = $data['description'];
		}

		if ($this->breadcrumb_node() !== null) {
			$webpage_node['breadcrumb'] = ['@id' => $this->schema_id('breadcrumb')];
		}

		if ($data['og_image'] !== '') {
			$primary_image_id = $this->schema_id('primaryimage');

			$webpage_node['primaryImageOfPage'] = ['@id' => $primary_image_id];

			Graph::add_node([
				'@type'      => 'ImageObject',
				'@id'        => $primary_image_id,
				'url'        => $data['og_image'],
				'contentUrl' => $data['og_image'],
				'inLanguage' => $lang,
			]);
		}

		/* --- the primary entity --- */
		if ($is_article) {
			$schema = [
				'@context'         => 'https://schema.org',
				'@type'            => $schema_type,
				'@id'              => $this->schema_id('article'),
				'headline'         => (string) MetaFields::get($post->ID, MetaFields::SCHEMA_HEADLINE, '') ?: $data['title'],
				'url'              => $data['canonical'],
				'isPartOf'         => ['@id' => $webpage_id],
				'mainEntityOfPage' => ['@id' => $webpage_id],
				'publisher'        => ['@id' => $this->schema_publisher_id()],
			];

			if ($data['canonical'] === '') {
				unset($schema['url']);
			}
		} else {
			/* Page type only — the WebPage node is itself the primary entity. */
			$schema = array_merge(['@context' => 'https://schema.org'], $webpage_node);
		}

		$section = MetaFields::get($post->ID, MetaFields::SCHEMA_SECTION);

		if (! empty($section)) {
			$schema['articleSection'] = $section;
		}

		if ($author_name !== '') {
			$author_schema = [
				'@type' => 'Person',
				'name'  => $author_name,
			];

			/* Add sameAs from the author's additional profile URLs. */
			$same_as = UserProfile::get_additional_profiles((int) $post->post_author);

			if (! empty($same_as)) {
				$author_schema['sameAs'] = $same_as;
			}

			$schema['author'] = $author_schema;
		}

		if ($data['og_image'] !== '') {
			$schema['image'] = $data['og_image'];
		}

		$schema['datePublished'] = get_the_date('c', $post);
		$schema['dateModified']  = get_the_modified_date('c', $post);

		/**
		 * Filter the JSON-LD node describing the queried content.
		 *
		 * The node is added to the single `@graph` printed by {@see Graph}.
		 *
		 * @param array    $schema The schema array.
		 * @param \WP_Post $post   The queried post.
		 */
		$schema = (array) apply_filters('crawlwp_schema_data', $schema, $post);

		if ($is_article) {
			Graph::add_node($webpage_node);
		}

		Graph::add_node($schema);
	}

	/**
	 * Stable `@id` for a node describing the current page.
	 */
	private function schema_id(string $fragment): string
	{
		$data = $this->resolve();
		$base = $data !== false ? (string) $data['canonical_base'] : '';

		if ($base === '') {
			$base = home_url('/');
		}

		return $base . '#' . $fragment;
	}

	/**
	 * `@id` of the site-wide WebSite node.
	 */
	private function schema_website_id(): string
	{
		return home_url('/') . '#website';
	}

	/**
	 * `@id` of the site-wide Organization or Person node.
	 */
	private function schema_publisher_id(): string
	{
		$site_type = (string) SiteInfoSettings::get('site_type', 'organization');

		return home_url('/') . '#' . ($site_type === 'person' ? 'person' : 'organization');
	}

	/**
	 * Memoised BreadcrumbList node, re-keyed onto the canonical URL.
	 *
	 * Breadcrumbs builds the `@id` from the requested URL. Every other node in
	 * the graph is keyed off the *canonical* base, so a page carrying a custom
	 * canonical keeps one consistent set of ids — hence the re-key here.
	 */
	private function breadcrumb_node(): ?array
	{
		if ($this->breadcrumb_node === null) {
			$node = $this->get_breadcrumbs()->get_schema_node();

			if (is_array($node)) {
				$node['@id']           = $this->schema_id('breadcrumb');
				$this->breadcrumb_node = $node;
			} else {
				$this->breadcrumb_node = false;
			}
		}

		return $this->breadcrumb_node === false ? null : $this->breadcrumb_node;
	}

	/**
	 * Collect the site-wide WebSite + Organization/Person JSON-LD nodes.
	 *
	 * Built on every public front-end page and handed to {@see Graph}, which
	 * prints one `@graph` holding the nodes of every producer. The structure
	 * matches the one produced by Yoast SEO:
	 *
	 *  - WebSite node  (@id #website)  — site name, url, description, language,
	 *                                    optional SearchAction potentialAction.
	 *  - Organization  (@id #organization)  — or Person (@id #person) based on
	 *    the "Site type" setting — with name, url, logo ImageObject, and sameAs.
	 */
	public function output_site_graph(): void
	{
		if (is_admin() || is_feed() || is_trackback() || is_robots()) {
			return;
		}

		$home_url = home_url('/');
		$lang     = get_bloginfo('language');

		/* --- identity node --- */
		$site_type = (string) SiteInfoSettings::get('site_type', 'organization');
		$is_org    = $site_type !== 'person';

		$entity_type = $is_org ? 'Organization' : 'Person';
		$entity_id   = $home_url . '#' . ($is_org ? 'organization' : 'person');

		$name = (string) SiteInfoSettings::get('site_name', '');
		if ($name === '') {
			$name = get_bloginfo('name');
		}

		$description = (string) SiteInfoSettings::get('site_description', '');
		if ($description === '') {
			$description = get_bloginfo('description');
		}

		/* --- logo --- */
		[$logo_id, $logo_url] = $this->resolve_image_setting(SiteInfoSettings::get('logo', ''));

		/* --- sameAs --- */
		$same_as = SiteInfoSettings::get_same_as();

		/* --- build entity node --- */
		$entity_node = [
			'@type' => $entity_type,
			'@id'   => $entity_id,
			'name'  => $name,
			'url'   => $home_url,
		];

		if ($logo_url !== '') {
			if ($logo_id <= 0) {
				$logo_id = $this->attachment_id_from_url($logo_url);
			}

			$logo_meta = $logo_id > 0 ? wp_get_attachment_metadata($logo_id) : false;
			$logo_node = [
				'@type'      => 'ImageObject',
				'inLanguage' => $lang,
				'@id'        => $home_url . '#/schema/logo/image/',
				'url'        => $logo_url,
				'contentUrl' => $logo_url,
			];

			if (is_array($logo_meta) && isset($logo_meta['width'], $logo_meta['height'])) {
				$logo_node['width']  = (int) $logo_meta['width'];
				$logo_node['height'] = (int) $logo_meta['height'];
			}

			if ($name !== '') {
				$logo_node['caption'] = $name;
			}

			if ($is_org) {
				/* Google expects `logo` on Organization … */
				$entity_node['logo']  = $logo_node;
				$entity_node['image'] = ['@id' => $home_url . '#/schema/logo/image/'];
			} else {
				/* … and `image` on Person. */
				$entity_node['image'] = $logo_node;
			}
		}

		if (! empty($same_as)) {
			$entity_node['sameAs'] = $same_as;
		}

		/* --- website node --- */
		$website_node = [
			'@type'       => 'WebSite',
			'@id'         => $home_url . '#website',
			'url'         => $home_url,
			'name'        => $name,
			'publisher'   => ['@id' => $entity_id],
			'inLanguage'  => $lang,
		];

		if ($description !== '') {
			$website_node['description'] = $description;
		}

		/* SearchAction (Sitelinks Search Box). */
		if (SiteInfoSettings::get('search_action', 'on') !== 'off') {
			$website_node['potentialAction'] = [
			[
				'@type'       => 'SearchAction',
				'target'      => [
					'@type'       => 'EntryPoint',
					'urlTemplate' => str_replace(
						urlencode( '{search_term_string}' ),
						'{search_term_string}',
						get_search_link( '{search_term_string}' )
					),
				],
				'query-input' => [
					'@type'         => 'PropertyValueSpecification',
					'valueRequired' => true,
					'valueName'     => 'search_term_string',
				],
			],
			];
		}

		/* --- BreadcrumbList node --- */
		$bc_node = $this->breadcrumb_node();

		/* --- assemble --- */
		$graph_nodes = [$website_node, $entity_node];
		if ($bc_node !== null) {
			$graph_nodes[] = $bc_node;
		}

		$graph = [
			'@context' => 'https://schema.org',
			'@graph'   => $graph_nodes,
		];

		/**
		 * Filter the site graph before it is handed to the request graph.
		 *
		 * @param array $graph The @graph array (WebSite + Organization/Person nodes).
		 */
		$graph = (array) apply_filters('crawlwp_site_graph', $graph);

		$nodes = isset($graph['@graph']) && is_array($graph['@graph']) ? $graph['@graph'] : [];

		Graph::add_nodes($nodes);
	}

	/**
	 * Returns the shared (memoised) Breadcrumbs instance for this request.
	 */
	private function get_breadcrumbs(): Breadcrumbs
	{
		if ($this->breadcrumbs === null) {
			$this->breadcrumbs = new Breadcrumbs();
		}

		return $this->breadcrumbs;
	}

	/**
	 * The post type object behind a post type archive request.
	 */
	private function queried_post_type(): ?\WP_Post_Type
	{
		$queried = get_query_var('post_type');

		if (is_array($queried)) {
			$queried = reset($queried);
		}

		if (! $queried) {
			return null;
		}

		$post_type = get_post_type_object((string) $queried);

		return $post_type instanceof \WP_Post_Type ? $post_type : null;
	}
}
