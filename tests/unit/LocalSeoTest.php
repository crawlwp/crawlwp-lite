<?php

namespace Mihdan\IndexNow\Tests\Unit;

use Mihdan\IndexNow\SEOCore\LocalSeo\LocalSeo;
use Mihdan\IndexNow\SEOCore\LocalSeo\OpeningHours;
use PHPUnit\Framework\TestCase;

/* Loaded here rather than in the shared bootstrap. */
require_once dirname(__DIR__, 2) . '/src/SEOCore/LocalSeo/OpeningHours.php';
require_once dirname(__DIR__, 2) . '/src/SEOCore/LocalSeo/LocalSeo.php';

class LocalSeoTest extends TestCase
{
	public function test_time_is_normalised_and_validated(): void
	{
		$this->assertSame('09:05', OpeningHours::time('9:05'));
		$this->assertSame('24:00', OpeningHours::time('24:00'));
		$this->assertSame('', OpeningHours::time('25:00'));
		$this->assertSame('', OpeningHours::time('nine'));
	}

	public function test_sanitize_reads_the_posted_editor(): void
	{
		$hours = OpeningHours::sanitize([
			'mon' => ['open' => '1', 'ranges' => [['09:00', '13:00'], ['14:00', '17:00']]],
			'tue' => ['open' => '0', 'ranges' => [['09:00', '17:00']]],
			'wed' => ['open' => '1', 'ranges' => [['09:00', ''], ['', '']]],
		]);

		$this->assertSame([['09:00', '13:00'], ['14:00', '17:00']], $hours['mon']);
		$this->assertSame([], $hours['tue'], 'An unticked day is closed.');
		$this->assertSame([], $hours['wed'], 'A range without a closing time is dropped.');
		$this->assertNull(OpeningHours::sanitize(['mon' => ['open' => '0']]), 'No open day means no hours.');
	}

	public function test_to_schema_groups_days_with_the_same_hours(): void
	{
		$specs = OpeningHours::to_schema([
			'mon' => [['09:00', '17:00']],
			'tue' => [['09:00', '17:00']],
			'sat' => [['10:00', '14:00']],
			'sun' => [],
		]);

		$this->assertCount(2, $specs);
		$this->assertSame(['Monday', 'Tuesday'], $specs[0]['dayOfWeek']);
		$this->assertSame('17:00', $specs[0]['closes']);
		$this->assertSame('Saturday', $specs[1]['dayOfWeek']);
	}

	public function test_old_free_text_hours_are_converted(): void
	{
		$hours = LocalSeo::parse_legacy_hours("Mo-Fr 09:00-17:00\nSa,Su 10:00-14:00");

		$this->assertSame([['09:00', '17:00']], $hours['wed']);
		$this->assertSame([['10:00', '14:00']], $hours['sun']);
		$this->assertNull(LocalSeo::parse_legacy_hours('Weekdays from nine'), 'Text it can\'t read is left alone.');
	}
}
