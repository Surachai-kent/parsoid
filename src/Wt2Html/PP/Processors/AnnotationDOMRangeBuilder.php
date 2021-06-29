<?php
namespace Wikimedia\Parsoid\Wt2Html\PP\Processors;

use Wikimedia\Parsoid\DOM\Document;
use Wikimedia\Parsoid\DOM\Element;
use Wikimedia\Parsoid\DOM\Node;
use Wikimedia\Parsoid\Utils\DOMCompat;
use Wikimedia\Parsoid\Utils\DOMDataUtils;
use Wikimedia\Parsoid\Utils\DOMUtils;
use Wikimedia\Parsoid\Utils\WTUtils;
use Wikimedia\Parsoid\Wt2Html\Frame;

class AnnotationDOMRangeBuilder extends DOMRangeBuilder {
	/** @var MigrateTrailingNLs */
	private $migrateTrailingNls;

	/**
	 * AnnotationDOMRangeBuilder constructor.
	 * @param Document $document
	 * @param Frame $frame
	 */
	public function __construct( Document $document, Frame $frame ) {
		parent::__construct( $document, $frame );
		$this->migrateTrailingNls = new MigrateTrailingNLs();
	}

	/**
	 * @param Node $node
	 */
	private function wrapAnnotationsInTree( Node $node ): void {
		$annRanges = self::findWrappableMetaRanges( $node );
		foreach ( $annRanges as $range ) {
			if ( DOMUtils::isFosterablePosition( $range->start ) ) {
				$newStart = $range->start;
				while ( DOMUtils::isFosterablePosition( $newStart ) ) {
					$newStart = $newStart->parentNode;
				}
				$aboutNewStart = $newStart->getAttribute( "about" );
				if ( $aboutNewStart !== null && $aboutNewStart !== "" ) {
					$range->startElem->setAttribute( "about", $aboutNewStart );
				}
				static::moveRangeStart( $range, $newStart );
			}

			if ( DOMUtils::isFosterablePosition( $range->end ) ) {
				$newEnd = $range->end;
				while ( DOMUtils::isFosterablePosition( $newEnd ) ) {
					$newEnd = $newEnd->parentNode;
				}
				$aboutNewEnd = $newEnd->getAttribute( "about" );
				if ( $aboutNewEnd !== null && $aboutNewEnd !== "" ) {
					$range->endElem->setAttribute( "about", $aboutNewEnd );
				}
				static::moveRangeEnd( $range, $newEnd );
			}

			if ( $range->startElem !== $range->start ) {
				static::moveRangeStart( $range, $range->start );
			}
			if ( $range->endElem !== $range->end ) {
				static::moveRangeEnd( $range, $range->end );
			}

			static::setMetaDataMwForRange( $range );
		}
	}

	/**
	 * Moves the start of the range to the designated node
	 * @param DOMRangeInfo $range the range to modify
	 * @param Node $node the new start of the range
	 */
	private function moveRangeStart( DOMRangeInfo $range, Node $node ): void {
		$startMeta = $range->startElem;
		$startDataParsoid = DOMDataUtils::getDataParsoid( $startMeta );
		if ( $node instanceof Element ) {
			if ( DOMCompat::nodeName( $node ) === "p" && $node->firstChild === $startMeta ) {
				// If the first child of "p" is the meta, and it gets moved, then it got mistakenly
				// pulled inside the paragraph, and the paragraph dsr that gets computed includes
				// it - which may lead to the tag getting duplicated on roundtrip. Hence, we
				// adjust the dsr of the paragraph in that case. We also don't consider the meta
				// tag to have been moved in that case.
				$pDataParsoid = DOMDataUtils::getDataParsoid( $node );
				$pDataParsoid->dsr->start = $startDataParsoid->dsr->end;
			} else {
				$startDataParsoid->wasMoved = true;
			}
		}
		$node = self::getStartConsideringFosteredContent( $node );
		$node->parentNode->insertBefore( $startMeta, $node );
		$range->start = $startMeta;
	}

	/**
	 * Moves the start of the range to the designated node
	 * @param DOMRangeInfo $range the range to modify
	 * @param Node $node the new start of the range
	 */
	private function moveRangeEnd( DOMRangeInfo $range, Node $node ): void {
		$endMeta = $range->endElem;
		$endDataParsoid = DOMDataUtils::getDataParsoid( $endMeta );

		if ( $node instanceof Element ) {
			if (
				( DOMCompat::nodeName( $node ) === "p" ) &&
				$node->lastChild === $endMeta
			) {
				// If the first child of "p" is the meta, and it gets moved, then it got mistakenly
				// pulled inside the paragraph, and the paragraph dsr that gets computed includes
				// it - which may lead to the tag getting duplicated on roundtrip. Hence, we
				// adjust the dsr of the paragraph in that case. We also don't consider the meta
				// tag to have been moved in that case.
				$pDataParsoid = DOMDataUtils::getDataParsoid( $node );
				$pDataParsoid->dsr->end = $endDataParsoid->dsr->start;
				$node->parentNode->insertBefore( $node->lastChild, $node->nextSibling );
				$prevLength = strlen( $node->lastChild->textContent ?? '' );
				$this->migrateTrailingNls->doMigrateTrailingNLs( $node, $this->env );
				$newLength = strlen( $node->lastChild->textContent ?? '' );
				if ( $prevLength != $newLength ) {
					$pDataParsoid->dsr->end -= ( $prevLength - $newLength );
				}
			} else {
				$endDataParsoid->wasMoved = true;
				DOMDataUtils::setDataParsoid( $endMeta, $endDataParsoid );
				$node->parentNode->insertBefore( $endMeta, $node->nextSibling );

			}
		}
		$range->end = $endMeta;
	}

	/**
	 * Sets the data-mw attribute for meta tags of the provided range
	 * @param DOMRangeInfo $range range whose start and end element needs to be to modified
	 */
	private static function setMetaDataMwForRange( DOMRangeInfo $range ): void {
		$startDataMw = DOMDataUtils::getDataMw( $range->startElem );
		$endDataMw = DOMDataUtils::getDataMw( $range->endElem );

		$startDataParsoid = DOMDataUtils::getDataParsoid( $range->startElem );
		$endDataParsoid = DOMDataUtils::getDataParsoid( $range->endElem );

		$startDataMw->extendedRange = ( ( $startDataParsoid->wasMoved ?? false ) ||
			( $endDataParsoid->wasMoved ?? false ) );
		$startDataMw->wtOffsets = $startDataParsoid->tsr;
		DOMDataUtils::setDataMw( $range->startElem, $startDataMw );

		$endDataMw->wtOffsets = $endDataParsoid->tsr;
		unset( $endDataMw->rangeId );
		DOMDataUtils::setDataMw( $range->endElem, $endDataMw );
	}

	/**
	 * Returns the meta type of the element if it exists and matches the type expected by the
	 * current class, null otherwise
	 * @param Element $elem the element to check
	 * @return string|null
	 */
	protected function matchMetaType( Element $elem ): ?string {
		// for this class we're interested in the annotation type
		return WTUtils::matchAnnotationMeta( $elem );
	}

	/**
	 * Returns the range ID of a node - in the case of annotations, the "rangeId" property
	 * of its "data-mw" attribute.
	 * @param Element $node
	 * @return string
	 */
	protected function getRangeId( Element $node ): string {
		return DOMDataUtils::getDataMw( $node )->rangeId ?? '';
	}

	/**
	 * @inheritDoc
	 */
	protected static function updateDSRForFirstRangeNode( Element $target, Element $source ): void {
		// nop
	}

	/**
	 * @param Node $root
	 */
	public function execute( Node $root ): void {
		$this->wrapAnnotationsInTree( $root );
	}
}
