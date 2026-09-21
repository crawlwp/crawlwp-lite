<?php
/**
 * Test bootstrap.
 *
 * The SEOCore classes that carry real logic (TokenMapper, Graph, AutoLinker …)
 * are plain PHP: they only touch WordPress through a handful of helpers. This
 * bootstrap stubs those helpers so the logic can be unit tested without a
 * WordPress install, and loads the classes under test directly (the plugin's
 * Composer autoloader is not authoritative and vendor/ is stripped in builds).
 *
 * @package mihdan-index-now
 */

define('CRAWLWP_TESTS_DIR', __DIR__);
define('CRAWLWP_TESTS_PLUGIN_DIR', dirname(__DIR__));

if (!defined('CRAWLWP_PREFIX')) {
	define('CRAWLWP_PREFIX', 'crawlwp');
}
if (!defined('CRAWLWP_SLUG')) {
	define('CRAWLWP_SLUG', 'crawlwp');
}
if (!defined('CRAWLWP_NAME')) {
	define('CRAWLWP_NAME', 'CrawlWP');
}
if (!defined('CRAWLWP_VERSION')) {
	define('CRAWLWP_VERSION', '3.1.0');
}
if (!defined('ABSPATH')) {
	define('ABSPATH', dirname(CRAWLWP_TESTS_PLUGIN_DIR, 2) . '/');
}
if (!defined('WP_PLUGIN_DIR')) {
	define('WP_PLUGIN_DIR', dirname(CRAWLWP_TESTS_PLUGIN_DIR));
}
if (!defined('CRAWLWP_PRO_VERSION')) {
	define('CRAWLWP_PRO_VERSION', '3.2.2');
}
if (!defined('CRAWLWP_PRO_SYSTEM_FILE_PATH')) {
	define('CRAWLWP_PRO_SYSTEM_FILE_PATH', dirname(CRAWLWP_TESTS_PLUGIN_DIR) . '/mihdan-index-now-pro/mihdan-index-now-pro.php');
}
if (!defined('CRAWLWP_PRO_LIBSODIUM_ASSETS_URL')) {
	define('CRAWLWP_PRO_LIBSODIUM_ASSETS_URL', 'https://example.test/wp-content/plugins/mihdan-index-now-pro/Libsodium/assets');
}
if (!defined('CRAWLWP_SETTINGS_URL')) {
	define('CRAWLWP_SETTINGS_URL', 'https://example.test/wp-admin/admin.php?page=crawlwp');
}

if (!defined('MINUTE_IN_SECONDS')) {
	define('MINUTE_IN_SECONDS', 60);
}
if (!defined('OBJECT')) {
	define('OBJECT', 'OBJECT');
}
if (!defined('HOUR_IN_SECONDS')) {
	define('HOUR_IN_SECONDS', 3600);
}
if (!defined('DAY_IN_SECONDS')) {
	define('DAY_IN_SECONDS', 86400);
}

/**
 * Values the stubs return. Tests may overwrite entries directly.
 *
 * @var array<string, mixed> $GLOBALS['crawlwp_test_state']
 */
$GLOBALS['crawlwp_test_state'] = [
	'options'   => [],
	'permalink' => 'https://example.test/current-post/',
	'home_url'  => 'https://example.test',
	'printed'   => '',
];

if (!function_exists('apply_filters')) {
	function apply_filters($hook, $value, ...$args)
	{
		if (!empty($GLOBALS['crawlwp_test_state']['filters'][$hook])) {
			foreach ($GLOBALS['crawlwp_test_state']['filters'][$hook] as $callback) {
				$value = call_user_func($callback, $value, ...$args);
			}
		}
		return $value;
	}
}

if (!function_exists('add_filter')) {
	function add_filter($hook, $callback, $priority = 10, $accepted_args = 1)
	{
		$GLOBALS['crawlwp_test_state']['filters'][$hook][] = $callback;
		return true;
	}
}

if (!function_exists('__return_true')) {
	function __return_true()
	{
		return true;
	}
}

if (!function_exists('__return_false')) {
	function __return_false()
	{
		return false;
	}
}

if (!function_exists('add_action')) {
	function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
	{
		$GLOBALS['crawlwp_test_state']['actions'][$hook][] = [
			'callback' => $callback,
			'priority' => $priority,
			'args'     => $accepted_args,
		];
		return true;
	}
}

if (!function_exists('add_submenu_page')) {
	function add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null)
	{
		global $submenu;
		if ($parent_slug !== null) {
			$submenu[$parent_slug][] = [$menu_title, $capability, $menu_slug, $page_title];
		}
		return 'admin_page_' . $menu_slug;
	}
}

if (!function_exists('add_menu_page')) {
	function add_menu_page($page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null)
	{
		global $menu;
		$menu[] = [$menu_title, $capability, $menu_slug, $page_title];
		return 'toplevel_page_' . $menu_slug;
	}
}

if (!function_exists('do_action')) {
	function do_action($hook, ...$args)
	{
	}
}

if (!function_exists('did_action')) {
	function did_action($hook)
	{
		return 0;
	}
}

if (!function_exists('wp_parse_url')) {
	function wp_parse_url($url, $component = -1)
	{
		return parse_url($url, $component);
	}
}

if (!function_exists('get_option')) {
	function get_option($name, $default = false)
	{
		return $GLOBALS['crawlwp_test_state']['options'][$name] ?? $default;
	}
}

if (!function_exists('update_option')) {
	function update_option($name, $value, $autoload = null)
	{
		$GLOBALS['crawlwp_test_state']['options'][$name] = $value;

		return true;
	}
}

if (!function_exists('add_option')) {
	function add_option($name, $value = '', $deprecated = '', $autoload = 'yes')
	{
		if (!isset($GLOBALS['crawlwp_test_state']['options'][$name])) {
			$GLOBALS['crawlwp_test_state']['options'][$name] = $value;
			return true;
		}
		return false;
	}
}

if (!function_exists('delete_option')) {
	function delete_option($name)
	{
		unset($GLOBALS['crawlwp_test_state']['options'][$name]);
		return true;
	}
}

if (!function_exists('get_permalink')) {
	function get_permalink($post = 0, $leavename = false)
	{
		return $GLOBALS['crawlwp_test_state']['permalink'] ?? 'https://example.test/current-post/';
	}
}

if (!function_exists('home_url')) {
	function home_url($path = '', $scheme = null)
	{
		return ($GLOBALS['crawlwp_test_state']['home_url'] ?? 'https://example.test') . $path;
	}
}

if (!function_exists('get_queried_object')) {
	function get_queried_object()
	{
		return $GLOBALS['crawlwp_test_state']['queried_object'] ?? null;
	}
}

if (!function_exists('is_admin')) {
	function is_admin()
	{
		return crawlwp_test_conditional('is_admin');
	}
}

if (!function_exists('wp_doing_ajax')) {
	function wp_doing_ajax()
	{
		return false;
	}
}

/**
 * Conditional tags default to false; a test flips them through
 * $GLOBALS['crawlwp_test_state']['is_singular'] = true and friends.
 */
function crawlwp_test_conditional(string $name): bool
{
	return !empty($GLOBALS['crawlwp_test_state'][$name]);
}

if (!function_exists('is_feed')) {
	function is_feed($feeds = '')
	{
		return crawlwp_test_conditional('is_feed');
	}
}

if (!function_exists('is_robots')) {
	function is_robots()
	{
		return crawlwp_test_conditional('is_robots');
	}
}

if (!function_exists('is_trackback')) {
	function is_trackback()
	{
		return crawlwp_test_conditional('is_trackback');
	}
}

if (!function_exists('is_singular')) {
	function is_singular($post_types = '')
	{
		return crawlwp_test_conditional('is_singular');
	}
}

if (!function_exists('is_home')) {
	function is_home()
	{
		return crawlwp_test_conditional('is_home');
	}
}

if (!function_exists('is_front_page')) {
	function is_front_page()
	{
		return crawlwp_test_conditional('is_front_page');
	}
}

if (!function_exists('in_the_loop')) {
	function in_the_loop()
	{
		return crawlwp_test_conditional('in_the_loop');
	}
}

if (!function_exists('is_main_query')) {
	function is_main_query()
	{
		return crawlwp_test_conditional('is_main_query');
	}
}

if (!function_exists('esc_html')) {
	function esc_html($text)
	{
		return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('esc_attr')) {
	function esc_attr($text)
	{
		return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('esc_url')) {
	function esc_url($url, $protocols = null, $context = 'display')
	{
		return htmlspecialchars((string)$url, ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('esc_url_raw')) {
	function esc_url_raw($url, $protocols = null)
	{
		return (string)$url;
	}
}

if (!function_exists('esc_xml')) {
	function esc_xml($text)
	{
		return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('__')) {
	function __($text, $domain = 'default')
	{
		return $text;
	}
}

if (!function_exists('_x')) {
	function _x($text, $context, $domain = 'default')
	{
		return $text;
	}
}

if (!function_exists('esc_html__')) {
	function esc_html__($text, $domain = 'default')
	{
		return esc_html($text);
	}
}

if (!function_exists('esc_html_e')) {
	function esc_html_e($text, $domain = 'default')
	{
		echo esc_html($text);
	}
}

if (!function_exists('_e')) {
	function _e($text, $domain = 'default')
	{
		echo $text;
	}
}

if (!function_exists('_n')) {
	function _n($single, $plural, $number, $domain = 'default')
	{
		return $number === 1 ? $single : $plural;
	}
}

if (!function_exists('number_format_i18n')) {
	function number_format_i18n($number, $decimals = 0)
	{
		return (string)$number;
	}
}

if (!function_exists('wp_create_nonce')) {
	function wp_create_nonce($action = -1)
	{
		return 'mock_nonce_' . $action;
	}
}

if (!function_exists('esc_attr__')) {
	function esc_attr__($text, $domain = 'default')
	{
		return esc_attr($text);
	}
}

if (!function_exists('esc_js')) {
	function esc_js($text)
	{
		return addcslashes((string)$text, "\\'\"&\n\r<>");
	}
}

if (!function_exists('current_user_can')) {
	function current_user_can($capability, ...$args)
	{
		return $GLOBALS['crawlwp_test_state']['current_user_can'][$capability] ?? true;
	}
}

if (!function_exists('wp_json_encode')) {
	function wp_json_encode($data, $options = 0, $depth = 512)
	{
		return json_encode($data, $options, $depth);
	}
}

if (!function_exists('wp_strip_all_tags')) {
	function wp_strip_all_tags($string, $remove_breaks = false)
	{
		$string = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string)$string);

		return trim(strip_tags((string)$string));
	}
}

if (!function_exists('sanitize_title')) {
	function sanitize_title($title, $fallback_title = '', $context = 'save')
	{
		$title = strtolower(preg_replace('/[^a-z0-9\-_ ]/i', '', (string)$title));

		return trim(preg_replace('/[\s_]+/', '-', $title), '-');
	}
}

if (!function_exists('absint')) {
	function absint($number)
	{
		return abs((int)$number);
	}
}

if (!function_exists('trailingslashit')) {
	function trailingslashit($string)
	{
		return rtrim((string)$string, '/\\') . '/';
	}
}

if (!function_exists('untrailingslashit')) {
	function untrailingslashit($string)
	{
		return rtrim((string)$string, '/\\');
	}
}

if (!function_exists('wp_parse_args')) {
	function wp_parse_args($args, $defaults = [])
	{
		return array_merge($defaults, (array)$args);
	}
}

if (!function_exists('_doing_it_wrong')) {
	function _doing_it_wrong($function, $message, $version)
	{
	}
}

if (!function_exists('wp_trigger_error')) {
	function wp_trigger_error($function_name, $message, $error_level = E_USER_NOTICE)
	{
	}
}

if (!function_exists('wp_scrub_utf8')) {
	function wp_scrub_utf8($text)
	{
		return (string) $text;
	}
}

if (!function_exists('add_rewrite_rule')) {
	function add_rewrite_rule($regex, $query, $after = 'bottom')
	{
		$GLOBALS['crawlwp_test_state']['rewrite_rules'][$regex] = $query;
	}
}

if (!function_exists('sanitize_text_field')) {
	function sanitize_text_field($str)
	{
		return trim(strip_tags((string)$str));
	}
}

if (!function_exists('sanitize_textarea_field')) {
	function sanitize_textarea_field($str)
	{
		return trim(strip_tags((string)$str));
	}
}

if (!function_exists('metadata_exists')) {
	function metadata_exists($meta_type, $object_id, $meta_key)
	{
		return array_key_exists($meta_key, $GLOBALS['crawlwp_test_state']['post_meta'][$object_id] ?? []);
	}
}

if (!function_exists('get_query_var')) {
	function get_query_var($var, $default = '')
	{
		return $GLOBALS['crawlwp_test_state']['query_vars'][$var] ?? $default;
	}
}

if (!function_exists('get_language_attributes')) {
	function get_language_attributes($doctype = 'html')
	{
		return 'lang="en-US"';
	}
}

if (!function_exists('is_rtl')) {
	function is_rtl()
	{
		return false;
	}
}

if (!function_exists('wp_sitemaps_get_max_urls')) {
	function wp_sitemaps_get_max_urls($object_type)
	{
		return 2000;
	}
}

if (!function_exists('wp_register_sitemap_provider')) {
	function wp_register_sitemap_provider($name, $provider)
	{
		$GLOBALS['crawlwp_test_state']['sitemap_providers'][$name] = $provider;
		return true;
	}
}

// Load WordPress HTML API when this suite runs inside a WP checkout so
// AutoLinker tests exercise WP_HTML_Processor instead of the DOM fallback.
$wp_includes = dirname(CRAWLWP_TESTS_PLUGIN_DIR, 3) . '/wp-includes';
$html_api    = $wp_includes . '/html-api';

if (!class_exists('WP_HTML_Processor') && is_readable($html_api . '/class-wp-html-processor.php')) {
	if (!class_exists('WP_Token_Map') && is_readable($wp_includes . '/class-wp-token-map.php')) {
		require_once $wp_includes . '/class-wp-token-map.php';
	}

	foreach ([
		'html5-named-character-references.php',
		'class-wp-html-attribute-token.php',
		'class-wp-html-span.php',
		'class-wp-html-doctype-info.php',
		'class-wp-html-text-replacement.php',
		'class-wp-html-decoder.php',
		'class-wp-html-tag-processor.php',
		'class-wp-html-unsupported-exception.php',
		'class-wp-html-active-formatting-elements.php',
		'class-wp-html-open-elements.php',
		'class-wp-html-token.php',
		'class-wp-html-stack-event.php',
		'class-wp-html-processor-state.php',
		'class-wp-html-processor.php',
	] as $file) {
		require_once $html_api . '/' . $file;
	}
}

if (!class_exists('WP_Sitemaps_Provider')) {
	$sitemaps_provider = dirname(CRAWLWP_TESTS_PLUGIN_DIR, 3) . '/wp-includes/sitemaps/providers/class-wp-sitemaps-provider.php';
	if (is_readable($sitemaps_provider)) {
		require_once $sitemaps_provider;
	} else {
		abstract class WP_Sitemaps_Provider {
			public $name;
			public $object_type;
			abstract public function get_url_list($page_num, $object_subtype = '');
			abstract public function get_max_num_pages($object_subtype = '');
		}
	}
}

if (!class_exists('WP_Sitemaps_Stylesheet')) {
	$sitemaps_stylesheet = dirname(CRAWLWP_TESTS_PLUGIN_DIR, 3) . '/wp-includes/sitemaps/class-wp-sitemaps-stylesheet.php';
	if (is_readable($sitemaps_stylesheet)) {
		require_once $sitemaps_stylesheet;
	}
}

if (!function_exists('wp_trim_words')) {
	function wp_trim_words($text, $num_words = 55, $more = null)
	{
		if ($more === null) {
			$more = '&hellip;';
		}
		$words_array = preg_split("/[\n\r\t ]+/", (string)$text, $num_words + 1, PREG_SPLIT_NO_EMPTY);
		if (is_array($words_array) && count($words_array) > $num_words) {
			array_pop($words_array);
			$text = implode(' ', $words_array);
			$text = $text . $more;
		} elseif (is_array($words_array)) {
			$text = implode(' ', $words_array);
		}
		return (string)$text;
	}
}

if (!function_exists('get_the_title')) {
	function get_the_title($post = 0)
	{
		if (is_object($post) && isset($post->post_title)) {
			return (string)$post->post_title;
		}
		return $GLOBALS['crawlwp_test_state']['title'] ?? '';
	}
}

if (!function_exists('get_the_post_thumbnail_url')) {
	function get_the_post_thumbnail_url($post = null, $size = 'post-thumbnail')
	{
		$post_id = is_object($post) && isset($post->ID) ? (int)$post->ID : (int)$post;
		return $GLOBALS['crawlwp_test_state']['post_thumbnail_url'][$post_id] ?? '';
	}
}

if (!function_exists('has_post_thumbnail')) {
	function has_post_thumbnail($post = null)
	{
		$post_id = is_object($post) && isset($post->ID) ? (int)$post->ID : (int)$post;
		return !empty($GLOBALS['crawlwp_test_state']['post_thumbnail_url'][$post_id]);
	}
}

if (!function_exists('get_post_time')) {
	function get_post_time($format = 'U', $gmt = false, $post = null, $translate = false)
	{
		if (is_object($post) && !empty($post->post_date_gmt)) {
			return $format === 'c' ? gmdate('c', strtotime($post->post_date_gmt)) : $post->post_date_gmt;
		}
		return gmdate($format);
	}
}

if (!function_exists('get_post_meta')) {
	function get_post_meta($post_id, $key = '', $single = false)
	{
		$meta = $GLOBALS['crawlwp_test_state']['post_meta'][$post_id][$key] ?? null;
		if ($single) {
			return $meta ?? '';
		}
		return $meta !== null ? [$meta] : [];
	}
}

if (!function_exists('update_post_meta')) {
	function update_post_meta($post_id, $meta_key, $meta_value, $prev_value = '')
	{
		$GLOBALS['crawlwp_test_state']['post_meta'][$post_id][$meta_key] = $meta_value;
		return true;
	}
}

if (!function_exists('add_post_meta')) {
	function add_post_meta($post_id, $meta_key, $meta_value, $unique = false)
	{
		$GLOBALS['crawlwp_test_state']['post_meta'][$post_id][$meta_key] = $meta_value;
		return true;
	}
}

if (!function_exists('delete_post_meta')) {
	function delete_post_meta($post_id, $meta_key, $meta_value = '')
	{
		unset($GLOBALS['crawlwp_test_state']['post_meta'][$post_id][$meta_key]);
		return true;
	}
}

if (!class_exists('WP_Post_Type')) {
	#[\AllowDynamicProperties]
	class WP_Post_Type
	{
		public string $name = '';
		public $labels;
		public bool $has_archive = false;
		public bool $public = true;
		public bool $publicly_queryable = true;

		public function __construct(string $name, string $singular_name, string $plural_name = '', bool $has_archive = false, bool $public = true)
		{
			$this->name = $name;
			$this->labels = (object)[
				'singular_name' => $singular_name,
				'name'          => $plural_name ?: $singular_name,
			];
			$this->has_archive = $has_archive;
			$this->public = $public;
			$this->publicly_queryable = $public;
		}
	}
}

if (!class_exists('WP_Taxonomy')) {
	#[\AllowDynamicProperties]
	class WP_Taxonomy
	{
		public string $name = '';
		public $labels;

		public function __construct(string $name, string $singular_name, string $plural_name = '')
		{
			$this->name = $name;
			$this->labels = (object)[
				'singular_name' => $singular_name,
				'name'          => $plural_name ?: $singular_name,
			];
		}
	}
}

if (!function_exists('is_ssl')) {
	function is_ssl()
	{
		return $GLOBALS['crawlwp_test_state']['is_ssl'] ?? true;
	}
}

if (!function_exists('get_home_path')) {
	function get_home_path()
	{
		return '/tmp/';
	}
}

if (!function_exists('WP_Filesystem')) {
	function WP_Filesystem()
	{
		return true;
	}
}

if (!function_exists('get_filesystem_method')) {
	function get_filesystem_method()
	{
		return 'direct';
	}
}

if (!function_exists('is_post_type_viewable')) {
	function is_post_type_viewable($post_type)
	{
		if (is_scalar($post_type)) {
			$post_type = get_post_type_object($post_type);
		}
		if (!is_object($post_type)) {
			return false;
		}
		return (bool) (!empty($post_type->publicly_queryable) || (!empty($post_type->_builtin) && !empty($post_type->public)) || !empty($post_type->public));
	}
}

if (!function_exists('get_post_types')) {
	function get_post_types($args = [], $output = 'names', $operator = 'and')
	{
		if (isset($GLOBALS['crawlwp_test_state']['post_types'])) {
			$pts = $GLOBALS['crawlwp_test_state']['post_types'];
			if ($output === 'objects') {
				return $pts;
			}
			return array_combine(array_keys($pts), array_keys($pts));
		}
		if ($output === 'objects') {
			return [
				'post' => new WP_Post_Type('post', 'Post', 'Posts', true),
				'page' => new WP_Post_Type('page', 'Page', 'Pages', false),
			];
		}
		return ['post' => 'post', 'page' => 'page'];
	}
}

if (!function_exists('get_taxonomies')) {
	function get_taxonomies($args = [], $output = 'names', $operator = 'and')
	{
		if ($output === 'objects') {
			return [
				'category' => new WP_Taxonomy('category', 'Category', 'Categories'),
				'post_tag' => new WP_Taxonomy('post_tag', 'Tag', 'Tags'),
			];
		}
		return ['category' => 'category', 'post_tag' => 'post_tag'];
	}
}

if (!function_exists('wp_is_post_revision')) {
	function wp_is_post_revision($post)
	{
		return false;
	}
}

if (!function_exists('wp_is_post_autosave')) {
	function wp_is_post_autosave($post)
	{
		return false;
	}
}

if (!function_exists('get_post')) {
	function get_post($post = null)
	{
		if ($post instanceof WP_Post) {
			return $post;
		}
		return $GLOBALS['crawlwp_test_state']['posts'][$post] ?? null;
	}
}

if (!function_exists('get_transient')) {
	function get_transient($transient)
	{
		return $GLOBALS['crawlwp_test_state']['transients'][$transient] ?? false;
	}
}

if (!function_exists('set_transient')) {
	function set_transient($transient, $value, $expiration = 0)
	{
		$GLOBALS['crawlwp_test_state']['transients'][$transient] = $value;
		return true;
	}
}

if (!function_exists('delete_transient')) {
	function delete_transient($transient)
	{
		unset($GLOBALS['crawlwp_test_state']['transients'][$transient]);
		return true;
	}
}

if (!function_exists('wp_kses_post')) {
	function wp_kses_post($data)
	{
		return (string)$data;
	}
}

if (!function_exists('update_meta_cache')) {
	function update_meta_cache($meta_type, $object_ids)
	{
		return true;
	}
}

if (!class_exists('WP_Query')) {
	class WP_Query
	{
		public $posts = [];

		public function __construct(array $args = [])
		{
			$this->posts = $GLOBALS['crawlwp_test_state']['wp_query_posts'] ?? [];
		}
	}
}

if (!class_exists('WP_Post')) {
	#[\AllowDynamicProperties]
	class WP_Post
	{
		public $ID = 0;
		public $post_title = '';
		public $post_content = '';
		public $post_excerpt = '';
		public $post_status = 'publish';
		public $post_type = 'post';
		public $post_password = '';
		public $post_date_gmt = '0000-00-00 00:00:00';

		/**
		 * @param array<string, mixed>|object $data
		 */
		public function __construct($data = [])
		{
			foreach ((array) $data as $k => $v) {
				$this->$k = $v;
			}
		}
	}
}

if (!class_exists('WP_Term')) {
	#[\AllowDynamicProperties]
	class WP_Term
	{
		public $term_id = 0;
		public $name = '';
		public $slug = '';
		public $term_group = 0;
		public $term_taxonomy_id = 0;
		public $taxonomy = '';
		public $description = '';
		public $parent = 0;
		public $count = 0;

		/**
		 * @param array<string, mixed>|object $data
		 */
		public function __construct($data = [])
		{
			foreach ((array) $data as $k => $v) {
				$this->$k = $v;
			}
		}
	}
}

if (!function_exists('get_term')) {
	function get_term($term, $taxonomy = '', $output = OBJECT, $filter = 'raw')
	{
		if ($term instanceof WP_Term) {
			return $term;
		}

		return $GLOBALS['crawlwp_test_state']['terms'][$term] ?? null;
	}
}

if (!function_exists('get_term_link')) {
	function get_term_link($term, $taxonomy = '')
	{
		if ($term instanceof WP_Term) {
			return $GLOBALS['crawlwp_test_state']['term_links'][$term->term_id] ?? ('https://example.test/' . ($term->taxonomy ?: 'category') . '/' . $term->slug . '/');
		}

		return $GLOBALS['crawlwp_test_state']['term_links'][$term] ?? ('https://example.test/term/' . $term . '/');
	}
}

if (!function_exists('wp_generate_password')) {
	function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false)
	{
		return substr(md5(uniqid((string) mt_rand(), true)), 0, $length);
	}
}

if (!function_exists('wp_normalize_path')) {
	function wp_normalize_path($path)
	{
		$path = str_replace('\\', '/', (string) $path);
		$path = preg_replace('|(?<=.)/+|', '/', $path);
		if (':' === substr($path, 1, 1)) {
			$path = ucfirst($path);
		}
		return $path;
	}
}

if (!function_exists('plugins_url')) {
	function plugins_url($path = '', $plugin = '')
	{
		$url = 'https://example.test/wp-content/plugins';
		if (!empty($path)) {
			$url .= '/' . ltrim($path, '/');
		}
		return $url;
	}
}

if (!function_exists('plugin_basename')) {
	function plugin_basename($file)
	{
		return basename($file);
	}
}

if (!function_exists('load_plugin_textdomain')) {
	function load_plugin_textdomain($domain, $deprecated = false, $plugin_rel_path = false)
	{
		return true;
	}
}

if (!function_exists('current_time')) {
	function current_time($type, $gmt = 0)
	{
		return $type === 'timestamp' ? time() : gmdate('Y-m-d H:i:s');
	}
}

if (!function_exists('mysql2date')) {
	function mysql2date($format, $date, $translate = true)
	{
		if (empty($date)) {
			return false;
		}
		$datetime = date_create($date);
		if (!$datetime) {
			return false;
		}
		return $datetime->format($format);
	}
}

if (!function_exists('date_i18n')) {
	function date_i18n($format, $timestamp_with_offset = false, $gmt = false)
	{
		if (false === $timestamp_with_offset) {
			$timestamp_with_offset = time();
		}
		return gmdate($format, (int) $timestamp_with_offset);
	}
}

if (!function_exists('status_header')) {
	function status_header($code, $description = '')
	{
		$GLOBALS['crawlwp_test_state']['status_header'] = $code;
	}
}

if (!function_exists('add_shortcode')) {
	function add_shortcode($tag, $callback)
	{
		$GLOBALS['crawlwp_test_state']['shortcodes'][$tag] = $callback;
	}
}

if (!function_exists('shortcode_atts')) {
	function shortcode_atts($pairs, $atts, $shortcode = '')
	{
		$atts = (array) $atts;
		$out = [];
		foreach ($pairs as $name => $default) {
			if (array_key_exists($name, $atts)) {
				$out[$name] = $atts[$name];
			} else {
				$out[$name] = $default;
			}
		}
		return $out;
	}
}

if (!function_exists('get_post_type_object')) {
	function get_post_type_object($post_type)
	{
		if (isset($GLOBALS['crawlwp_test_state']['post_types'][$post_type])) {
			return $GLOBALS['crawlwp_test_state']['post_types'][$post_type];
		}
		return (object) [
			'name' => $post_type,
			'public' => true,
			'publicly_queryable' => true,
			'label' => ucfirst($post_type),
			'labels' => (object) [
				'name' => ucfirst($post_type) . 's',
				'singular_name' => ucfirst($post_type),
			],
		];
	}
}

if (!function_exists('get_posts')) {
	function get_posts($args = null)
	{
		return $GLOBALS['crawlwp_test_state']['posts'] ?? [];
	}
}

if (!class_exists('WP_Error')) {
	class WP_Error
	{
		protected $errors = [];
		protected $error_data = [];

		public function __construct($code = '', $message = '', $data = '')
		{
			if (!empty($code)) {
				$this->errors[$code][] = $message;
				if (!empty($data)) {
					$this->error_data[$code] = $data;
				}
			}
		}

		public function get_error_message($code = '')
		{
			if (empty($code)) {
				$code = $this->get_error_code();
			}
			$messages = $this->errors[$code] ?? [];
			return $messages[0] ?? '';
		}

		public function get_error_code()
		{
			$codes = array_keys($this->errors);
			return $codes[0] ?? '';
		}

		public function get_error_codes()
		{
			return array_keys($this->errors);
		}
	}
}

if (!function_exists('is_wp_error')) {
	function is_wp_error($thing)
	{
		return $thing instanceof WP_Error;
	}
}

if (!function_exists('wp_remote_post')) {
	function wp_remote_post($url, $args = [])
	{
		if (isset($GLOBALS['crawlwp_test_state']['remote_post_response'])) {
			return $GLOBALS['crawlwp_test_state']['remote_post_response'];
		}
		return ['response' => ['code' => 200], 'body' => ''];
	}
}

if (!function_exists('wp_remote_get')) {
	function wp_remote_get($url, $args = [])
	{
		if (isset($GLOBALS['crawlwp_test_state']['remote_get_response'])) {
			return $GLOBALS['crawlwp_test_state']['remote_get_response'];
		}
		return ['response' => ['code' => 200], 'body' => ''];
	}
}

if (!function_exists('wp_remote_retrieve_response_code')) {
	function wp_remote_retrieve_response_code($response)
	{
		if (is_wp_error($response)) {
			return '';
		}
		return $response['response']['code'] ?? 200;
	}
}

if (!function_exists('wp_remote_retrieve_body')) {
	function wp_remote_retrieve_body($response)
	{
		if (is_wp_error($response)) {
			return '';
		}
		return $response['body'] ?? '';
	}
}

if (!function_exists('get_bloginfo')) {
	function get_bloginfo($show = '', $filter = 'raw')
	{
		if ($show === 'version') {
			return $GLOBALS['crawlwp_test_state']['wp_version'] ?? '6.6';
		}
		return '';
	}
}

if (!function_exists('is_multisite')) {
	function is_multisite()
	{
		return crawlwp_test_conditional('is_multisite');
	}
}

if (!function_exists('wp_get_active_and_valid_plugins')) {
	function wp_get_active_and_valid_plugins()
	{
		return $GLOBALS['crawlwp_test_state']['active_plugins'] ?? [];
	}
}

if (!function_exists('wp_get_active_network_plugins')) {
	function wp_get_active_network_plugins()
	{
		return $GLOBALS['crawlwp_test_state']['active_network_plugins'] ?? [];
	}
}

if (!function_exists('get_plugins')) {
	function get_plugins()
	{
		return $GLOBALS['crawlwp_test_state']['installed_plugins'] ?? [];
	}
}

if (!function_exists('get_plugin_data')) {
	function get_plugin_data($plugin_file, $markup = true, $translate = true)
	{
		return $GLOBALS['crawlwp_test_state']['plugin_data'][$plugin_file] ?? ['Version' => '3.0'];
	}
}

if (!function_exists('wp_nonce_url')) {
	function wp_nonce_url($actionurl, $action = -1, $name = '_wpnonce')
	{
		return $actionurl . '&' . $name . '=mocknonce';
	}
}

if (!function_exists('self_admin_url')) {
	function self_admin_url($path = '', $scheme = 'admin')
	{
		return 'https://example.test/wp-admin/' . ltrim($path, '/');
	}
}

if (!function_exists('admin_url')) {
	function admin_url($path = '', $scheme = 'admin')
	{
		return 'https://example.test/wp-admin/' . ltrim($path, '/');
	}
}

if (!function_exists('add_query_arg')) {
	function add_query_arg(...$args)
	{
		if (is_array($args[0])) {
			$params = $args[0];
			$url = $args[1] ?? '';
		} else {
			$params = [$args[0] => $args[1]];
			$url = $args[2] ?? '';
		}

		$parts = explode('#', $url, 2);
		$base = $parts[0];
		$fragment = isset($parts[1]) ? '#' . $parts[1] : '';

		$sep = strpos($base, '?') !== false ? '&' : '?';
		return $base . $sep . http_build_query($params) . $fragment;
	}
}

if (!function_exists('check_ajax_referer')) {
	function check_ajax_referer($action = -1, $query_arg = false, $die = true)
	{
		return true;
	}
}

if (!function_exists('wp_send_json_success')) {
	function wp_send_json_success($data = null, $status_code = null, $options = 0)
	{
		throw new \Exception('wp_send_json_success: ' . json_encode($data));
	}
}

if (!function_exists('wp_send_json_error')) {
	function wp_send_json_error($data = null, $status_code = null, $options = 0)
	{
		throw new \Exception('wp_send_json_error: ' . json_encode($data));
	}
}

if (!function_exists('wp_script_is')) {
	function wp_script_is($handle, $list = 'enqueued')
	{
		return !empty($GLOBALS['crawlwp_test_state']['scripts'][$list][$handle]);
	}
}

if (!function_exists('wp_enqueue_script')) {
	function wp_enqueue_script($handle, $src = '', $deps = [], $ver = false, $args = [])
	{
		$GLOBALS['crawlwp_test_state']['scripts']['enqueued'][$handle] = compact('handle', 'src', 'deps', 'ver', 'args');
	}
}

if (!function_exists('register_activation_hook')) {
	function register_activation_hook($file, $callback)
	{
		$GLOBALS['crawlwp_test_state']['activation_hooks'][$file] = $callback;
	}
}

if (!function_exists('switch_to_blog')) {
	function switch_to_blog($new_blog_id)
	{
		$GLOBALS['crawlwp_test_state']['current_blog_id'] = $new_blog_id;
		return true;
	}
}

if (!function_exists('restore_current_blog')) {
	function restore_current_blog()
	{
		$GLOBALS['crawlwp_test_state']['current_blog_id'] = 1;
		return true;
	}
}

if (!function_exists('get_sites')) {
	function get_sites($args = [])
	{
		return $GLOBALS['crawlwp_test_state']['sites'] ?? [];
	}
}

if (!function_exists('is_plugin_active_for_network')) {
	function is_plugin_active_for_network($plugin)
	{
		return !empty($GLOBALS['crawlwp_test_state']['active_network_plugins_map'][$plugin]);
	}
}

if (!function_exists('pll_get_post_language')) {
	function pll_get_post_language($post_id)
	{
		return $GLOBALS['crawlwp_test_state']['polylang']['post_lang'][$post_id] ?? 'en';
	}
}

if (!function_exists('pll_get_post_translations')) {
	function pll_get_post_translations($post_id)
	{
		return $GLOBALS['crawlwp_test_state']['polylang']['post_translations'][$post_id] ?? [];
	}
}

if (!function_exists('pll_get_term_translations')) {
	function pll_get_term_translations($term_id)
	{
		return $GLOBALS['crawlwp_test_state']['polylang']['term_translations'][$term_id] ?? [];
	}
}

if (!function_exists('wp_unslash')) {
	function wp_unslash($value)
	{
		return is_array($value) ? array_map('wp_unslash', $value) : (is_string($value) ? stripslashes($value) : $value);
	}
}

if (!function_exists('sanitize_key')) {
	function sanitize_key($key)
	{
		$raw_key = $key;
		$key = strtolower((string) $key);
		$key = preg_replace('/[^a-z0-9_\-]/', '', $key);
		return $key;
	}
}

if (!function_exists('get_the_terms')) {
	function get_the_terms($post, $taxonomy)
	{
		return $GLOBALS['crawlwp_test_state']['terms'][$taxonomy] ?? [];
	}
}

if (!function_exists('wp_get_attachment_image_url')) {
	function wp_get_attachment_image_url($attachment_id, $size = 'thumbnail', $icon = false)
	{
		return 'https://example.test/uploads/' . $attachment_id . '.jpg';
	}
}

if (!function_exists('wp_get_attachment_url')) {
	function wp_get_attachment_url($attachment_id)
	{
		return $attachment_id > 0 ? 'https://example.test/uploads/' . $attachment_id . '.jpg' : false;
	}
}

if (!function_exists('strip_shortcodes')) {
	function strip_shortcodes($content)
	{
		return (string) $content;
	}
}

if (!function_exists('post_password_required')) {
	function post_password_required($post = null)
	{
		return false;
	}
}

if (!function_exists('get_post_thumbnail_id')) {
	function get_post_thumbnail_id($post = null)
	{
		return 0;
	}
}

// Classes under test. Loaded explicitly so the suite never depends on vendor/.
require_once CRAWLWP_TESTS_PLUGIN_DIR . '/src/SEOCore/TitleMeta/Variables.php';
require_once CRAWLWP_TESTS_PLUGIN_DIR . '/src/SEOCore/Importer/TokenMapper.php';
require_once CRAWLWP_TESTS_PLUGIN_DIR . '/src/SEOCore/Schema/Graph.php';
require_once CRAWLWP_TESTS_PLUGIN_DIR . '/src/SEOCore/InternalLinks/InternalLinksUpsell.php';
require_once CRAWLWP_TESTS_PLUGIN_DIR . '/src/SEOCore/EmailReports/EmailReportsUpsell.php';
require_once CRAWLWP_TESTS_PLUGIN_DIR . '/src/SEOCore/MetaBox/MetaFields.php';
require_once CRAWLWP_TESTS_PLUGIN_DIR . '/src/SEOCore/SettingsFieldsTrait.php';
require_once CRAWLWP_TESTS_PLUGIN_DIR . '/src/SEOCore/SitemapSettings/SitemapSettings.php';
require_once CRAWLWP_TESTS_PLUGIN_DIR . '/src/SEOCore/SitemapSettings/SitemapStylesheet.php';
require_once CRAWLWP_TESTS_PLUGIN_DIR . '/src/Utils.php';
require_once CRAWLWP_TESTS_PLUGIN_DIR . '/src/SEOCore/FeatureGate/FeatureGate.php';
require_once CRAWLWP_TESTS_PLUGIN_DIR . '/src/SEOCore/Notifications/Notifications.php';
require_once CRAWLWP_TESTS_PLUGIN_DIR . '/src/SEOCore/Code/CodeSettings.php';
require_once CRAWLWP_TESTS_PLUGIN_DIR . '/src/SEOCore/MetaBox/FieldProcessor.php';
require_once CRAWLWP_TESTS_PLUGIN_DIR . '/src/SEOCore/MetaBox/SeoSignals.php';
require_once CRAWLWP_TESTS_PLUGIN_DIR . '/src/SEOCore/MetaBox/Assets.php';
require_once CRAWLWP_TESTS_PLUGIN_DIR . '/src/SEOCore/Integrations/Elementor.php';

if (! class_exists('WC_Product')) {
	class WC_Product {
		public int $id = 0;
		public string $price = '';
		public string $sku = '';
		public string $stock_status = 'instock';
		public float $average_rating = 0.0;
		public int $review_count = 0;
		public bool $on_sale = false;
		public ?object $date_on_sale_from = null;
		public ?object $date_on_sale_to = null;
		public string $type = 'simple';
		public array $variation_prices = [];
		public array $children = [];

		public function get_id(): int { return $this->id; }
		public function get_price(): string { return $this->price; }
		public function get_sku(): string { return $this->sku; }
		public function get_stock_status(): string { return $this->stock_status; }
		public function get_average_rating(): float { return $this->average_rating; }
		public function get_review_count(): int { return $this->review_count; }
		public function is_on_sale(): bool { return $this->on_sale; }
		public function get_date_on_sale_from(): ?object { return $this->date_on_sale_from; }
		public function get_date_on_sale_to(): ?object { return $this->date_on_sale_to; }
		public function is_type(string $type): bool { return $this->type === $type; }
		public function get_variation_price(string $type = 'min', bool $for_display = false) {
			return $this->variation_prices[$type] ?? '';
		}
		public function get_children(): array { return $this->children; }
	}
}

if (! function_exists('wc_get_product')) {
	function wc_get_product($the_product = false) {
		if ($the_product instanceof WC_Product) {
			return $the_product;
		}
		$id = $the_product instanceof \WP_Post ? $the_product->ID : (int) $the_product;
		return $GLOBALS['crawlwp_test_state']['wc_products'][$id] ?? null;
	}
}

if (! function_exists('get_woocommerce_currency')) {
	function get_woocommerce_currency(): string {
		return $GLOBALS['crawlwp_test_state']['wc_currency'] ?? 'USD';
	}
}

if (! function_exists('wc_get_price_including_tax')) {
	function wc_get_price_including_tax($product, array $args = []) {
		$price = $args['price'] ?? ($product ? $product->get_price() : 0);
		$tax_rate = $GLOBALS['crawlwp_test_state']['wc_tax_rate'] ?? 1.2;
		return is_numeric($price) ? (float) $price * $tax_rate : $price;
	}
}

if (! function_exists('wc_get_product_stock_status_options')) {
	function wc_get_product_stock_status_options(): array {
		return [
			'instock'     => 'In stock',
			'outofstock'  => 'Out of stock',
			'onbackorder' => 'On backorder',
		];
	}
}

if (! function_exists('wc_string_to_timestamp')) {
	function wc_string_to_timestamp(string $time_string, ?int $from_timestamp = null): int {
		return strtotime($time_string, $from_timestamp ?? time()) ?: time();
	}
}

if (! class_exists('Elementor\Controls_Manager')) {
	eval('namespace Elementor; class Controls_Manager {
		public const TAB_SETTINGS = "settings";
		public const TEXT         = "text";
		public const TEXTAREA     = "textarea";
		public const SELECT       = "select";
		public const SELECT2      = "select2";
		public const URL          = "url";
		public const SWITCHER     = "switcher";
		public const MEDIA        = "media";
		public const RAW_HTML     = "raw_html";
		public const CODE         = "code";
		public const HIDDEN       = "hidden";
		public const HEADING      = "heading";
		public const DIVIDER      = "divider";
	}');
}

if (! class_exists('Elementor\Core\DocumentTypes\Document')) {
	eval('namespace Elementor\Core\DocumentTypes; class Document {
		public array $controls = [];
		public array $sections = [];
		public int $id = 0;
		public function get_main_id(): int { return $this->id; }
		public function start_controls_section(string $section_id, array $args = []): void {
			$this->sections[$section_id] = $args;
		}
		public function end_controls_section(): void {}
		public function add_control(string $id, array $args = []): void {
			$this->controls[$id] = $args;
		}
	}');
}

$crawlwp_pro_autoload = dirname(CRAWLWP_TESTS_PLUGIN_DIR) . '/mihdan-index-now-pro/Libsodium/vendor/autoload.php';
if (file_exists($crawlwp_pro_autoload)) {
	require_once $crawlwp_pro_autoload;
}
