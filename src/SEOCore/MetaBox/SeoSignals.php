<?php

namespace Mihdan\IndexNow\SEOCore\MetaBox;

use Mihdan\IndexNow\SEOCore\SocialSettings\SocialSettings;
use Mihdan\IndexNow\SEOCore\TitleMeta\Entities;
use Mihdan\IndexNow\SEOCore\TitleMeta\Options;
use Mihdan\IndexNow\SEOCore\TitleMeta\Variables;

/**
 * Builds the per-post "SEO signals" shown in the post list SEO column.
 *
 * Unlike the numeric score (which is a rough length heuristic), every signal
 * here is evaluated against what search engines will *actually* receive:
 * the title/description are resolved through the same Title & Meta templates
 * and variables the frontend uses, robots take the global entity defaults into
 * account, and the social image follows the exact frontend fallback chain.
 *
 * Signals:
 *  T — Title            resolved <title> length
 *  D — Description      resolved meta description length
 *  K — Keyword          focus keyword presence in title / slug / content
 *  I — Indexing         indexable? (status, robots, canonical, redirect)
 *  F — Following        follow / nofollow + extra crawler directives
 *  S — Social           social image availability and size
 *
 * Each signal is an array:
 *   id, letter, label, state (good|warn|bad|neutral), summary, detail (optional).
 *
 * Third parties (e.g. the Pro plugin) can add or alter signals with the
 * `crawlwp_post_seo_signals` filter.
 */
class SeoSignals
{
	public const GOOD    = 'good';
	public const WARN    = 'warn';
	public const BAD     = 'bad';
	public const NEUTRAL = 'neutral';

	/** Title length thresholds (characters). */
	public const TITLE_MIN = 30;
	public const TITLE_MAX = 60;

	/** Description length thresholds (characters). */
	public const DESC_MIN = 50;
	public const DESC_MAX = 160;

	/** Recommended minimum social image size. */
	private const SOCIAL_MIN_WIDTH  = 1200;
	private const SOCIAL_MIN_HEIGHT = 630;

	/**
	 * Cached signal list, refreshed on save_post.
	 *
	 * Building the signals scans the post content (strip_shortcodes +
	 * wp_strip_all_tags for the keyword check), which is far too expensive to
	 * repeat for every row of a post list table.
	 */
	public const CACHE_META = '_crawlwp_seo_signals';

	/** Bumped whenever the cached payload shape or the signal logic changes. */
	private const CACHE_VERSION = 2;

	/**
	 * Every signal for a post, in display order.
	 *
	 * @return array<int, array>
	 */
	public static function for_post(\WP_Post $post): array
	{
		$entity = Entities::post_type_key($post->post_type);

		return self::build($post, $entity);
	}

	/**
	 * Signals for a post, served from the post meta cache when possible.
	 *
	 * A stale cache is detected through a fingerprint of everything the signals
	 * depend on outside the post meta we write ourselves (locale, modification
	 * time, the global entity options), so the list table
	 * never shows values from before a settings change.
	 *
	 * @return array<int, array>
	 */
	public static function cached(\WP_Post $post): array
	{
		$entity      = Entities::post_type_key($post->post_type);
		$fingerprint = self::fingerprint($post, $entity);

		/**
		 * Filters whether the SEO signals may be served from the post meta cache.
		 *
		 * @param bool     $enabled Defaults to true.
		 * @param \WP_Post $post    The post being rendered.
		 */
		if (apply_filters('crawlwp_seo_signals_cache_enabled', true, $post)) {
			$cached = get_post_meta($post->ID, self::CACHE_META, true);

			if (
				is_array($cached) &&
				isset($cached['fingerprint'], $cached['signals']) &&
				$cached['fingerprint'] === $fingerprint &&
				is_array($cached['signals'])
			) {
				return $cached['signals'];
			}
		}

		$signals = self::build($post, $entity);

		update_post_meta($post->ID, self::CACHE_META, [
			'fingerprint' => $fingerprint,
			'signals'     => $signals,
		]);

		return $signals;
	}

	/**
	 * Recompute and store the cached signals for a post.
	 */
	public static function persist(int $post_id): void
	{
		$post = get_post($post_id);

		if (! $post instanceof \WP_Post) {
			return;
		}

		$entity = Entities::post_type_key($post->post_type);

		update_post_meta($post_id, self::CACHE_META, [
			'fingerprint' => self::fingerprint($post, $entity),
			'signals'     => self::build($post, $entity),
		]);
	}

	/**
	 * Drop the cached signals so the next read recomputes them.
	 */
	public static function flush(int $post_id): void
	{
		delete_post_meta($post_id, self::CACHE_META);
	}

	/**
	 * Identifies the inputs the signals were built from.
	 */
	private static function fingerprint(\WP_Post $post, string $entity): string
	{
		$parts = [
			(string) self::CACHE_VERSION,
			function_exists('determine_locale') ? determine_locale() : get_locale(),
			(string) $post->post_modified_gmt,
			(string) $post->post_status,
			(string) get_option('blog_public', 1),
			(string) wp_json_encode(Options::all($entity)),
		];

		return md5(implode('|', $parts));
	}

	/**
	 * Build the signal list from scratch.
	 *
	 * @return array<int, array>
	 */
	private static function build(\WP_Post $post, string $entity): array
	{
		$title = self::resolved_title($post, $entity);

		$signals = [
			self::title_signal($post, $entity, $title),
			self::description_signal($post, $entity),
			self::keyword_signal($post, $title['text']),
			self::indexing_signal($post, $entity),
			self::follow_signal($post, $entity),
			self::social_signal($post, $entity),
		];

		/**
		 * Filter the SEO signals shown for a post in the list table.
		 *
		 * Each signal is an array with keys: id, letter, label, state
		 * (good|warn|bad|neutral), summary and detail.
		 *
		 * @param array    $signals The signal list.
		 * @param \WP_Post $post    The post being rendered.
		 */
		$signals = (array) apply_filters('crawlwp_post_seo_signals', $signals, $post);

		return array_values(array_filter($signals, static function ($signal) {
			return is_array($signal) && isset($signal['letter'], $signal['state']);
		}));
	}

	/**
	 * Human readable label for a state.
	 */
	public static function state_label(string $state): string
	{
		switch ($state) {
			case self::GOOD:
				return __('Good', 'mihdan-index-now');
			case self::WARN:
				return __('Needs attention', 'mihdan-index-now');
			case self::BAD:
				return __('Problem', 'mihdan-index-now');
			default:
				return __('Not applicable', 'mihdan-index-now');
		}
	}

	// -------------------------------------------------------------------------
	// Individual signals
	// -------------------------------------------------------------------------

	private static function title_signal(\WP_Post $post, string $entity, array $title): array
	{
		$length = mb_strlen($title['text']);

		if ($length === 0) {
			$state   = self::BAD;
			$summary = __('No title will be output.', 'mihdan-index-now');
		} elseif ($length < self::TITLE_MIN) {
			$state = self::WARN;
			/* translators: %d: character count */
			$summary = sprintf(__('Too short (%d characters).', 'mihdan-index-now'), $length);
		} elseif ($length > self::TITLE_MAX) {
			$state = self::WARN;
			/* translators: %d: character count */
			$summary = sprintf(__('Too long (%d characters) — may be cut off in results.', 'mihdan-index-now'), $length);
		} else {
			$state = self::GOOD;
			/* translators: %d: character count */
			$summary = sprintf(__('Good length (%d characters).', 'mihdan-index-now'), $length);
		}

		$source = $title['custom']
			? __('Custom SEO title.', 'mihdan-index-now')
			: sprintf(
				/* translators: %s: post type name */
				__('Generated from the %s title template.', 'mihdan-index-now'),
				self::post_type_label($post)
			);

		return self::signal('title', 'T', __('Title', 'mihdan-index-now'), $state, $summary, self::quote($title['text']) . ' ' . $source);
	}

	private static function description_signal(\WP_Post $post, string $entity): array
	{
		$custom   = (string) MetaFields::get($post->ID, MetaFields::SEO_DESCRIPTION, '');
		$template = $custom !== '' ? $custom : self::template($entity, 'description');
		$text     = trim(Variables::replace($template, ['post' => $post]));
		$length   = mb_strlen($text);

		if ($length === 0) {
			$state   = self::BAD;
			$summary = __('No meta description will be output — search engines will pick their own snippet.', 'mihdan-index-now');
		} elseif ($length < self::DESC_MIN) {
			$state = self::WARN;
			/* translators: %d: character count */
			$summary = sprintf(__('Too short (%d characters).', 'mihdan-index-now'), $length);
		} elseif ($length > self::DESC_MAX) {
			$state = self::WARN;
			/* translators: %d: character count */
			$summary = sprintf(__('Too long (%d characters) — may be cut off in results.', 'mihdan-index-now'), $length);
		} else {
			$state = self::GOOD;
			/* translators: %d: character count */
			$summary = sprintf(__('Good length (%d characters).', 'mihdan-index-now'), $length);
		}

		if ($custom !== '') {
			$source = __('Custom meta description.', 'mihdan-index-now');
		} elseif (strpos($template, 'auto_description') !== false) {
			$source = __('Auto-generated from the excerpt/content.', 'mihdan-index-now');
		} else {
			$source = sprintf(
				/* translators: %s: post type name */
				__('Generated from the %s description template.', 'mihdan-index-now'),
				self::post_type_label($post)
			);
		}

		return self::signal('description', 'D', __('Description', 'mihdan-index-now'), $state, $summary, self::quote($text) . ' ' . $source);
	}

	private static function keyword_signal(\WP_Post $post, string $resolved_title): array
	{
		$keywords = MetaFields::keywords($post->ID);

		if ($keywords === []) {
			return self::signal(
				'keyword',
				'K',
				__('Keyword', 'mihdan-index-now'),
				self::NEUTRAL,
				__('No focus keyword set.', 'mihdan-index-now'),
				__('Set one in the SEO metabox to unlock keyword checks.', 'mihdan-index-now')
			);
		}

		$primary     = $keywords[0];
		$secondaries = array_slice($keywords, 1);

		$needle  = mb_strtolower($primary);
		$content = mb_strtolower(wp_strip_all_tags(strip_shortcodes((string) $post->post_content)));
		$slug    = str_replace('-', ' ', (string) $post->post_name);

		$in_title   = mb_stripos($resolved_title, $needle) !== false;
		$in_slug    = $slug !== '' && mb_stripos($slug, $needle) !== false;
		$in_content = $content !== '' && mb_stripos($content, $needle) !== false;

		$hits    = (int) $in_title + (int) $in_slug + (int) $in_content;
		$missing = [];

		if (! $in_title) {
			$missing[] = __('title', 'mihdan-index-now');
		}
		if (! $in_slug) {
			$missing[] = __('URL slug', 'mihdan-index-now');
		}
		if (! $in_content) {
			$missing[] = __('content', 'mihdan-index-now');
		}

		$sec_total = count($secondaries);
		$sec_found = 0;
		if ($sec_total > 0 && $content !== '') {
			foreach ($secondaries as $sec) {
				if (mb_stripos($content, mb_strtolower($sec)) !== false) {
					$sec_found++;
				}
			}
		}

		if ($sec_total === 0) {
			if ($hits === 3) {
				$state   = self::GOOD;
				$summary = __('Found in the title, URL slug and content.', 'mihdan-index-now');
			} elseif ($hits === 0) {
				$state   = self::BAD;
				$summary = __('Not found in the title, URL slug or content.', 'mihdan-index-now');
			} else {
				$state = self::WARN;
				/* translators: %s: comma-separated list of places */
				$summary = sprintf(__('Missing from the %s.', 'mihdan-index-now'), implode(', ', $missing));
			}

			$detail = self::quote($primary);
		} else {
			if ($hits === 3 && $sec_found === $sec_total) {
				$state   = self::GOOD;
				/* translators: %d: number of secondary keywords */
				$summary = sprintf(__('Found in title, slug and content. All %d secondary keywords found in content.', 'mihdan-index-now'), $sec_total);
			} elseif ($hits === 3) {
				$state   = self::WARN;
				/* translators: 1: number of found secondary keywords, 2: total secondary keywords */
				$summary = sprintf(__('Found in title, slug and content. %1$d of %2$d secondary keywords found in content.', 'mihdan-index-now'), $sec_found, $sec_total);
			} else {
				$state   = ($hits === 0 && $sec_found === 0) ? self::BAD : self::WARN;
				/* translators: %s: comma-separated list of places */
				$summary = sprintf(__('Primary missing from %s.', 'mihdan-index-now'), implode(', ', $missing));
				/* translators: 1: number of found secondary keywords, 2: total secondary keywords */
				$summary .= ' ' . sprintf(__('%1$d of %2$d secondary keywords found in content.', 'mihdan-index-now'), $sec_found, $sec_total);
			}

			/* translators: 1: primary keyword, 2: number of secondary keywords */
			$detail = sprintf(__('%1$s (plus %2$d secondary keywords)', 'mihdan-index-now'), self::quote($primary), $sec_total);
		}

		return self::signal('keyword', 'K', __('Keyword', 'mihdan-index-now'), $state, $summary, $detail);
	}

	private static function indexing_signal(\WP_Post $post, string $entity): array
	{
		$label = __('Indexing', 'mihdan-index-now');

		if ($post->post_status !== 'publish') {
			$status = get_post_status_object($post->post_status);

			return self::signal(
				'indexing',
				'I',
				$label,
				self::NEUTRAL,
				/* translators: %s: post status label */
				sprintf(__('Not indexable while %s.', 'mihdan-index-now'), $status ? mb_strtolower($status->label) : $post->post_status),
				__('Only published, public content can be indexed.', 'mihdan-index-now')
			);
		}

		if ((int) get_option('blog_public', 1) === 0) {
			return self::signal(
				'indexing',
				'I',
				$label,
				self::BAD,
				__('Blocked site-wide.', 'mihdan-index-now'),
				__('"Discourage search engines from indexing this site" is enabled in Settings → Reading.', 'mihdan-index-now')
			);
		}

		$redirect = (string) MetaFields::get($post->ID, MetaFields::REDIRECT_URL, '');

		if ($redirect !== '') {
			return self::signal(
				'indexing',
				'I',
				$label,
				self::WARN,
				__('Redirects to another URL.', 'mihdan-index-now'),
				/* translators: 1: redirect type, 2: destination URL */
				sprintf(__('%1$s redirect to %2$s — this URL itself will not be indexed.', 'mihdan-index-now'), (string) MetaFields::get($post->ID, MetaFields::REDIRECT_TYPE, '301'), $redirect)
			);
		}

		$post_index = (string) MetaFields::get($post->ID, MetaFields::ROBOTS_INDEX, '');
		$noindex    = $post_index !== '' ? $post_index === 'noindex' : self::entity_noindex($entity);

		if ($noindex) {
			return self::signal(
				'indexing',
				'I',
				$label,
				self::BAD,
				__('Excluded from search engines (noindex).', 'mihdan-index-now'),
				$post_index !== ''
					? __('Set per post in the SEO metabox → Advanced.', 'mihdan-index-now')
					/* translators: %s: post type name */
					: sprintf(__('Inherited from the %s defaults in Title & Meta.', 'mihdan-index-now'), self::post_type_label($post))
			);
		}

		$canonical = (string) MetaFields::get($post->ID, MetaFields::CANONICAL_URL, '');

		if ($canonical !== '' && untrailingslashit($canonical) !== untrailingslashit((string) get_permalink($post))) {
			return self::signal(
				'indexing',
				'I',
				$label,
				self::WARN,
				__('Canonical points to a different URL.', 'mihdan-index-now'),
				/* translators: %s: canonical URL */
				sprintf(__('Search engines are told to credit %s instead.', 'mihdan-index-now'), $canonical)
			);
		}

		$detail = post_password_required($post)
			? __('Indexable, but password protected — engines will only see the password form.', 'mihdan-index-now')
			: __('Published, public and set to index.', 'mihdan-index-now');

		return self::signal('indexing', 'I', $label, post_password_required($post) ? self::WARN : self::GOOD, __('Indexable.', 'mihdan-index-now'), $detail);
	}

	private static function follow_signal(\WP_Post $post, string $entity): array
	{
		$post_follow = (string) MetaFields::get($post->ID, MetaFields::ROBOTS_FOLLOW, '');
		$nofollow    = $post_follow !== '' ? $post_follow === 'nofollow' : Options::is_on($entity, 'nofollow');

		$extra = [];

		if (Options::is_on($entity, 'noarchive')) {
			$extra[] = 'noarchive';
		}

		$advanced = MetaFields::get($post->ID, MetaFields::ROBOTS_ADVANCED, []);

		if (is_array($advanced)) {
			foreach ($advanced as $directive) {
				if (in_array($directive, ['noarchive', 'nosnippet', 'noimageindex', 'notranslate'], true)) {
					$extra[] = $directive;
				}
			}
		}

		$extra  = array_values(array_unique($extra));
		$detail = $extra
			/* translators: %s: comma-separated robots directives */
			? sprintf(__('Extra directives: %s.', 'mihdan-index-now'), implode(', ', $extra))
			: __('No extra crawler directives.', 'mihdan-index-now');

		if ($nofollow) {
			return self::signal('follow', 'F', __('Following', 'mihdan-index-now'), self::WARN, __('Links are nofollow — no link equity is passed on.', 'mihdan-index-now'), $detail);
		}

		return self::signal('follow', 'F', __('Following', 'mihdan-index-now'), self::GOOD, __('Links are followed.', 'mihdan-index-now'), $detail);
	}

	private static function social_signal(\WP_Post $post, string $entity): array
	{
		$label = __('Social', 'mihdan-index-now');

		$og_id = (int) MetaFields::get($post->ID, MetaFields::OG_IMAGE, 0);

		if ($og_id > 0 && wp_get_attachment_image_url($og_id, 'full')) {
			return self::image_signal($og_id, __('Custom social image set.', 'mihdan-index-now'), self::GOOD);
		}

		if ((string) Options::get($entity, 'og_image', '') !== '') {
			return self::signal(
				'social',
				'S',
				$label,
				self::WARN,
				__('Using the global default social image.', 'mihdan-index-now'),
				__('Set a featured image or a custom social image so shares show this post’s own visual.', 'mihdan-index-now')
			);
		}

		$thumb_id = (int) get_post_thumbnail_id($post);

		if ($thumb_id > 0) {
			return self::image_signal($thumb_id, __('Featured image will be used for shares.', 'mihdan-index-now'), self::GOOD);
		}

		if ((int) SocialSettings::get('social_image_fallback', 0) > 0) {
			return self::signal(
				'social',
				'S',
				$label,
				self::WARN,
				__('Using the site-wide fallback social image.', 'mihdan-index-now'),
				__('Set a featured image or a custom social image so shares show this post’s own visual.', 'mihdan-index-now')
			);
		}

		return self::signal(
			'social',
			'S',
			$label,
			self::BAD,
			__('No social image — shares will have no preview image.', 'mihdan-index-now'),
			__('Add a featured image or pick one under SEO metabox → Social.', 'mihdan-index-now')
		);
	}

	/**
	 * Grade an attachment used as the social image by its dimensions.
	 */
	private static function image_signal(int $attachment_id, string $summary, string $state): array
	{
		$meta   = wp_get_attachment_metadata($attachment_id);
		$detail = '';

		if (is_array($meta) && isset($meta['width'], $meta['height'])) {
			$width  = (int) $meta['width'];
			$height = (int) $meta['height'];

			if ($width < self::SOCIAL_MIN_WIDTH || $height < self::SOCIAL_MIN_HEIGHT) {
				$state = self::WARN;
				/* translators: 1: width, 2: height, 3: minimum width, 4: minimum height */
				$detail = sprintf(__('%1$d × %2$d px is smaller than the recommended %3$d × %4$d px.', 'mihdan-index-now'), $width, $height, self::SOCIAL_MIN_WIDTH, self::SOCIAL_MIN_HEIGHT);
			} else {
				/* translators: 1: width, 2: height */
				$detail = sprintf(__('%1$d × %2$d px — meets the recommended size.', 'mihdan-index-now'), $width, $height);
			}
		}

		return self::signal('social', 'S', __('Social', 'mihdan-index-now'), $state, $summary, $detail);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * The effective <title> for a post, resolved the same way the frontend does.
	 *
	 * @return array{text: string, custom: bool}
	 */
	private static function resolved_title(\WP_Post $post, string $entity): array
	{
		$custom   = (string) MetaFields::get($post->ID, MetaFields::SEO_TITLE, '');
		$template = $custom !== '' ? $custom : self::template($entity, 'title');

		if ($template === '') {
			$template = '{{ post.title }} {{ sep }} {{ site.title }}';
		}

		return [
			'text'   => trim(Variables::replace($template, ['post' => $post])),
			'custom' => $custom !== '',
		];
	}

	/**
	 * Stored Title & Meta template for an entity field, falling back to the
	 * registered default.
	 */
	private static function template(string $entity, string $field): string
	{
		return (string) Options::get($entity, $field, Entities::default_value($entity, $field));
	}

	/**
	 * Whether the entity (post type) defaults mark content as noindex.
	 */
	private static function entity_noindex(string $entity): bool
	{
		$stored = Options::all($entity);

		if (isset($stored['noindex']) && $stored['noindex'] !== '') {
			return $stored['noindex'] === 'on';
		}

		return Entities::default_value($entity, 'noindex', 'off') === 'on';
	}

	private static function post_type_label(\WP_Post $post): string
	{
		$type = get_post_type_object($post->post_type);

		return $type ? mb_strtolower($type->labels->singular_name) : $post->post_type;
	}

	/**
	 * Wrap a value in quotes, shortening long strings for the tooltip.
	 */
	private static function quote(string $text, int $max = 110): string
	{
		if ($text === '') {
			return '';
		}

		if (mb_strlen($text) > $max) {
			$text = rtrim(mb_substr($text, 0, $max - 1)) . '…';
		}

		return '“' . $text . '”';
	}

	/**
	 * Translatable one-character abbreviation shown in the post list strip.
	 *
	 * Uses _x() so translators get the context: on its own a letter like "T"
	 * is meaningless and would otherwise collide with unrelated strings.
	 */
	private static function letter(string $id, string $fallback): string
	{
		switch ($id) {
			case 'title':
				/* translators: One-character abbreviation of "Title" in the post list SEO strip. Keep it to a single character. */
				return _x('T', 'SEO signal letter: Title', 'mihdan-index-now');
			case 'description':
				/* translators: One-character abbreviation of "Description" in the post list SEO strip. Keep it to a single character. */
				return _x('D', 'SEO signal letter: Description', 'mihdan-index-now');
			case 'keyword':
				/* translators: One-character abbreviation of "Keyword" in the post list SEO strip. Keep it to a single character. */
				return _x('K', 'SEO signal letter: Keyword', 'mihdan-index-now');
			case 'indexing':
				/* translators: One-character abbreviation of "Indexing" in the post list SEO strip. Keep it to a single character. */
				return _x('I', 'SEO signal letter: Indexing', 'mihdan-index-now');
			case 'follow':
				/* translators: One-character abbreviation of "Following" in the post list SEO strip. Keep it to a single character. */
				return _x('F', 'SEO signal letter: Following', 'mihdan-index-now');
			case 'social':
				/* translators: One-character abbreviation of "Social" in the post list SEO strip. Keep it to a single character. */
				return _x('S', 'SEO signal letter: Social', 'mihdan-index-now');
			default:
				return $fallback;
		}
	}

	private static function signal(string $id, string $letter, string $label, string $state, string $summary, string $detail = ''): array
	{
		return [
			'id'      => $id,
			'letter'  => self::letter($id, $letter),
			'label'   => $label,
			'state'   => $state,
			'summary' => $summary,
			'detail'  => trim($detail),
		];
	}
}
