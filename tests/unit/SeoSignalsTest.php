<?php

declare(strict_types=1);

namespace Mihdan\IndexNow\Tests\Unit;

use Mihdan\IndexNow\SEOCore\MetaBox\MetaFields;
use Mihdan\IndexNow\SEOCore\MetaBox\SeoSignals;
use PHPUnit\Framework\TestCase;

class SeoSignalsTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		$GLOBALS['crawlwp_test_state']['post_meta'] = [];
	}

	public function test_signals_do_not_contain_indexnow_n_signal(): void
	{
		$post = new \WP_Post((object) [
			'ID'           => 201,
			'post_title'   => 'Test SEO Post',
			'post_name'    => 'test-seo-post',
			'post_content' => 'This is a test post content for evaluating SEO signals.',
			'post_type'    => 'post',
			'post_status'  => 'publish',
		]);

		$GLOBALS['crawlwp_test_state']['post_meta'][$post->ID][MetaFields::SEO_TITLE] = 'Test SEO Post Title';
		$GLOBALS['crawlwp_test_state']['post_meta'][$post->ID][MetaFields::SEO_DESCRIPTION] = 'Test SEO Post Description for testing purposes.';
		$GLOBALS['crawlwp_test_state']['post_meta'][$post->ID][MetaFields::FOCUS_KEYWORD] = 'test';
		$GLOBALS['crawlwp_test_state']['post_meta'][$post->ID]['_crawlwp_last_indexnow'] = time();

		$signals = SeoSignals::for_post($post);

		$signal_ids = array_column($signals, 'id');
		$signal_letters = array_column($signals, 'letter');

		$this->assertNotContains('indexnow', $signal_ids);
		$this->assertNotContains('N', $signal_letters);

		$this->assertSame(['title', 'description', 'keyword', 'indexing', 'follow', 'social'], $signal_ids);
		$this->assertSame(['T', 'D', 'K', 'I', 'F', 'S'], $signal_letters);
	}

	/**
	 * @dataProvider slug_cases
	 */
	public function test_keyword_in_slug(string $keyword, string $slug, bool $expected): void
	{
		$this->assertSame($expected, SeoSignals::keyword_in_slug($keyword, $slug));
	}

	public static function slug_cases(): array
	{
		return [
			'exact'                      => ['best seo plugin', 'best-seo-plugin', true],
			'inside a longer slug'       => ['seo plugin', 'the-best-seo-plugin-2026', true],
			'plural in slug'             => ['seo plugin', 'best-seo-plugins', true],
			'small word left out'        => ['best plugin for seo', 'best-plugin-seo', true],
			'several small words'        => ['how to submit a sitemap to google', 'submit-sitemap-google', true],
			'other order'                => ['wordpress seo plugin', 'seo-plugin-wordpress', true],
			'percent-encoded slug'       => ['seo', 'seo-%e2%9c%93-guide', true],
			'meaningful word missing'    => ['best plugin for seo', 'best-plugin', false],
			'part of a word only'        => ['rank', 'frank-review', false],
			'words joined in slug'       => ['word press', 'wordpress-tips', true],
			'only small words'           => ['how to', 'guide-how-to', true],
			'only small words, missing'  => ['how to', 'guide', false],
			'empty slug'                 => ['seo', '', false],
			'empty keyword'              => ['', 'seo-guide', false],
		];
	}

	public function test_slug_stop_words_are_filterable(): void
	{
		add_filter('crawlwp_slug_stop_words', function ($words) {
			return array_merge($words, ['best']);
		});

		$this->assertTrue(SeoSignals::keyword_in_slug('best seo plugin', 'seo-plugin'));
		$this->assertContains('best', SeoSignals::slug_stop_words());
	}

	public function test_normalize_text_makes_spacing_and_apostrophes_comparable(): void
	{
		$this->assertSame("star rating plugin for wordpress", SeoSignals::normalize_text("Star&nbsp;Rating  plugin for\nWordPress"));
		$this->assertSame("star rating plugin", SeoSignals::normalize_text("star\u{00A0}rating plugin"));
		$this->assertSame("rank math's guide", SeoSignals::normalize_text("Rank Math\u{2019}s guide"));
	}
}
