<?php

namespace Mihdan\IndexNow\Tests\Unit;

use Mihdan\IndexNow\SEOCore\Code\CodeSettings;
use PHPUnit\Framework\TestCase;

class CodeSettingsTest extends TestCase
{
	private CodeSettings $code_settings;

	private string $sample_script = <<<'EOD'
<script type="text/javascript">
window.heap=window.heap||[],heap.load=function(e,t){window.heap.appid=e,window.heap.config=t=t||{};var r=document.createElement("script");r.type="text/javascript",r.async=!0,r.src="https://cdn.heapanalytics.com/js/heap-"+e+".js";var a=document.getElementsByTagName("script")[0];a.parentNode.insertBefore(r,a);for(var n=function(e){return function(){heap.push([e].concat(Array.prototype.slice.call(arguments,0)))}},p=["addEventProperties","addUserProperties","clearEventProperties","identify","resetIdentity","removeEventProperty","setEventProperties","track","unsetEventProperty"],o=0;o<p.length;o++)heap[p[o]]=n(p[o])};
heap.load("YOUR_APP_ID");
</script>
EOD;

	protected function setUp(): void
	{
		parent::setUp();

		$this->code_settings = new CodeSettings();
		$GLOBALS['crawlwp_test_state']['options'] = [];
		$GLOBALS['crawlwp_test_state']['current_user_can'] = [
			'unfiltered_html' => true,
		];
	}

	public function test_sanitize_code_field_preserves_scripts_when_user_can_edit_code(): void
	{
		$this->assertSame($this->sample_script, $this->code_settings->sanitize_head($this->sample_script));
		$this->assertSame($this->sample_script, $this->code_settings->sanitize_body($this->sample_script));
		$this->assertSame($this->sample_script, $this->code_settings->sanitize_footer($this->sample_script));
	}

	public function test_sanitize_code_field_allows_clearing(): void
	{
		$this->assertSame('', $this->code_settings->sanitize_head(''));
		$this->assertSame('', $this->code_settings->sanitize_body(''));
		$this->assertSame('', $this->code_settings->sanitize_footer(''));
	}

	public function test_sanitize_code_field_returns_existing_value_when_user_cannot_edit_code(): void
	{
		$GLOBALS['crawlwp_test_state']['options']['crawlwp_code'] = [
			'head'   => '<script>existing_head</script>',
			'body'   => '<script>existing_body</script>',
			'footer' => '<script>existing_footer</script>',
		];
		$GLOBALS['crawlwp_test_state']['current_user_can']['unfiltered_html'] = false;

		$this->assertSame('<script>existing_head</script>', $this->code_settings->sanitize_head($this->sample_script));
		$this->assertSame('<script>existing_body</script>', $this->code_settings->sanitize_body($this->sample_script));
		$this->assertSame('<script>existing_footer</script>', $this->code_settings->sanitize_footer($this->sample_script));
	}

	public function test_sanitize_code_field_non_scalar_returns_empty_string(): void
	{
		$this->assertSame('', $this->code_settings->sanitize_head(['invalid']));
		$this->assertSame('', $this->code_settings->sanitize_body(null));
		$this->assertSame('', $this->code_settings->sanitize_footer(new \stdClass()));
	}

	public function test_gate_raw_code_fields_unsets_when_user_cannot_edit_code(): void
	{
		$GLOBALS['crawlwp_test_state']['current_user_can']['unfiltered_html'] = false;

		$submitted = [
			'ga4_id' => 'G-12345678',
			'head'   => $this->sample_script,
			'body'   => $this->sample_script,
			'footer' => $this->sample_script,
		];

		$result = $this->code_settings->gate_raw_code_fields($submitted, 'crawlwp_code');

		$this->assertArrayNotHasKey('head', $result);
		$this->assertArrayNotHasKey('body', $result);
		$this->assertArrayNotHasKey('footer', $result);
		$this->assertSame('G-12345678', $result['ga4_id']);
	}

	public function test_gate_raw_code_fields_keeps_fields_when_user_can_edit_code(): void
	{
		$GLOBALS['crawlwp_test_state']['current_user_can']['unfiltered_html'] = true;

		$submitted = [
			'ga4_id' => 'G-12345678',
			'head'   => $this->sample_script,
			'body'   => $this->sample_script,
			'footer' => $this->sample_script,
		];

		$result = $this->code_settings->gate_raw_code_fields($submitted, 'crawlwp_code');

		$this->assertSame($submitted, $result);
	}

	public function test_output_head_body_footer(): void
	{
		$GLOBALS['crawlwp_test_state']['options']['crawlwp_code'] = [
			'head'   => '<script>head_code();</script>',
			'body'   => '<div>body_code</div>',
			'footer' => '<script>footer_code();</script>',
		];

		ob_start();
		$this->code_settings->output_head();
		$head_output = ob_get_clean();

		ob_start();
		$this->code_settings->output_body();
		$body_output = ob_get_clean();

		ob_start();
		$this->code_settings->output_footer();
		$footer_output = ob_get_clean();

		$this->assertStringContainsString('<script>head_code();</script>', $head_output);
		$this->assertStringContainsString('<div>body_code</div>', $body_output);
		$this->assertStringContainsString('<script>footer_code();</script>', $footer_output);
	}
}
