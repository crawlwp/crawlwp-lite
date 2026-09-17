<?php

/**
 * Standalone tests for TokenMapper.
 *
 * Run: php tests/TokenMapperTest.php
 */

namespace {

	$base = dirname(__DIR__);
	require_once $base . '/tests/bootstrap.php';
	require_once $base . '/src/SEOCore/Importer/TokenMapper.php';

	use Mihdan\IndexNow\SEOCore\Importer\TokenMapper;

	function cwp_assert(bool $ok, string $msg): void
	{
		if (! $ok) {
			echo "FAIL {$msg}\n";
			exit(1);
		}
		echo "OK  {$msg}\n";
	}

	$yoast = TokenMapper::convert('%%title%% %%sep%% %%sitename%% %%parent_title%% %%sitedesc%%', 'yoast');
	cwp_assert($yoast === '{{ post.title }} {{ sep }} {{ site.title }} {{ post.parent_title }} {{ site.description }}', 'Yoast title tokens');

	$yoast_terms = TokenMapper::convert('%%term_title%% %%term_description%% %%term_hierarchy%% %%category_description%%', 'yoast');
	cwp_assert($yoast_terms === '{{ term.title }} {{ term.description }} {{ term.parent }} {{ term.description }}', 'Yoast term tokens');

	$yoast_dyn = TokenMapper::convert('%%cf_subtitle%% %%ct_genre%% %%ct_desc_genre%%', 'yoast');
	cwp_assert($yoast_dyn === '{{ post.custom_field.subtitle }} {{ post.taxonomy.genre }} {{ post.taxonomy_description.genre }}', 'Yoast custom field and taxonomy tokens');

	$rm = TokenMapper::convert('%title% on %sitename% | %sitedesc% %parent_title%', 'rankmath');
	cwp_assert($rm === '{{ post.title }} on {{ site.title }} | {{ site.description }} {{ post.parent_title }}', 'Rank Math title/site/parent');

	$rm_terms = TokenMapper::convert('%term% %term_description% %categories% %tags%', 'rankmath');
	cwp_assert($rm_terms === '{{ term.title }} {{ term.description }} {{ post.categories }} {{ post.tags }}', 'Rank Math term tokens');

	$rm_dyn = TokenMapper::convert('%customfield(subtitle)% %customterm(genre)% %customterm_desc(genre)% %date(Y)%', 'rankmath');
	cwp_assert($rm_dyn === '{{ post.custom_field.subtitle }} {{ post.taxonomy.genre }} {{ post.taxonomy_description.genre }} {{ post.date }}', 'Rank Math dynamic tokens');

	$aio = TokenMapper::convert('#post_title #separator_sa #site_title #tagline #parent_title #author_bio', 'aioseo');
	cwp_assert($aio === '{{ post.title }} {{ sep }} {{ site.title }} {{ site.description }} {{ post.parent_title }} {{ author.description }}', 'AIOSEO tokens');

	$aio_terms = TokenMapper::convert('#taxonomy_title #taxonomy_description #tax_parent_name #custom_field-subtitle #tax_name-genre', 'aioseo');
	cwp_assert($aio_terms === '{{ term.title }} {{ term.description }} {{ term.parent }} {{ post.custom_field.subtitle }} {{ post.taxonomy.genre }}', 'AIOSEO term and dynamic tokens');

	$sp = TokenMapper::convert('%%post_title%% %%sep%% %%sitetitle%%', 'seopress');
	cwp_assert($sp === '{{ post.title }} {{ sep }} {{ site.title }}', 'SEOPress tokens');

	$sp_terms = TokenMapper::convert('%%term_title%% %%term_description%% %%tag_title%% %%_category_title%%', 'seopress');
	cwp_assert($sp_terms === '{{ term.title }} {{ term.description }} {{ post.tag }} {{ post.category }}', 'SEOPress term tokens');

	$sp_dyn = TokenMapper::convert('%%_cf_subtitle%% %%_ct_genre%% %%_ucf_badge%%', 'seopress');
	cwp_assert($sp_dyn === '{{ post.custom_field.subtitle }} {{ post.taxonomy.genre }} {{ author.custom_field.badge }}', 'SEOPress custom field tokens');

	$sp_archive = TokenMapper::convert('%%archive_date_year%% %%archive_date_month_name%% %%currentmonth_short%%', 'seopress');
	cwp_assert($sp_archive === '{{ date.year }} {{ date.month_name }} {{ current.month_short }}', 'SEOPress archive date tokens');

	$slim = TokenMapper::convert('{{ post.modified_date }} {{ post.thumbnail }} {{ post.tax.genre }}', 'slimseo');
	cwp_assert($slim === '{{ post.modified }} {{ post.thumbnail_url }} {{ post.taxonomy.genre }}', 'Slim SEO aliases');

	$slim_kept = TokenMapper::convert('{{ term.name }} {{ post.categories }} {{ post.custom_field.subtitle }}', 'slimseo');
	cwp_assert($slim_kept === '{{ term.name }} {{ post.categories }} {{ post.custom_field.subtitle }}', 'Slim SEO native tokens pass through');

	$tsf = TokenMapper::convert('A plain title', 'tsf');
	cwp_assert($tsf === 'A plain title', 'TSF leaves plain text');

	$tsf_foreign = TokenMapper::convert('%%title%% %%sep%% %%sitename%%', 'tsf');
	cwp_assert($tsf_foreign === '{{ post.title }} {{ sep }} {{ site.title }}', 'TSF translates migrated Yoast syntax');

	echo "\nAll tests passed.\n";
	exit(0);
}
