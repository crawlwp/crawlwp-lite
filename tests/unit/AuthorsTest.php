<?php

namespace Mihdan\IndexNow\Tests\Unit;

use Mihdan\IndexNow\SEOCore\Authors\Author;
use Mihdan\IndexNow\SEOCore\Authors\AuthorBox;
use PHPUnit\Framework\TestCase;

/* Loaded here rather than in the shared bootstrap. */
require_once dirname(__DIR__, 2) . '/src/SEOCore/Authors/Author.php';
require_once dirname(__DIR__, 2) . '/src/SEOCore/Authors/AuthorBox.php';

class AuthorsTest extends TestCase
{
	public function test_split_trims_and_drops_empty_and_duplicate_items(): void
	{
		$this->assertSame(
			['WooCommerce', 'online courses', 'WordPress SEO'],
			Author::split(' WooCommerce,  online courses ,, WordPress SEO , WooCommerce', ',')
		);

		$this->assertSame(
			['BSc Computer Science', 'Certified Developer'],
			Author::split("BSc Computer Science\r\n\r\nCertified Developer\n", "\n")
		);

		$this->assertSame([], Author::split('', ','));
	}

	public function test_link_label_names_known_sites(): void
	{
		$this->assertSame('LinkedIn', AuthorBox::link_label('https://www.linkedin.com/in/amara'));
		$this->assertSame('LinkedIn', AuthorBox::link_label('https://uk.linkedin.com/in/amara'));
		$this->assertSame('X', AuthorBox::link_label('https://twitter.com/amara'));
		$this->assertSame('GitHub', AuthorBox::link_label('https://github.com/amara'));
		$this->assertSame('Facebook', AuthorBox::link_label('https://m.facebook.com/amara'));
	}

	public function test_link_label_falls_back_to_the_domain(): void
	{
		$this->assertSame('amara.dev', AuthorBox::link_label('https://www.amara.dev/about'));
		/* A look-alike domain is not mistaken for the real site. */
		$this->assertSame('notlinkedin.com', AuthorBox::link_label('https://notlinkedin.com/x'));
	}
}
