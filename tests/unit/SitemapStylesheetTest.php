<?php

namespace Mihdan\IndexNow\Tests\Unit;

use Mihdan\IndexNow\SEOCore\SitemapSettings\SitemapStylesheet;
use PHPUnit\Framework\TestCase;

class SitemapStylesheetTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		$GLOBALS['crawlwp_test_state']['home_url'] = 'https://example.test';
		$GLOBALS['crawlwp_test_state']['rewrite_rules'] = [];
		$GLOBALS['crawlwp_test_state']['query_vars'] = [];
	}

	public function test_normalize_type(): void
	{
		$this->assertSame('custom', SitemapStylesheet::normalize_type('custom'));
		$this->assertSame('custom', SitemapStylesheet::normalize_type('crawlwpcustom'));

		$this->assertSame('video', SitemapStylesheet::normalize_type('video'));
		$this->assertSame('video', SitemapStylesheet::normalize_type('crawlwpvideo'));

		$this->assertSame('news', SitemapStylesheet::normalize_type('news'));
		$this->assertSame('news', SitemapStylesheet::normalize_type('crawlwpnews'));

		$this->assertNull(SitemapStylesheet::normalize_type('unknown'));
		$this->assertNull(SitemapStylesheet::normalize_type('sitemap'));
	}

	public function test_get_stylesheet_url(): void
	{
		$custom_url = SitemapStylesheet::get_stylesheet_url('custom');
		$this->assertStringContainsString('sitemap-stylesheet=custom', $custom_url);

		$video_url = SitemapStylesheet::get_stylesheet_url('video');
		$this->assertStringContainsString('sitemap-stylesheet=video', $video_url);

		$news_url = SitemapStylesheet::get_stylesheet_url('news');
		$this->assertStringContainsString('sitemap-stylesheet=news', $news_url);
	}

	public function test_get_custom_sitemap_stylesheet_is_valid_xml(): void
	{
		$stylesheet = new SitemapStylesheet();
		$xsl = $stylesheet->get_sitemap_stylesheet('custom');

		$this->assertStringContainsString('<xsl:stylesheet', $xsl);
		$this->assertStringContainsString('xmlns:sitemap="http://www.sitemaps.org/schemas/sitemap/0.9"', $xsl);
		$this->assertStringContainsString('Custom URLs XML Sitemap', $xsl);

		$xml = simplexml_load_string($xsl);
		$this->assertInstanceOf(\SimpleXMLElement::class, $xml);
	}

	public function test_get_video_sitemap_stylesheet_is_valid_xml(): void
	{
		$stylesheet = new SitemapStylesheet();
		$xsl = $stylesheet->get_sitemap_stylesheet('video');

		$this->assertStringContainsString('<xsl:stylesheet', $xsl);
		$this->assertStringContainsString('xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"', $xsl);
		$this->assertStringContainsString('Video XML Sitemap', $xsl);
		$this->assertStringContainsString('video:video/video:thumbnail_loc', $xsl);

		$xml = simplexml_load_string($xsl);
		$this->assertInstanceOf(\SimpleXMLElement::class, $xml);
	}

	public function test_get_news_sitemap_stylesheet_is_valid_xml(): void
	{
		$stylesheet = new SitemapStylesheet();
		$xsl = $stylesheet->get_sitemap_stylesheet('news');

		$this->assertStringContainsString('<xsl:stylesheet', $xsl);
		$this->assertStringContainsString('xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"', $xsl);
		$this->assertStringContainsString('Google News XML Sitemap', $xsl);
		$this->assertStringContainsString('news:news/news:publication', $xsl);

		$xml = simplexml_load_string($xsl);
		$this->assertInstanceOf(\SimpleXMLElement::class, $xml);
	}

	public function test_add_rewrite_rules(): void
	{
		$stylesheet = new SitemapStylesheet();
		$stylesheet->add_rewrite_rules();

		$rules = $GLOBALS['crawlwp_test_state']['rewrite_rules'];
		$this->assertArrayHasKey('^wp-sitemap-custom\.xsl$', $rules);
		$this->assertArrayHasKey('^wp-sitemap-video\.xsl$', $rules);
		$this->assertArrayHasKey('^wp-sitemap-news\.xsl$', $rules);
	}

	public function test_query_vars(): void
	{
		$stylesheet = new SitemapStylesheet();
		$vars = $stylesheet->query_vars(['sitemap']);

		$this->assertContains('sitemap-stylesheet', $vars);
	}
}
