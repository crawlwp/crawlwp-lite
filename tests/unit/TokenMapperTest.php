<?php

namespace Mihdan\IndexNow\Tests\Unit;

use Mihdan\IndexNow\SEOCore\Importer\TokenMapper;
use PHPUnit\Framework\TestCase;

/**
 * Template-token translation from the source SEO plugins.
 */
class TokenMapperTest extends TestCase
{
	/**
	 * @dataProvider tokenProvider
	 */
	public function test_convert(string $template, string $source, string $expected): void
	{
		$this->assertSame($expected, TokenMapper::convert($template, $source));
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function tokenProvider(): array
	{
		return [
			'yoast title'       => ['%%title%% %%sep%% %%sitename%%', 'yoast', '{{ post.title }} {{ sep }} {{ site.title }}'],
			'yoast term'        => ['%%term_title%% %%term_description%%', 'yoast', '{{ term.title }} {{ term.description }}'],
			'yoast custom tax'  => ['%%ct_genre%%', 'yoast', '{{ post.taxonomy.genre }}'],
			'rank math excerpt' => ['%excerpt% on %sitename%', 'rankmath', '{{ post.auto_description }} on {{ site.title }}'],
			'rank math term'    => ['%term% %term_description%', 'rankmath', '{{ term.title }} {{ term.description }}'],
			'rank math field'   => ['%customfield(subtitle)%', 'rankmath', '{{ post.custom_field.subtitle }}'],
			'aioseo'            => ['#post_title #separator_sa #site_title', 'aioseo', '{{ post.title }} {{ sep }} {{ site.title }}'],
			'aioseo term'       => ['#taxonomy_title #taxonomy_description', 'aioseo', '{{ term.title }} {{ term.description }}'],
			'seopress'          => ['%%post_title%% %%sep%% %%sitetitle%%', 'seopress', '{{ post.title }} {{ sep }} {{ site.title }}'],
			'seopress term'     => ['%%term_title%% %%term_description%%', 'seopress', '{{ term.title }} {{ term.description }}'],
			'seopress field'    => ['%%_cf_subtitle%%', 'seopress', '{{ post.custom_field.subtitle }}'],
			'slim seo alias'    => ['{{ post.modified_date }}', 'slimseo', '{{ post.modified }}'],
			'slim seo native'   => ['{{ post.categories }}', 'slimseo', '{{ post.categories }}'],
			'plain text'        => ['A plain title', 'tsf', 'A plain title'],
		];
	}

	public function test_unknown_source_is_left_untouched(): void
	{
		$this->assertSame('%%title%%', TokenMapper::convert('%%title%%', 'not-a-plugin'));
	}

	public function test_the_seo_framework_tokens_are_mapped(): void
	{
		$this->assertNotSame(
			[],
			TokenMapper::map_for('tsf'),
			'TSF/Genesis tokens must be mapped so they do not survive as raw text.'
		);
	}
}
