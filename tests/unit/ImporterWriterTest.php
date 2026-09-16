<?php

namespace Mihdan\IndexNow\Tests\Unit;

use Mihdan\IndexNow\SEOCore\Importer\Writer;
use PHPUnit\Framework\TestCase;

require_once CRAWLWP_TESTS_PLUGIN_DIR . '/src/SEOCore/TitleMeta/Options.php';
require_once CRAWLWP_TESTS_PLUGIN_DIR . '/src/SEOCore/Importer/Writer.php';

class ImporterWriterTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		$GLOBALS['crawlwp_test_state']['options'] = [];
	}

	public function test_global_attachment_ids_are_stored_as_image_urls(): void
	{
		$result = Writer::write_settings([
			'site_info' => ['logo' => 41],
			'social'    => ['social_image_fallback' => '42'],
		], false);

		$this->assertSame(2, $result['imported']);
		$this->assertSame('https://example.test/uploads/41.jpg', $GLOBALS['crawlwp_test_state']['options']['crawlwp_site_info']['logo']);
		$this->assertSame('https://example.test/uploads/42.jpg', $GLOBALS['crawlwp_test_state']['options']['crawlwp_social']['social_image_fallback']);
	}

	public function test_global_image_urls_are_preserved(): void
	{
		Writer::write_settings([
			'site_info' => ['logo' => 'https://cdn.example.test/logo.png'],
			'social'    => ['social_image_fallback' => 'https://cdn.example.test/social.png'],
		], false);

		$this->assertSame('https://cdn.example.test/logo.png', $GLOBALS['crawlwp_test_state']['options']['crawlwp_site_info']['logo']);
		$this->assertSame('https://cdn.example.test/social.png', $GLOBALS['crawlwp_test_state']['options']['crawlwp_social']['social_image_fallback']);
	}

	public function test_invalid_global_attachment_ids_are_not_imported(): void
	{
		$result = Writer::write_settings([
			'site_info' => ['logo' => 0],
			'social'    => ['social_image_fallback' => -1],
		], false);

		$this->assertSame(0, $result['imported']);
		$this->assertArrayNotHasKey('crawlwp_site_info', $GLOBALS['crawlwp_test_state']['options']);
		$this->assertArrayNotHasKey('crawlwp_social', $GLOBALS['crawlwp_test_state']['options']);
	}
}