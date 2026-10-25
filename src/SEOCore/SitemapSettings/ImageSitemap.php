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

	public function __construct()
	{
		$this->name        = self::PROVIDER_NAME;
		$this->object_type = 'post';

		add_action('init', [$this, 'register_provider'], 20);
		add_action('template_redirect', [$this, 'maybe_render'], 1);

		add_action('save_post', [$this, 'flush_cache']);
		add_action('deleted_post', [$this, 'flush_cache']);
		add_action('crawlwp_sitemap_settings_saved', [$this, 'flush_cache']);
	}

	public static function is_enabled(): bool
	{
		return (string) SitemapSettings::get('image_sitemap', 'on') === 'on';
	}

	public function register_provider(): void
	{
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

		$args                  = $this->query_args($object_subtype, 1);
		$args['no_found_rows'] = false;
		$query                 = new \WP_Query($args);

		return (int) ceil($query->found_posts / self::PER_PAGE);
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

		foreach ($urls as $url) {
			$url = is_string($url) ? self::absolute($url) : '';

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
		}, $matches[2] ?? []);
	}

	/**
	 * An absolute http(s) URL, or '' for data URIs and anything else.
	 */
	private static function absolute(string $url): string
	{
		$url = trim($url);

		if (strpos($url, '//') === 0) {
			$url = (is_ssl() ? 'https:' : 'http:') . $url;
		} elseif (strpos($url, '/') === 0) {
			$url = home_url($url);
		}

		return preg_match('#^https?://#i', $url) ? esc_url_raw($url) : '';
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

		$key = 'crawlwp_imgsm_' . md5($subtype . '|' . $page . '|' . get_option(self::VERSION_OPTION, '1'));
		$xml = get_transient($key);

		if (! is_string($xml) || $xml === '') {
			$xml = self::xml($this->entries($subtype, $page));
			set_transient($key, $xml, DAY_IN_SECONDS);
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
		update_option(self::VERSION_OPTION, (string) time(), false);
	}
}
