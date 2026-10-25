<?php

declare(strict_types=1);

namespace Mihdan\IndexNow\Tests\Unit;

use Mihdan\IndexNow\SEOCore\Importer\Sources\Redirection;
use PHPUnit\Framework\TestCase;

class RedirectionImportTest extends TestCase
{
	private function row(array $over = []): array
	{
		return $over + [
			'url'         => '/old-page/',
			'regex'       => 0,
			'status'      => 'enabled',
			'action_type' => 'url',
			'action_code' => 301,
			'action_data' => '/new-page/',
			'match_type'  => 'url',
			'match_data'  => '',
			'title'       => '',
		];
	}

	public function test_plain_redirect(): void
	{
		$r = Redirection::map_row($this->row(), ['module' => 1, 'enabled' => true]);

		$this->assertSame('/old-page/', $r['from_url']);
		$this->assertSame('/new-page/', $r['to_url']);
		$this->assertSame(301, $r['redirect_type']);
		$this->assertSame('exact', $r['match_type']);
		$this->assertSame(1, $r['enabled']);
		$this->assertSame(0, $r['ignore_query_string']);
	}

	public function test_regex_query_flag_308_and_disabled_group(): void
	{
		$r = Redirection::map_row($this->row([
			'url'         => '^/blog/(.*)',
			'regex'       => 1,
			'action_code' => 308,
			'action_data' => '/news/$1',
			'match_data'  => '{"source":{"flag_query":"ignore"}}',
		]), ['module' => 1, 'enabled' => false]);

		$this->assertSame('regex', $r['match_type']);
		$this->assertSame(301, $r['redirect_type']);
		$this->assertSame('/news/$1', $r['to_url']);
		$this->assertSame(1, $r['ignore_query_string']);
		$this->assertSame(0, $r['enabled']);
	}

	public function test_410_error_keeps_no_target(): void
	{
		$r = Redirection::map_row($this->row(['action_type' => 'error', 'action_code' => 410, 'action_data' => '']), null);

		$this->assertSame(410, $r['redirect_type']);
		$this->assertSame('', $r['to_url']);
	}

	public function test_unsupported_rows_are_skipped(): void
	{
		$this->assertNull(Redirection::map_row($this->row(['match_type' => 'login']), null), 'login-based redirect');
		$this->assertNull(Redirection::map_row($this->row(['action_type' => 'random']), null), 'random post');
		$this->assertNull(Redirection::map_row($this->row(['action_type' => 'error', 'action_code' => 404]), null), '404 error');
		$this->assertNull(Redirection::map_row($this->row(), ['module' => 2, 'enabled' => true]), 'Apache group');
		$this->assertNull(Redirection::map_row($this->row(['action_data' => '']), null), 'no target');
	}
}
