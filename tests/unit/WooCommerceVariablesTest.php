<?php

namespace Mihdan\IndexNow\Tests\Unit;

use Mihdan\IndexNow\SEOCore\Importer\TokenMapper;
use Mihdan\IndexNow\SEOCore\TitleMeta\Variables;
use PHPUnit\Framework\TestCase;

class WooCommerceVariablesTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		$GLOBALS['crawlwp_test_state']['wc_products'] = [];
		$GLOBALS['crawlwp_test_state']['wc_currency'] = 'USD';
		$GLOBALS['crawlwp_test_state']['wc_tax_rate'] = 1.2;
	}

	public function test_definitions_includes_woocommerce_group(): void
	{
		$defs = Variables::definitions();

		$this->assertArrayHasKey('woocommerce', $defs);
		$this->assertSame('WooCommerce', $defs['woocommerce']['label']);

		$expected_vars = [
			'product.price',
			'product.price_with_tax',
			'product.sale_from',
			'product.sale_to',
			'product.sku',
			'product.stock',
			'product.currency',
			'product.rating',
			'product.review_count',
			'product.low_price',
			'product.high_price',
			'product.offer_count',
		];

		foreach ($expected_vars as $var) {
			$this->assertArrayHasKey($var, $defs['woocommerce']['variables'], "Missing variable: {$var}");
		}
	}

	public function test_simple_product_tokens_resolution(): void
	{
		$post            = new \WP_Post();
		$post->ID        = 101;
		$post->post_type = 'product';
		$post->post_title = 'Sample Hoodie';

		$product                 = new \WC_Product();
		$product->id             = 101;
		$product->price          = '49.99';
		$product->sku            = 'HD-001';
		$product->stock_status   = 'instock';
		$product->average_rating = 4.75;
		$product->review_count   = 12;
		$product->on_sale        = true;

		$from_date = (object) ['timestamp' => strtotime('2026-01-01')];
		$to_date   = (object) ['timestamp' => strtotime('2026-12-31')];
		$product->date_on_sale_from = new class($from_date->timestamp) {
			public function __construct(private int $ts) {}
			public function getTimestamp(): int { return $this->ts; }
		};
		$product->date_on_sale_to = new class($to_date->timestamp) {
			public function __construct(private int $ts) {}
			public function getTimestamp(): int { return $this->ts; }
		};

		$GLOBALS['crawlwp_test_state']['wc_products'][101] = $product;

		$context = ['post' => $post];

		$this->assertSame('49.99', Variables::replace('{{ product.price }}', $context));
		$this->assertSame((string) (49.99 * 1.2), Variables::replace('{{ product.price_with_tax }}', $context));
		$this->assertSame('HD-001', Variables::replace('{{ product.sku }}', $context));
		$this->assertSame('In stock', Variables::replace('{{ product.stock }}', $context));
		$this->assertSame('USD', Variables::replace('{{ product.currency }}', $context));
		$this->assertSame('4.75', Variables::replace('{{ product.rating }}', $context));
		$this->assertSame('12', Variables::replace('{{ product.review_count }}', $context));
		$this->assertSame('2026-01-01', Variables::replace('{{ product.sale_from }}', $context));
		$this->assertSame('2026-12-31', Variables::replace('{{ product.sale_to }}', $context));
	}

	public function test_variable_product_tokens_resolution(): void
	{
		$post            = new \WP_Post();
		$post->ID        = 202;
		$post->post_type = 'product';

		$product                   = new \WC_Product();
		$product->id               = 202;
		$product->type             = 'variable';
		$product->variation_prices = ['min' => 15, 'max' => 35];
		$product->children         = [203, 204, 205];

		$GLOBALS['crawlwp_test_state']['wc_products'][202] = $product;

		$context = ['post' => $post];

		// tax rate 1.2: 15 * 1.2 = 18, 35 * 1.2 = 42
		$this->assertSame('18', Variables::replace('{{ product.low_price }}', $context));
		$this->assertSame('42', Variables::replace('{{ product.high_price }}', $context));
		$this->assertSame('3', Variables::replace('{{ product.offer_count }}', $context));
	}

	public function test_non_product_post_returns_empty_for_product_tokens(): void
	{
		$post            = new \WP_Post();
		$post->ID        = 303;
		$post->post_type = 'post';

		$context = ['post' => $post];

		$this->assertSame('', Variables::replace('{{ product.price }}', $context));
		$this->assertSame('', Variables::replace('{{ product.sku }}', $context));
		$this->assertSame('', Variables::replace('{{ product.stock }}', $context));
	}

	public function test_token_mapper_converts_woocommerce_tokens(): void
	{
		// Yoast
		$yoast = 'Buy for %%wc_price%% SKU: %%wc_sku%%';
		$this->assertSame('Buy for {{ product.price }} SKU: {{ product.sku }}', TokenMapper::convert($yoast, 'yoast'));

		// Rank Math
		$rm = 'Price: %wc_price% SKU: %wc_sku%';
		$this->assertSame('Price: {{ product.price }} SKU: {{ product.sku }}', TokenMapper::convert($rm, 'rankmath'));

		// SEOPress
		$sp = 'Price: %%wc_single_price%% Inc Tax: %%wc_single_price_inc_tax%% SKU: %%wc_sku%%';
		$this->assertSame('Price: {{ product.price }} Inc Tax: {{ product.price_with_tax }} SKU: {{ product.sku }}', TokenMapper::convert($sp, 'seopress'));
	}
}
