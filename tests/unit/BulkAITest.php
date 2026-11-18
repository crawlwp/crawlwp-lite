<?php

use Mihdan\IndexNow\SEOCore\AI\Generator;
use Mihdan\IndexNow\SEOCore\BulkEditor\BulkAI;
use Mihdan\IndexNow\SEOCore\BulkEditor\ImageAlt;
use PHPUnit\Framework\TestCase;

class BulkAITest extends TestCase
{
	public function test_generated_title_goes_into_the_template(): void
	{
		$this->assertSame('New title {{ sep }} {{ site.title }}', BulkAI::into_template('{{ post.title }} {{ sep }} {{ site.title }}', 'New title'));
		$this->assertSame('Buy x', BulkAI::into_template('Buy {{post.title}}', 'x'));
		// "$1" in generated text is kept as written, not treated as a backreference.
		$this->assertSame('Save $1 today | Site', BulkAI::into_template('{{ post.title }} | Site', 'Save $1 today'));
		// only the first token is replaced
		$this->assertSame('A {{ post.title }}', BulkAI::into_template('{{ post.title }} {{ post.title }}', 'A'));
	}

	public function test_missing_alt_query(): void
	{
		$args = ImageAlt::query_args(true, 'logo', 50, 2);

		$this->assertSame('attachment', $args['post_type']);
		$this->assertSame('image', $args['post_mime_type']);
		$this->assertSame('logo', $args['s']);
		$this->assertSame(2, $args['paged']);
		$this->assertSame('OR', $args['meta_query']['relation']);
		$this->assertSame('NOT EXISTS', $args['meta_query'][0]['compare']);
		$this->assertSame('', $args['meta_query'][1]['value']);

		$this->assertArrayNotHasKey('meta_query', ImageAlt::query_args(false));
		$this->assertArrayNotHasKey('s', ImageAlt::query_args(false));
	}

	public function test_alt_text_is_cleaned_and_capped(): void
	{
		$generator = new Generator();
		$sanitize  = new ReflectionMethod($generator, 'sanitize');
		$sanitize->setAccessible(true);

		$this->assertSame('Two dogs playing in snow', $sanitize->invoke($generator, 'Alt text: "Two dogs playing in snow"', 'alt'));
		$this->assertSame('A chart', $sanitize->invoke($generator, "```\nA chart\n```", 'alt'));

		$long = $sanitize->invoke($generator, str_repeat('word ', 60), 'alt');
		$this->assertLessThanOrEqual(Generator::ALT_LIMIT + 1, mb_strlen($long));
		$this->assertStringEndsWith('…', $long);
	}
}
