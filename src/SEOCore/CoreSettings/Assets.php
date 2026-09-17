<?php

namespace Mihdan\IndexNow\SEOCore\CoreSettings;

use Mihdan\IndexNow\SEOCore\TitleMeta\Entities;
use Mihdan\IndexNow\SEOCore\TitleMeta\Variables;
use Mihdan\IndexNow\Utils;

/**
 * Loads the live preview script for the Title & Meta settings screens.
 */
class Assets
{
	/**
	 * Transient prefix for the per-entity preview samples.
	 *
	 * Building them queries one post per post type and one term per taxonomy,
	 * which is far too much work to repeat on every settings page load.
	 */
	private const SAMPLES_TRANSIENT_PREFIX = 'crawlwp_tm_samples_';

	/**
	 * Default lifetime of the cached samples.
	 */
	private const SAMPLES_TTL = 10 * MINUTE_IN_SECONDS;

	public function __construct()
	{
		add_action('admin_enqueue_scripts', [$this, 'enqueue']);
	}

	public function enqueue(string $hook): void
	{
		if (strpos($hook, Utils::get_plugin_slug()) === false) {
			return;
		}

		$assets_url = CRAWLWP_PLUGIN_URL . 'src/SEOCore/CoreSettings/assets/';
		$version    = CRAWLWP_VERSION;

		wp_enqueue_style(
			'crawlwp-title-meta',
			$assets_url . 'crawlwp-title-meta.css',
			[],
			$version
		);

		wp_enqueue_media();

		wp_enqueue_script(
			'crawlwp-title-meta',
			$assets_url . 'crawlwp-title-meta.js',
			['jquery'],
			$version,
			true
		);

		// The live preview only exists on the Title & Meta screens, so the
		// per-entity data is not worth building anywhere else.
		$is_title_meta = $this->is_title_meta_screen();

		wp_localize_script('crawlwp-title-meta', 'crawlwpTitleMeta', [
			'separator'       => Variables::separator(),
			'variables'       => $this->variables(),
			'entityVariables' => $is_title_meta ? $this->entity_variables() : [],
			'samples'         => $is_title_meta ? $this->samples() : [],
			'i18n'            => [
				'previewLabel'    => __('Preview:', 'mihdan-index-now'),
				'insertVariable'  => __('Insert variable', 'mihdan-index-now'),
				'searchVariables' => __('Search variables…', 'mihdan-index-now'),
				'noVariables'     => __('No matching variables.', 'mihdan-index-now'),
				'emptyPreview'    => __('Nothing will be output.', 'mihdan-index-now'),
			],
		]);
	}

	/**
	 * Whether the Title & Meta tab — the only screen with preview fields — is
	 * the one being rendered.
	 */
	private function is_title_meta_screen(): bool
	{
		$menu = Utils::_GET_var('wposa-menu', '');
		$menu = is_string($menu) ? sanitize_text_field($menu) : '';

		// Title & Meta is the first header menu, so an absent parameter means it.
		if ($menu === '') {
			return true;
		}

		return $menu === Utils::get_plugin_prefix() . '_title_meta';
	}

	/**
	 * Variable reference for the insert-variable dropdown.
	 */
	private function variables(): array
	{
		$groups = [];

		foreach (Variables::definitions() as $group) {
			$items = [];

			foreach ($group['variables'] as $token => $description) {
				$items[] = [
					'token' => $token,
					'desc'  => $description,
				];
			}

			$groups[] = [
				'label' => $group['label'],
				'items' => $items,
			];
		}

		return $groups;
	}

	/**
	 * Per-entity variable groups, keyed by entity key.
	 *
	 * Only the groups relevant to each entity type are included, so the
	 * insert-variable dropdown shows contextually useful tokens only.
	 *
	 * @return array<string, array>
	 */
	private function entity_variables(): array
	{
		$defs        = Variables::definitions();
		$general     = isset($defs['general'])     ? [$defs['general']]     : [];
		$post        = isset($defs['post'])        ? [$defs['post']]        : [];
		$term        = isset($defs['term'])        ? [$defs['term']]        : [];
		$author      = isset($defs['author'])      ? [$defs['author']]      : [];
		$archive     = isset($defs['archive'])     ? [$defs['archive']]     : [];
		$woocommerce = isset($defs['woocommerce']) ? [$defs['woocommerce']] : [];

		$map = [];

		/* Homepage: site-level tokens only */
		$map['home'] = $this->format_variables(array_merge($general));

		foreach (Entities::post_types() as $post_type) {
			$key = Entities::post_type_key($post_type->name);

			/* Singular post type: post tokens + general (plus WooCommerce if product) */
			$groups = $post_type->name === 'product' && ! empty($woocommerce)
				? array_merge($post, $woocommerce, $general)
				: array_merge($post, $general);

			$map[$key] = $this->format_variables($groups);

			/* Archive sub-section uses a synthetic _archive entity key */
			$map[$key . '_archive'] = $this->format_variables(array_merge($archive, $general));
		}

		foreach (Entities::taxonomies() as $taxonomy) {
			$key = Entities::taxonomy_key($taxonomy->name);

			/* Taxonomy archive: term tokens + general */
			$map[$key] = $this->format_variables(array_merge($term, $general));
		}

		/* Author archive: author tokens + general */
		$map['author'] = $this->format_variables(array_merge($author, $general));

		/* Date archive: date/archive tokens + general */
		$map['date'] = $this->format_variables(array_merge($archive, $general));

		/* Search results: search tokens + general */
		$map['search'] = $this->format_variables(array_merge($archive, $general));

		/* 404: no dynamic tokens, just general */
		$map['not_found'] = $this->format_variables($general);

		return $map;
	}

	/**
	 * Convert a list of raw definition groups into the JS-ready format.
	 *
	 * @param array $groups Raw definition groups from Variables::definitions().
	 * @return array
	 */
	private function format_variables(array $groups): array
	{
		$out = [];

		foreach ($groups as $group) {
			$items = [];

			foreach ($group['variables'] as $token => $description) {
				$items[] = [
					'token' => $token,
					'desc'  => $description,
				];
			}

			$out[] = [
				'label' => $group['label'],
				'items' => $items,
			];
		}

		return $out;
	}

	/**
	 * Representative values so the preview reflects real content.
	 */
	private function samples(): array
	{
		/* The global block is option-only, so it is always built fresh. */
		return ['global' => $this->global_samples()] + $this->entity_samples();
	}

	/**
	 * Site-level sample values. No database queries beyond options.
	 */
	private function global_samples(): array
	{
		return [
			'sep'                  => Variables::separator(),
			'page'                 => '',
			'site.title'           => get_bloginfo('name'),
			'site.description'     => get_bloginfo('description'),
			'site.url'             => home_url('/'),
			'current.year'         => gmdate('Y'),
			'current.month'        => gmdate('F'),
			'current.date'         => wp_date((string) get_option('date_format')) ?: gmdate('Y-m-d'),
			'date.archive_title'   => gmdate('F Y'),
			'search.query'         => __('example search', 'mihdan-index-now'),
			'search.results_count' => '12',
		];
	}

	/**
	 * Per-post-type, per-taxonomy and author samples.
	 *
	 * These hit the database, so the result is cached briefly. The author
	 * block is user-specific and the labels are translated, hence the user id
	 * and locale in the cache key.
	 *
	 * @return array<string, array>
	 */
	private function entity_samples(): array
	{
		$cache_key = self::SAMPLES_TRANSIENT_PREFIX . get_current_user_id() . '_' . md5(get_locale());
		$cached    = get_transient($cache_key);

		if (is_array($cached)) {
			return $cached;
		}

		$samples = [];

		foreach (Entities::post_types() as $post_type) {
			$samples[Entities::post_type_key($post_type->name)] = $this->post_type_samples($post_type);
		}

		foreach (Entities::taxonomies() as $taxonomy) {
			$samples[Entities::taxonomy_key($taxonomy->name)] = $this->taxonomy_samples($taxonomy);
		}

		$samples['author'] = $this->author_samples();

		/**
		 * Filter how long the preview samples stay cached.
		 *
		 * @param int $ttl Lifetime in seconds. 0 disables caching.
		 */
		$ttl = (int) apply_filters('crawlwp_tm_samples_cache_ttl', self::SAMPLES_TTL);

		if ($ttl > 0) {
			set_transient($cache_key, $samples, $ttl);
		}

		return $samples;
	}

	private function post_type_samples(\WP_Post_Type $post_type): array
	{
		$samples = [
			'post_type.name'        => (string) $post_type->labels->singular_name,
			'post_type.plural_name' => (string) $post_type->label,
			'post_type.description' => (string) $post_type->description,
		];

		$posts = get_posts([
			'post_type'          => $post_type->name,
			'post_status'        => 'publish',
			'posts_per_page'     => 1,
			'orderby'            => 'date',
			'order'              => 'DESC',
			'no_found_rows'      => true,
			'suppress_filters'   => false,
			'ignore_sticky_posts' => true,
		]);

		if (empty($posts)) {
			$samples['post.title']            = (string) $post_type->labels->singular_name;
			$samples['post.auto_description'] = '';

			if ($post_type->name === 'product') {
				$samples['product.price']          = '19.99';
				$samples['product.price_with_tax'] = '19.99';
				$samples['product.sale_from']      = '';
				$samples['product.sale_to']        = '';
				$samples['product.sku']            = 'SAMPLE-SKU';
				$samples['product.stock']          = __('In stock', 'mihdan-index-now');
				$samples['product.currency']       = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';
				$samples['product.rating']         = '4.5';
				$samples['product.review_count']   = '10';
				$samples['product.low_price']      = '15.00';
				$samples['product.high_price']     = '25.00';
				$samples['product.offer_count']    = '3';
			}

			return $samples;
		}

		$post = $posts[0];

		$context = ['post' => $post, 'post_type' => $post_type];

		foreach (['title', 'auto_description', 'excerpt', 'author', 'category', 'tag', 'date', 'modified', 'url'] as $key) {
			$samples['post.' . $key] = Variables::replace('{{ post.' . $key . ' }}', $context);
		}

		if ($post_type->name === 'product') {
			$wc_keys = [
				'price', 'price_with_tax', 'sale_from', 'sale_to', 'sku', 'stock',
				'currency', 'rating', 'review_count', 'low_price', 'high_price', 'offer_count',
			];
			foreach ($wc_keys as $wc_key) {
				$samples['product.' . $wc_key] = Variables::replace('{{ product.' . $wc_key . ' }}', $context);
			}
		}

		return $samples;
	}

	private function taxonomy_samples(\WP_Taxonomy $taxonomy): array
	{
		$terms = get_terms([
			'taxonomy'   => $taxonomy->name,
			'number'     => 1,
			'hide_empty' => false,
		]);

		if (is_wp_error($terms) || empty($terms)) {
			return [
				'term.title'            => (string) $taxonomy->labels->singular_name,
				'term.auto_description' => '',
			];
		}

		$context = ['term' => $terms[0]];
		$samples = [];

		foreach (['title', 'auto_description', 'description', 'count'] as $key) {
			$samples['term.' . $key] = Variables::replace('{{ term.' . $key . ' }}', $context);
		}

		return $samples;
	}

	private function author_samples(): array
	{
		$user = wp_get_current_user();

		if (! $user instanceof \WP_User || ! $user->exists()) {
			return [];
		}

		$context = ['user' => $user];
		$samples = [];

		foreach (['display_name', 'auto_description', 'first_name', 'last_name', 'posts_count'] as $key) {
			$samples['author.' . $key] = Variables::replace('{{ author.' . $key . ' }}', $context);
		}

		return $samples;
	}
}
