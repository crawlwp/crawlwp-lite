<?php

namespace Mihdan\IndexNow\SEOCore\TitleMeta;

/**
 * Registry of everything that can hold global title & meta defaults.
 *
 * Each entry becomes one item in the Title & Meta sidebar, one WPOSA section
 * and one option row. The registry is also what the frontend output walks to
 * decide which defaults apply to the current request.
 */
class Entities
{
	public const TYPE_HOME      = 'home';
	public const TYPE_POST_TYPE = 'post_type';
	public const TYPE_TAXONOMY  = 'taxonomy';
	public const TYPE_AUTHOR    = 'author';
	public const TYPE_DATE      = 'date';
	public const TYPE_SEARCH    = 'search';
	public const TYPE_NOT_FOUND = 'not_found';

	public const GROUP_NONE       = '';
	public const GROUP_POST_TYPES = 'post_types';
	public const GROUP_TAXONOMIES = 'taxonomies';
	public const GROUP_OTHER      = 'other_pages';

	/**
	 * @var array<string, array>|null
	 */
	private static ?array $entities = null;

	/**
	 * Sidebar group labels, in display order.
	 */
	public static function groups(): array
	{
		return [
			self::GROUP_POST_TYPES => __('Post Types', 'mihdan-index-now'),
			self::GROUP_TAXONOMIES => __('Taxonomies', 'mihdan-index-now'),
			self::GROUP_OTHER      => __('Other Pages', 'mihdan-index-now'),
		];
	}

	/**
	 * All configurable entities keyed by entity key.
	 *
	 * @return array<string, array>
	 */
	public static function all(): array
	{
		if (self::$entities !== null) {
			return self::$entities;
		}

		$entities = [];

		$entities['home'] = [
			'key'      => 'home',
			'type'     => self::TYPE_HOME,
			'group'    => self::GROUP_NONE,
			'label'    => __('Homepage', 'mihdan-index-now'),
			'heading'  => __('Homepage', 'mihdan-index-now'),
			'object'   => null,
			'archive'  => false,
			'defaults' => [
				'title'       => '{{ site.title }} {{ sep }} {{ site.description }}',
				'description' => '{{ site.description }}',
			],
		];

		foreach (self::post_types() as $post_type) {
			$key = 'pt_' . $post_type->name;

			$entities[$key] = [
				'key'      => $key,
				'type'     => self::TYPE_POST_TYPE,
				'group'    => self::GROUP_POST_TYPES,
				'label'    => self::object_label($post_type->labels->singular_name, $post_type->name),
				'heading'  => sprintf(
				/* translators: %s: post type singular label. */
					__('Singular %s', 'mihdan-index-now'),
					strtolower($post_type->labels->singular_name)
				),
				'object'   => $post_type,
				'archive'  => self::post_type_has_archive($post_type),
				'defaults' => [
					'title'               => '{{ post.title }} {{ sep }} {{ page }} {{ sep }} {{ site.title }}',
					'description'         => '{{ post.auto_description }}',
					/* The frontend resolves the schema type from these two keys.
					 * Only blog posts are articles by default; every other post
					 * type falls back to its page type alone. */
					'schema_page_type'    => 'WebPage',
					'schema_article_type' => 'post' === $post_type->name ? 'Article' : 'none',
					'archive_title'       => '{{ post_type.plural_name }} {{ sep }} {{ page }} {{ sep }} {{ site.title }}',
					'archive_description' => '{{ post_type.description }}',
				],
			];
		}

		foreach (self::taxonomies() as $taxonomy) {
			$key = 'tax_' . $taxonomy->name;

			$entities[$key] = [
				'key'      => $key,
				'type'     => self::TYPE_TAXONOMY,
				'group'    => self::GROUP_TAXONOMIES,
				'label'    => self::object_label($taxonomy->labels->singular_name, $taxonomy->name),
				'heading'  => sprintf(
				/* translators: %s: taxonomy singular label. */
					__('%s archive page', 'mihdan-index-now'),
					$taxonomy->labels->singular_name
				),
				'object'   => $taxonomy,
				'archive'  => false,
				'defaults' => [
					'title'       => '{{ term.title }} {{ sep }} {{ page }} {{ sep }} {{ site.title }}',
					'description' => '{{ term.auto_description }}',
				],
			];
		}

		$entities['author'] = [
			'key'      => 'author',
			'type'     => self::TYPE_AUTHOR,
			'group'    => self::GROUP_OTHER,
			'label'    => __('Author', 'mihdan-index-now'),
			'heading'  => __('Author archive page', 'mihdan-index-now'),
			'object'   => null,
			'archive'  => false,
			'defaults' => [
				'title'       => '{{ author.display_name }} {{ sep }} {{ page }} {{ sep }} {{ site.title }}',
				'description' => '{{ author.auto_description }}',
			],
		];

		$entities['date'] = [
			'key'      => 'date',
			'type'     => self::TYPE_DATE,
			'group'    => self::GROUP_OTHER,
			'label'    => __('Date', 'mihdan-index-now'),
			'heading'  => __('Date archive page', 'mihdan-index-now'),
			'object'   => null,
			'archive'  => false,
			'defaults' => [
				'title'       => '{{ date.archive_title }} {{ sep }} {{ page }} {{ sep }} {{ site.title }}',
				'description' => '',
			],
		];

		$entities['search'] = [
			'key'      => 'search',
			'type'     => self::TYPE_SEARCH,
			'group'    => self::GROUP_OTHER,
			'label'    => __('Search', 'mihdan-index-now'),
			'heading'  => __('Search results page', 'mihdan-index-now'),
			'object'   => null,
			'archive'  => false,
			'defaults' => [
				'title'       => '{{ search.query }} {{ sep }} {{ page }} {{ sep }} {{ site.title }}',
				'description' => '',
				'noindex'     => 'on',
			],
		];

		$entities['not_found'] = [
			'key'      => 'not_found',
			'type'     => self::TYPE_NOT_FOUND,
			'group'    => self::GROUP_OTHER,
			'label'    => __('404 Page', 'mihdan-index-now'),
			'heading'  => __('404 not found page', 'mihdan-index-now'),
			'object'   => null,
			'archive'  => false,
			'defaults' => [
				'title'       => __('Page not found', 'mihdan-index-now') . ' {{ sep }} {{ site.title }}',
				'description' => '',
				'noindex'     => 'on',
			],
		];

		/**
		 * Filter the entities that receive global title & meta defaults.
		 *
		 * @param array $entities Entity definitions keyed by entity key.
		 */
		self::$entities = apply_filters('crawlwp_title_meta_entities', $entities);

		return self::$entities;
	}

	/**
	 * A single entity definition, or null when unknown.
	 */
	public static function get(string $key): ?array
	{
		$entities = self::all();

		return $entities[$key] ?? null;
	}

	/**
	 * Registered default for one field of one entity.
	 */
	public static function default_value(string $entity_key, string $field, string $fallback = ''): string
	{
		$entity = self::get($entity_key);

		if ($entity === null) {
			return $fallback;
		}

		$value = $entity['defaults'][$field] ?? null;

		return $value === null ? $fallback : (string) $value;
	}

	/**
	 * Entity key for a post type.
	 */
	public static function post_type_key(string $post_type): string
	{
		return 'pt_' . $post_type;
	}

	/**
	 * Entity key for a taxonomy.
	 */
	public static function taxonomy_key(string $taxonomy): string
	{
		return 'tax_' . $taxonomy;
	}

	/**
	 * Public post types that can carry SEO defaults.
	 *
	 * @return \WP_Post_Type[]
	 */
	public static function post_types(): array
	{
		$post_types = get_post_types(['public' => true], 'objects');

		unset($post_types['attachment']);

		/**
		 * Filter the post types shown in the Title & Meta settings.
		 *
		 * @param \WP_Post_Type[] $post_types Post type objects keyed by name.
		 */
		$post_types = apply_filters('crawlwp_title_meta_post_types', $post_types);

		return array_values($post_types);
	}

	/**
	 * Public taxonomies that can carry SEO defaults.
	 *
	 * @return \WP_Taxonomy[]
	 */
	public static function taxonomies(): array
	{
		$taxonomies = get_taxonomies(['public' => true], 'objects');

		unset($taxonomies['post_format']);

		/**
		 * Filter the taxonomies shown in the Title & Meta settings.
		 *
		 * @param \WP_Taxonomy[] $taxonomies Taxonomy objects keyed by name.
		 */
		$taxonomies = apply_filters('crawlwp_title_meta_taxonomies', $taxonomies);

		return array_values($taxonomies);
	}

	/**
	 * Whether a post type renders its own archive listing.
	 */
	private static function post_type_has_archive(\WP_Post_Type $post_type): bool
	{
		if ($post_type->name === 'post') {
			/* The blog posts index is an archive even though has_archive is false. */
			return true;
		}

		return ! empty($post_type->has_archive);
	}

	/**
	 * "Post (post)" style label used in the sidebar.
	 */
	private static function object_label(string $singular_name, string $name): string
	{
		return sprintf('%s (%s)', $singular_name, $name);
	}

	/**
	 * Reset the memoised registry. Mainly useful for tests.
	 */
	public static function flush_cache(): void
	{
		self::$entities = null;
	}
}
