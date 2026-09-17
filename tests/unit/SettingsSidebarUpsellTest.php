<?php

namespace Mihdan\IndexNow\Tests\Unit;

use Mihdan\IndexNow\Logger\Logger;
use Mihdan\IndexNow\Views\Settings;
use Mihdan\IndexNow\Views\WPOSA;
use PHPUnit\Framework\TestCase;

class SettingsSidebarUpsellTest extends TestCase
{
	public function test_sidebar_upsell_contains_moved_pro_features(): void
	{
		$_GET['wposa-menu'] = 'non_index_menu';
		$logger = $this->createMock(Logger::class);
		$wposa  = new WPOSA('CrawlWP', '3.0', 'crawlwp', 'crawlwp');
		$settings = new Settings($logger, $wposa);
		$settings->setup_fields();

		$cards = $wposa->get_sidebar_cards();
		$this->assertNotEmpty($cards);

		$upsell_card = null;
		foreach ($cards as $card) {
			if (($card['id'] ?? '') === 'upsell_card') {
				$upsell_card = $card;
				break;
			}
		}

		$this->assertNotNull($upsell_card, 'Upsell card should be registered in sidebar');
		$this->assertSame('Get CrawlWP Premium', $upsell_card['title']);

		$desc = $upsell_card['desc'];
		$this->assertStringContainsString('Google Search Insights', $desc);
		$this->assertStringContainsString('Bing Search Insights', $desc);
		$this->assertStringContainsString('Yandex Search Insights', $desc);
		$this->assertStringContainsString('Auto Indexing', $desc);
		$this->assertStringContainsString('Index Status Insights', $desc);
		$this->assertStringContainsString('Index History', $desc);
		$this->assertStringContainsString('Keyword Tracking', $desc);
		$this->assertStringContainsString('SEO Stats &amp; Index Email Report', $desc);

		// Moved Pro features:
		$this->assertStringContainsString('Video, HTML and Custom URL Sitemaps', $desc);
		$this->assertStringContainsString('Internal Linking', $desc);
		$this->assertStringContainsString('WPML, Polylang, TranslatePress Sitemap Integrations', $desc);
	}
}
