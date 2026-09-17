<?php

namespace Mihdan\IndexNow\SEOCore\MetaBox;

class MetaFields
{
	public const SEO_TITLE       = '_crawlwp_seo_title';
	public const SEO_DESCRIPTION = '_crawlwp_seo_description';
	public const FOCUS_KEYWORD   = '_crawlwp_focus_keyword';

	public const ROBOTS_INDEX    = '_crawlwp_robots_index';
	public const ROBOTS_FOLLOW   = '_crawlwp_robots_follow';
	public const ROBOTS_ADVANCED = '_crawlwp_robots_advanced';
	public const CANONICAL_URL   = '_crawlwp_canonical_url';
	public const MAX_SNIPPET     = '_crawlwp_max_snippet';
	public const MAX_IMAGE       = '_crawlwp_max_image_preview';

	public const REDIRECT_URL    = '_crawlwp_redirect_url';
	public const REDIRECT_TYPE   = '_crawlwp_redirect_type';

	public const OG_TITLE       = '_crawlwp_og_title';
	public const OG_DESCRIPTION = '_crawlwp_og_description';
	public const OG_IMAGE       = '_crawlwp_og_image';
	public const OG_IMAGE_ALT   = '_crawlwp_og_image_alt';
	public const OG_SYNC        = '_crawlwp_og_sync';

	public const X_TITLE       = '_crawlwp_x_title';
	public const X_DESCRIPTION = '_crawlwp_x_description';
	public const X_IMAGE       = '_crawlwp_x_image';
	public const X_CARD_TYPE   = '_crawlwp_x_card_type';
	public const X_CREATOR     = '_crawlwp_x_creator';
	public const X_SYNC        = '_crawlwp_x_sync';

	public const SCHEMA_PAGE_TYPE    = '_crawlwp_schema_page_type';
	public const SCHEMA_ARTICLE_TYPE = '_crawlwp_schema_article_type';
	public const SCHEMA_TYPE         = '_crawlwp_schema_type'; // legacy — kept for migration
	public const SCHEMA_HEADLINE     = '_crawlwp_schema_headline';
	public const SCHEMA_BREADCRUMB   = '_crawlwp_schema_breadcrumb';
	public const SCHEMA_SECTION      = '_crawlwp_schema_section';
	public const SCHEMA_CUSTOM       = '_crawlwp_schema_custom';

	public const PRIMARY_CATEGORY = '_crawlwp_primary_category';
	public const CORNERSTONE      = '_crawlwp_cornerstone';

	/** Cached SEO score (0–100 float stored as string). Updated on each metabox/inline save. */
	public const SEO_SCORE = '_crawlwp_seo_score';

	public const NONCE_ACTION = 'crawlwp_seo_metabox';
	public const NONCE_NAME   = '_crawlwp_seo_nonce';

	/**
	 * Hidden tracker rendered by the SEO metabox (and any other full SEO form).
	 *
	 * Checkbox and multi-select fields signal "off" by being absent from the
	 * request, so they can only be reset when we know the form that renders them
	 * was actually submitted. Without this marker a third-party programmatic
	 * wp_update_post()/save_post would wipe them.
	 */
	public const CHECKBOX_TRACKER = '_crawlwp_seo_fields_present';

	/**
	 * Meta keys that are always stored, even when empty.
	 *
	 * Keeping a row with an empty string lets the Bulk Editor find posts with no
	 * SEO title/description through an indexed `meta_value = ''` comparison
	 * instead of a NOT EXISTS sub-query.
	 *
	 * @var string[]
	 */
	public const ALWAYS_STORED = [
		self::SEO_TITLE,
		self::SEO_DESCRIPTION,
	];

	private static array $text_fields = [
		self::SEO_TITLE,
		self::FOCUS_KEYWORD,
		self::OG_TITLE,
		self::OG_IMAGE_ALT,
		self::X_TITLE,
		self::X_CREATOR,
		self::SCHEMA_HEADLINE,
		self::SCHEMA_BREADCRUMB,
		self::SCHEMA_SECTION,
	];

	private static array $textarea_fields = [
		self::SEO_DESCRIPTION,
		self::OG_DESCRIPTION,
		self::X_DESCRIPTION,
	];

	private static array $url_fields = [
		self::CANONICAL_URL,
		self::REDIRECT_URL,
	];

	private static array $page_types = [
		'WebPage',
		'ItemPage',
		'AboutPage',
		'FAQPage',
		'QAPage',
		'ProfilePage',
		'ContactPage',
		'MedicalWebPage',
		'CollectionPage',
		'RealEstateListing',
		'none',
	];

	private static array $article_types = [
		'Article',
		'BlogPosting',
		'SocialMediaPosting',
		'NewsArticle',
		'AdvertiserContentArticle',
		'SatiricalArticle',
		'ScholarlyArticle',
		'TechArticle',
		'Report',
		'none',
	];

	/**
	 * Select fields => [allowed values, fallback stored when the submitted value is not allowed].
	 * Must mirror the <option> values in views/metabox-template.php.
	 */
	private static function select_fields(): array
	{
		return [
			self::ROBOTS_INDEX        => [['index', 'noindex'], 'index'],
			self::ROBOTS_FOLLOW       => [['follow', 'nofollow'], 'follow'],
			self::MAX_SNIPPET         => [['', 'none', '160'], ''],
			self::MAX_IMAGE           => [['large', 'standard', 'none'], 'large'],
			self::X_CARD_TYPE         => [['summary_large_image', 'summary'], 'summary_large_image'],
			self::SCHEMA_TYPE         => [array_merge(self::$page_types, self::$article_types), ''],
			self::SCHEMA_PAGE_TYPE    => [self::$page_types, 'WebPage'],
			self::SCHEMA_ARTICLE_TYPE => [self::$article_types, 'Article'],
			self::REDIRECT_TYPE       => [['301', '302', '307', '410', '451'], '301'],
		];
	}

	private static array $robots_advanced_values = [
		'noimageindex',
		'noarchive',
		'nosnippet',
		'notranslate',
	];

	private static array $checkbox_fields = [
		self::OG_SYNC,
		self::X_SYNC,
	];

	private static array $image_fields = [
		self::OG_IMAGE,
		self::X_IMAGE,
	];

	private static array $array_fields = [
		self::ROBOTS_ADVANCED,
	];

	/**
	 * Field definitions handed to {@see FieldProcessor::process()}.
	 *
	 * @param bool $form_rendered Whether the request came from a form that
	 *                            rendered the checkbox / multi-select fields
	 *                            (see self::CHECKBOX_TRACKER).
	 * @return array<string, array<string, mixed>>
	 */
	public static function field_definitions(bool $form_rendered = false): array
	{
		$fields = [];

		foreach (self::$text_fields as $key) {
			$fields[$key] = ['type' => FieldProcessor::TYPE_TEXT];
		}

		foreach (self::$textarea_fields as $key) {
			$fields[$key] = ['type' => FieldProcessor::TYPE_TEXTAREA];
		}

		foreach (self::$url_fields as $key) {
			$fields[$key] = ['type' => FieldProcessor::TYPE_URL];
		}

		foreach (self::select_fields() as $key => [$allowed, $fallback]) {
			$fields[$key] = [
				'type'     => FieldProcessor::TYPE_SELECT,
				'allowed'  => $allowed,
				'fallback' => $fallback,
			];
		}

		foreach (self::$image_fields as $key) {
			$fields[$key] = ['type' => FieldProcessor::TYPE_INT];
		}

		$fields[self::PRIMARY_CATEGORY] = ['type' => FieldProcessor::TYPE_INT];
		$fields[self::SCHEMA_CUSTOM]    = ['type' => FieldProcessor::TYPE_JSON];

		/*
		 * Checkbox and robots-advanced fields are only touched when the SEO form
		 * was rendered — otherwise an unrelated programmatic save would reset
		 * them to their "off" state.
		 */
		foreach (self::$checkbox_fields as $key) {
			$fields[$key] = [
				'type'   => FieldProcessor::TYPE_CHECKBOX,
				'always' => $form_rendered,
			];
		}

		foreach (self::$array_fields as $key) {
			$fields[$key] = [
				'type'    => FieldProcessor::TYPE_MULTI_SELECT,
				'allowed' => self::$robots_advanced_values,
				'always'  => $form_rendered,
			];
		}

		return $fields;
	}

	public static function save(int $post_id): void
	{
		if (
			! isset($_POST[self::NONCE_NAME]) ||
			! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)
		) {
			return;
		}

		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		/*
		 * Revisions are deliberately skipped and the SEO meta is NOT revisioned.
		 *
		 * WordPress' `_wp_post_revision_fields` only understands columns of the
		 * posts table — post meta is not part of a revision, so tracking these
		 * fields would mean shadow-copying every key onto each revision object
		 * and re-applying it on `wp_restore_post_revision`. For this field set
		 * that is riskier than it is useful: the values include serialized
		 * arrays (robots advanced, schema extra), machine-managed caches (the SEO
		 * score and signal caches) and checkbox fields whose "off" state is an
		 * absent request key, so a partially populated revision would silently
		 * clear real values on restore. Autosave revisions would also multiply
		 * the meta rows on every keystroke-triggered save. Until there is a
		 * dedicated diff/restore UI for SEO fields, bailing out here keeps the
		 * stored values authoritative.
		 */
		if (wp_is_post_revision($post_id)) {
			return;
		}

		if (! current_user_can('edit_post', $post_id)) {
			return;
		}

		$form_rendered = isset($_POST[self::CHECKBOX_TRACKER]);
		$values        = FieldProcessor::process(self::field_definitions($form_rendered), $_POST);

		if (
			isset($values[self::REDIRECT_URL]) &&
			$values[self::REDIRECT_URL] !== '' &&
			self::is_external_url($values[self::REDIRECT_URL]) &&
			! self::can_redirect_externally($post_id)
		) {
			$values[self::REDIRECT_URL] = '';
		}

		foreach ($values as $key => $value) {
			update_post_meta($post_id, $key, $value);
		}

		self::store_defaults($post_id);
	}

	/**
	 * Make sure every self::ALWAYS_STORED key has a row, so filters can use an
	 * indexed `= ''` comparison instead of a NOT EXISTS sub-query.
	 */
	public static function store_defaults(int $post_id): void
	{
		foreach (self::ALWAYS_STORED as $key) {
			if (! metadata_exists('post', $post_id, $key)) {
				add_post_meta($post_id, $key, '', true);
			}
		}
	}

	/**
	 * Store an optional text value, keeping an empty row for the keys the Bulk
	 * Editor filters rely on and deleting the row for every other key.
	 */
	public static function save_optional(int $post_id, string $key, string $value): void
	{
		if ($value === '' && ! in_array($key, self::ALWAYS_STORED, true)) {
			delete_post_meta($post_id, $key);

			return;
		}

		update_post_meta($post_id, $key, $value);
	}

	/**
	 * Parse a comma-separated keywords string into a trimmed, non-empty array of keywords.
	 *
	 * @return string[]
	 */
	public static function parse_keywords(string $raw): array
	{
		if ($raw === '') {
			return [];
		}

		$parts = array_map('trim', explode(',', $raw));

		return array_values(array_filter($parts, static function ($v) {
			return $v !== '';
		}));
	}

	/**
	 * Comma-separated focus keywords; first is the primary.
	 *
	 * @return string[]
	 */
	public static function keywords(int $post_id): array
	{
		return self::parse_keywords((string) self::get($post_id, self::FOCUS_KEYWORD, ''));
	}

	/**
	 * Returns an absolute http(s) URL or a site-relative path (single leading '/'), else ''.
	 */
	public static function sanitize_url($raw): string
	{
		if (! is_string($raw)) {
			return '';
		}

		$url = esc_url_raw(trim($raw));

		if ($url === '') {
			return '';
		}

		if ($url[0] === '/') {
			return (isset($url[1]) && $url[1] === '/') ? '' : $url;
		}

		$parts = wp_parse_url($url);

		if (
			! is_array($parts) ||
			empty($parts['scheme']) ||
			empty($parts['host']) ||
			! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
		) {
			return '';
		}

		return $url;
	}

	/**
	 * True when the (already sanitized) URL points to a host other than the site's own.
	 */
	public static function is_external_url(string $url): bool
	{
		if ($url === '' || $url[0] === '/') {
			return false;
		}

		$host = wp_parse_url($url, PHP_URL_HOST);
		$home = wp_parse_url(home_url(), PHP_URL_HOST);

		if (! is_string($host) || ! is_string($home)) {
			return true;
		}

		return strtolower($host) !== strtolower($home);
	}

	public static function can_redirect_externally(int $post_id): bool
	{
		/**
		 * Filters whether the current user may store a redirect to an external host.
		 *
		 * @param bool $allowed Defaults to current_user_can('manage_options').
		 * @param int  $post_id The post being saved.
		 */
		return (bool) apply_filters('crawlwp_allow_external_redirects', current_user_can('manage_options'), $post_id);
	}

	public static function get(int $post_id, string $key, $default = '')
	{
		$value = get_post_meta($post_id, $key, true);

		return $value !== '' ? $value : $default;
	}

	public static function get_all(int $post_id): array
	{
		return [
			'seo_title'           => self::get($post_id, self::SEO_TITLE),
			'seo_description'     => self::get($post_id, self::SEO_DESCRIPTION),
			'focus_keyword'       => self::get($post_id, self::FOCUS_KEYWORD),
			'robots_index'        => self::get($post_id, self::ROBOTS_INDEX, 'index'),
			'robots_follow'       => self::get($post_id, self::ROBOTS_FOLLOW, 'follow'),
			'robots_advanced'     => self::get($post_id, self::ROBOTS_ADVANCED, []),
			'canonical_url'       => self::get($post_id, self::CANONICAL_URL),
			'max_snippet'         => self::get($post_id, self::MAX_SNIPPET, ''),
			'max_image'           => self::get($post_id, self::MAX_IMAGE, 'large'),
			'og_title'            => self::get($post_id, self::OG_TITLE),
			'og_description'      => self::get($post_id, self::OG_DESCRIPTION),
			'og_image'            => self::get($post_id, self::OG_IMAGE, 0),
			'og_image_alt'        => self::get($post_id, self::OG_IMAGE_ALT),
			'og_sync'             => self::get($post_id, self::OG_SYNC, '1'),
			'x_title'             => self::get($post_id, self::X_TITLE),
			'x_description'       => self::get($post_id, self::X_DESCRIPTION),
			'x_image'             => self::get($post_id, self::X_IMAGE, 0),
			'x_card_type'         => self::get($post_id, self::X_CARD_TYPE, 'summary_large_image'),
			'x_creator'           => self::get($post_id, self::X_CREATOR),
			'x_sync'              => self::get($post_id, self::X_SYNC, '1'),
			'schema_page_type'    => self::get($post_id, self::SCHEMA_PAGE_TYPE, 'WebPage'),
			'schema_article_type' => self::get($post_id, self::SCHEMA_ARTICLE_TYPE, 'Article'),
			'schema_type'         => self::get($post_id, self::SCHEMA_TYPE, ''), // legacy
			'schema_headline'     => self::get($post_id, self::SCHEMA_HEADLINE),
			'schema_breadcrumb'   => self::get($post_id, self::SCHEMA_BREADCRUMB),
			'schema_section'      => self::get($post_id, self::SCHEMA_SECTION),
			'schema_custom'       => self::get($post_id, self::SCHEMA_CUSTOM),
			'primary_category'    => (int) self::get($post_id, self::PRIMARY_CATEGORY, 0),
			'redirect_url'        => self::get($post_id, self::REDIRECT_URL),
			'redirect_type'       => self::get($post_id, self::REDIRECT_TYPE, '301'),
		];
	}
}
