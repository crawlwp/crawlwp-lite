<?php

namespace Mihdan\IndexNow\SEOCore\Importer;

/**
 * Converts title/description template tokens from other SEO plugins into
 * CrawlWP's {{ namespaced.token }} syntax.
 *
 * The tag lists below are taken from each plugin's own source:
 *   Yoast     — inc/class-wpseo-replace-vars.php (the retrieve_* methods)
 *   Rank Math — includes/replace-variables/class-*-variables.php
 *   AIOSEO    — app/Common/Utils/Tags.php
 *   SEOPress  — inc/functions/variables/dynamic-variables.php
 *   Slim SEO  — src/Settings/MetaTags/RestApi.php
 *
 * Pure PHP — no WordPress dependency — so it can be unit-tested in isolation.
 */
class TokenMapper
{
	/**
	 * Replace foreign template tokens in $text with CrawlWP equivalents.
	 *
	 * Unknown tokens are left untouched so editors can still see them.
	 */
	public static function convert(string $text, string $source): string
	{
		if ($text === '') {
			return '';
		}

		$text = self::convert_dynamic($text, $source);

		$map = self::map_for($source);

		if ($map === []) {
			return $text;
		}

		return strtr($text, $map);
	}

	/**
	 * @return array<string,string>
	 */
	public static function map_for(string $source): array
	{
		switch ($source) {
			case 'yoast':
				return self::yoast_map();
			case 'rankmath':
				return self::rank_math_map();
			case 'aioseo':
				return self::aioseo_map();
			case 'seopress':
				return self::seopress_map();
			case 'slimseo':
				return self::slim_seo_map();
			case 'tsf':
				return self::tsf_map();
			default:
				return [];
		}
	}

	/**
	 * Tags that carry a meta key, a taxonomy slug or a date format as part of
	 * the tag itself, so they cannot be expressed as a fixed lookup table.
	 *
	 * Patterns are applied in order: the more specific prefix first, otherwise
	 * `%%ct_desc_genre%%` would be consumed by the `%%ct_…%%` pattern.
	 *
	 * @return array<string,string>
	 */
	private static function dynamic_patterns(string $source): array
	{
		switch ($source) {
			case 'yoast':
			case 'tsf':
				return [
					'/%%cf_([^%\s]+)%%/'      => '{{ post.custom_field.$1 }}',
					'/%%ct_desc_([^%\s]+)%%/' => '{{ post.taxonomy_description.$1 }}',
					'/%%ct_([^%\s]+)%%/'      => '{{ post.taxonomy.$1 }}',
					'/%%_cf_([^%\s]+)%%/'     => '{{ post.custom_field.$1 }}',
					'/%%_ct_([^%\s]+)%%/'     => '{{ post.taxonomy.$1 }}',
					'/%%_ucf_([^%\s]+)%%/'    => '{{ author.custom_field.$1 }}',
				];

			case 'seopress':
				return [
					'/%%_cf_([^%\s]+)%%/'  => '{{ post.custom_field.$1 }}',
					'/%%_ct_([^%\s]+)%%/'  => '{{ post.taxonomy.$1 }}',
					'/%%_ucf_([^%\s]+)%%/' => '{{ author.custom_field.$1 }}',
				];

			case 'rankmath':
				return [
					'/%customfield\(([^)]+)\)%/'     => '{{ post.custom_field.$1 }}',
					'/%customterm_desc\(([^)]+)\)%/' => '{{ post.taxonomy_description.$1 }}',
					'/%customterm\(([^)]+)\)%/'      => '{{ post.taxonomy.$1 }}',
					'/%categories\([^)]*\)%/'        => '{{ post.categories }}',
					'/%tags\([^)]*\)%/'              => '{{ post.tags }}',
					'/%date\([^)]*\)%/'              => '{{ post.date }}',
					'/%modified\([^)]*\)%/'          => '{{ post.modified }}',
					'/%currenttime\([^)]*\)%/'       => '{{ current.time }}',
					'/%count\([^)]*\)%/'             => '',
				];

			case 'aioseo':
				return [
					'/#custom_field-([a-z0-9_\-]+)/i' => '{{ post.custom_field.$1 }}',
					'/#tax_name-([a-z0-9_\-]+)/i'     => '{{ post.taxonomy.$1 }}',
				];

			case 'slimseo':
				return [
					'/\{\{\s*post\.tax\.([a-z0-9_\-]+)\s*\}\}/i' => '{{ post.taxonomy.$1 }}',
				];
		}

		return [];
	}

	private static function convert_dynamic(string $text, string $source): string
	{
		$patterns = self::dynamic_patterns($source);

		if ($patterns === []) {
			return $text;
		}

		foreach ($patterns as $pattern => $replacement) {
			$text = preg_replace($pattern, $replacement, $text) ?? $text;
		}

		return $text;
	}

	/**
	 * @return array<string,string>
	 */
	private static function yoast_map(): array
	{
		return [
			/* Post. */
			'%%title%%'                => '{{ post.title }}',
			'%%excerpt%%'              => '{{ post.auto_description }}',
			'%%excerpt_only%%'         => '{{ post.excerpt }}',
			'%%post_content%%'         => '{{ post.content }}',
			'%%caption%%'              => '{{ post.excerpt }}',
			'%%date%%'                 => '{{ post.date }}',
			'%%modified%%'             => '{{ post.modified }}',
			'%%post_year%%'            => '{{ post.year }}',
			'%%post_month%%'           => '{{ post.month }}',
			'%%post_day%%'             => '{{ post.day }}',
			'%%permalink%%'            => '{{ post.url }}',
			'%%id%%'                   => '{{ post.id }}',
			'%%parent_title%%'         => '{{ post.parent_title }}',
			'%%focuskw%%'              => '{{ post.focus_keyword }}',

			/* Author. */
			'%%name%%'                 => '{{ post.author }}',
			'%%userid%%'               => '{{ post.author }}',
			'%%user_description%%'     => '{{ author.description }}',
			'%%author_first_name%%'    => '{{ author.first_name }}',
			'%%author_last_name%%'     => '{{ author.last_name }}',

			/* Terms. Yoast's %%category%% and %%tag%% list every term. */
			'%%category%%'             => '{{ post.categories }}',
			'%%primary_category%%'     => '{{ post.category }}',
			'%%category_title%%'       => '{{ post.category }}',
			'%%category_description%%' => '{{ term.description }}',
			'%%tag%%'                  => '{{ post.tags }}',
			'%%tag_description%%'      => '{{ term.description }}',
			'%%term_title%%'           => '{{ term.title }}',
			'%%term_description%%'     => '{{ term.description }}',
			'%%term_hierarchy%%'       => '{{ term.parent }}',

			/* Site. */
			'%%sitename%%'             => '{{ site.title }}',
			'%%sitedesc%%'             => '{{ site.description }}',
			'%%sep%%'                  => '{{ sep }}',

			/* Current date and time. */
			'%%currentdate%%'          => '{{ current.date }}',
			'%%currentday%%'           => '{{ current.day }}',
			'%%currentmonth%%'         => '{{ current.month }}',
			'%%currentyear%%'          => '{{ current.year }}',
			'%%currenttime%%'          => '{{ current.time }}',

			/* Archives, search and pagination. */
			'%%searchphrase%%'         => '{{ search.query }}',
			'%%archive_title%%'        => '{{ post_type.plural }}',
			'%%pt_single%%'            => '{{ post_type.singular }}',
			'%%pt_plural%%'            => '{{ post_type.plural }}',
			'%%page%%'                 => '{{ page }}',
			'%%pagenumber%%'           => '{{ page }}',
			'%%pagetotal%%'            => '{{ page }}',
			'%%term404%%'              => '',

			/* WooCommerce SEO add-on. */
			'%%wc_shortdesc%%'         => '{{ post.excerpt }}',
			'%%wc_price%%'             => '{{ product.price }}',
			'%%wc_sku%%'               => '{{ product.sku }}',
			'%%wc_brand%%'             => '',
		];
	}

	/**
	 * @return array<string,string>
	 */
	private static function rank_math_map(): array
	{
		return [
			/* Post. */
			'%title%'                  => '{{ post.title }}',
			'%seo_title%'              => '{{ post.title }}',
			'%seo_description%'        => '{{ post.auto_description }}',
			'%excerpt%'                => '{{ post.auto_description }}',
			'%excerpt_only%'           => '{{ post.excerpt }}',
			'%parent_title%'           => '{{ post.parent_title }}',
			'%url%'                    => '{{ post.url }}',
			'%id%'                     => '{{ post.id }}',
			'%date%'                   => '{{ post.date }}',
			'%modified%'               => '{{ post.modified }}',
			'%post_thumbnail%'         => '{{ post.thumbnail_url }}',
			'%filename%'               => '{{ post.title }}',
			'%focuskw%'                => '{{ post.focus_keyword }}',
			'%keywords%'               => '{{ post.focus_keyword }}',

			/* Author. */
			'%post_author%'            => '{{ post.author }}',
			'%name%'                   => '{{ post.author }}',
			'%userid%'                 => '{{ post.author }}',
			'%user_description%'       => '{{ author.description }}',

			/* Terms. */
			'%category%'               => '{{ post.category }}',
			'%categories%'             => '{{ post.categories }}',
			'%primary_taxonomy_terms%' => '{{ post.category }}',
			'%tag%'                    => '{{ post.tag }}',
			'%tags%'                   => '{{ post.tags }}',
			'%term%'                   => '{{ term.title }}',
			'%term_description%'       => '{{ term.description }}',

			/* Site. */
			'%sitename%'               => '{{ site.title }}',
			'%sitedesc%'               => '{{ site.description }}',
			'%org_name%'               => '{{ site.title }}',
			'%org_url%'                => '{{ site.url }}',
			'%org_logo%'               => '',
			'%sep%'                    => '{{ sep }}',

			/* Current date and time. */
			'%currentdate%'            => '{{ current.date }}',
			'%currentday%'             => '{{ current.day }}',
			'%currentmonth%'           => '{{ current.month }}',
			'%currentyear%'            => '{{ current.year }}',
			'%currenttime%'            => '{{ current.time }}',

			/* Archives, search and pagination. */
			'%search_query%'           => '{{ search.query }}',
			'%pt_single%'              => '{{ post_type.singular }}',
			'%pt_plural%'              => '{{ post_type.plural }}',
			'%page%'                   => '{{ page }}',
			'%pagenumber%'             => '{{ page }}',
			'%pagetotal%'              => '{{ page }}',

			/* WooCommerce and BuddyPress modules. */
			'%wc_shortdesc%'           => '{{ post.excerpt }}',
			'%wc_price%'               => '{{ product.price }}',
			'%wc_sku%'                 => '{{ product.sku }}',
			'%wc_brand%'               => '',
			'%group_name%'             => '',
			'%group_desc%'             => '',
		];
	}

	/**
	 * @return array<string,string>
	 */
	private static function aioseo_map(): array
	{
		return [
			/* Post. */
			'#post_title'             => '{{ post.title }}',
			'#post_excerpt_only'      => '{{ post.excerpt }}',
			'#post_excerpt'           => '{{ post.auto_description }}',
			'#post_content'           => '{{ post.content }}',
			'#description'            => '{{ post.auto_description }}',
			'#post_date'              => '{{ post.date }}',
			'#post_day'               => '{{ post.day }}',
			'#post_month'             => '{{ post.month }}',
			'#post_year'              => '{{ post.year }}',
			'#post_link_alt'          => '{{ post.url }}',
			'#post_link'              => '{{ post.title }}',
			'#permalink'              => '{{ post.url }}',
			'#featured_image'         => '{{ post.thumbnail_url }}',
			'#parent_title'           => '{{ post.parent_title }}',
			'#attachment_caption'     => '{{ post.excerpt }}',
			'#attachment_description' => '{{ post.content }}',
			'#alt_tag'                => '',

			/* Author. */
			'#author_name'            => '{{ post.author }}',
			'#author_first_name'      => '{{ author.first_name }}',
			'#author_last_name'       => '{{ author.last_name }}',
			'#author_bio'             => '{{ author.description }}',
			'#author_link_alt'        => '{{ author.url }}',
			'#author_link'            => '{{ post.author }}',
			'#author_url'             => '{{ author.url }}',

			/* Terms. */
			'#categories'             => '{{ post.categories }}',
			'#category_link_alt'      => '',
			'#category_link'          => '{{ post.category }}',
			'#category'               => '{{ post.category }}',
			'#taxonomy_title'         => '{{ term.title }}',
			'#taxonomy_description'   => '{{ term.description }}',
			'#tax_parent_name'        => '{{ term.parent }}',
			'#tax_name'               => '{{ term.title }}',

			/* Site. */
			'#site_title'             => '{{ site.title }}',
			'#blog_title'             => '{{ site.title }}',
			'#site_description'       => '{{ site.description }}',
			'#tagline'                => '{{ site.description }}',
			'#site_link_alt'          => '{{ site.url }}',
			'#site_link'              => '{{ site.title }}',
			'#blog_link'              => '{{ site.url }}',
			'#separator_sa'           => '{{ sep }}',

			/* Current date and archives. */
			'#current_date'           => '{{ current.date }}',
			'#current_day'            => '{{ current.day }}',
			'#current_month'          => '{{ current.month }}',
			'#current_year'           => '{{ current.year }}',
			'#archive_date'           => '{{ date.archive_title }}',
			'#archive_title'          => '{{ post_type.plural }}',
			'#search_term'            => '{{ search.query }}',
			'#page_number'            => '{{ page }}',
		];
	}

	/**
	 * @return array<string,string>
	 */
	private static function seopress_map(): array
	{
		return [
			/* Site. */
			'%%sitetitle%%'                 => '{{ site.title }}',
			'%%sitename%%'                  => '{{ site.title }}',
			'%%tagline%%'                   => '{{ site.description }}',
			'%%sitedesc%%'                  => '{{ site.description }}',
			'%%site_alternate_name%%'       => '{{ site.title }}',
			'%%siteurl%%'                   => '{{ site.url }}',
			'%%sep%%'                       => '{{ sep }}',

			/* Post. */
			'%%title%%'                     => '{{ post.title }}',
			'%%post_title%%'                => '{{ post.title }}',
			'%%post_excerpt%%'              => '{{ post.auto_description }}',
			'%%excerpt%%'                   => '{{ post.auto_description }}',
			'%%post_content%%'              => '{{ post.content }}',
			'%%post_thumbnail_url%%'        => '{{ post.thumbnail_url }}',
			'%%post_url%%'                  => '{{ post.url }}',
			'%%post_permalink%%'            => '{{ post.url }}',
			'%%post_date%%'                 => '{{ post.date }}',
			'%%date%%'                      => '{{ post.date }}',
			'%%post_modified_date%%'        => '{{ post.modified }}',
			'%%post_author%%'               => '{{ post.author }}',
			'%%post_category%%'             => '{{ post.category }}',
			'%%post_tag%%'                  => '{{ post.tag }}',
			'%%target_keyword%%'            => '{{ post.focus_keyword }}',

			/* Terms. */
			'%%category%%'                  => '{{ post.category }}',
			'%%category_description%%'      => '{{ term.description }}',
			'%%_category_title%%'           => '{{ post.category }}',
			'%%_category_description%%'     => '{{ term.description }}',
			'%%tag%%'                       => '{{ post.tag }}',
			'%%tag_title%%'                 => '{{ post.tag }}',
			'%%tag_description%%'           => '{{ term.description }}',
			'%%term_title%%'                => '{{ term.title }}',
			'%%term_description%%'          => '{{ term.description }}',

			/* Author. */
			'%%name%%'                      => '{{ post.author }}',
			'%%author_first_name%%'         => '{{ author.first_name }}',
			'%%author_last_name%%'          => '{{ author.last_name }}',
			'%%author_nickname%%'           => '{{ author.nickname }}',
			'%%author_website%%'            => '{{ author.website }}',
			'%%author_permalink%%'          => '{{ author.url }}',
			'%%author_bio%%'                => '{{ author.description }}',
			'%%user_description%%'          => '{{ author.description }}',

			/* Current date and time. */
			'%%currentday%%'                => '{{ current.day }}',
			'%%currentmonth_short%%'        => '{{ current.month_short }}',
			'%%currentmonth_num%%'          => '{{ current.month_num }}',
			'%%currentmonth%%'              => '{{ current.month }}',
			'%%currentyear%%'               => '{{ current.year }}',
			'%%currentdate%%'               => '{{ current.date }}',
			'%%currenttime%%'               => '{{ current.time }}',

			/* Archives, search and pagination. */
			'%%search_keywords%%'           => '{{ search.query }}',
			'%%searchphrase%%'              => '{{ search.query }}',
			'%%current_pagination%%'        => '{{ page }}',
			'%%page%%'                      => '{{ page }}',
			'%%cpt_plural%%'                => '{{ post_type.plural }}',
			'%%pt_plural%%'                 => '{{ post_type.plural }}',
			'%%archive_title%%'             => '{{ post_type.plural }}',
			'%%archive_date_day%%'          => '{{ date.day }}',
			'%%archive_date_month_name%%'   => '{{ date.month_name }}',
			'%%archive_date_month%%'        => '{{ date.month }}',
			'%%archive_date_year%%'         => '{{ date.year }}',
			'%%archive_date%%'              => '{{ date.archive_title }}',

			/* WooCommerce. */
			'%%wc_single_cat%%'             => '{{ post.category }}',
			'%%wc_single_tag%%'             => '{{ post.tag }}',
			'%%wc_single_short_desc%%'      => '{{ post.excerpt }}',
			'%%wc_single_price_exc_tax%%'   => '{{ product.price }}',
			'%%wc_single_price%%'           => '{{ product.price }}',
			'%%wc_get_price%%'              => '{{ product.price }}',
			'%%wc_single_price_inc_tax%%'   => '{{ product.price_with_tax }}',
			'%%wc_sku%%'                    => '{{ product.sku }}',
		];
	}

	/**
	 * The SEO Framework generates its titles and descriptions itself and has no
	 * replacement tags of its own, but it stores its data under the legacy
	 * Genesis keys and knowingly keeps whatever Yoast or SEOPress syntax was
	 * already in those fields — see its own
	 * `Helper\Migrate::text_has_yoast_seo_syntax()`. So both of those dialects
	 * are translated here, together with the Genesis shortcodes.
	 *
	 * @return array<string,string>
	 */
	private static function tsf_map(): array
	{
		$map = [
			/* Genesis shortcode-style tokens. */
			'[post_title]'            => '{{ post.title }}',
			'[post_content]'          => '{{ post.content }}',
			'[post_excerpt]'          => '{{ post.excerpt }}',
			'[post_date]'             => '{{ post.date }}',
			'[post_modified_date]'    => '{{ post.modified }}',
			'[post_author]'           => '{{ post.author }}',
			'[post_author_nicename]'  => '{{ post.author }}',
			'[post_author_firstname]' => '{{ author.first_name }}',
			'[post_author_lastname]'  => '{{ author.last_name }}',
			'[site_title]'            => '{{ site.title }}',
			'[site_description]'      => '{{ site.description }}',
			'[term_title]'            => '{{ term.title }}',
			'[term_description]'      => '{{ term.description }}',
			'[category_title]'        => '{{ post.category }}',
			'[archive_title]'         => '{{ post_type.plural }}',
			'[archive_description]'   => '{{ term.description }}',
			'[page]'                  => '{{ page }}',
			'[pagenumber]'            => '{{ page }}',
			'[sep]'                   => '{{ sep }}',
			'[tagline]'               => '{{ site.description }}',
		];

		/* Yoast first: where both dialects share a tag name they also share its
		 * meaning, so the merge order only decides which comment it came from. */
		return array_merge(self::yoast_map(), self::seopress_map(), $map);
	}

	/**
	 * Slim SEO already uses a {{ }} syntax; only the tokens it spells
	 * differently need translating, the rest pass through untouched.
	 *
	 * @return array<string,string>
	 */
	private static function slim_seo_map(): array
	{
		return [
			'{{ post.modified_date }}'  => '{{ post.modified }}',
			'{{ post.thumbnail }}'      => '{{ post.thumbnail_url }}',
			'{{ post.author.name }}'    => '{{ post.author }}',
			'{{ user.display_name }}'   => '{{ author.display_name }}',
			'{{ user.description }}'    => '{{ author.description }}',
			'{{ site.facebook_image }}' => '',
			'{{ site.twitter_image }}'  => '',
		];
	}
}
