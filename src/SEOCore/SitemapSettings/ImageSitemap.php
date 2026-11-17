<?php

namespace Mihdan\IndexNow\SEOCore\SitemapSettings;

/**
 * Image sitemap: each post with the images on it, one sitemap per post type
 * (wp-sitemap-crawlwpimages-post-1.xml …), listed in the core sitemap index.
 *
 * The core renderer can't write nested <image:image> tags, so the XML is
 * built here, like the Premium video sitemap.
 */
class ImageSitemap extends \WP_Sitemaps_Provider
{
	public const PROVIDER_NAME = 'crawlwpimages';

	/** Posts per sitemap page. Each may list up to MAX_IMAGES images. */
	public const PER_PAGE = 1000;

	/** Google's limit of images per page. */
	public const MAX_IMAGES = 1000;

	/** Bumped to drop every cached sitemap page at once. */
	private const VERSION_OPTION = 'crawlwp_image_sitemap_version';

	/** Post meta that decides what a post lists: its featured image, product gallery and noindex setting. */
	private const WATCHED_META = ['_thumbnail_id', '_product_image_gallery', '_crawlwp_robots_index'];

	/** Which post types have any image at all: [post type => [1 or 0, time checked]]. Kept across cache flushes. */
	private const TYPES_OPTION = 'crawlwp_image_sitemap_types';

	/** Cached in place of the XML for a page with no posts that have images; such a page answers 404. */
	private const EMPTY_PAGE = 'none';

	/** @var bool The cache was already emptied in this request. */
	private $flushed = false;

	/** @var bool Another change came after that, so it is emptied once more when the request ends. */
	private $flush_again = false;

	public function __construct()
	{
		$this->name        = self::PROVIDER_NAME;
		$this->object_type = 'post';

		/* Only on sitemap requests, just before WordPress and maybe_render() answer them: see register_provider(). */
		add_action('template_redirect', [$this, 'register_provider'], 0);
		add_action('template_redirect', [$this, 'maybe_render'], 1);

		/* Only changes to published, public content can change the sitemap. */
		add_action('transition_post_status', [$this, 'flush_on_status'], 10, 3);
		add_action('deleted_post', [$this, 'flush_on_delete'], 10, 2);

		/* Images themselves: deleted, edited (title, caption, alt) or replaced by the image editor. */
		add_action('delete_attachment', [$this, 'flush_on_attachment']);
		add_action('attachment_updated', [$this, 'flush_on_attachment']);
		add_filter('update_attached_file', [$this, 'flush_on_file_change'], 10, 2);
		add_action('crawlwp_sitemap_settings_saved', [$this, 'flush_settings']);

		/* Changes that don't save the post: a featured image set from the editor sidebar, noindex written by an importer. */
		add_action('added_post_meta', [$this, 'flush_on_meta'], 10, 3);
		add_action('updated_post_meta', [$this, 'flush_on_meta'], 10, 3);
		add_action('deleted_post_meta', [$this, 'flush_on_meta'], 10, 3);
		add_action('permalink_structure_changed', [$this, 'flush_cache']);
	}

	public static function is_enabled(): bool
	{
		return (string) SitemapSettings::get('image_sitemap', 'on') === 'on';
	}

	/**
	 * Add the image sitemaps to WordPress's sitemap index. Nothing else needs the provider, so
	 * it is only registered when a sitemap was requested: the on/off setting is in an option
	 * that isn't autoloaded, and reading it on every page view would cost a query each time.
	 */
	public function register_provider(): void
	{
		if ((string) get_query_var('sitemap') === '') {
			return;
		}

		if (self::is_enabled() && function_exists('wp_register_sitemap_provider')) {
			wp_register_sitemap_provider(self::PROVIDER_NAME, $this);
		}
	}

	/**
	 * The post types in the normal post sitemaps (CrawlWP leaves out hidden ones there).
	 *
	 * @return array<string, \WP_Post_Type>
	 */
	public function get_object_subtypes(): array
	{
		$server = function_exists('wp_sitemaps_get_server') ? wp_sitemaps_get_server() : null;
		$posts  = $server ? $server->registry->get_provider('posts') : null;

		return $posts ? (array) $posts->get_object_subtypes() : [];
	}

	/**
	 * Query args matching the core post sitemap, so the same posts are left out
	 * (drafts, password-protected, noindexed through CrawlWP's filter).
	 *
	 * @return array<string, mixed>
	 */
	private function query_args(string $post_type, int $page): array
	{
		$args = (array) apply_filters('wp_sitemaps_posts_query_args', [
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'post_type'              => $post_type,
			'posts_per_page'         => self::PER_PAGE,
			'post_status'            => ['publish'],
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
			'ignore_sticky_posts'    => true,
			'has_password'           => false,
		], $post_type);

		$args['posts_per_page'] = self::PER_PAGE;
		$args['paged']          = max(1, $page);
		$args['fields']         = 'ids';

		return $args;
	}

	public function get_max_num_pages($object_subtype = ''): int
	{
		if ($object_subtype === '' || ! isset($this->get_object_subtypes()[$object_subtype])) {
			return 0;
		}

		/* Counting is the heaviest query here, and it runs for the sitemap index and every page. */
		$key    = self::cache_key('n|' . $object_subtype);
		$cached = get_transient($key);

		if (is_string($cached) && strpos($cached, 'n:') === 0) {
			return (int) substr($cached, 2);
		}

		$pages = 0;

		/* A post type without a single image gets no image sitemap: an empty one is invalid. */
		if ($this->has_images($object_subtype)) {
			$args                  = $this->query_args($object_subtype, 1);
			$args['no_found_rows'] = false;
			$query                 = new \WP_Query($args);
			$pages                 = (int) ceil($query->found_posts / self::PER_PAGE);
		}

		set_transient($key, 'n:' . $pages, DAY_IN_SECONDS);

		return $pages;
	}

	/**
	 * Whether any published post of this type has an image in its content, a featured image
	 * or a product gallery.
	 *
	 * Finding that out reads the content of every published post of a type that has none, so
	 * the answer is kept outside the page cache (which is emptied on every edit): "yes" for a
	 * week or until every sitemap page of the type turns out empty, "no" for a day or until a
	 * post of that type gets an image (see note_post()).
	 */
	private function has_images(string $post_type): bool
	{
		$known = self::known_types();
		$entry = $known[$post_type] ?? null;
		/* "No" is checked again after a day, "yes" after a week (its images may all have been removed since). */
		$fresh = is_array($entry) && isset($entry[0], $entry[1]) && (int) $entry[1] > time() - ($entry[0] ? WEEK_IN_SECONDS : DAY_IN_SECONDS);

		if ($fresh) {
			$found = (bool) $entry[0];
		} else {
			$found = $this->scan_for_images($post_type);
			self::remember_type($post_type, $found);
		}

		/**
		 * Filter whether a post type has images to list. Return true for a post type whose
		 * images are only added through the crawlwp_image_sitemap_images filter.
		 *
		 * @param bool   $found     Whether a published post of this type has an image.
		 * @param string $post_type Post type.
		 */
		return (bool) apply_filters('crawlwp_image_sitemap_has_images', $found, $post_type);
	}

	/**
	 * One query that stops at the first published post with an image.
	 */
	private function scan_for_images(string $post_type): bool
	{
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->posts} p
				WHERE p.post_type = %s AND p.post_status = 'publish'
				AND (p.post_content LIKE %s OR EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} m
					WHERE m.post_id = p.ID AND m.meta_key IN ('_thumbnail_id', '_product_image_gallery') AND m.meta_value NOT IN ('', '0')
				))
				LIMIT 1",
				$post_type,
				'%' . $wpdb->esc_like('<img') . '%'
			)
		);
	}

	/**
	 * @return array<string, array{0: int, 1: int}>
	 */
	private static function known_types(): array
	{
		$known = get_option(self::TYPES_OPTION, []);

		return is_array($known) ? $known : [];
	}

	private static function remember_type(string $post_type, bool $has_images): void
	{
		$known             = self::known_types();
		$known[$post_type] = [$has_images ? 1 : 0, time()];

		update_option(self::TYPES_OPTION, $known, false);
	}

	/**
	 * A published post changed. If its type was down as having no images and this post has one,
	 * the type has images now: no need to read the whole post type again to find that out.
	 */
	private function note_post(\WP_Post $post): void
	{
		$entry = self::known_types()[$post->post_type] ?? null;

		if (! is_array($entry) || ! empty($entry[0]) || $post->post_status !== 'publish') {
			return;
		}

		if (
			stripos((string) $post->post_content, '<img') !== false
			|| (int) get_post_meta($post->ID, '_thumbnail_id', true) > 0
			|| trim((string) get_post_meta($post->ID, '_product_image_gallery', true), " ,0") !== ''
		) {
			self::remember_type($post->post_type, true);
		}
	}

	/**
	 * Cache key for a sitemap page or page count. It holds the site address and language, so
	 * a multilingual site (WPML, Polylang) keeps one copy per language, and the version, so
	 * every copy misses after a flush.
	 */
	private static function cache_key(string $part): string
	{
		/**
		 * Filter what makes a cached image sitemap page unique, besides the post type and page.
		 *
		 * @param string $scope Site address and locale.
		 */
		$scope = (string) apply_filters('crawlwp_image_sitemap_cache_scope', home_url('/') . '|' . determine_locale());

		return 'crawlwp_imgsm_' . md5($part . '|' . $scope . '|' . get_option(self::VERSION_OPTION, '1'));
	}

	public function get_url_list($page_num, $object_subtype = ''): array
	{
		return array_map(static function ($entry) {
			return ['loc' => $entry['loc']];
		}, $this->entries((string) $object_subtype, (int) $page_num));
	}

	/**
	 * Posts with at least one image, and their image URLs.
	 *
	 * @return array<int, array{loc: string, images: string[]}>
	 */
	public function entries(string $post_type, int $page): array
	{
		if (! isset($this->get_object_subtypes()[$post_type])) {
			return [];
		}

		$ids = (new \WP_Query($this->query_args($post_type, $page)))->posts;

		if ($ids === []) {
			return [];
		}

		_prime_post_caches($ids, false, true);

		/* %product_cat% and similar permalinks read the post's terms. */
		update_object_term_cache($ids, $post_type);

		/* The featured and gallery images in one go, instead of a post and meta query for each. */
		$attachments = [];

		foreach ($ids as $id) {
			$attachments[] = (int) get_post_meta((int) $id, '_thumbnail_id', true);

			if ($post_type === 'product') {
				foreach (explode(',', (string) get_post_meta((int) $id, '_product_image_gallery', true)) as $attachment) {
					$attachments[] = absint($attachment);
				}
			}
		}

		$attachments = array_values(array_unique(array_filter($attachments)));

		if ($attachments !== []) {
			_prime_post_caches($attachments, false, true);
		}

		$entries = [];

		foreach ($ids as $id) {
			$images = self::images_for((int) $id);

			if ($images !== []) {
				$entries[] = ['loc' => (string) get_permalink((int) $id), 'images' => $images];
			}
		}

		return $entries;
	}

	/**
	 * Featured image, images in the content and the WooCommerce product gallery.
	 *
	 * @return string[]
	 */
	public static function images_for(int $post_id): array
	{
		$urls  = [];
		$thumb = (int) get_post_thumbnail_id($post_id);

		if ($thumb) $urls[] = wp_get_attachment_url($thumb);

		foreach (self::content_images((string) get_post_field('post_content', $post_id)) as $url) {
			$urls[] = $url;
		}

		if (get_post_type($post_id) === 'product') {
			foreach (array_filter(array_map('absint', explode(',', (string) get_post_meta($post_id, '_product_image_gallery', true)))) as $attachment) {
				$urls[] = wp_get_attachment_url($attachment);
			}
		}

		/**
		 * Filter the images listed for a post in the image sitemap.
		 *
		 * @param string[] $urls    Absolute image URLs.
		 * @param int      $post_id Post ID.
		 */
		$urls = (array) apply_filters('crawlwp_image_sitemap_images', $urls, $post_id);

		$clean = [];

		$base = (string) get_permalink($post_id);

		foreach ($urls as $url) {
			$url = is_string($url) ? self::absolute($url, $base) : '';

			if ($url !== '' && ! isset($clean[$url])) {
				$clean[$url] = true;
			}
		}

		return array_slice(array_keys($clean), 0, self::MAX_IMAGES);
	}

	/**
	 * The src of every <img> in post content.
	 *
	 * @return string[]
	 */
	public static function content_images(string $content): array
	{
		if ($content === '' || stripos($content, '<img') === false) {
			return [];
		}

		preg_match_all('/<img\b[^>]*?\ssrc\s*=\s*(["\'])(.*?)\1/is', $content, $matches);

		return array_map(static function ($src) {
			return html_entity_decode(trim($src), ENT_QUOTES);
		}, $matches[2]);
	}

	/**
	 * An absolute http(s) URL, or '' for data URIs and anything else.
	 *
	 * Relative addresses are resolved the way a browser does on the post's page:
	 * "/wp-content/x.jpg" against the site's scheme and host (it already holds any
	 * subfolder WordPress is installed in), "x.jpg" against the post's address.
	 */
	public static function absolute(string $url, string $base = ''): string
	{
		$url = trim($url);

		if ($url === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) && ! preg_match('#^https?://#i', $url)) {
			return ''; // data:, javascript: and other schemes
		}

		if (strpos($url, '//') === 0) {
			$url = (is_ssl() ? 'https:' : 'http:') . $url;
		} elseif (! preg_match('#^https?://#i', $url)) {
			$base = $base !== '' ? $base : home_url('/');
			$url  = \WP_Http::make_absolute_url($url, $base);
		}

		return preg_match('#^https?://#i', $url) ? esc_url_raw($url) : '';
	}

	/**
	 * Whether every sitemap page of a post type is known to be empty, given that $empty_page
	 * just turned out empty. Only pages already built (and cached as empty) count, so nothing
	 * is built to find out; a type with very many pages is left alone.
	 */
	private function all_pages_empty(string $subtype, int $empty_page): bool
	{
		$pages = $this->get_max_num_pages($subtype);

		if ($pages < 1 || $pages > 50) {
			return false;
		}

		for ($page = 1; $page <= $pages; $page++) {
			if ($page !== $empty_page && get_transient(self::cache_key($subtype . '|' . $page)) !== self::EMPTY_PAGE) {
				return false;
			}
		}

		return true;
	}

	public function maybe_render(): void
	{
		if (get_query_var('sitemap') !== self::PROVIDER_NAME) {
			return;
		}

		$subtype = (string) get_query_var('sitemap-subtype');
		$page    = max(1, (int) get_query_var('paged'));

		if (! self::is_enabled() || ! isset($this->get_object_subtypes()[$subtype]) || $page > $this->get_max_num_pages($subtype)) {
			status_header(404);
			nocache_headers();
			return;
		}

		$key = self::cache_key($subtype . '|' . $page);
		$xml = get_transient($key);

		if (! is_string($xml) || $xml === '') {
			$entries = $this->entries($subtype, $page);

			/* A sitemap must list at least one URL: an empty page is remembered as "none". */
			$xml = $entries === [] ? self::EMPTY_PAGE : self::xml($entries);
			set_transient($key, $xml, DAY_IN_SECONDS);

			/* Every page of this post type is empty (its posts with images are all noindexed or
			   protected, or the images are gone): take the type out of the sitemap index until one
			   of its posts changes. */
			if ($entries === [] && $this->all_pages_empty($subtype, $page)) {
				self::remember_type($subtype, false);
				set_transient(self::cache_key('n|' . $subtype), 'n:0', DAY_IN_SECONDS);
			}
		}

		if ($xml === self::EMPTY_PAGE) {
			status_header(404);
			nocache_headers();
			return;
		}

		if (! headers_sent()) {
			status_header(200);
			header('Content-Type: application/xml; charset=UTF-8');
			header('X-Robots-Tag: noindex, follow');
		}

		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in xml().
		exit;
	}

	/**
	 * @param array<int, array{loc: string, images: string[]}> $entries
	 */
	public static function xml(array $entries): string
	{
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

		foreach ($entries as $entry) {
			$xml .= "\t<url>\n\t\t<loc>" . esc_xml($entry['loc']) . "</loc>\n";

			foreach ($entry['images'] as $image) {
				$xml .= "\t\t<image:image>\n\t\t\t<image:loc>" . esc_xml($image) . "</image:loc>\n\t\t</image:image>\n";
			}

			$xml .= "\t</url>\n";
		}

		return $xml . "</urlset>\n";
	}

	public function flush_cache(): void
	{
		/* Switched off: there are no cached pages, so a save has nothing to empty. Switching it
		   on again saves the settings, which empties the cache through flush_settings(). */
		if (! self::is_enabled()) {
			return;
		}

		/* An import or bulk edit changes many posts in one request: empty the cache at the first
		   change and once more when the request ends, not once per post. */
		if ($this->flushed) {
			if (! $this->flush_again) {
				$this->flush_again = true;
				add_action('shutdown', [$this, 'flush_now']);
			}

			return;
		}

		$this->flushed = true;
		$this->flush_now();
	}

	/**
	 * The sitemap settings were saved (the image sitemap may have been switched on or off):
	 * empty the cache whatever the switch says, and check again which post types have images.
	 */
	public function flush_settings(): void
	{
		delete_option(self::TYPES_OPTION);

		if (! $this->flushed) {
			$this->flushed = true;
			$this->flush_now();
		}
	}

	public function flush_now(): void
	{
		global $wpdb;

		/* A new version makes every cached page miss (this also covers persistent object caches). */
		update_option(self::VERSION_OPTION, uniqid('', true), false);

		/*
		 * Without an object cache the old pages are rows in the options table: delete them now
		 * rather than leave them until they expire a day later. One indexed prefix query.
		 */
		if (! wp_using_ext_object_cache()) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
					$wpdb->esc_like('_transient_crawlwp_imgsm_') . '%',
					$wpdb->esc_like('_transient_timeout_crawlwp_imgsm_') . '%'
				)
			);
		}
	}

	/**
	 * A post was saved: drop the cached pages if it is, or was, published public content.
	 * Autosaves, revisions, drafts, menu items and the like change nothing in the sitemap.
	 *
	 * @param string $new_status
	 * @param string $old_status
	 * @param mixed  $post
	 */
	public function flush_on_status($new_status, $old_status, $post): void
	{
		if (($new_status === 'publish' || $old_status === 'publish') && self::listed_type($post)) {
			$this->note_post($post);
			$this->flush_cache();
		}
	}

	/**
	 * A post was deleted without being trashed first (trashing already flushed).
	 *
	 * @param int   $post_id
	 * @param mixed $post
	 */
	public function flush_on_delete($post_id, $post = null): void
	{
		if ($post instanceof \WP_Post && $post->post_status === 'publish' && self::listed_type($post)) {
			$this->flush_cache();
		}
	}

	/**
	 * A featured image, product gallery or noindex setting changed without the post being saved.
	 *
	 * @param mixed $meta_id
	 * @param mixed $post_id
	 * @param mixed $meta_key
	 */
	public function flush_on_meta($meta_id, $post_id, $meta_key): void
	{
		if (! in_array($meta_key, self::WATCHED_META, true)) {
			return;
		}

		$post = get_post((int) $post_id);

		if ($post instanceof \WP_Post && $post->post_status === 'publish' && self::listed_type($post)) {
			$this->note_post($post);
			$this->flush_cache();
		}
	}

	/**
	 * An image was deleted or edited. Only images can be in the sitemap.
	 *
	 * @param mixed $attachment_id
	 */
	public function flush_on_attachment($attachment_id): void
	{
		if (wp_attachment_is_image((int) $attachment_id)) {
			$this->flush_cache();
		}
	}

	/**
	 * The image editor saved a new file for an existing image (crop, rotate): its
	 * address changed. A brand-new upload sets its file too, but can't be in the
	 * sitemap yet, so uploads don't empty the cache.
	 *
	 * @param mixed $file
	 * @param mixed $attachment_id
	 *
	 * @return mixed
	 */
	public function flush_on_file_change($file, $attachment_id = 0)
	{
		$attachment = get_post((int) $attachment_id);

		if ($attachment instanceof \WP_Post && strtotime($attachment->post_date_gmt . ' UTC') < time() - MINUTE_IN_SECONDS && wp_attachment_is_image($attachment)) {
			$this->flush_cache();
		}

		return $file;
	}

	/**
	 * Whether posts of this type can be in the image sitemap.
	 *
	 * @param mixed $post
	 */
	private static function listed_type($post): bool
	{
		return $post instanceof \WP_Post
			&& ! wp_is_post_revision($post)
			&& ! wp_is_post_autosave($post)
			&& $post->post_type !== 'attachment'
			&& is_post_type_viewable($post->post_type);
	}
}
