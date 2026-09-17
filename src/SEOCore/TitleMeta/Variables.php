<?php

namespace Mihdan\IndexNow\SEOCore\TitleMeta;

use Mihdan\IndexNow\SEOCore\MetaBox\MetaFields;

/**
 * Resolves template variables used in the global title & meta defaults.
 *
 * Variables use a single namespaced syntax: {{ site.title }}, {{ sep }},
 * {{ post.auto_description }}. Inner spaces are optional.
 */
class Variables
{
	/**
	 * Matches {{ name }}, {{name}}, {{ some.name }} and the dynamic forms that
	 * carry a meta key or taxonomy slug as their last segment, e.g.
	 * {{ post.custom_field.my-key }}.
	 */
	private const TOKEN_REGEX = '/\{\{\s*([a-z0-9_]+(?:\.[a-z0-9_\-]+)*)\s*\}\}/i';

	/**
	 * Resolution context for the current request.
	 *
	 * @var array
	 */
	private array $context;

	/**
	 * Fingerprint of {@see self::$context}, '' when it cannot be memoised.
	 */
	private string $context_key;

	/**
	 * Resolved templates for this request, keyed by context + template.
	 *
	 * A single request resolves up to six templates (title, description, social
	 * title/description, X title/description) against the same context, and the
	 * same template often repeats between them.
	 *
	 * @var array<string,string>
	 */
	private static array $resolved = [];

	/**
	 * Resolved token values for this request, keyed by context + token.
	 *
	 * @var array<string,string>
	 */
	private static array $tokens = [];

	/**
	 * Memoised separator for this request.
	 *
	 * @var string|null
	 */
	private static ?string $separator = null;

	/**
	 * Generated post descriptions, keyed by post ID.
	 *
	 * @var array<int,string>
	 */
	private static array $auto_descriptions = [];

	public function __construct(array $context = [])
	{
		$this->context     = $context;
		$this->context_key = self::context_key($context);
	}

	/**
	 * Convenience wrapper: resolve a template against a context.
	 */
	public static function replace(string $template, array $context = []): string
	{
		if ($template === '') {
			return '';
		}

		$instance = new self($context);
		$memo_key = $instance->context_key === '' ? '' : $instance->context_key . '|' . $template;

		if ($memo_key !== '' && isset(self::$resolved[$memo_key])) {
			return self::$resolved[$memo_key];
		}

		$value = strpos($template, '{{') === false
			? self::cleanup($template)
			: $instance->resolve($template);

		if ($memo_key !== '') {
			self::$resolved[$memo_key] = $value;
		}

		return $value;
	}

	/**
	 * Fingerprint identifying a context for the memo caches.
	 *
	 * Returns '' for a context holding something we cannot describe, so that
	 * such a context is never served a memoised value.
	 */
	private static function context_key(array $context): string
	{
		$parts = [];

		foreach ($context as $name => $value) {
			if ($value instanceof \WP_Post) {
				$parts[] = $name . ':post:' . $value->ID;
			} elseif ($value instanceof \WP_Term) {
				$parts[] = $name . ':term:' . $value->term_taxonomy_id;
			} elseif ($value instanceof \WP_User) {
				$parts[] = $name . ':user:' . $value->ID;
			} elseif ($value instanceof \WP_Post_Type) {
				$parts[] = $name . ':post_type:' . $value->name;
			} elseif ($value === null || is_scalar($value)) {
				$parts[] = $name . ':' . (string) $value;
			} else {
				return '';
			}
		}

		return implode('|', $parts);
	}

	/**
	 * Drop the per-request memo caches. Mainly useful for tests.
	 */
	public static function flush_cache(): void
	{
		self::$resolved          = [];
		self::$tokens            = [];
		self::$auto_descriptions = [];
		self::$separator         = null;
	}

	/**
	 * Replace every recognised token in the template.
	 */
	public function resolve(string $template): string
	{
		$output = preg_replace_callback(
			self::TOKEN_REGEX,
			function ($matches) {
				$token = strtolower($matches[1]);
				$value = $this->token_value($token);

				/**
				 * Filter the value resolved for a single template variable.
				 *
				 * @param string $value   The resolved value.
				 * @param string $token   The token name (e.g. 'post.title').
				 * @param array  $context The resolution context array.
				 */
				return (string) apply_filters('crawlwp_variable_resolved', $value, $token, $this->context);
			},
			$template
		);

		/**
		 * Filter the fully resolved template string (after all token substitutions,
		 * before separator cleanup).
		 *
		 * @param string $output   The resolved string before cleanup.
		 * @param string $template The original template.
		 * @param array  $context  The resolution context.
		 */
		$output = (string) apply_filters('crawlwp_template_resolved', (string) $output, $template, $this->context);

		return self::cleanup($output);
	}

	/**
	 * The site-wide title separator.
	 */
	public static function separator(): string
	{
		if (self::$separator !== null) {
			return self::$separator;
		}

		$separators = self::separator_choices();
		$stored     = Options::get('home', 'separator', '-');
		$separator  = $separators[$stored] ?? $stored;

		if ($separator === '') {
			$separator = '-';
		}

		/**
		 * Filter the title separator used by the {{ sep }} variable.
		 *
		 * @param string $separator The separator character.
		 */
		self::$separator = (string) apply_filters('crawlwp_title_separator', $separator);

		return self::$separator;
	}

	/**
	 * Selectable separator characters.
	 */
	public static function separator_choices(): array
	{
		return [
			'-'  => '-',
			'–'  => '–',
			'—'  => '—',
			'|'  => '|',
			'•'  => '•',
			'·'  => '·',
			'/'  => '/',
			'~'  => '~',
			'«'  => '«',
			'»'  => '»',
			'<'  => '<',
			'>'  => '>',
		];
	}

	/**
	 * Resolve a single token, remembering the value for this request.
	 */
	private function token_value(string $token): string
	{
		$memo_key = $this->context_key === '' ? '' : $this->context_key . '|' . $token;

		if ($memo_key !== '' && isset(self::$tokens[$memo_key])) {
			return self::$tokens[$memo_key];
		}

		$value = $this->resolve_token($token);

		if ($memo_key !== '') {
			self::$tokens[$memo_key] = $value;
		}

		return $value;
	}

	/**
	 * Resolve a single token to its replacement value.
	 */
	private function resolve_token(string $token): string
	{
		switch ($token) {
			case 'sep':
				return self::separator();

			case 'page':
				return $this->paged_label();

			case 'site.title':
				return (string) get_bloginfo('name');

			case 'site.description':
				return (string) get_bloginfo('description');

			case 'site.url':
				return home_url('/');

			/* Site timezone and locale, like {{ current.date }} below. */
			case 'current.year':
				return (string) (wp_date('Y') ?: gmdate('Y'));

			case 'current.month':
				return (string) (wp_date('F') ?: gmdate('F'));

			case 'current.day':
				return (string) (wp_date('j') ?: gmdate('j'));

			case 'current.date':
				return wp_date((string) get_option('date_format')) ?: gmdate('Y-m-d');

			case 'current.time':
				return wp_date((string) get_option('time_format')) ?: gmdate('H:i');

			case 'current.month_short':
				return (string) (wp_date('M') ?: gmdate('M'));

			case 'current.month_num':
				return (string) (wp_date('m') ?: gmdate('m'));

			case 'search.query':
				return (string) get_search_query();

			case 'search.results_count':
				global $wp_query;

				return isset($wp_query->found_posts) ? (string) (int) $wp_query->found_posts : '0';

			case 'date.archive_title':
				return $this->date_archive_title();

			case 'date.year':
				return $this->date_archive_part('year');

			case 'date.month':
				return $this->date_archive_part('month');

			case 'date.month_name':
				return $this->date_archive_part('month_name');

			case 'date.day':
				return $this->date_archive_part('day');
		}

		if (strpos($token, 'post.') === 0) {
			return $this->resolve_post_token(substr($token, 5));
		}

		if (strpos($token, 'product.') === 0) {
			return $this->resolve_product_token(substr($token, 8));
		}

		if (strpos($token, 'term.') === 0) {
			return $this->resolve_term_token(substr($token, 5));
		}

		if (strpos($token, 'author.') === 0) {
			return $this->resolve_author_token(substr($token, 7));
		}

		if (strpos($token, 'post_type.') === 0) {
			return $this->resolve_post_type_token(substr($token, 10));
		}

		/**
		 * Allow third parties to resolve custom variables.
		 *
		 * @param string $value   Empty by default.
		 * @param string $token   The token name without braces.
		 * @param array  $context The resolution context.
		 */
		return (string) apply_filters('crawlwp_resolve_title_variable', '', $token, $this->context);
	}

	private function resolve_post_token(string $key): string
	{
		/* The blog posts index keeps its "Posts page" under its own key so it is
		 * not mistaken for the content being rendered — but its values still
		 * feed the title and description templates. */
		$post = $this->context['post'] ?? $this->context['posts_page'] ?? null;

		if (! $post instanceof \WP_Post) {
			return '';
		}

		/* Dynamic segments: {{ post.custom_field.key }}, {{ post.taxonomy.slug }}
		 * and {{ post.taxonomy_description.slug }}. */
		if (strpos($key, 'custom_field.') === 0) {
			return self::meta_value('post', $post->ID, substr($key, 13));
		}

		if (strpos($key, 'taxonomy_description.') === 0) {
			return $this->taxonomy_description($post, substr($key, 21));
		}

		if (strpos($key, 'taxonomy.') === 0) {
			return $this->term_names($post, substr($key, 9));
		}

		switch ($key) {
			case 'title':
				return get_the_title($post);

			case 'excerpt':
				return $post->post_excerpt;

			case 'auto_description':
				return self::post_auto_description($post);

			case 'content':
				return wp_strip_all_tags($post->post_content);

			case 'id':
				return (string) $post->ID;

			case 'url':
				return (string) get_permalink($post);

			case 'slug':
				return $post->post_name;

			case 'date':
				return (string) get_the_date('', $post);

			case 'modified':
				return (string) get_the_modified_date('', $post);

			case 'author':
				$author = get_userdata((int) $post->post_author);

				return $author ? $author->display_name : '';

			case 'category':
				return $this->first_term_name($post, 'category');

			case 'categories':
				return $this->term_names($post, 'category');

			case 'tag':
				return $this->first_term_name($post, 'post_tag');

			case 'tags':
				return $this->term_names($post, 'post_tag');

			case 'parent_title':
				return ! empty($post->post_parent) ? get_the_title($post->post_parent) : '';

			case 'thumbnail_url':
				return (string) get_the_post_thumbnail_url($post, 'full');

			case 'focus_keyword':
				$keywords = MetaFields::keywords($post->ID);

				return $keywords === [] ? '' : $keywords[0];

			case 'year':
				return (string) get_the_date('Y', $post);

			case 'month':
				return (string) get_the_date('F', $post);

			case 'day':
				return (string) get_the_date('j', $post);

			case 'comment_count':
				return (string) (int) $post->comment_count;
		}

		return '';
	}

	private function resolve_product_token(string $key): string
	{
		$product = $this->context['product'] ?? null;
		$post    = null;

		if (is_object($product) && method_exists($product, 'get_id')) {
			$post = get_post($product->get_id());
		} else {
			$post = $this->context['post'] ?? $this->context['posts_page'] ?? null;

			if (! $post instanceof \WP_Post) {
				$queried = function_exists('get_queried_object') ? get_queried_object() : null;
				if ($queried instanceof \WP_Post) {
					$post = $queried;
				} elseif (function_exists('get_post')) {
					$post = get_post();
				}
			}

			if (! $post instanceof \WP_Post || $post->post_type !== 'product') {
				return '';
			}

			if (function_exists('wc_get_product')) {
				$product = wc_get_product($post);
			}
		}

		if (! $product) {
			return '';
		}

		switch ($key) {
			case 'price':
				return method_exists($product, 'get_price') ? $product->get_price() : '';

			case 'price_with_tax':
				if (! method_exists($product, 'get_price')) {
					return '';
				}
				$price = $product->get_price();
				if ($price === '' || $price === null) {
					return '';
				}
				if (function_exists('wc_get_price_including_tax')) {
					return (string) wc_get_price_including_tax($product, ['price' => $price]);
				}
				return $price;

			case 'sale_from':
				if (method_exists($product, 'get_date_on_sale_from') && $product->get_date_on_sale_from()) {
					return gmdate('Y-m-d', $product->get_date_on_sale_from()->getTimestamp());
				}
				return '';

			case 'sale_to':
				$today     = gmdate('Y-m-d');
				$timestamp = function_exists('wc_string_to_timestamp')
					? (int) wc_string_to_timestamp('+1 month')
					: (int) strtotime('+1 month');
				$sale_to   = gmdate('Y-m-d', $timestamp);
				$sale_from = '';
				if (method_exists($product, 'get_date_on_sale_from') && $product->get_date_on_sale_from()) {
					$sale_from = gmdate('Y-m-d', $product->get_date_on_sale_from()->getTimestamp());
				}

				if (method_exists($product, 'is_on_sale') && $product->is_on_sale()) {
					if (method_exists($product, 'get_date_on_sale_to') && $product->get_date_on_sale_to()) {
						$sale_to = gmdate('Y-m-d', $product->get_date_on_sale_to()->getTimestamp());
					}
				} else {
					if ($sale_from !== '' && $sale_from > $today) {
						$from_ts = function_exists('wc_string_to_timestamp')
							? (int) wc_string_to_timestamp($sale_from)
							: (int) strtotime($sale_from);
						$day_sec = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
						$sale_to = gmdate('Y-m-d', $from_ts - $day_sec);
					} elseif ($sale_from === $today) {
						$sale_to = $today;
					}
				}
				return $sale_to;

			case 'sku':
				return method_exists($product, 'get_sku') ? $product->get_sku() : '';

			case 'stock':
				$status = method_exists($product, 'get_stock_status')
					? strtolower($product->get_stock_status())
					: 'instock';
				if (function_exists('wc_get_product_stock_status_options')) {
					$options = wc_get_product_stock_status_options();
					if (isset($options[$status])) {
						return (string) $options[$status];
					}
				}
				$statuses = self::product_stock_statuses();
				return $statuses[$status] ?? __('In stock', 'mihdan-index-now');

			case 'currency':
				return function_exists('get_woocommerce_currency')
					? get_woocommerce_currency()
					: '';

			case 'rating':
				$rating = method_exists($product, 'get_average_rating') ? $product->get_average_rating() : 0;
				return (float) $rating > 0 ? (string) $rating : '';

			case 'review_count':
				$count = method_exists($product, 'get_review_count') ? $product->get_review_count() : 0;
				return $count > 0 ? (string) $count : '0';

			case 'low_price':
				if (method_exists($product, 'is_type') && $product->is_type('variable')) {
					$min_price = method_exists($product, 'get_variation_price')
						? $product->get_variation_price('min', false)
						: '';
					if ($min_price !== '' && $min_price !== null && function_exists('wc_get_price_including_tax')) {
						return (string) wc_get_price_including_tax($product, ['price' => $min_price]);
					}
					return (string) $min_price;
				}
				return '';

			case 'high_price':
				if (method_exists($product, 'is_type') && $product->is_type('variable')) {
					$max_price = method_exists($product, 'get_variation_price')
						? $product->get_variation_price('max', false)
						: '';
					if ($max_price !== '' && $max_price !== null && function_exists('wc_get_price_including_tax')) {
						return (string) wc_get_price_including_tax($product, ['price' => $max_price]);
					}
					return (string) $max_price;
				}
				return '';

			case 'offer_count':
				if (method_exists($product, 'is_type') && $product->is_type('variable')) {
					$children = method_exists($product, 'get_children') ? $product->get_children() : [];
					return (string) count($children);
				}
				return '';
		}

		return '';
	}

	public static function product_stock_statuses(): array
	{
		return [
			'instock'              => __('In stock', 'mihdan-index-now'),
			'outofstock'           => __('Out of stock', 'mihdan-index-now'),
			'onbackorder'          => __('Back order', 'mihdan-index-now'),
			'discontinued'         => __('Discontinued', 'mihdan-index-now'),
			'instoreonly'          => __('In store only', 'mihdan-index-now'),
			'in_store_only'        => __('In store only', 'mihdan-index-now'),
			'in-store-only'        => __('In store only', 'mihdan-index-now'),
			'limitedavailability'  => __('Limited availability', 'mihdan-index-now'),
			'limited_availability' => __('Limited availability', 'mihdan-index-now'),
			'limited-availability' => __('Limited availability', 'mihdan-index-now'),
			'onlineonly'           => __('Online only', 'mihdan-index-now'),
			'online_only'          => __('Online only', 'mihdan-index-now'),
			'online-only'          => __('Online only', 'mihdan-index-now'),
			'preorder'             => __('Pre order', 'mihdan-index-now'),
			'pre_order'            => __('Pre order', 'mihdan-index-now'),
			'pre-order'            => __('Pre order', 'mihdan-index-now'),
			'presale'              => __('Pre sale', 'mihdan-index-now'),
			'pre_sale'             => __('Pre sale', 'mihdan-index-now'),
			'pre-sale'             => __('Pre sale', 'mihdan-index-now'),
			'soldout'              => __('Sold out', 'mihdan-index-now'),
		];
	}

	private function resolve_term_token(string $key): string
	{
		$term = $this->context['term'] ?? null;

		if (! $term instanceof \WP_Term) {
			return '';
		}

		if (strpos($key, 'custom_field.') === 0) {
			return self::meta_value('term', $term->term_id, substr($key, 13));
		}

		switch ($key) {
			case 'title':
			case 'name':
				return $term->name;

			case 'description':
				return wp_strip_all_tags($term->description);

			case 'auto_description':
				$description = wp_strip_all_tags($term->description);

				if ($description !== '') {
					return wp_trim_words($description, 30, '...');
				}

				/* translators: %s: taxonomy term name. */
				return sprintf(__('Browse all content filed under %s.', 'mihdan-index-now'), $term->name);

			case 'slug':
				return $term->slug;

			case 'parent':
				if (empty($term->parent)) {
					return '';
				}

				$parent = get_term($term->parent, $term->taxonomy);

				return $parent instanceof \WP_Term ? $parent->name : '';

			case 'count':
				return (string) (int) $term->count;

			case 'url':
				$link = get_term_link($term);

				return is_wp_error($link) ? '' : $link;
		}

		return '';
	}

	private function resolve_author_token(string $key): string
	{
		$user = $this->context['user'] ?? null;

		if (! $user instanceof \WP_User) {
			return '';
		}

		if (strpos($key, 'custom_field.') === 0) {
			return self::meta_value('user', $user->ID, substr($key, 13));
		}

		switch ($key) {
			case 'display_name':
			case 'name':
				return $user->display_name;

			case 'first_name':
				return (string) $user->first_name;

			case 'last_name':
				return (string) $user->last_name;

			case 'nickname':
				return (string) $user->nickname;

			case 'description':
				return wp_strip_all_tags((string) $user->description);

			case 'auto_description':
				$bio = wp_strip_all_tags((string) $user->description);

				if ($bio !== '') {
					return wp_trim_words($bio, 30, '...');
				}

				/* translators: 1: author display name, 2: site title. */
				return sprintf(
					__('Read all articles written by %1$s on %2$s.', 'mihdan-index-now'),
					$user->display_name,
					get_bloginfo('name')
				);

			case 'posts_count':
				return (string) count_user_posts($user->ID);

			case 'url':
				return (string) get_author_posts_url($user->ID);

			case 'website':
				return (string) $user->user_url;
		}

		return '';
	}

	private function resolve_post_type_token(string $key): string
	{
		$post_type = $this->context['post_type'] ?? null;

		if (! $post_type instanceof \WP_Post_Type) {
			return '';
		}

		switch ($key) {
			case 'name':
			case 'singular':
			case 'singular_name':
				return (string) $post_type->labels->singular_name;

			case 'plural':
			case 'plural_name':
			case 'label':
				return (string) $post_type->label;

			case 'description':
				return (string) $post_type->description;

			case 'slug':
				return (string) $post_type->name;
		}

		return '';
	}

	/**
	 * Post excerpt, or the first 30 words of the content.
	 *
	 * Stripping the shortcodes and the markup off a full post body is expensive,
	 * so the result is remembered for the rest of the request.
	 */
	private static function post_auto_description(\WP_Post $post): string
	{
		if (isset(self::$auto_descriptions[$post->ID])) {
			return self::$auto_descriptions[$post->ID];
		}

		$description = $post->post_excerpt
			?: wp_trim_words(wp_strip_all_tags(strip_shortcodes($post->post_content)), 30, '...');

		self::$auto_descriptions[$post->ID] = (string) $description;

		return self::$auto_descriptions[$post->ID];
	}

	private function first_term_name(\WP_Post $post, string $taxonomy): string
	{
		if ($taxonomy === 'category') {
			$primary = (int) MetaFields::get($post->ID, MetaFields::PRIMARY_CATEGORY, 0);

			if ($primary > 0) {
				$term = get_term($primary, $taxonomy);

				if ($term instanceof \WP_Term) {
					return $term->name;
				}
			}
		}

		$terms = get_the_terms($post->ID, $taxonomy);

		if (empty($terms) || is_wp_error($terms)) {
			return '';
		}

		return $terms[0]->name;
	}

	/**
	 * Every term the post has in a taxonomy, comma separated.
	 */
	private function term_names(\WP_Post $post, string $taxonomy): string
	{
		if ($taxonomy === '') {
			return '';
		}

		$terms = get_the_terms($post->ID, $taxonomy);

		if (empty($terms) || is_wp_error($terms)) {
			return '';
		}

		return implode(', ', wp_list_pluck($terms, 'name'));
	}

	/**
	 * Description of the first term the post has in a taxonomy.
	 */
	private function taxonomy_description(\WP_Post $post, string $taxonomy): string
	{
		if ($taxonomy === '') {
			return '';
		}

		$terms = get_the_terms($post->ID, $taxonomy);

		if (empty($terms) || is_wp_error($terms)) {
			return '';
		}

		return wp_strip_all_tags((string) $terms[0]->description);
	}

	/**
	 * A single meta value, flattened to a string.
	 */
	private static function meta_value(string $object_type, int $id, string $key): string
	{
		if ($key === '') {
			return '';
		}

		if ($object_type === 'term') {
			$value = get_term_meta($id, $key, true);
		} elseif ($object_type === 'user') {
			$value = get_user_meta($id, $key, true);
		} else {
			$value = get_post_meta($id, $key, true);
		}

		if (is_array($value)) {
			$value = implode(', ', array_filter($value, 'is_scalar'));
		}

		if (! is_scalar($value)) {
			return '';
		}

		return wp_strip_all_tags((string) $value);
	}

	/**
	 * "Page 2 of 7" for paginated archives, empty on the first page.
	 */
	private function paged_label(): string
	{
		global $wp_query;

		/* Query vars rather than the $page / $paged globals, which are only set
		 * up once the main query starts running. */
		$current = is_singular()
			? (int) get_query_var('page')
			: (int) get_query_var('paged');

		if ($current < 2) {
			return '';
		}

		$total = 0;

		if (is_singular()) {
			$post = $this->context['post'] ?? null;
			if ($post instanceof \WP_Post) {
				$total = count(explode('<!--nextpage-->', $post->post_content));
			}
		} elseif (isset($wp_query->max_num_pages)) {
			$total = (int) $wp_query->max_num_pages;
		}

		if ($total > 1) {
			/* translators: 1: current page number, 2: total number of pages. */
			return sprintf(__('Page %1$d of %2$d', 'mihdan-index-now'), $current, $total);
		}

		/* translators: %d: current page number. */
		return sprintf(__('Page %d', 'mihdan-index-now'), $current);
	}

	/**
	 * "March 2024" style label for the requested date archive.
	 *
	 * Built from the query vars, not from the loop: `get_the_date()` needs a
	 * post, so it returns the wrong date before the loop and nothing at all on
	 * an empty archive.
	 */
	private function date_archive_title(): string
	{
		$year  = (int) get_query_var('year');
		$month = (int) get_query_var('monthnum');
		$day   = (int) get_query_var('day');

		if ($year <= 0) {
			return __('Archives', 'mihdan-index-now');
		}

		/* Midday keeps the date stable whatever the site timezone offset is. */
		$timestamp = mktime(12, 0, 0, max(1, $month), max(1, $day), $year);

		if ($timestamp === false) {
			return __('Archives', 'mihdan-index-now');
		}

		if ($month > 0 && $day > 0) {
			$format = (string) get_option('date_format');

			return (string) wp_date($format !== '' ? $format : 'F j, Y', $timestamp);
		}

		if ($month > 0) {
			/* translators: monthly date archive title format. See https://www.php.net/manual/datetime.format.php */
			return (string) wp_date(_x('F Y', 'monthly archives date format', 'mihdan-index-now'), $timestamp);
		}

		/* translators: yearly date archive title format. See https://www.php.net/manual/datetime.format.php */
		return (string) wp_date(_x('Y', 'yearly archives date format', 'mihdan-index-now'), $timestamp);
	}

	/**
	 * A single part of the requested date archive: its year, month or day.
	 */
	private function date_archive_part(string $part): string
	{
		$year  = (int) get_query_var('year');
		$month = (int) get_query_var('monthnum');
		$day   = (int) get_query_var('day');

		switch ($part) {
			case 'year':
				return $year > 0 ? (string) $year : '';

			case 'day':
				return $day > 0 ? (string) $day : '';

			case 'month':
				return $month > 0 ? (string) $month : '';

			case 'month_name':
				if ($month <= 0) {
					return '';
				}

				$timestamp = mktime(12, 0, 0, $month, 1, $year > 0 ? $year : (int) wp_date('Y'));

				return $timestamp === false ? '' : (string) wp_date('F', $timestamp);
		}

		return '';
	}

	/**
	 * Tidy up a resolved string: unresolved tokens are dropped and separators
	 * left dangling by empty values are collapsed.
	 */
	private static function cleanup(string $value): string
	{
		/* Drop any token we could not resolve. */
		$value = preg_replace(self::TOKEN_REGEX, '', $value) ?? $value;

		$separator = preg_quote(self::separator(), '/');

		/* Collapse "A - - B" into "A - B". */
		$value = preg_replace('/(?:\s*' . $separator . '\s*){2,}/u', ' ' . self::separator() . ' ', $value) ?? $value;

		/* Trim leading/trailing separators. */
		$value = preg_replace('/^(?:\s*' . $separator . '\s*)+/u', '', $value) ?? $value;
		$value = preg_replace('/(?:\s*' . $separator . '\s*)+$/u', '', $value) ?? $value;

		/* Normalise whitespace. */
		$value = preg_replace('/\s{2,}/u', ' ', $value) ?? $value;

		return trim($value);
	}

	/**
	 * Variable reference shown in the settings UI, grouped by namespace.
	 *
	 * @return array<string, array{label: string, variables: array<string, string>}>
	 */
	public static function definitions(): array
	{
		$definitions = [
			'general' => [
				'label'     => __('General', 'mihdan-index-now'),
				'variables' => [
					'sep'              => __('Title separator', 'mihdan-index-now'),
					'page'             => __('Current page number, e.g. "Page 2 of 7"', 'mihdan-index-now'),
					'site.title'       => __('Site title', 'mihdan-index-now'),
					'site.description' => __('Site tagline', 'mihdan-index-now'),
					'site.url'         => __('Site home URL', 'mihdan-index-now'),
					'current.year'     => __('Current year', 'mihdan-index-now'),
					'current.month'    => __('Current month', 'mihdan-index-now'),
					'current.day'      => __('Current day', 'mihdan-index-now'),
					'current.date'     => __('Current date', 'mihdan-index-now'),
					'current.time'     => __('Current time', 'mihdan-index-now'),
				],
			],
			'post'    => [
				'label'     => __('Post', 'mihdan-index-now'),
				'variables' => [
					'post.title'            => __('Post title', 'mihdan-index-now'),
					'post.auto_description' => __('Post excerpt, or the first 30 words of the content', 'mihdan-index-now'),
					'post.excerpt'          => __('Post excerpt only', 'mihdan-index-now'),
					'post.author'           => __('Post author display name', 'mihdan-index-now'),
					'post.category'         => __('First category assigned to the post', 'mihdan-index-now'),
					'post.categories'       => __('Every category assigned to the post', 'mihdan-index-now'),
					'post.tag'              => __('First tag assigned to the post', 'mihdan-index-now'),
					'post.tags'             => __('Every tag assigned to the post', 'mihdan-index-now'),
					'post.parent_title'     => __('Parent post or page title', 'mihdan-index-now'),
					'post.date'             => __('Post publish date', 'mihdan-index-now'),
					'post.modified'         => __('Post last modified date', 'mihdan-index-now'),
					'post.url'              => __('Post permalink', 'mihdan-index-now'),
					'post.thumbnail_url'    => __('Featured image URL', 'mihdan-index-now'),
					'post.focus_keyword'    => __('Primary focus keyword of the post', 'mihdan-index-now'),
					'post.custom_field.key' => __('Value of a custom field, e.g. post.custom_field.subtitle', 'mihdan-index-now'),
					'post.taxonomy.slug'    => __('Terms of a taxonomy, e.g. post.taxonomy.product_cat', 'mihdan-index-now'),
				],
			],
			'term'    => [
				'label'     => __('Taxonomy term', 'mihdan-index-now'),
				'variables' => [
					'term.title'            => __('Term name', 'mihdan-index-now'),
					'term.auto_description' => __('Term description, or a generated fallback', 'mihdan-index-now'),
					'term.description'      => __('Term description only', 'mihdan-index-now'),
					'term.parent'           => __('Parent term name', 'mihdan-index-now'),
					'term.count'            => __('Number of items in the term', 'mihdan-index-now'),
				],
			],
			'author'  => [
				'label'     => __('Author', 'mihdan-index-now'),
				'variables' => [
					'author.display_name'     => __('Author display name', 'mihdan-index-now'),
					'author.auto_description' => __('Author biography, or a generated fallback', 'mihdan-index-now'),
					'author.first_name'       => __('Author first name', 'mihdan-index-now'),
					'author.last_name'        => __('Author last name', 'mihdan-index-now'),
					'author.nickname'         => __('Author nickname', 'mihdan-index-now'),
					'author.website'          => __('Author website URL', 'mihdan-index-now'),
					'author.posts_count'      => __('Number of posts by the author', 'mihdan-index-now'),
				],
			],
			'archive' => [
				'label'     => __('Archive', 'mihdan-index-now'),
				'variables' => [
					'post_type.plural_name' => __('Post type plural label', 'mihdan-index-now'),
					'post_type.name'        => __('Post type singular label', 'mihdan-index-now'),
					'post_type.description' => __('Post type description', 'mihdan-index-now'),
					'date.archive_title'    => __('Date archive title', 'mihdan-index-now'),
					'date.year'             => __('Year of the date archive', 'mihdan-index-now'),
					'date.month_name'       => __('Month name of the date archive', 'mihdan-index-now'),
					'date.day'              => __('Day of the date archive', 'mihdan-index-now'),
					'search.query'          => __('Search query', 'mihdan-index-now'),
					'search.results_count'  => __('Number of search results', 'mihdan-index-now'),
				],
			],
			'woocommerce' => [
				'label'     => __('WooCommerce', 'mihdan-index-now'),
				'variables' => [
					'product.price'          => __('Price', 'mihdan-index-now'),
					'product.price_with_tax' => __('Price including tax', 'mihdan-index-now'),
					'product.sale_from'      => __('Sale price date "From"', 'mihdan-index-now'),
					'product.sale_to'        => __('Sale price date "To"', 'mihdan-index-now'),
					'product.sku'            => __('SKU', 'mihdan-index-now'),
					'product.stock'          => __('Stock status', 'mihdan-index-now'),
					'product.currency'       => __('Currency', 'mihdan-index-now'),
					'product.rating'         => __('Rating value', 'mihdan-index-now'),
					'product.review_count'   => __('Review count', 'mihdan-index-now'),
					'product.low_price'      => __('Low price (variable product)', 'mihdan-index-now'),
					'product.high_price'     => __('High price (variable product)', 'mihdan-index-now'),
					'product.offer_count'    => __('Offer count (variable product)', 'mihdan-index-now'),
				],
			],
		];

		/**
		 * Filter the variables advertised in the settings UI.
		 *
		 * @param array $definitions Grouped variable definitions.
		 */
		return apply_filters('crawlwp_title_meta_variables', $definitions);
	}
}
