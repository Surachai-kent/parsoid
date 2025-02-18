<?php

namespace Test\Parsoid\Wt2Html\PP\Handlers;

use PHPUnit\Framework\TestCase;
use Wikimedia\Parsoid\DOM\Element;
use Wikimedia\Parsoid\Mocks\MockDataAccess;
use Wikimedia\Parsoid\Mocks\MockPageConfig;
use Wikimedia\Parsoid\Mocks\MockPageContent;
use Wikimedia\Parsoid\Mocks\MockSiteConfig;
use Wikimedia\Parsoid\Parsoid;
use Wikimedia\Parsoid\Utils\ContentUtils;
use Wikimedia\Parsoid\Utils\DOMCompat;
use Wikimedia\Parsoid\Utils\DOMDataUtils;

class UnpackDOMFragmentsTest extends TestCase {
	/**
	 * @param array $wt
	 * @return Element
	 */
	private function getOutput( string $wt ): Element {
		$siteConfig = new MockSiteConfig( [] );
		$dataAccess = new MockDataAccess( [] );
		$parsoid = new Parsoid( $siteConfig, $dataAccess );

		$content = new MockPageContent( [ 'main' => $wt ] );
		$pageConfig = new MockPageConfig( [], $content );
		$html = $parsoid->wikitext2html( $pageConfig, [ "wrapSections" => false ] );

		$doc = ContentUtils::createAndLoadDocument( $html );

		// Prevent GC from reclaiming $doc once we exit this function.
		// Necessary hack because we use PHPDOM which wraps libxml.
		$this->liveDocs[] = $doc;

		return DOMCompat::getBody( $doc );
	}

	/**
	 * @param Element $body
	 */
	private function validateFixedupDSR( Element $body ): void {
		$links = DOMCompat::querySelectorAll( $body, 'a' );
		foreach ( $links as $link ) {
			$dp = DOMDataUtils::getDataParsoid( $link );
			if ( $dp->misnested ?? false ) {
				$hoistedNode = $link;
				$hoistedNodeDSR = $dp->dsr ?? null;
				$outerLink = $hoistedNode->previousSibling;
				while ( !$outerLink ) {
					$this->assertTrue( $dp->misnested );
					$this->assertNotNull( $hoistedNodeDSR );

					$hoistedNode = $hoistedNode->parentNode;
					$dp = DOMDataUtils::getDataParsoid( $hoistedNode );
					$hoistedNodeDSR = $dp->dsr ?? null;
					$outerLink = $hoistedNode->previousSibling;
				}
				$this->assertSame( DOMCompat::nodeName( $outerLink ), 'a' );
				$outerLinkDSR = DOMDataUtils::getDataParsoid( $outerLink )->dsr ?? null;
				$this->assertNotNull( $outerLinkDSR );
				$this->assertSame( $outerLinkDSR->end, $hoistedNodeDSR->start );
			}
		}
	}

	/**
	 * @covers \Wikimedia\Parsoid\Wt2Html\PP\Handlers\UnpackDOMFragments
	 * @dataProvider provideFixMisnestedTagDSRCases
	 * @param string $wt
	 */
	public function testFixMisnestedTagDSRCases( string $wt ): void {
		// Strictly speaking, *NOT* a unit test, but we are
		// abusing this notion for verification of properties
		// not easily verifiable via parser tests.
		$this->validateFixedupDSR( $this->getOutput( $wt ) );
	}

	/**
	 * @return array
	 */
	public function provideFixMisnestedTagDSRCases(): array {
		return [
			[ "[http://example.org Link with [[wikilink]] link in the label]" ],
			[ "[http://example.org <span>[[wikilink]]</span> link in the label]" ],
			[ "[http://example.org <div>[[wikilink]]</div> link in the label]" ],
			[ "[http://example.org <b>''[[wikilink]]''</b> link in the label]" ]
		];
	}

}
