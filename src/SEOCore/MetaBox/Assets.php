<?php

namespace Mihdan\IndexNow\SEOCore\MetaBox;

use Mihdan\IndexNow\SEOCore\AI\Generator;
use Mihdan\IndexNow\SEOCore\Breadcrumbs\BreadcrumbSettings;
use Mihdan\IndexNow\SEOCore\SiteInfoSettings\SiteInfoSettings;
use Mihdan\IndexNow\SEOCore\TitleMeta\Entities;
use Mihdan\IndexNow\SEOCore\TitleMeta\Options;
use Mihdan\IndexNow\SEOCore\TitleMeta\Variables;
use Mihdan\IndexNow\Indexing;
use Mihdan\IndexNow\Utils;

class Assets
{
	public function __construct()
	{
		add_action('admin_enqueue_scripts', [$this, 'enqueue']);
		add_action('wp_ajax_crawlwp_ai_generate', [$this, 'ajax_ai_generate']);
		add_action('wp_ajax_crawlwp_submit_indexnow', [$this, 'ajax_submit_indexnow']);
		add_action('wp_ajax_crawlwp_check_duplicate_keyword', [$this, 'ajax_check_duplicate_keyword']);
		add_action('wp_ajax_crawlwp_suggested_links', [$this, 'ajax_suggested_links']);
		add_action('crawlwp/index_pinged', [$this, 'store_last_pinged_time'], 10, 2);
		add_action('save_post', [$this, 'flush_inbound_links_cache']);
		add_action('deleted_post', [$this, 'flush_inbound_links_cache']);
	}

	public function enqueue(string $hook): void
	{
		if (! in_array($hook, ['post.php', 'post-new.php'], true)) {
			return;
		}

		$assets_url = CRAWLWP_PLUGIN_URL . 'src/SEOCore/MetaBox/assets/';
		$version    = CRAWLWP_VERSION;

		wp_enqueue_style(
			'crawlwp-seo-metabox',
			$assets_url . 'crawlwp-metabox.css',
			[],
			$version
		);

		wp_enqueue_media();

		wp_enqueue_script(
			'crawlwp-seo-metabox',
			$assets_url . 'crawlwp-metabox.js',
			['jquery'],
			$version,
			true
		);

		global $post;

		$localize_data = self::get_localized_data($post instanceof \WP_Post ? $post : null);

		// JSON_HEX_* flags make the payload safe to embed inline (post content may contain "</script>").
		wp_add_inline_script(
			'crawlwp-seo-metabox',
			'var crawlwpSEO = ' . wp_json_encode($localize_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';',
			'before'
		);

		/**
		 * Fires after the SEO metabox assets are enqueued, so add-on plugins can
		 * enqueue their own scripts/styles for the metabox (e.g. an extra tab).
		 *
		 * @param string $hook The current admin page hook.
		 */
		do_action('crawlwp_metabox_enqueue_assets', $hook);
	}

	/**
	 * Build localized configuration and data for the SEO editor interface.
	 *
	 * @param \WP_Post|null $post The post being edited, if any.
	 * @return array
	 */
	public static function get_localized_data(?\WP_Post $post = null): array
	{
		if (! $post instanceof \WP_Post) {
			global $post;
		}

		$post_title = '';
		$excerpt    = '';

		if ($post instanceof \WP_Post) {
			$post_title = wp_specialchars_decode($post->post_title, ENT_QUOTES);
			$excerpt    = $post->post_excerpt ?: wp_trim_words(wp_strip_all_tags($post->post_content), 30, '...');
		}

		$author      = '';
		$categories  = [];
		$inbound     = ['links' => [], 'more' => false];
		$suggested   = [];
		$templates   = self::title_meta_templates($post);
		$variables   = [];

		if ($post instanceof \WP_Post) {
			$author_obj = get_userdata((int) $post->post_author);
			$author     = $author_obj ? $author_obj->display_name : '';
			$terms      = get_the_terms($post->ID, 'category');
			if (! empty($terms) && ! is_wp_error($terms)) {
				$categories = wp_list_pluck($terms, 'name');
			}
			$inbound   = self::get_inbound_links($post->ID);
			$suggested = self::get_suggested_links($post->ID);
			$variables = self::resolved_variables($post, array_merge(array_values($templates), [
				(string) MetaFields::get($post->ID, MetaFields::SEO_TITLE),
				(string) MetaFields::get($post->ID, MetaFields::SEO_DESCRIPTION),
				(string) MetaFields::get($post->ID, MetaFields::OG_TITLE),
				(string) MetaFields::get($post->ID, MetaFields::OG_DESCRIPTION),
				(string) MetaFields::get($post->ID, MetaFields::X_TITLE),
				(string) MetaFields::get($post->ID, MetaFields::X_DESCRIPTION),
			]));
		}

		$localize_data = [
			'siteName'         => get_bloginfo('name'),
			'siteUrl'          => home_url('/'),
			'postTitle'        => $post_title,
			'excerpt'          => $excerpt,
			'separator'        => Variables::separator(),
			'currentYear'      => gmdate('Y'),
			'author'           => $author,
			'category'         => ! empty($categories) ? $categories[0] : '',
			'permalink'        => $post instanceof \WP_Post ? get_permalink($post->ID) : '',
			/* The post content is deliberately NOT localized here — it can be
			   hundreds of kilobytes on every editor load. The JS reads the live
			   content from the block editor store (or the Classic editor) via
			   getEditorContent(). */
			/* Title & Meta templates the front end falls back to when the fields are empty. */
			'titleTemplate'    => $templates['title'],
			'descTemplate'     => $templates['description'],
			/* Server-resolved values of every known variable, so the preview matches the front end. */
			'variables'        => (object) $variables,
			'hasExcerpt'       => $post instanceof \WP_Post && trim($post->post_excerpt) !== '',
			'inboundLinks'     => $inbound['links'],
			'inboundLinksMore' => $inbound['more'],
			'suggestedLinks'   => $suggested,
			'kwCheckNonce'     => wp_create_nonce('crawlwp_check_keyword'),
			'suggestedNonce'   => wp_create_nonce('crawlwp_suggested_links'),
			'datePublished'    => $post instanceof \WP_Post ? (string) get_the_date('c', $post) : '',
			'dateModified'     => $post instanceof \WP_Post ? (string) get_the_modified_date('c', $post) : '',
			'breadcrumbs'      => self::get_breadcrumb_trail($post),
			'ajaxUrl'          => admin_url('admin-ajax.php'),
			'aiNonce'          => wp_create_nonce('crawlwp_ai_generate'),
			'indexNowNonce'    => wp_create_nonce('crawlwp_submit_indexnow'),
			'postId'           => $post instanceof \WP_Post ? $post->ID : 0,
			'featuredImageUrl' => $post instanceof \WP_Post ? (get_the_post_thumbnail_url($post->ID, 'medium') ?: '') : '',
			'featuredImageFull' => $post instanceof \WP_Post ? (get_the_post_thumbnail_url($post->ID, 'full') ?: '') : '',
			'language'         => get_bloginfo('language'),
			'websiteId'        => home_url('/') . '#website',
			'publisherId'      => home_url('/') . '#' . ((string) SiteInfoSettings::get('site_type', 'organization') === 'person' ? 'person' : 'organization'),
			'i18n'             => self::get_i18n_strings(),
		];

		if ($post instanceof \WP_Post && $post->post_type === 'product') {
			$context = ['post' => $post];
			$localize_data['product'] = [
				'price'          => Variables::replace('{{ product.price }}', $context),
				'price_with_tax' => Variables::replace('{{ product.price_with_tax }}', $context),
				'sale_from'      => Variables::replace('{{ product.sale_from }}', $context),
				'sale_to'        => Variables::replace('{{ product.sale_to }}', $context),
				'sku'            => Variables::replace('{{ product.sku }}', $context),
				'stock'          => Variables::replace('{{ product.stock }}', $context),
				'currency'       => Variables::replace('{{ product.currency }}', $context),
				'rating'         => Variables::replace('{{ product.rating }}', $context),
				'review_count'   => Variables::replace('{{ product.review_count }}', $context),
				'low_price'      => Variables::replace('{{ product.low_price }}', $context),
				'high_price'     => Variables::replace('{{ product.high_price }}', $context),
				'offer_count'    => Variables::replace('{{ product.offer_count }}', $context),
			];
		}

		/**
		 * Let add-on plugins (e.g. mihdan-index-now-pro) inject extra data into
		 * the metabox's localized `crawlwpSEO` object — nonces, feature flags,
		 * etc. — without this plugin needing to know about them.
		 *
		 * @param array         $localize_data Data exposed to JS as the `crawlwpSEO` global.
		 * @param \WP_Post|null $post          The post being edited, if any.
		 */
		return apply_filters('crawlwp_metabox_localize_data', $localize_data, $post);
	}

	/**
	 * Title & Meta templates the front end uses for this post when its own
	 * SEO title/description are empty (see FrontendOutput::resolve()).
	 *
	 * @return array{title: string, description: string}
	 */
	public static function title_meta_templates(?\WP_Post $post): array
	{
		/* Post types CrawlWP does not handle keep the WordPress document title. */
		$templates = [
			'title'       => '{{ post.title }} {{ sep }} {{ site.title }}',
			'description' => '',
		];

		if (! $post instanceof \WP_Post) {
			return $templates;
		}

		$is_front_page = get_option('show_on_front') === 'page' && (int) get_option('page_on_front') === $post->ID;
		$entity_key    = $is_front_page ? 'home' : Entities::post_type_key($post->post_type);

		if (Entities::get($entity_key) === null) {
			return $templates;
		}

		foreach (array_keys($templates) as $field) {
			$templates[$field] = (string) Options::get($entity_key, $field, Entities::default_value($entity_key, $field));
		}

		return $templates;
	}

	/**
	 * Resolve every advertised variable, plus any used in the given strings,
	 * against the post, keyed by token name.
	 *
	 * @param string[] $strings Templates/values whose tokens must be resolvable too.
	 * @return array<string,string>
	 */
	private static function resolved_variables(\WP_Post $post, array $strings): array
	{
		$tokens = [];

		foreach (Variables::definitions() as $group) {
			foreach (array_keys((array) ($group['variables'] ?? [])) as $token) {
				$tokens[strtolower((string) $token)] = true;
			}
		}

		/* Placeholders documented in the reference, not real variables. */
		unset($tokens['post.custom_field.key'], $tokens['post.taxonomy.slug']);

		foreach ($strings as $string) {
			if (preg_match_all('/\{\{\s*([a-z0-9_]+(?:\.[a-z0-9_\-]+)*)\s*\}\}/i', (string) $string, $matches)) {
				foreach ($matches[1] as $token) {
					$tokens[strtolower($token)] = true;
				}
			}
		}

		$context = [
			'post'      => $post,
			'post_type' => get_post_type_object($post->post_type),
		];

		$values = [];

		foreach (array_keys($tokens) as $token) {
			/* The markers stop the separator cleanup from trimming a lone value such as {{ sep }}. */
			$resolved       = Variables::replace("\x02{{ " . $token . " }}\x03", $context);
			$values[$token] = trim(str_replace(["\x02", "\x03"], '', $resolved));
		}

		return $values;
	}

	/**
	 * Related posts an editor could link to from this content.
	 *
	 * @param int         $post_id The post being edited.
	 * @param string|null $keyword Focus keyword to search for. Defaults to the
	 *                             saved meta value; pass a string to preview a
	 *                             keyword that has not been saved yet.
	 */
	private static function get_suggested_links(int $post_id, ?string $keyword = null): array
	{
		$post = get_post($post_id);

		if (! $post instanceof \WP_Post) return [];

		$categories = wp_get_post_categories($post_id, ['fields' => 'ids']);
		$tags       = wp_get_post_tags($post_id, ['fields' => 'ids']);

		$limit = 10;
		$base  = [
			'post_type'           => $post->post_type,
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		];

		/* Try keyword-based search first */
		if ($keyword === null) {
			$keyword = (string) get_post_meta($post_id, MetaFields::FOCUS_KEYWORD, true);
		}

		$parsed_keywords = MetaFields::parse_keywords($keyword);
		$search_keyword  = $parsed_keywords[0] ?? '';
		$recent          = ['orderby' => 'date', 'order' => 'DESC'];

		if ($search_keyword !== '') {
			/* Keyword suggestions stay keyword-only, so "no matches" stays visible. */
			$strategies = [['s' => $search_keyword, 'orderby' => 'relevance']];
		} else {
			/* Same-category posts, topped up with same-tag and then recent posts. */
			$strategies = [];

			if (! empty($categories)) {
				$strategies[] = ['category__in' => $categories] + $recent;
			}

			if (! empty($tags)) {
				$strategies[] = ['tag__in' => $tags] + $recent;
			}

			$strategies[] = $recent;
		}

		$links   = [];
		$exclude = [$post_id];

		foreach ($strategies as $strategy) {
			$query = new \WP_Query(array_merge($base, $strategy, [
				'post__not_in'   => $exclude,
				'posts_per_page' => $limit - count($links),
			]));

			foreach ($query->posts as $suggested) {
				$exclude[] = $suggested->ID;
				$links[]   = [
					'title' => $suggested->post_title,
					'url'   => get_permalink($suggested->ID),
					'date'  => mysql2date('j M Y', $suggested->post_date),
				];
			}

			if (count($links) >= $limit) {
				break;
			}
		}

		wp_reset_postdata();

		return $links;
	}

	/**
	 * Refresh the suggested links for a keyword typed in the editor.
	 *
	 * The list is localized once on page load, so without this the suggestions
	 * would stay stale until the editor reloaded the screen.
	 */
	public function ajax_suggested_links(): void
	{
		check_ajax_referer('crawlwp_suggested_links', 'nonce');

		$post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

		if (! $post_id || ! current_user_can('edit_post', $post_id)) {
			wp_send_json_error(['message' => __('Unauthorized', 'mihdan-index-now')], 403);
		}

		$keyword = isset($_POST['keyword'])
			? sanitize_text_field(wp_unslash($_POST['keyword']))
			: '';

		wp_send_json_success([
			'links' => $this->get_suggested_links($post_id, $keyword),
		]);
	}

	/**
	 * Transient name holding the inbound links of a post.
	 */
	private static function inbound_links_cache_key(int $post_id): string
	{
		return 'crawlwp_inbound_links_' . $post_id;
	}

	/**
	 * Drop the cached inbound links of a post.
	 *
	 * Editing a post changes both its own outgoing links and — for the post it
	 * links to — that post's inbound list, so the cache also carries a short
	 * expiry to cover the second case.
	 */
	public function flush_inbound_links_cache(int $post_id): void
	{
		delete_transient(self::inbound_links_cache_key($post_id));
	}

	/**
	 * Published content that links to this post.
	 *
	 * A link may use the absolute permalink (http or https, with or without
	 * www.), a site-relative path or the ?p= / ?page_id= form. The lookup is an
	 * unindexed LIKE over post_content, so the candidates are narrowed in SQL,
	 * confirmed against the actual href in PHP and the result is cached in a
	 * transient.
	 *
	 * @return array{links: array, more: bool}
	 */
	private static function get_inbound_links(int $post_id): array
	{
		$empty     = ['links' => [], 'more' => false];
		$permalink = get_permalink($post_id);

		if (! $permalink) return $empty;

		$cache_key = self::inbound_links_cache_key($post_id);
		$cached    = get_transient($cache_key);

		if (is_array($cached) && isset($cached['links'])) {
			return $cached;
		}

		global $wpdb;

		/* Number of linking posts listed before the count shows as "N+". */
		$max = 50;

		/* The path also matches relative links and either scheme. The home page path is too broad to search for. */
		$path    = untrailingslashit((string) wp_parse_url($permalink, PHP_URL_PATH));
		$home    = untrailingslashit((string) wp_parse_url(home_url('/'), PHP_URL_PATH));
		$needles = $path !== '' && $path !== $home ? [$path] : [];

		foreach (['?p=', '&p=', ';p=', 'page_id='] as $prefix) {
			$needles[] = $prefix . $post_id;
		}

		$host    = preg_replace('/^www\./i', '', (string) wp_parse_url(home_url('/'), PHP_URL_HOST));
		$host_re = '(?:(?:https?:)?//(?:www\.)?' . preg_quote($host, '#') . '(?::\d+)?)?';
		$alts    = [];

		if ($path !== '' && $path !== $home) {
			$alts[] = $host_re . preg_quote($path, '#') . '/?(?:[?\#][^"\']*)?';
		}

		$alts[]  = $host_re . '(?:/[^"\'?]*)?\?(?:[^"\']*?(?:&amp;|&|;))?(?:p|page_id)=' . $post_id . '(?:(?:&|\#)[^"\']*)?';
		$pattern = '#<a\s[^>]*?href=(["\'])(?:' . implode('|', $alts) . ')\1[^>]*>(.*?)</a>#is';

		$post_types = array_values(array_diff(get_post_types(['public' => true]), ['attachment']));

		if ($post_types === []) return $empty;

		/* Characters kept before/after the first match so the anchor tag usually fits in the excerpt. */
		$before = 300;
		$length = 900;

		$like_sql  = implode(' OR ', array_fill(0, count($needles), 'post_content LIKE %s'));
		$types_sql = implode(',', array_fill(0, count($post_types), '%s'));
		$params    = array_merge(
			[$needles[0], $before, $length],
			array_map(static function ($needle) use ($wpdb) {
				return '%' . $wpdb->esc_like($needle) . '%';
			}, $needles),
			$post_types,
			[$post_id]
		);

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_date,
					SUBSTRING(post_content, GREATEST(1, LOCATE(%s, post_content) - %d), %d) AS content_excerpt
				 FROM {$wpdb->posts}
				 WHERE ({$like_sql})
				   AND post_status = 'publish'
				   AND post_type IN ({$types_sql})
				   AND ID != %d
				 ORDER BY post_date DESC
				 LIMIT 200",
				$params
			)
		);

		$links = [];
		$more  = false;

		foreach ($results as $row) {
			/* The excerpt only covers the first needle; check the whole content before giving up. */
			if (! preg_match($pattern, (string) $row->content_excerpt, $m)
				&& ! preg_match($pattern, (string) get_post_field('post_content', (int) $row->ID, 'raw'), $m)) {
				continue;
			}

			if (count($links) >= $max) {
				$more = true;
				break;
			}

			$links[] = [
				'title'  => $row->post_title,
				'url'    => get_permalink($row->ID),
				'anchor' => wp_strip_all_tags($m[2]),
				'date'   => mysql2date('j M Y', $row->post_date),
			];
		}

		$result = ['links' => $links, 'more' => $more];

		/**
		 * Filters how long the inbound link list of a post stays cached.
		 *
		 * @param int $ttl     Lifetime in seconds. Defaults to 15 minutes.
		 * @param int $post_id The post the links point to.
		 */
		$ttl = (int) apply_filters('crawlwp_inbound_links_cache_ttl', 15 * MINUTE_IN_SECONDS, $post_id);

		set_transient($cache_key, $result, max(MINUTE_IN_SECONDS, $ttl));

		return $result;
	}

	public function ajax_ai_generate(): void
	{
		check_ajax_referer('crawlwp_ai_generate', 'nonce');

		$post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
		$field   = isset($_POST['field']) ? sanitize_key($_POST['field']) : '';
		$title   = isset($_POST['post_title']) ? sanitize_text_field(wp_unslash($_POST['post_title'])) : '';
		$content = isset($_POST['post_content']) ? wp_kses_post(wp_unslash($_POST['post_content'])) : '';
		$keyword = isset($_POST['focus_keyword']) ? sanitize_text_field(wp_unslash($_POST['focus_keyword'])) : '';
		$previous = isset($_POST['previous_value']) ? sanitize_textarea_field(wp_unslash($_POST['previous_value'])) : '';

		// Check the capability against the actual post being edited, not just
		// the generic edit_posts capability.
		$allowed = $post_id ? current_user_can('edit_post', $post_id) : current_user_can('edit_posts');

		if (! $allowed) {
			wp_send_json_error(['message' => __('You are not allowed to generate SEO text for this post.', 'mihdan-index-now')], 403);
		}

		if ($this->ai_rate_limited()) {
			wp_send_json_error(['message' => __('Too many AI requests. Please wait a few minutes and try again.', 'mihdan-index-now')], 429);
		}

		if (! in_array($field, Generator::fields(), true)) {
			wp_send_json_error(['message' => __('Unsupported field.', 'mihdan-index-now')]);
		}

		$context = [
			'post_id'  => $post_id,
			'title'    => $title,
			'content'  => $content,
			'keyword'  => $keyword,
			'previous' => $previous,
		];

		/**
		 * Filter the AI-generated SEO text.
		 *
		 * Third-party plugins or the pro add-on can hook into this filter
		 * to provide the text from their own service instead.
		 *
		 * @param string $text    The generated text (empty by default).
		 * @param string $field   The field being generated.
		 * @param array  $context Post context: post_id, title, content, keyword, previous.
		 */
		$generated = apply_filters('crawlwp_ai_generate_seo', '', $field, $context);

		if (! empty($generated)) {
			wp_send_json_success(['text' => $generated, 'source' => 'filter']);
		}

		// Ask the AI provider connected to WordPress (Settings > Connectors).
		$generated = (new Generator())->generate($field, $context);

		if (is_wp_error($generated)) {
			wp_send_json_error($this->ai_error_response($generated));
		}

		wp_send_json_success(['text' => $generated, 'source' => 'ai']);
	}

	/**
	 * Per-user rate limit for AI generation: at most 20 requests per 5 minutes.
	 *
	 * Increments the counter on every call, so call it once per request.
	 */
	private function ai_rate_limited(): bool
	{
		$limit  = (int) apply_filters('crawlwp_ai_rate_limit', 20);
		$window = (int) apply_filters('crawlwp_ai_rate_limit_window', 5 * MINUTE_IN_SECONDS);

		if ($limit <= 0) {
			return false;
		}

		$key   = 'crawlwp_ai_rl_' . get_current_user_id();
		$state = get_transient($key);
		$now   = time();

		// Fixed window: [count, window start]. A missing/expired transient starts a fresh window.
		if (! is_array($state) || ! isset($state['count'], $state['start']) || ($now - (int) $state['start']) >= $window) {
			$state = ['count' => 0, 'start' => $now];
		}

		if ((int) $state['count'] >= $limit) {
			return true;
		}

		$state['count'] = (int) $state['count'] + 1;
		$remaining      = $window - ($now - (int) $state['start']);

		set_transient($key, $state, max(1, $remaining));

		return false;
	}

	/**
	 * Build the payload shown to the user when AI generation failed.
	 *
	 * Only errors that the user fixes by connecting a provider link to the
	 * Connectors screen; content and provider errors are shown as they are so
	 * the message stays truthful.
	 */
	private function ai_error_response(\WP_Error $error): array
	{
		$code    = $error->get_error_code();
		$message = $error->get_error_message();

		$connection_codes = ['crawlwp_ai_unavailable', 'crawlwp_ai_unsupported'];

		if (in_array($code, $connection_codes, true)) {
			return [
				'message'    => __('AI generation is unavailable. Connect an AI provider in WordPress under Settings → Connectors, then try again.', 'mihdan-index-now')
					. ($message !== '' ? "\n\n" . $message : ''),
				'connectUrl' => admin_url('options-connectors.php'),
			];
		}

		return [
			'message' => $message !== ''
				? $message
				: __('AI generation failed. Please try again.', 'mihdan-index-now'),
		];
	}

	/**
	 * Singular and plural form of a translatable string, for the JS plural() helper.
	 *
	 * @param array $nooped Result of _n_noop().
	 * @return array{one: string, other: string}
	 */
	private static function plural(array $nooped): array
	{
		return [
			'one'   => translate_nooped_plural($nooped, 1, 'mihdan-index-now'),
			'other' => translate_nooped_plural($nooped, 2, 'mihdan-index-now'),
		];
	}

	private static function get_i18n_strings(): array
	{
		return [
			/* Pixel meter labels */
			'meterTooShort'    => __('Too short', 'mihdan-index-now'),
			'meterGoodLength'  => __('Good length', 'mihdan-index-now'),
			'meterWillBeCut'   => __('Will be cut off', 'mihdan-index-now'),
			/* translators: %1$s: pixel width, %2$s: pixel limit, %3$s: character count */
			'meterDetail'      => __('%1$s / %2$s px · %3$s chars', 'mihdan-index-now'),

			/* Variable inserter */
			'insertVariable'   => __('Insert variable', 'mihdan-index-now'),

			/* Live preview placeholders */
			'enterTitle'       => __('Enter a title', 'mihdan-index-now'),
			'addMetaDesc'      => __('Add a meta description to control what appears here.', 'mihdan-index-now'),

			/* JSON-LD toggle */
			'hideJsonLd'       => __('Hide JSON-LD', 'mihdan-index-now'),
			'showJsonLd'       => __('Show JSON-LD', 'mihdan-index-now'),

			/* Schema preview */
			'noStructuredData' => __('// No structured data will be output for this post.', 'mihdan-index-now'),

			/* Image picker */
			'selectImage'      => __('Select Image', 'mihdan-index-now'),

			/* Show-all toggle */
			/* translators: %s: total number of links */
			'showAllLinks'     => __('Show all %s links', 'mihdan-index-now'),

			/* Link chips */
			'internal'         => __('Internal', 'mihdan-index-now'),
			'external'         => __('External', 'mihdan-index-now'),
			'suggested'        => __('Suggested', 'mihdan-index-now'),

			/* Inbound link meta */
			/* translators: %s: anchor text */
			'anchorLabel'      => __('Anchor: "%s"', 'mihdan-index-now'),
			/* translators: %s: date string */
			'publishedDate'    => __('published %s', 'mihdan-index-now'),

			/* Suggested links empty state when a focus keyword is set */
			'noSuggestionsForKw' => __('No published posts match the focus keyword yet. Try a broader keyword.', 'mihdan-index-now'),
			'noSuggestions'      => __('No suggestions available yet. Add a focus keyword to get link suggestions.', 'mihdan-index-now'),

			/* Links notice */
			'noInternalLinks'  => __('This post links to nothing on your site. Adding two or three internal links helps crawlers reach related posts and passes ranking signals along.', 'mihdan-index-now'),

			/* Copy URL */
			'copyUrl'          => __('Copy URL', 'mihdan-index-now'),
			'copied'           => __('Copied!', 'mihdan-index-now'),

			/* Analysis notice */
			'enterFocusKw'            => __('Enter a focus keyword above to run the analysis.', 'mihdan-index-now'),
			/* translators: %s: keyword */
			'scoredAgainst'           => __('Scored against %s. Change the focus keyword above to rescore.', 'mihdan-index-now'),
			/* translators: %s: secondary keyword */
			'scoredAgainstSecondary'  => __('Scored against secondary keyword %s. Title and slug checks are relaxed to prevent keyword stuffing.', 'mihdan-index-now'),
			'primaryKw'               => __('Primary', 'mihdan-index-now'),
			'secondaryKw'             => __('Secondary', 'mihdan-index-now'),
			'kwInContentGood'         => __('Keyword is in the post content.', 'mihdan-index-now'),
			/* translators: %s: number of occurrences */
			'kwInContentGoodD'        => self::plural(_n_noop('Found %s time in post content.', 'Found %s times in post content.', 'mihdan-index-now')),
			'kwInContentBad'          => __('Keyword is missing from post content.', 'mihdan-index-now'),
			'kwInContentBadD'         => __('Mention this secondary keyword naturally in your article body.', 'mihdan-index-now'),

			/* Analysis: 1 – Keyword in title */
			'kwInTitleGood'    => __('Keyword is in the SEO title.', 'mihdan-index-now'),
			'kwInTitleStart'   => __('It appears near the start, where it carries the most weight.', 'mihdan-index-now'),
			'kwInTitleMove'    => __('Try moving it closer to the beginning for more impact.', 'mihdan-index-now'),
			'kwInTitleBad'     => __('Keyword is missing from the SEO title.', 'mihdan-index-now'),
			'kwInTitleFix'     => __('Add it to the title so search engines and users see it immediately.', 'mihdan-index-now'),

			/* Analysis: 2 – Keyword in slug */
			'kwInSlugGood'     => __('Keyword is in the URL slug.', 'mihdan-index-now'),
			'kwInSlugBad'      => __('Keyword is missing from the URL slug.', 'mihdan-index-now'),
			'kwInSlugFix'      => __('Include it in the slug for better URL relevance.', 'mihdan-index-now'),

			/* Analysis: 3 – Title length */
			'titleLenGood'     => __('Title length fits.', 'mihdan-index-now'),
			/* translators: %s: pixel width */
			'titleLenDetail'   => __('%s px of the 580 px Google shows.', 'mihdan-index-now'),
			'titleLenLong'     => __('Title is too long.', 'mihdan-index-now'),
			/* translators: %s: pixel width */
			'titleLenLongD'    => __('%s px exceeds the 580 px limit — it will be cut off in search results.', 'mihdan-index-now'),
			'titleLenShort'    => __('Title is too short.', 'mihdan-index-now'),
			/* translators: %s: pixel width */
			'titleLenShortD'   => __('%s px of the 580 px Google shows. Aim for at least 200 px.', 'mihdan-index-now'),

			/* Analysis: 4 – Meta description keyword */
			'kwInDescGood'     => __('Keyword is in the meta description.', 'mihdan-index-now'),
			'kwInDescWarn'     => __('Meta description does not contain the keyword.', 'mihdan-index-now'),
			'kwInDescWarnD'    => __('Mentioning it helps bold the term in search results.', 'mihdan-index-now'),
			'noDescBad'        => __('No meta description set.', 'mihdan-index-now'),
			'noDescFix'        => __('Write a compelling description that includes the keyword.', 'mihdan-index-now'),
			'descGenerated'    => __('No custom meta description — one is generated from the post type template.', 'mihdan-index-now'),
			'descGeneratedFix' => __('Write your own that includes the keyword for more control over the snippet.', 'mihdan-index-now'),
			'descGeneratedD'   => __('The meta description is generated from the post type template.', 'mihdan-index-now'),

			/* Analysis: 5 – Meta description length */
			'descLenGood'      => __('Meta description length is good.', 'mihdan-index-now'),
			/* translators: %s: pixel width */
			'descLenGoodD'     => __('%s px of the 920 px limit.', 'mihdan-index-now'),
			'descLenLong'      => __('Meta description is too long.', 'mihdan-index-now'),
			/* translators: %s: pixel width */
			'descLenLongD'     => __('%s px exceeds 920 px — it may be truncated.', 'mihdan-index-now'),
			'descLenShort'     => __('Meta description is too short.', 'mihdan-index-now'),
			'descLenShortD'    => __('Aim for at least 400 px to use the available space.', 'mihdan-index-now'),

			/* Analysis: 6 – Keyword in first paragraph */
			'kwFirstParaGood'  => __('Keyword appears in the first paragraph.', 'mihdan-index-now'),
			'kwFirstParaWarn'  => __('Keyword is missing from the first paragraph.', 'mihdan-index-now'),
			'kwFirstParaFix'   => __('Introduce the topic early so readers and engines see it upfront.', 'mihdan-index-now'),

			/* Analysis: 7 – Keyword in subheadings */
			/* translators: %s: number of subheadings */
			'kwSubheadGood'    => __('Keyword appears in %s subheadings.', 'mihdan-index-now'),
			'kwSubheadOne'     => __('Only one subheading uses the keyword.', 'mihdan-index-now'),
			'kwSubheadOneFix'  => __('Work it into one or two more H2s where it reads naturally.', 'mihdan-index-now'),
			'kwSubheadBad'     => __('No subheading uses the keyword.', 'mihdan-index-now'),
			'kwSubheadFix'     => __('Add the keyword to at least one H2 or H3.', 'mihdan-index-now'),

			/* translators: %s: number of H1 tags */
			'h1Multiple'       => __('Multiple H1 tags found (%s).', 'mihdan-index-now'),
			'h1MultipleFix'    => __('Use only one H1 per page; the theme usually prints the title as the H1, so use H2 and below in the content.', 'mihdan-index-now'),

			/* Analysis: 9 – Images alt text */
			'noImages'         => __('No images found.', 'mihdan-index-now'),
			'noImagesFix'      => __('Adding relevant images can improve engagement and image search traffic.', 'mihdan-index-now'),
			'allImgAlt'        => __('All images have alt text.', 'mihdan-index-now'),
			/* translators: %s: number of images */
			'imgAltDetail'     => self::plural(_n_noop('%s image found.', '%s images found.', 'mihdan-index-now')),
			/* translators: %s: number of images missing alt */
			'imgAltMissing'    => self::plural(_n_noop('%s image is missing alt text.', '%s images are missing alt text.', 'mihdan-index-now')),
			'imgAltFix'        => __('Describe what each one shows for accessibility and SEO.', 'mihdan-index-now'),

			/* Analysis: 10 – Keyword in image alt */
			'kwImgAltGood'     => __('Keyword found in an image alt attribute.', 'mihdan-index-now'),
			'kwImgAltWarn'     => __('No image alt text contains the keyword.', 'mihdan-index-now'),
			'kwImgAltFix'      => __('Add the keyword to at least one relevant image alt tag.', 'mihdan-index-now'),

			/* Analysis: 11 – Internal links */
			/* translators: %s: number of internal links */
			'intLinksGood'     => __('%s internal links.', 'mihdan-index-now'),
			'intLinksGoodD'    => __('Good internal linking structure.', 'mihdan-index-now'),
			'intLinksOne'      => __('Only 1 internal link.', 'mihdan-index-now'),
			'intLinksOneFix'   => __('Add at least one more internal link to improve crawlability.', 'mihdan-index-now'),
			'intLinksNone'     => __('No internal links.', 'mihdan-index-now'),
			'intLinksNoneFix'  => __('Link to at least two related posts so crawlers can reach them from here.', 'mihdan-index-now'),

			/* Analysis: 12 – External links */
			/* translators: %s: number of external links */
			'extLinksGood'     => self::plural(_n_noop('%s external link.', '%s external links.', 'mihdan-index-now')),
			'extLinksGoodD'    => __('Linking to authoritative sources adds credibility.', 'mihdan-index-now'),
			'extLinksNone'     => __('No external links.', 'mihdan-index-now'),
			'extLinksNoneFix'  => __('Consider linking to a relevant authoritative source to add context.', 'mihdan-index-now'),

			/* Analysis: 13 – Content length */
			/* translators: %s: word count */
			'wordsLabel'       => __('%s words.', 'mihdan-index-now'),
			'wordsEnough'      => __('Long enough to cover the topic.', 'mihdan-index-now'),
			'wordsAim300'      => __('Aim for at least 300 words to provide enough depth.', 'mihdan-index-now'),
			'wordsThin'        => __('Content is too thin. Search engines prefer in-depth articles.', 'mihdan-index-now'),

			/* Analysis: 14 – Keyword density */
			/* translators: %s: density percentage */
			'densityLabel'     => __('Keyword density is %s%%.', 'mihdan-index-now'),
			'densityGoodD'     => __('Within the recommended 0.5–3% range.', 'mihdan-index-now'),
			'densityHighD'     => __('This may look like keyword stuffing. Aim for 0.5–3%.', 'mihdan-index-now'),
			'densityLowD'      => __('Try to mention the keyword a few more times naturally.', 'mihdan-index-now'),

			/* Analysis: 15 – Readability */
			/* translators: %s: average sentence length */
			'readability'      => __('Average sentence length is %s words.', 'mihdan-index-now'),
			'readabilityGoodD' => __('Easy to read.', 'mihdan-index-now'),
			'readabilityWarnD' => __('Some sentences may be hard to follow. Try breaking them up.', 'mihdan-index-now'),
			'readabilityBadD'  => __('Sentences are too long. Aim for under 20 words on average.', 'mihdan-index-now'),

			/* Analysis: 16 – Heading hierarchy */
			/* translators: %s: number of H2 tags */
			'h2Good'           => __('%s H2 subheadings structure the content.', 'mihdan-index-now'),
			'h2One'            => __('Only 1 H2 subheading found.', 'mihdan-index-now'),
			'h2OneFix'         => __('Adding more H2s improves readability and SEO.', 'mihdan-index-now'),
			'h2None'           => __('No H2 subheadings found.', 'mihdan-index-now'),
			'h2NoneFix'        => __('Break up long content with H2 headings for better structure.', 'mihdan-index-now'),

			/* Analysis dot */
			/* translators: %s: number of issues */
			'issueCount'       => self::plural(_n_noop('%s issue', '%s issues', 'mihdan-index-now')),

			/* AI generate */
			'aiGenerate'       => __('Generate with AI', 'mihdan-index-now'),
			'aiGenerating'     => __('Generating…', 'mihdan-index-now'),
			'aiError'          => __('AI generation is unavailable. Connect an AI provider in WordPress under Settings → Connectors, then try again.', 'mihdan-index-now'),
			'aiOpenConnectors' => __('Open the Connectors settings page in a new tab?', 'mihdan-index-now'),
			'aiRewrite'        => __('Rewrite with AI', 'mihdan-index-now'),

			/* IndexNow submit */
			'submitIndexNow'   => __('Submit for Indexing', 'mihdan-index-now'),
			'submitting'       => __('Submitting…', 'mihdan-index-now'),
			'submitSuccess'    => __('Successfully submitted for indexing!', 'mihdan-index-now'),
			'submitError'      => __('Failed to submit. Please try again.', 'mihdan-index-now'),
			'submitPartial'    => __('Submitted, but a search engine returned an error.', 'mihdan-index-now'),
			'savePostFirst'    => __('Please save the post first before submitting to IndexNow.', 'mihdan-index-now'),

			/* Readability badge */
			'readabilityGood'       => __('Good readability', 'mihdan-index-now'),
			'readabilityOk'         => __('Fairly readable', 'mihdan-index-now'),
			'readabilityPoor'       => __('Needs improvement', 'mihdan-index-now'),
			'readabilityNA'         => __('Readability analysis will run when content is available.', 'mihdan-index-now'),
			/* translators: %1$s: Flesch score, %2$s: avg sentence length, %3$s: percentage of long sentences */
			'readabilityDetail'     => __('Flesch score %1$s · avg. sentence %2$s words · %3$s% long sentences', 'mihdan-index-now'),

			/* Focus keyword duplicate warning */
			/* translators: %1$s: post title, %2$s: edit link */
			'kwDuplicateWarn'       => __('This keyword is already used by "%1$s". Using the same keyword on multiple posts may cause keyword cannibalization.', 'mihdan-index-now'),
			/* translators: 1: keyword, 2: post title */
			'kwDuplicateWarnWithKw' => __('The focus keyword %1$s is already used by %2$s. Using the same keyword on multiple posts may cause keyword cannibalization.', 'mihdan-index-now'),
			'kwChecking'            => __('Checking…', 'mihdan-index-now'),

			/* Breadcrumb preview */
			'breadcrumbHome'        => __('Home', 'mihdan-index-now'),

			/* Social image dimensions */
			/* translators: %1$s: actual width, %2$s: actual height */
			'imgDimensions'         => __('%1$s × %2$s px', 'mihdan-index-now'),
			'imgTooSmall'           => __('Image is too small. Minimum recommended:', 'mihdan-index-now'),
			'imgSizeGood'           => __('Image meets the recommended size.', 'mihdan-index-now'),
			'imgOgMin'              => __('1200 × 630 px', 'mihdan-index-now'),
			'imgXMin'               => __('800 × 418 px', 'mihdan-index-now'),
		];
	}

	public function ajax_submit_indexnow(): void
	{
		check_ajax_referer('crawlwp_submit_indexnow', 'nonce');

		$post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

		if (! $post_id) {
			wp_send_json_error(['message' => 'Invalid post ID.']);
		}

		if (! current_user_can('edit_post', $post_id)) {
			wp_send_json_error(['message' => 'Unauthorized'], 403);
		}

		$post = get_post($post_id);

		if (! $post instanceof \WP_Post) {
			wp_send_json_error(['message' => 'Post not found.']);
		}

		if (get_post_status($post_id) !== 'publish') {
			wp_send_json_error(['message' => __('Only published posts can be submitted for indexing.', 'mihdan-index-now')]);
		}

		/* Same rules as automatic submissions: enabled post type, not noindex. */
		$skip_reason = Indexing::get_post_skip_reason($post);

		if ($skip_reason !== '') {
			wp_send_json_error(['message' => $skip_reason]);
		}

		/* Every engine hooks this action only while it is enabled. */
		if (! has_action('crawlwp/post_updated')) {
			wp_send_json_error(['message' => __('No search engine is enabled for indexing. Enable IndexNow or a webmaster API in the CrawlWP settings.', 'mihdan-index-now')]);
		}

		$paused_until = self::indexing_paused_until();

		if ($paused_until > 0) {
			wp_send_json_error([
				'message' => sprintf(
					/* translators: %s: date and time the submissions resume. */
					__('Submissions are paused because a search engine reported a rate limit. They resume on %s.', 'mihdan-index-now'),
					wp_date(get_option('date_format') . ' ' . get_option('time_format'), $paused_until)
				),
			]);
		}

		/* Watch what the engines actually do: what they report, and how their HTTP requests end. */
		$pinged     = 0;
		$successes  = 0;
		$errors     = [];
		$on_pinged  = static function ($type, $object_id) use (&$pinged, $post_id) {
			if ($type === 'post' && (int) $object_id === $post_id) {
				$pinged++;
			}
		};
		$on_http    = static function ($response) use (&$successes, &$errors) {
			if (is_wp_error($response)) {
				$errors[] = $response->get_error_message();
				return;
			}

			$code = (int) wp_remote_retrieve_response_code($response);

			if ($code >= 200 && $code < 300) {
				$successes++;
				return;
			}

			$body     = json_decode((string) wp_remote_retrieve_body($response), true);
			$errors[] = is_array($body) && ! empty($body['message']) && is_string($body['message'])
				? $body['message']
				: trim($code . ' ' . wp_remote_retrieve_response_message($response));
		};
		/* Engines that do not use the WordPress HTTP API store a rate-limit pause on a 4xx reply. */
		$on_option  = static function ($option) use (&$errors) {
			if (is_string($option) && preg_match('/^crawlwp_.+_rate_limit_expiration$/', $option)) {
				$errors[] = __('The search engine rejected the request.', 'mihdan-index-now');
			}
		};

		add_action('crawlwp/index_pinged', $on_pinged, 10, 2);
		add_action('http_api_debug', $on_http, 10, 1);
		add_action('added_option', $on_option, 10, 1);
		add_action('updated_option', $on_option, 10, 1);

		/**
		 * Trigger the same action the plugin fires when a post is updated,
		 * so all registered IndexNow providers will ping the URL.
		 */
		do_action('crawlwp/post_updated', $post->ID, $post);

		remove_action('crawlwp/index_pinged', $on_pinged, 10);
		remove_action('http_api_debug', $on_http, 10);
		remove_action('added_option', $on_option, 10);
		remove_action('updated_option', $on_option, 10);

		$errors = array_values(array_unique(array_filter(array_map('wp_strip_all_tags', $errors))));

		if ($errors !== [] && $successes === 0) {
			wp_send_json_error([
				'message' => sprintf(
					/* translators: %s: error returned by the search engine. */
					__('Submission failed: %s', 'mihdan-index-now'),
					implode(' ', $errors)
				),
			]);
		}

		if ($pinged === 0 && $successes === 0) {
			wp_send_json_error(['message' => __('Nothing was submitted. Check that an API key or token is configured for the enabled search engine.', 'mihdan-index-now')]);
		}

		$timestamp = time();
		update_post_meta($post_id, '_crawlwp_last_indexnow', $timestamp);

		$message = __('Successfully submitted for indexing!', 'mihdan-index-now');

		if ($errors !== []) {
			$message = sprintf(
				/* translators: %s: error returned by a search engine. */
				__('Submitted, but a search engine returned an error: %s', 'mihdan-index-now'),
				implode(' ', $errors)
			);
		}

		wp_send_json_success([
			'message'   => $message,
			'partial'   => $errors !== [],
			'timestamp' => $timestamp,
			'date'      => wp_date(get_option('date_format'), $timestamp),
		]);
	}

	/**
	 * When every enabled search engine is in a rate-limit pause, the time the
	 * first of them resumes; 0 when at least one engine can submit now.
	 */
	private static function indexing_paused_until(): int
	{
		$engines = [];

		if (Indexing::is_on('enable', 'index_now')) {
			$engines[] = Indexing::get_indexnow_pause_option((string)Indexing::get_option('search_engine', 'index_now'));
		}

		foreach (['google', 'bing', 'yandex'] as $engine) {
			if (Utils::wposa_get_option('enable', $engine . '_webmaster', 'off') === 'on') {
				$engines[] = 'crawlwp_' . $engine . '_indexing_rate_limit_expiration';
			}
		}

		$now   = time();
		$until = [];

		foreach ($engines as $option) {
			$expires = (int) get_option($option, 0);

			if ($expires <= $now) {
				return 0;
			}

			$until[] = $expires;
		}

		/* No known engine enabled (an add-on may handle the action): nothing to report. */
		return $until === [] ? 0 : min($until);
	}

	/**
	 * Store the last pinged timestamp whenever any IndexNow provider pings a post.
	 */
	public function store_last_pinged_time(string $type, int $object_id): void
	{
		if ($type === 'post') {
			update_post_meta($object_id, '_crawlwp_last_indexnow', time());
		}
	}

	/**
	 * Build the breadcrumb trail array for a post.
	 */
	private static function get_breadcrumb_trail(?\WP_Post $post): array
	{
		if (! $post instanceof \WP_Post) {
			return [];
		}

		/* WordPress KSES-encodes literal "&" in term names when they are saved
		 * (so "Computer & Internet" is stored as "Computer &amp; Internet").
		 * Decode before sending to JS, otherwise the preview shows the raw entity.
		 * The first crumb matches the front end (Breadcrumbs::$args['label_home']). */
		$crumbs = [wp_specialchars_decode((string) BreadcrumbSettings::get('label_home', __('Home', 'mihdan-index-now')), ENT_QUOTES)];

		$terms = get_the_terms($post->ID, 'category');
		if (! empty($terms) && ! is_wp_error($terms)) {
			/* Build the category hierarchy */
			$primary = $terms[0];
			$ancestors = get_ancestors($primary->term_id, 'category', 'taxonomy');
			$ancestors = array_reverse($ancestors);
			foreach ($ancestors as $anc_id) {
				$anc = get_term($anc_id, 'category');
				if ($anc && ! is_wp_error($anc)) {
					$crumbs[] = wp_specialchars_decode($anc->name, ENT_QUOTES);
				}
			}
			$crumbs[] = wp_specialchars_decode($primary->name, ENT_QUOTES);
		}

		return $crumbs;
	}

	/**
	 * AJAX: Check if any focus keyword is already used by another published post.
	 */
	public function ajax_check_duplicate_keyword(): void
	{
		check_ajax_referer('crawlwp_check_keyword', 'nonce');

		if (! current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Unauthorized'], 403);
		}

		$raw_keyword = isset($_POST['keyword']) ? sanitize_text_field(wp_unslash($_POST['keyword'])) : '';
		$post_id     = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

		if ($post_id && ! current_user_can('edit_post', $post_id)) {
			wp_send_json_error(['message' => 'Unauthorized'], 403);
		}

		$keywords = MetaFields::parse_keywords($raw_keyword);

		if ($keywords === []) {
			wp_send_json_success(['duplicate' => false]);
		}

		global $wpdb;

		foreach ($keywords as $kw) {
			$target_lower = mb_strtolower($kw);

			/* Match the keyword as a whole entry of the comma-separated list in SQL,
			 * so posts that merely contain it as a substring cannot crowd out the match. */
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, p.post_title, pm.meta_value
					 FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					 WHERE pm.meta_key = %s
					   AND (
						LOWER(TRIM(pm.meta_value)) = %s
						OR CONCAT(',', REPLACE(REPLACE(REPLACE(LOWER(pm.meta_value), '  ', ' '), ', ', ','), ' ,', ','), ',') LIKE %s
					   )
					   AND p.post_status = 'publish'
					   AND p.ID != %d
					 LIMIT 20",
					MetaFields::FOCUS_KEYWORD,
					$target_lower,
					'%,' . $wpdb->esc_like($target_lower) . ',%',
					$post_id
				)
			);

			foreach ($rows as $row) {
				$existing_kws = array_map('mb_strtolower', MetaFields::parse_keywords((string) $row->meta_value));
				if (in_array($target_lower, $existing_kws, true)) {
					wp_send_json_success([
						'duplicate' => true,
						'keyword'   => $kw,
						'postTitle' => $row->post_title,
						'editUrl'   => get_edit_post_link((int) $row->ID, 'raw'),
					]);
				}
			}
		}

		wp_send_json_success(['duplicate' => false]);
	}
}
