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
}
