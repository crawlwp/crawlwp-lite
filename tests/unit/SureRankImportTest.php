<?php

declare(strict_types=1);

namespace Mihdan\IndexNow\Tests\Unit;

use Mihdan\IndexNow\SEOCore\Importer\Sources\SureRank;
use PHPUnit\Framework\TestCase;

require_once CRAWLWP_TESTS_PLUGIN_DIR . '/src/SEOCore/TitleMeta/Options.php';
require_once CRAWLWP_TESTS_PLUGIN_DIR . '/src/SEOCore/Importer/Writer.php';
require_once CRAWLWP_TESTS_PLUGIN_DIR . '/src/SEOCore/Importer/Source.php';
require_once CRAWLWP_TESTS_PLUGIN_DIR . '/src/SEOCore/Importer/Sources/SureRank.php';

class SureRankImportTest extends TestCase
{
	/**
	 * @param array<string, mixed> $meta
	 * @return array<string, mixed>
	 */
	private function data(array $meta): array
	{
		$method = new \ReflectionMethod(SureRank::class, 'object_data');
		$method->setAccessible(true);

		return $method->invoke(new SureRank(), static function (string $key) use ($meta) {
			return $meta[$key] ?? '';
		});
	}

	public function test_post_kept_indexable_inside_a_noindexed_type_stays_indexable(): void
	{
		$data = $this->data(['surerank_settings_post_no_index' => 'no']);

		$this->assertSame('index', $data['robots_index']);
		$this->assertSame('follow', $data['robots_follow']);
		$this->assertArrayNotHasKey('robots_advanced', $data);
	}

	public function test_post_own_noindex_and_noarchive(): void
	{
		$data = $this->data(['surerank_settings_post_no_index' => 'yes', 'surerank_settings_post_no_archive' => 'yes']);

		$this->assertSame('noindex', $data['robots_index']);
		$this->assertSame('follow', $data['robots_follow']);
		$this->assertSame(['noarchive'], $data['robots_advanced']);
	}

	public function test_no_robots_values_inherit_the_post_type(): void
	{
		$data = $this->data([]);

		$this->assertArrayNotHasKey('robots_index', $data);
		$this->assertArrayNotHasKey('robots_follow', $data);
	}
}
