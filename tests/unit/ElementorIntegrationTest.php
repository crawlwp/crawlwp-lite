<?php

declare(strict_types=1);

namespace Mihdan\IndexNow\Tests\Unit;

use Elementor\Core\DocumentTypes\Document;
use Mihdan\IndexNow\SEOCore\Integrations\Elementor;
use Mihdan\IndexNow\SEOCore\MetaBox\MetaFields;
use PHPUnit\Framework\TestCase;

class ElementorIntegrationTest extends TestCase
{
	private Elementor $elementor;

	protected function setUp(): void
	{
		parent::setUp();

		$this->elementor = new Elementor();
		$GLOBALS['crawlwp_test_state']['options'] = [];
		$GLOBALS['crawlwp_test_state']['post_meta'] = [];
		$GLOBALS['crawlwp_test_state']['current_user_can'] = [
			'edit_post'       => true,
			'edit_posts'      => true,
			'manage_options'  => true,
		];
	}

	public function test_register_controls_registers_all_four_sections_and_fields(): void
	{
		$document     = new Document();
		$document->id = 42;

		$this->elementor->register_controls($document);

		// Assert all four structured sections were created
		$this->assertArrayHasKey('crawlwp_seo_general', $document->sections);
		$this->assertArrayHasKey('crawlwp_seo_social', $document->sections);
		$this->assertArrayHasKey('crawlwp_seo_schema', $document->sections);
		$this->assertArrayHasKey('crawlwp_seo_advanced', $document->sections);

		// Assert general controls and actions
		$this->assertArrayNotHasKey('crawlwp_ai_generate', $document->controls);
		$this->assertArrayNotHasKey('crawlwp_seo_analysis', $document->controls);
		$this->assertArrayNotHasKey('crawlwp_seo_preview', $document->controls);
		$this->assertArrayNotHasKey(MetaFields::SEO_SCORE, $document->controls);
		$this->assertArrayHasKey(MetaFields::SEO_TITLE, $document->controls);
		$this->assertArrayHasKey(MetaFields::SEO_DESCRIPTION, $document->controls);
		$this->assertArrayHasKey(MetaFields::FOCUS_KEYWORD, $document->controls);
		$this->assertArrayHasKey(MetaFields::CANONICAL_URL, $document->controls);
		$this->assertArrayHasKey(MetaFields::PRIMARY_CATEGORY, $document->controls);

		// Assert AI is disabled on controls to prevent Elementor AI buttons from showing
		$this->assertFalse($document->controls[MetaFields::SEO_TITLE]['ai']['active'] ?? true);
		$this->assertFalse($document->controls[MetaFields::SEO_DESCRIPTION]['ai']['active'] ?? true);
		$this->assertFalse($document->controls[MetaFields::FOCUS_KEYWORD]['ai']['active'] ?? true);
		$this->assertFalse($document->controls[MetaFields::OG_TITLE]['ai']['active'] ?? true);
		$this->assertFalse($document->controls[MetaFields::X_TITLE]['ai']['active'] ?? true);

		// Assert label_block is true on title and description fields for variable inserter header
		$this->assertTrue($document->controls[MetaFields::SEO_TITLE]['label_block'] ?? false);
		$this->assertTrue($document->controls[MetaFields::SEO_DESCRIPTION]['label_block'] ?? false);

		// Assert dynamic tags are enabled on text and url controls
		$this->assertTrue($document->controls[MetaFields::SEO_TITLE]['dynamic']['active'] ?? false);
		$this->assertTrue($document->controls[MetaFields::SEO_DESCRIPTION]['dynamic']['active'] ?? false);
		$this->assertTrue($document->controls[MetaFields::FOCUS_KEYWORD]['dynamic']['active'] ?? false);
		$this->assertTrue($document->controls[MetaFields::CANONICAL_URL]['dynamic']['active'] ?? false);

		// Assert social controls
		$this->assertArrayHasKey(MetaFields::OG_SYNC, $document->controls);
		$this->assertArrayHasKey(MetaFields::OG_TITLE, $document->controls);
		$this->assertArrayHasKey(MetaFields::OG_DESCRIPTION, $document->controls);
		$this->assertArrayHasKey(MetaFields::OG_IMAGE, $document->controls);
		$this->assertArrayHasKey(MetaFields::OG_IMAGE_ALT, $document->controls);
		$this->assertArrayHasKey(MetaFields::X_SYNC, $document->controls);
		$this->assertArrayHasKey(MetaFields::X_TITLE, $document->controls);
		$this->assertArrayHasKey(MetaFields::X_DESCRIPTION, $document->controls);
		$this->assertArrayHasKey(MetaFields::X_IMAGE, $document->controls);
		$this->assertArrayHasKey(MetaFields::X_CARD_TYPE, $document->controls);
		$this->assertArrayHasKey(MetaFields::X_CREATOR, $document->controls);

		// Assert schema controls
		$this->assertArrayHasKey(MetaFields::SCHEMA_PAGE_TYPE, $document->controls);
		$this->assertArrayHasKey(MetaFields::SCHEMA_ARTICLE_TYPE, $document->controls);
		$this->assertArrayHasKey(MetaFields::SCHEMA_HEADLINE, $document->controls);
		$this->assertArrayHasKey(MetaFields::SCHEMA_SECTION, $document->controls);
		$this->assertArrayHasKey(MetaFields::SCHEMA_BREADCRUMB, $document->controls);
		$this->assertArrayHasKey(MetaFields::SCHEMA_CUSTOM, $document->controls);

		// Assert advanced / robots controls
		$this->assertArrayHasKey(MetaFields::ROBOTS_INDEX, $document->controls);
		$this->assertArrayHasKey(MetaFields::ROBOTS_FOLLOW, $document->controls);
		$this->assertArrayHasKey(MetaFields::ROBOTS_ADVANCED, $document->controls);
		$this->assertArrayHasKey(MetaFields::MAX_SNIPPET, $document->controls);
		$this->assertArrayHasKey(MetaFields::MAX_IMAGE, $document->controls);
		$this->assertArrayNotHasKey(MetaFields::CORNERSTONE, $document->controls);
		$this->assertArrayHasKey(MetaFields::REDIRECT_URL, $document->controls);
		$this->assertArrayHasKey(MetaFields::REDIRECT_TYPE, $document->controls);
		$this->assertArrayNotHasKey('crawlwp_indexnow_action', $document->controls);
	}

	public function test_save_normalizes_and_persists_settings_via_field_processor(): void
	{
		$post_id      = 42;
		$document     = new Document();
		$document->id = $post_id;

		$settings = [
			MetaFields::SEO_TITLE           => 'Elementor SEO Title',
			MetaFields::SEO_DESCRIPTION     => 'Elementor meta description.',
			MetaFields::FOCUS_KEYWORD       => 'elementor seo, wordpress',
			MetaFields::CANONICAL_URL       => ['url' => 'https://example.test/canonical-page'],
			MetaFields::PRIMARY_CATEGORY    => '2',
			MetaFields::OG_SYNC             => 'yes',
			MetaFields::OG_TITLE            => 'Custom OG Title',
			MetaFields::OG_IMAGE            => ['id' => 123, 'url' => 'https://example.test/img.jpg'],
			MetaFields::OG_IMAGE_ALT        => 'Custom Alt',
			MetaFields::X_SYNC              => '',
			MetaFields::X_TITLE             => 'Custom X Title',
			MetaFields::X_IMAGE             => ['id' => 456, 'url' => 'https://example.test/x.jpg'],
			MetaFields::X_CARD_TYPE         => 'summary',
			MetaFields::X_CREATOR           => '@testuser',
			MetaFields::SCHEMA_PAGE_TYPE    => 'AboutPage',
			MetaFields::SCHEMA_ARTICLE_TYPE => 'NewsArticle',
			MetaFields::SCHEMA_HEADLINE     => 'News Headline',
			MetaFields::ROBOTS_INDEX        => 'noindex',
			MetaFields::ROBOTS_FOLLOW       => 'nofollow',
			MetaFields::ROBOTS_ADVANCED     => ['noarchive', 'nosnippet'],
			MetaFields::REDIRECT_URL        => ['url' => 'https://example.test/redirect-target'],
			MetaFields::REDIRECT_TYPE       => '301',
		];

		$this->elementor->save($document, ['settings' => $settings]);

		$meta = $GLOBALS['crawlwp_test_state']['post_meta'][$post_id] ?? [];

		$this->assertSame('Elementor SEO Title', $meta[MetaFields::SEO_TITLE] ?? null);
		$this->assertSame('Elementor meta description.', $meta[MetaFields::SEO_DESCRIPTION] ?? null);
		$this->assertSame('elementor seo, wordpress', $meta[MetaFields::FOCUS_KEYWORD] ?? null);
		$this->assertSame('https://example.test/canonical-page', $meta[MetaFields::CANONICAL_URL] ?? null);
		$this->assertSame(2, $meta[MetaFields::PRIMARY_CATEGORY] ?? null);
		$this->assertSame('1', $meta[MetaFields::OG_SYNC] ?? null);
		$this->assertSame(123, $meta[MetaFields::OG_IMAGE] ?? null);
		$this->assertSame('Custom Alt', $meta[MetaFields::OG_IMAGE_ALT] ?? null);
		$this->assertSame('0', $meta[MetaFields::X_SYNC] ?? null);
		$this->assertSame('Custom X Title', $meta[MetaFields::X_TITLE] ?? null);
		$this->assertSame(456, $meta[MetaFields::X_IMAGE] ?? null);
		$this->assertSame('summary', $meta[MetaFields::X_CARD_TYPE] ?? null);
		$this->assertSame('@testuser', $meta[MetaFields::X_CREATOR] ?? null);
		$this->assertSame('AboutPage', $meta[MetaFields::SCHEMA_PAGE_TYPE] ?? null);
		$this->assertSame('NewsArticle', $meta[MetaFields::SCHEMA_ARTICLE_TYPE] ?? null);
		$this->assertSame('News Headline', $meta[MetaFields::SCHEMA_HEADLINE] ?? null);
		$this->assertSame('noindex', $meta[MetaFields::ROBOTS_INDEX] ?? null);
		$this->assertSame('nofollow', $meta[MetaFields::ROBOTS_FOLLOW] ?? null);
		$this->assertSame(['noarchive', 'nosnippet'], $meta[MetaFields::ROBOTS_ADVANCED] ?? null);
		$this->assertSame('https://example.test/redirect-target', $meta[MetaFields::REDIRECT_URL] ?? null);
		$this->assertSame('301', $meta[MetaFields::REDIRECT_TYPE] ?? null);
		$this->assertArrayNotHasKey(MetaFields::CORNERSTONE, $meta);
		$this->assertArrayNotHasKey(MetaFields::SEO_SCORE, $meta);
	}

	public function test_save_blocks_external_redirect_when_user_lacks_capability(): void
	{
		$post_id      = 42;
		$document     = new Document();
		$document->id = $post_id;

		$GLOBALS['crawlwp_test_state']['current_user_can']['manage_options'] = false;

		$settings = [
			MetaFields::REDIRECT_URL => ['url' => 'https://external-site.com/target'],
		];

		$this->elementor->save($document, ['settings' => $settings]);

		$meta = $GLOBALS['crawlwp_test_state']['post_meta'][$post_id] ?? [];
		$this->assertSame('', $meta[MetaFields::REDIRECT_URL] ?? null);
	}
}
