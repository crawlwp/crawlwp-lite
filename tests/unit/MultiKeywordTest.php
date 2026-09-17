<?php

declare(strict_types=1);

namespace Mihdan\IndexNow\Tests\Unit;

use Mihdan\IndexNow\SEOCore\MetaBox\MetaFields;
use Mihdan\IndexNow\SEOCore\MetaBox\SeoSignals;
use PHPUnit\Framework\TestCase;

class MultiKeywordTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		$GLOBALS['crawlwp_test_state']['post_meta'] = [];
	}

	public function test_parse_keywords(): void
	{
		$this->assertSame([], MetaFields::parse_keywords(''));
		$this->assertSame([], MetaFields::parse_keywords('   , ,   '));
		$this->assertSame(['running shoes'], MetaFields::parse_keywords('running shoes'));
		$this->assertSame(
			['running shoes', 'trail sneakers', 'marathon gear'],
			MetaFields::parse_keywords('  running shoes , trail sneakers, , marathon gear  ')
		);
	}

	public function test_keywords_retrieves_from_post_meta(): void
	{
		$post_id = 101;
		$GLOBALS['crawlwp_test_state']['post_meta'][$post_id][MetaFields::FOCUS_KEYWORD] = 'seo audit, keyword research, site speed';

		$keywords = MetaFields::keywords($post_id);
		$this->assertSame(['seo audit', 'keyword research', 'site speed'], $keywords);
	}

	public function test_seo_signals_single_keyword(): void
	{
		$post = new \WP_Post((object) [
			'ID'           => 102,
			'post_title'   => 'Running Shoes Guide',
			'post_name'    => 'running-shoes-guide',
			'post_content' => 'Here is our full guide on running shoes.',
			'post_type'    => 'post',
			'post_status'  => 'publish',
		]);

		$GLOBALS['crawlwp_test_state']['post_meta'][$post->ID][MetaFields::FOCUS_KEYWORD] = 'running shoes';
		$GLOBALS['crawlwp_test_state']['post_meta'][$post->ID][MetaFields::SEO_TITLE] = 'Running Shoes Guide';

		$signals = SeoSignals::for_post($post);
		$kw_signal = null;
		foreach ($signals as $s) {
			if (($s['id'] ?? '') === 'keyword') {
				$kw_signal = $s;
				break;
			}
		}

		$this->assertNotNull($kw_signal);
		$this->assertSame(SeoSignals::GOOD, $kw_signal['state']);
		$this->assertStringContainsString('Found in the title, URL slug and content', $kw_signal['summary']);
	}

	public function test_seo_signals_multiple_keywords_all_found(): void
	{
		$post = new \WP_Post((object) [
			'ID'           => 103,
			'post_title'   => 'Running Shoes Guide',
			'post_name'    => 'running-shoes-guide',
			'post_content' => 'Here is our full guide on running shoes, including trail sneakers and marathon gear.',
			'post_type'    => 'post',
			'post_status'  => 'publish',
		]);

		$GLOBALS['crawlwp_test_state']['post_meta'][$post->ID][MetaFields::FOCUS_KEYWORD] = 'running shoes, trail sneakers, marathon gear';
		$GLOBALS['crawlwp_test_state']['post_meta'][$post->ID][MetaFields::SEO_TITLE] = 'Running Shoes Guide';

		$signals = SeoSignals::for_post($post);
		$kw_signal = null;
		foreach ($signals as $s) {
			if (($s['id'] ?? '') === 'keyword') {
				$kw_signal = $s;
				break;
			}
		}

		$this->assertNotNull($kw_signal);
		$this->assertSame(SeoSignals::GOOD, $kw_signal['state']);
		$this->assertStringContainsString('All 2 secondary keywords found in content', $kw_signal['summary']);
		$this->assertStringContainsString('plus 2 secondary keywords', $kw_signal['detail']);
	}

	public function test_seo_signals_multiple_keywords_secondary_missing(): void
	{
		$post = new \WP_Post((object) [
			'ID'           => 104,
			'post_title'   => 'Running Shoes Guide',
			'post_name'    => 'running-shoes-guide',
			'post_content' => 'Here is our full guide on running shoes and trail sneakers.',
			'post_type'    => 'post',
			'post_status'  => 'publish',
		]);

		// 'marathon gear' is missing from content
		$GLOBALS['crawlwp_test_state']['post_meta'][$post->ID][MetaFields::FOCUS_KEYWORD] = 'running shoes, trail sneakers, marathon gear';
		$GLOBALS['crawlwp_test_state']['post_meta'][$post->ID][MetaFields::SEO_TITLE] = 'Running Shoes Guide';

		$signals = SeoSignals::for_post($post);
		$kw_signal = null;
		foreach ($signals as $s) {
			if (($s['id'] ?? '') === 'keyword') {
				$kw_signal = $s;
				break;
			}
		}

		$this->assertNotNull($kw_signal);
		$this->assertSame(SeoSignals::WARN, $kw_signal['state']);
		$this->assertStringContainsString('1 of 2 secondary keywords found in content', $kw_signal['summary']);
	}
}
