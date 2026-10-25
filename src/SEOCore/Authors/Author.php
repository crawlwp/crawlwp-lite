<?php

namespace Mihdan\IndexNow\SEOCore\Authors;

use Mihdan\IndexNow\SEOCore\SiteInfoSettings\SiteInfoSettings;
use Mihdan\IndexNow\SEOCore\SocialSettings\UserProfile;

/**
 * Everything CrawlWP knows about a person who writes or reviews content.
 *
 * The details come from the "Author Profile" section of the user profile
 * (see {@see AuthorProfile}), WordPress' own Biographical Info and the social
 * profile fields of {@see UserProfile}. They feed the Person schema node
 * ({@see self::person_node()}) and the optional author box ({@see AuthorBox}).
 */
class Author
{
	public const META_JOB_TITLE      = '_crawlwp_author_job_title';
	public const META_WORKS_FOR      = '_crawlwp_author_works_for';       // '' (default) | site | other | none
	public const META_WORKS_FOR_NAME = '_crawlwp_author_works_for_name';
	public const META_WORKS_FOR_URL  = '_crawlwp_author_works_for_url';
	public const META_EXPERTISE      = '_crawlwp_author_expertise';       // comma separated
	public const META_CREDENTIALS    = '_crawlwp_author_credentials';     // one per line
	public const META_PHOTO          = '_crawlwp_author_photo';           // attachment ID

	/** @var array<int, array<string, mixed>|null> */
	private static array $cache = [];

	/**
	 * Whether the site itself is an organisation an author can work for.
	 */
	public static function site_is_organization(): bool
	{
		return (string) SiteInfoSettings::get('site_type', 'organization') !== 'person';
	}

	/**
	 * Name of the organisation in Site Information (falls back to the site title).
	 */
	public static function site_name(): string
	{
		$name = (string) SiteInfoSettings::get('site_name', '');

		return $name !== '' ? $name : (string) get_bloginfo('name');
	}

	/**
	 * The author's details, with empty values for anything not filled in.
	 *
	 * @return array{
	 *   id:int, name:string, url:string (author archive, '' when it has no posts), bio:string, job_title:string,
	 *   works_for:array{name:string, url:string, site:bool}|null,
	 *   expertise:string[], credentials:string[], photo:string, photo_id:int,
	 *   same_as:string[]
	 * }|null Null when the user does not exist.
	 */
	public static function get(int $user_id): ?array
	{
		if ($user_id <= 0) {
			return null;
		}

		if (array_key_exists($user_id, self::$cache)) {
			return self::$cache[$user_id];
		}

		$user = get_userdata($user_id);

		if (! $user instanceof \WP_User) {
			return self::$cache[$user_id] = null;
		}

		$photo_id = (int) get_user_meta($user_id, self::META_PHOTO, true);
		$photo    = $photo_id > 0 ? (string) wp_get_attachment_image_url($photo_id, 'medium') : '';

		if ($photo === '') {
			$photo_id = 0;
			$photo    = (string) get_avatar_url($user_id, ['size' => 256]);
		}

		$data = [
			'id'          => $user_id,
			'name'        => (string) $user->display_name,
			/* WordPress shows a 404 for the archive of someone with no published posts. */
			'url'         => self::has_posts($user_id) ? (string) get_author_posts_url($user_id) : '',
			'bio'         => trim(wp_strip_all_tags((string) get_user_meta($user_id, 'description', true))),
			'job_title'   => (string) get_user_meta($user_id, self::META_JOB_TITLE, true),
			'works_for'   => self::works_for($user_id),
			'expertise'   => self::split((string) get_user_meta($user_id, self::META_EXPERTISE, true), ','),
			'credentials' => self::split((string) get_user_meta($user_id, self::META_CREDENTIALS, true), "\n"),
			'photo'       => $photo,
			'photo_id'    => $photo_id,
			'same_as'     => self::same_as($user_id),
		];

		/**
		 * Filter the details CrawlWP uses for an author's Person schema and author box.
		 *
		 * @param array $data    Author details.
		 * @param int   $user_id User ID.
		 */
		$data = (array) apply_filters('crawlwp_author_data', $data, $user_id);

		return self::$cache[$user_id] = $data;
	}

	/**
	 * The organisation the author works for, or null.
	 *
	 * @return array{name:string, url:string, site:bool}|null
	 */
	public static function works_for(int $user_id): ?array
	{
		$choice = (string) get_user_meta($user_id, self::META_WORKS_FOR, true);

		if ($choice === '') {
			$choice = self::site_is_organization() ? 'site' : 'none';
		}

		if ($choice === 'site' && self::site_is_organization()) {
			return ['name' => self::site_name(), 'url' => home_url('/'), 'site' => true];
		}

		if ($choice === 'other') {
			$name = (string) get_user_meta($user_id, self::META_WORKS_FOR_NAME, true);

			if ($name !== '') {
				return ['name' => $name, 'url' => (string) get_user_meta($user_id, self::META_WORKS_FOR_URL, true), 'site' => false];
			}
		}

		return null;
	}

	/**
	 * Social and other profile URLs: Facebook, X and the additional profiles.
	 *
	 * @return string[]
	 */
	public static function same_as(int $user_id): array
	{
		$urls = [];

		$facebook = esc_url_raw((string) UserProfile::get($user_id, UserProfile::META_FACEBOOK));

		if ($facebook !== '') {
			$urls[] = $facebook;
		}

		$x = ltrim((string) UserProfile::get($user_id, UserProfile::META_TWITTER), '@');

		if ($x !== '' && preg_match('/^[A-Za-z0-9_]{1,30}$/', $x)) {
			$urls[] = 'https://x.com/' . $x;
		}

		foreach (UserProfile::get_additional_profiles($user_id) as $url) {
			$url = esc_url_raw($url);

			if ($url !== '') {
				$urls[] = $url;
			}
		}

		return array_values(array_unique($urls));
	}

	/**
	 * Stable `@id` of an author's Person node. A hash, so it doesn't reveal the user ID.
	 */
	public static function person_id(int $user_id): string
	{
		return home_url('/') . '#/schema/person/' . substr(wp_hash('crawlwp_person_' . $user_id), 0, 16);
	}

	/**
	 * The schema.org Person node for an author.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function person_node(int $user_id): ?array
	{
		$author = self::get($user_id);

		if ($author === null || $author['name'] === '') {
			return null;
		}

		$id   = self::person_id($user_id);
		$node = [
			'@type' => 'Person',
			'@id'   => $id,
			'name'  => $author['name'],
		];

		if ($author['url'] !== '') {
			$node['url'] = $author['url'];
		}

		if ($author['photo'] !== '') {
			$node['image'] = [
				'@type'      => 'ImageObject',
				'@id'        => $id . '/image',
				'url'        => $author['photo'],
				'contentUrl' => $author['photo'],
				'caption'    => $author['name'],
			];
		}

		if ($author['bio'] !== '') {
			$node['description'] = $author['bio'];
		}

		if ($author['job_title'] !== '') {
			$node['jobTitle'] = $author['job_title'];
		}

		if (is_array($author['works_for'])) {
			if (! empty($author['works_for']['site'])) {
				$node['worksFor'] = ['@id' => home_url('/') . '#organization'];
			} else {
				$org = ['@type' => 'Organization', 'name' => $author['works_for']['name']];

				if ($author['works_for']['url'] !== '') {
					$org['url'] = $author['works_for']['url'];
				}

				$node['worksFor'] = $org;
			}
		}

		if ($author['expertise'] !== []) {
			$node['knowsAbout'] = $author['expertise'];
		}

		if ($author['credentials'] !== []) {
			$node['hasCredential'] = array_map(static function (string $name): array {
				return ['@type' => 'EducationalOccupationalCredential', 'name' => $name];
			}, $author['credentials']);
		}

		if ($author['same_as'] !== []) {
			$node['sameAs'] = $author['same_as'];
		}

		/**
		 * Filter an author's Person schema node.
		 *
		 * @param array $node    The Person node.
		 * @param int   $user_id User ID.
		 * @param array $author  Author details from {@see Author::get()}.
		 */
		return (array) apply_filters('crawlwp_author_person_node', $node, $user_id, $author);
	}

	/**
	 * Whether the user has published anything that appears on their author archive.
	 */
	public static function has_posts(int $user_id): bool
	{
		$types = array_values(array_diff(get_post_types(['public' => true]), ['attachment']));

		return (int) count_user_posts($user_id, $types, true) > 0;
	}

	/**
	 * Drop the cached details, e.g. after a profile is saved.
	 */
	public static function flush(int $user_id): void
	{
		unset(self::$cache[$user_id]);
	}

	/**
	 * Split a list into trimmed, non-empty, unique items.
	 *
	 * @return string[]
	 */
	public static function split(string $raw, string $separator): array
	{
		$items = array_map('trim', explode($separator, str_replace("\r", '', $raw)));

		return array_values(array_unique(array_filter($items, static function (string $item): bool {
			return $item !== '';
		})));
	}
}
