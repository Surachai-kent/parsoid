<?php
declare( strict_types = 1 );

namespace Wikimedia\Parsoid\Utils;

use Wikimedia\Parsoid\Config\Env;
use Wikimedia\Parsoid\DOM\Comment;
use Wikimedia\Parsoid\DOM\Document;
use Wikimedia\Parsoid\DOM\DocumentFragment;
use Wikimedia\Parsoid\DOM\Element;
use Wikimedia\Parsoid\DOM\Node;
use Wikimedia\Parsoid\DOM\Text;
use Wikimedia\Parsoid\Ext\ExtensionTagHandler;
use Wikimedia\Parsoid\NodeData\DataParsoid;
use Wikimedia\Parsoid\NodeData\TempData;
use Wikimedia\Parsoid\Tokens\CommentTk;
use Wikimedia\Parsoid\Wikitext\Consts;
use Wikimedia\Parsoid\Wt2Html\Frame;

/**
 * These utilites pertain to querying / extracting / modifying wikitext information from the DOM.
 */
class WTUtils {
	private const FIRST_ENCAP_REGEXP =
		'#(?:^|\s)(mw:(?:Transclusion|Param|LanguageVariant|Extension(/[^\s]+)))(?=$|\s)#D';

	/**
	 * Regexp for checking marker metas typeofs representing
	 * transclusion markup or template param markup.
	 */
	private const TPL_META_TYPE_REGEXP = '#^mw:(?:Transclusion|Param)(?:/End)?$#D';

	/**
	 * Regexp for checking marker metas typeofs representing
	 * annotation markup
	 */
	public const ANNOTATION_META_TYPE_REGEXP = '#^mw:(?:Annotation/([\w\d]+))(?:/End)?$#uD';

	/**
	 * Check whether a node's data-parsoid object includes
	 * an indicator that the original wikitext was a literal
	 * HTML element (like table or p)
	 *
	 * @param DataParsoid $dp
	 * @return bool
	 */
	public static function hasLiteralHTMLMarker( DataParsoid $dp ): bool {
		return isset( $dp->stx ) && $dp->stx === 'html';
	}

	/**
	 * Run a node through {@link #hasLiteralHTMLMarker}.
	 * @param ?Node $node
	 * @return bool
	 */
	public static function isLiteralHTMLNode( ?Node $node ): bool {
		return $node instanceof Element &&
			self::hasLiteralHTMLMarker( DOMDataUtils::getDataParsoid( $node ) );
	}

	/**
	 * @param Node $node
	 * @return bool
	 */
	public static function isZeroWidthWikitextElt( Node $node ): bool {
		return isset( Consts::$ZeroWidthWikitextTags[DOMCompat::nodeName( $node )] ) &&
			!self::isLiteralHTMLNode( $node );
	}

	/**
	 * Is `$node` a block node that is also visible in wikitext?
	 * An example of an invisible block node is a `<p>`-tag that
	 * Parsoid generated, or a `<ul>`, `<ol>` tag.
	 *
	 * @param Node $node
	 * @return bool
	 */
	public static function isBlockNodeWithVisibleWT( Node $node ): bool {
		return DOMUtils::isWikitextBlockNode( $node ) &&
			!self::isZeroWidthWikitextElt( $node );
	}

	/**
	 * Helper functions to detect when an A-$node uses [[..]]/[..]/... style
	 * syntax (for wikilinks, ext links, url links). rel-type is not sufficient
	 * anymore since mw:ExtLink is used for all the three link syntaxes.
	 *
	 * @param Element $node
	 * @param ?DataParsoid $dp
	 * @return bool
	 */
	public static function usesWikiLinkSyntax(
		Element $node, ?DataParsoid $dp
	): bool {
		// FIXME: Optimization from ComputeDSR to avoid refetching this property
		// Is it worth the unnecessary code here?
		if ( !$dp ) {
			$dp = DOMDataUtils::getDataParsoid( $node );
		}

		// SSS FIXME: This requires to be made more robust
		// for when dp->stx value is not present
		return $node->getAttribute( "rel" ) === "mw:WikiLink" ||
			( isset( $dp->stx ) && $dp->stx !== "url" && $dp->stx !== "magiclink" );
	}

	/**
	 * Helper function to detect when an A-node uses ext-link syntax.
	 * rel attribute is not sufficient anymore since mw:ExtLink is used for
	 * multiple link types
	 *
	 * @param Element $node
	 * @param ?DataParsoid $dp
	 * @return bool
	 */
	public static function usesExtLinkSyntax(
		Element $node, ?DataParsoid $dp
	): bool {
		// FIXME: Optimization from ComputeDSR to avoid refetching this property
		// Is it worth the unnecessary code here?
		if ( !$dp ) {
			$dp = DOMDataUtils::getDataParsoid( $node );
		}

		// SSS FIXME: This requires to be made more robust
		// for when $dp->stx value is not present
		return $node->getAttribute( "rel" ) === "mw:ExtLink" &&
			( !isset( $dp->stx ) || ( $dp->stx !== "url" && $dp->stx !== "magiclink" ) );
	}

	/**
	 * Helper function to detect when an A-node uses url-link syntax.
	 * rel attribute is not sufficient anymore since mw:ExtLink is used for
	 * multiple link types
	 *
	 * @param Element $node
	 * @param ?DataParsoid $dp
	 * @return bool
	 */
	public static function usesURLLinkSyntax(
		Element $node, ?DataParsoid $dp = null
	): bool {
		// FIXME: Optimization from ComputeDSR to avoid refetching this property
		// Is it worth the unnecessary code here?
		if ( !$dp ) {
			$dp = DOMDataUtils::getDataParsoid( $node );
		}

		// SSS FIXME: This requires to be made more robust
		// for when $dp->stx value is not present
		return $node->getAttribute( "rel" ) === "mw:ExtLink" &&
			isset( $dp->stx ) && $dp->stx === "url";
	}

	/**
	 * Helper function to detect when an A-node uses magic-link syntax.
	 * rel attribute is not sufficient anymore since mw:ExtLink is used for
	 * multiple link types
	 *
	 * @param Element $node
	 * @param ?DataParsoid $dp
	 * @return bool
	 */
	public static function usesMagicLinkSyntax(
		Element $node, ?DataParsoid $dp = null
	): bool {
		if ( !$dp ) {
			$dp = DOMDataUtils::getDataParsoid( $node );
		}

		// SSS FIXME: This requires to be made more robust
		// for when $dp->stx value is not present
		return $node->getAttribute( "rel" ) === "mw:ExtLink" &&
			isset( $dp->stx ) && $dp->stx === "magiclink";
	}

	/**
	 * Check whether a node's typeof indicates that it is a template expansion.
	 *
	 * @param Element $node
	 * @return ?string The matched type, or null if no match.
	 */
	public static function matchTplType( Element $node ): ?string {
		return DOMUtils::matchTypeOf( $node, self::TPL_META_TYPE_REGEXP );
	}

	/**
	 * Check whether a typeof indicates that it signifies an
	 * expanded attribute.
	 *
	 * @param Element $node
	 * @return bool
	 */
	public static function hasExpandedAttrsType( Element $node ): bool {
		return DOMUtils::matchTypeOf( $node, '/^mw:ExpandedAttrs(\/[^\s]+)*$/' ) !== null;
	}

	/**
	 * Check whether a node is a meta tag that signifies a template expansion.
	 *
	 * @param Node $node
	 * @return bool
	 */
	public static function isTplMarkerMeta( Node $node ): bool {
		return DOMUtils::matchNameAndTypeOf( $node, 'meta', self::TPL_META_TYPE_REGEXP ) !== null;
	}

	/**
	 * Check whether a node is a meta signifying the start of a template expansion.
	 *
	 * @param Node $node
	 * @return bool
	 */
	public static function isTplStartMarkerMeta( Node $node ): bool {
		$t = DOMUtils::matchNameAndTypeOf( $node, 'meta', self::TPL_META_TYPE_REGEXP );
		return $t !== null && !str_ends_with( $t, '/End' );
	}

	/**
	 * Check whether a node is a meta signifying the end of a template expansion.
	 *
	 * @param Node $node
	 * @return bool
	 */
	public static function isTplEndMarkerMeta( Node $node ): bool {
		$t = DOMUtils::matchNameAndTypeOf( $node, 'meta', self::TPL_META_TYPE_REGEXP );
		return $t !== null && str_ends_with( $t, '/End' );
	}

	/**
	 * Find the first wrapper element of encapsulated content.
	 * @param Node $node
	 * @return Element|null
	 */
	public static function findFirstEncapsulationWrapperNode( Node $node ): ?Element {
		if ( !self::hasParsoidAboutId( $node ) ) {
			return null;
		}
		/** @var Element $node */
		DOMUtils::assertElt( $node );

		$about = $node->getAttribute( 'about' );
		$prev = $node;
		do {
			$node = $prev;
			$prev = DOMUtils::previousNonDeletedSibling( $node );
		} while (
			$prev instanceof Element &&
			$prev->getAttribute( 'about' ) === $about
		);
		$elt = self::isFirstEncapsulationWrapperNode( $node ) ? $node : null;
		'@phan-var ?Element $elt'; // @var ?Element $elt
		return $elt;
	}

	/**
	 * This tests whether a DOM node is a new node added during an edit session
	 * or an existing node from parsed wikitext.
	 *
	 * As written, this function can only be used on non-template/extension content
	 * or on the top-level nodes of template/extension content. This test will
	 * return the wrong results on non-top-level $nodes of template/extension content.
	 *
	 * @param Node $node
	 * @return bool
	 */
	public static function isNewElt( Node $node ): bool {
		// We cannot determine newness on text/comment $nodes.
		if ( !( $node instanceof Element ) ) {
			return false;
		}

		// For template/extension content, newness should be
		// checked on the encapsulation wrapper $node.
		$node = self::findFirstEncapsulationWrapperNode( $node ) ?? $node;
		return DOMDataUtils::getDataParsoid( $node )->getTempFlag( TempData::IS_NEW );
	}

	/**
	 * Check whether a pre is caused by indentation in the original wikitext.
	 * @param Node $node
	 * @return bool
	 */
	public static function isIndentPre( Node $node ): bool {
		return DOMCompat::nodeName( $node ) === "pre" && !self::isLiteralHTMLNode( $node );
	}

	/**
	 * @param Node $node
	 * @return bool
	 */
	public static function isInlineMedia( Node $node ): bool {
		return self::isGeneratedFigure( $node ) &&
			DOMCompat::nodeName( $node ) !== 'figure';  // span, figure-inline
	}

	/**
	 * @param Node $node
	 * @return bool
	 */
	public static function isGeneratedFigure( Node $node ): bool {
		// TODO: Remove "Image|Video|Audio" when version 2.4.0 of the content
		// is no longer supported
		return DOMUtils::matchTypeOf( $node, '#^mw:(File|Image|Video|Audio)($|/)#D' ) !== null;
	}

	/**
	 * Find how much offset is necessary for the DSR of an
	 * indent-originated pre tag.
	 *
	 * @param Node $textNode
	 * @return int
	 */
	public static function indentPreDSRCorrection( Node $textNode ): int {
		// NOTE: This assumes a text-node and doesn't check that it is one.
		//
		// FIXME: Doesn't handle text nodes that are not direct children of the pre
		if ( self::isIndentPre( $textNode->parentNode ) ) {
			$numNLs = substr_count( $textNode->nodeValue, "\n" );
			if ( $textNode->parentNode->lastChild === $textNode ) {
				// We dont want the trailing newline of the last child of the pre
				// to contribute a pre-correction since it doesn't add new content
				// in the pre-node after the text
				if ( str_ends_with( $textNode->nodeValue, "\n" ) ) {
					$numNLs--;
				}
			}
			return $numNLs;
		} else {
			return 0;
		}
	}

	/**
	 * Check if $node is an ELEMENT $node belongs to a template/extension.
	 *
	 * NOTE: Use with caution. This technique works reliably for the
	 * root level elements of tpl-content DOM subtrees since only they
	 * are guaranteed to be  marked and nested content might not
	 * necessarily be marked.
	 *
	 * @param Node $node
	 * @return bool
	 */
	public static function hasParsoidAboutId( Node $node ): bool {
		if (
			$node instanceof Element &&
			$node->hasAttribute( 'about' )
		) {
			$about = $node->getAttribute( 'about' );
			// SSS FIXME: Verify that our DOM spec clarifies this
			// expectation on about-ids and that our clients respect this.
			return $about && Utils::isParsoidObjectId( $about );
		} else {
			return false;
		}
	}

	/**
	 * Does $node represent a redirect link?
	 *
	 * @param Node $node
	 * @return bool
	 */
	public static function isRedirectLink( Node $node ): bool {
		return $node instanceof Element &&
			DOMCompat::nodeName( $node ) === 'link' &&
			preg_match( '#\bmw:PageProp/redirect\b#', $node->getAttribute( 'rel' ) ?? '' );
	}

	/**
	 * Does $node represent a category link?
	 *
	 * @param ?Node $node
	 * @return bool
	 */
	public static function isCategoryLink( ?Node $node ): bool {
		return $node instanceof Element &&
			DOMCompat::nodeName( $node ) === 'link' &&
			preg_match( '#\bmw:PageProp/Category\b#', $node->getAttribute( 'rel' ) ?? '' );
	}

	/**
	 * Does $node represent a link that is sol-transparent?
	 *
	 * @param Node $node
	 * @return bool
	 */
	public static function isSolTransparentLink( Node $node ): bool {
		return $node instanceof Element &&
			DOMCompat::nodeName( $node ) === 'link' &&
			preg_match( TokenUtils::SOL_TRANSPARENT_LINK_REGEX, $node->getAttribute( 'rel' ) ?? '' );
	}

	/**
	 * Check if '$node' emits wikitext that is sol-transparent in wikitext form.
	 * This is a test for wikitext that doesn't introduce line breaks.
	 *
	 * Comment, whitespace text $nodes, category links, redirect links, behavior
	 * switches, and include directives currently satisfy this definition.
	 *
	 * This should come close to matching TokenUtils.isSolTransparent()
	 *
	 * @param Node $node
	 * @return bool
	 */
	public static function emitsSolTransparentSingleLineWT( Node $node ): bool {
		if ( $node instanceof Text ) {
			// NB: We differ here to meet the nl condition.
			return (bool)preg_match( '/^[ \t]*$/D', $node->nodeValue );
		} elseif ( self::isRenderingTransparentNode( $node ) ) {
			// NB: The only metas in a DOM should be for behavior switches and
			// include directives, other than explicit HTML meta tags. This
			// differs from our counterpart in Util where ref meta tokens
			// haven't been expanded to spans yet.
			return true;
		} else {
			return false;
		}
	}

	/**
	 * This is the span added to headings to add fallback ids for when legacy
	 * and HTML5 ids don't match up. This prevents broken links to legacy ids.
	 *
	 * @param Node $node
	 * @return bool
	 */
	public static function isFallbackIdSpan( Node $node ): bool {
		return DOMUtils::hasNameAndTypeOf( $node, 'span', 'mw:FallbackId' );
	}

	/**
	 * These are primarily 'metadata'-like $nodes that don't show up in output rendering.
	 * - In Parsoid output, they are represented by link/meta tags.
	 * - In the PHP parser, they are completely stripped from the input early on.
	 *   Because of this property, these rendering-transparent $nodes are also
	 *   SOL-transparent for the purposes of parsing behavior.
	 *
	 * @param Node $node
	 * @return bool
	 */
	public static function isRenderingTransparentNode( Node $node ): bool {
		// FIXME: Can we change this entire thing to
		// $node instanceof Comment ||
		// DOMUtils::getDataParsoid($node).stx !== 'html' &&
		// (DOMCompat::nodeName($node) === 'meta' || DOMCompat::nodeName($node) === 'link')
		//
		return $node instanceof Comment ||
			self::isSolTransparentLink( $node ) || (
				// Catch-all for everything else.
				$node instanceof Element &&
				DOMCompat::nodeName( $node ) === 'meta' &&
				!self::isMarkerAnnotation( $node ) &&
				( DOMDataUtils::getDataParsoid( $node )->stx ?? '' ) !== 'html'
			) || self::isFallbackIdSpan( $node );
	}

	/**
	 * Is $node nested inside a table tag that uses HTML instead of native
	 * wikitext?
	 *
	 * @param Node $node
	 * @return bool
	 */
	public static function inHTMLTableTag( Node $node ): bool {
		$p = $node->parentNode;
		while ( DOMUtils::isTableTag( $p ) ) {
			if ( self::isLiteralHTMLNode( $p ) ) {
				return true;
			} elseif ( DOMCompat::nodeName( $p ) === 'table' ) {
				// Don't cross <table> boundaries
				return false;
			}
			$p = $p->parentNode;
		}

		return false;
	}

	/**
	 * Is $node the first wrapper element of encapsulated content?
	 *
	 * @param Node $node
	 * @return bool
	 */
	public static function isFirstEncapsulationWrapperNode( Node $node ): bool {
		return DOMUtils::matchTypeOf( $node, self::FIRST_ENCAP_REGEXP ) !== null;
	}

	/**
	 * Is $node an encapsulation wrapper elt?
	 *
	 * All root-level $nodes of generated content are considered
	 * encapsulation wrappers and share an about-id.
	 *
	 * @param Node $node
	 * @return bool
	 */
	public static function isEncapsulationWrapper( Node $node ): bool {
		// True if it has an encapsulation type or while walking backwards
		// over elts with identical about ids, we run into a $node with an
		// encapsulation type.
		if ( !( $node instanceof Element ) ) {
			return false;
		}
		return self::findFirstEncapsulationWrapperNode( $node ) !== null;
	}

	/**
	 * Is $node a DOMFragment wrapper?
	 *
	 * @param Node $node
	 * @return bool
	 */
	public static function isDOMFragmentWrapper( Node $node ): bool {
		// See TokenUtils::hasDOMFragmentType
		return DOMUtils::matchTypeOf( $node, '#^mw:DOMFragment(/sealed/\w+)?$#D' ) !== null;
	}

	/**
	 * Is $node a sealed DOMFragment of a specific type?
	 *
	 * @param Node $node
	 * @param string $type
	 * @return bool
	 */
	public static function isSealedFragmentOfType( Node $node, string $type ): bool {
		return DOMUtils::hasTypeOf( $node, "mw:DOMFragment/sealed/$type" );
	}

	/**
	 * Is $node a Parsoid-generated <section> tag?
	 *
	 * @param Node $node
	 * @return bool
	 */
	public static function isParsoidSectionTag( Node $node ): bool {
		return $node instanceof Element &&
			DOMCompat::nodeName( $node ) === 'section' &&
			$node->hasAttribute( 'data-mw-section-id' );
	}

	/** Is $node a Parsoid-generated extended annotation wrapper
	 * @param Node $node
	 * @return bool
	 */
	public static function isExtendedAnnotationWrapperTag( Node $node ): bool {
		return $node instanceof Element &&
			$node->hasAttribute( 'typeof' ) &&
			$node->getAttribute( 'typeof' ) === 'mw:ExtendedAnnRange';
	}

	/**
	 * Is the $node from extension content?
	 * @param Node $node
	 * @param string $extType
	 * @return bool
	 */
	public static function fromExtensionContent( Node $node, string $extType ): bool {
		$parentNode = $node->parentNode;
		while ( $parentNode && !DOMUtils::atTheTop( $parentNode ) ) {
			if ( DOMUtils::hasTypeOf( $parentNode, "mw:Extension/$extType" ) ) {
				return true;
			}
			$parentNode = $parentNode->parentNode;
		}
		return false;
	}

	/**
	 * Is $node from encapsulated (template, extension, etc.) content?
	 * @param Node $node
	 * @return bool
	 */
	public static function fromEncapsulatedContent( Node $node ): bool {
		while ( $node && !DOMUtils::atTheTop( $node ) ) {
			if ( self::findFirstEncapsulationWrapperNode( $node ) !== null ) {
				return true;
			}
			$node = $node->parentNode;
		}
		return false;
	}

	/**
	 * Compute, when possible, the wikitext source for a $node in
	 * an environment env. Returns null if the source cannot be
	 * extracted.
	 * @param Frame $frame
	 * @param Element $node
	 * @return string|null
	 */
	public static function getWTSource( Frame $frame, Element $node ): ?string {
		$dp = DOMDataUtils::getDataParsoid( $node );
		$dsr = $dp->dsr ?? null;
		// FIXME: We could probably change the null return to ''
		// Just need to verify that code that uses this won't break
		return Utils::isValidDSR( $dsr ) ?
			$dsr->substr( $frame->getSrcText() ) : null;
	}

	/**
	 * Gets all siblings that follow '$node' that have an 'about' as
	 * their about id.
	 *
	 * This is used to fetch transclusion/extension content by using
	 * the about-id as the key.  This works because
	 * transclusion/extension content is a forest of dom-trees formed
	 * by adjacent dom-nodes.  This is the contract that template
	 * encapsulation, dom-reuse, and VE code all have to abide by.
	 *
	 * The only exception to this adjacency rule is IEW nodes in
	 * fosterable positions (in tables) which are not span-wrapped to
	 * prevent them from getting fostered out.
	 *
	 * @param Node $node
	 * @param string $about
	 * @return Node[]
	 */
	public static function getAboutSiblings( Node $node, string $about ): array {
		$nodes = [ $node ];

		if ( !$about ) {
			return $nodes;
		}

		$node = $node->nextSibling;
		while ( $node && (
			( $node instanceof Element && $node->getAttribute( 'about' ) === $about ) ||
			( DOMUtils::isFosterablePosition( $node ) && DOMUtils::isIEW( $node ) )
		) ) {
			$nodes[] = $node;
			$node = $node->nextSibling;
		}

		// Remove already consumed trailing IEW, if any
		while ( count( $nodes ) > 0 && DOMUtils::isIEW( $nodes[count( $nodes ) - 1] ) ) {
			array_pop( $nodes );
		}

		return $nodes;
	}

	/**
	 * This function is only intended to be used on encapsulated $nodes
	 * (Template/Extension/Param content).
	 *
	 * Given a '$node' that has an about-id, it is assumed that it is generated
	 * by templates or extensions.  This function skips over all
	 * following content nodes and returns the first non-template node
	 * that follows it.
	 *
	 * @param Node $node
	 * @return Node|null
	 */
	public static function skipOverEncapsulatedContent( Node $node ): ?Node {
		if ( $node instanceof Element && $node->hasAttribute( 'about' ) ) {
			$about = $node->getAttribute( 'about' );
			// Guaranteed not to be empty. It will at least include $node.
			$aboutSiblings = self::getAboutSiblings( $node, $about );
			return end( $aboutSiblings )->nextSibling;
		} else {
			return $node->nextSibling;
		}
	}

	/**
	 * Comment encoding/decoding.
	 *
	 * * Some relevant phab tickets: T94055, T70146, T60184, T95039
	 *
	 * The wikitext comment rule is very simple: <!-- starts a comment,
	 * and --> ends a comment.  This means we can have almost anything as the
	 * contents of a comment (except the string "-->", but see below), including
	 * several things that are not valid in HTML5 comments:
	 *
	 * * For one, the html5 comment parsing algorithm [0] leniently accepts
	 * --!> as a closing comment tag, which differs from the php+tidy combo.
	 *
	 * * If the comment's data matches /^-?>/, html5 will end the comment.
	 *    For example, <!-->stuff<--> breaks up as
	 *    <!--> (the comment) followed by, stuff<--> (as text).
	 *
	 *  * Finally, comment data shouldn't contain two consecutive hyphen-minus
	 *    characters (--), nor end in a hyphen-minus character (/-$/) as defined
	 *    in the spec [1].
	 *
	 * We work around all these problems by using HTML entity encoding inside
	 * the comment body.  The characters -, >, and & must be encoded in order
	 * to prevent premature termination of the comment by one of the cases
	 * above.  Encoding other characters is optional; all entities will be
	 * decoded during wikitext serialization.
	 *
	 * In order to allow *arbitrary* content inside a wikitext comment,
	 * including the forbidden string "-->" we also do some minimal entity
	 * decoding on the wikitext.  We are also limited by our inability
	 * to encode DSR attributes on the comment $node, so our wikitext entity
	 * decoding must be 1-to-1: that is, there must be a unique "decoded"
	 * string for every wikitext sequence, and for every decoded string there
	 * must be a unique wikitext which creates it.
	 *
	 * The basic idea here is to replace every string ab*c with the string with
	 * one more b in it.  This creates a string with no instance of "ac",
	 * so you can use 'ac' to encode one more code point.  In this case
	 * a is "--&", "b" is "amp;", and "c" is "gt;" and we use ac to
	 * encode "-->" (which is otherwise unspeakable in wikitext).
	 *
	 * Note that any user content which does not match the regular
	 * expression /--(>|&(amp;)*gt;)/ is unchanged in its wikitext
	 * representation, as shown in the first two examples below.
	 *
	 * User-authored comment text    Wikitext       HTML5 DOM
	 * --------------------------    -------------  ----------------------
	 * & - >                         & - >          &amp; &#43; &gt;
	 * Use &gt; here                 Use &gt; here  Use &amp;gt; here
	 * -->                           --&gt;         &#43;&#43;&gt;
	 * --&gt;                        --&amp;gt;     &#43;&#43;&amp;gt;
	 * --&amp;gt;                    --&amp;amp;gt; &#43;&#43;&amp;amp;gt;
	 *
	 * [0] http://www.w3.org/TR/html5/syntax.html#comment-start-state
	 * [1] http://www.w3.org/TR/html5/syntax.html#comments
	 *
	 * Map a wikitext-escaped comment to an HTML DOM-escaped comment.
	 *
	 * @param string $comment Wikitext-escaped comment.
	 * @return string DOM-escaped comment.
	 */
	public static function encodeComment( string $comment ): string {
		// Undo wikitext escaping to obtain "true value" of comment.
		$trueValue = preg_replace_callback( '/--&(amp;)*gt;/', static function ( $m ) {
				return Utils::decodeWtEntities( $m[0] );
		}, $comment );

		// Now encode '-', '>' and '&' in the "true value" as HTML entities,
		// so that they can be safely embedded in an HTML comment.
		// This part doesn't have to map strings 1-to-1.
		// WARNING(T279451): This is actually the part which protects the
		// "-type" key in self::fosterCommentData
		return preg_replace_callback( '/[->&]/', static function ( $m ) {
			return Utils::entityEncodeAll( $m[0] );
		}, $trueValue );
	}

	/**
	 * Map an HTML DOM-escaped comment to a wikitext-escaped comment.
	 * @param string $comment DOM-escaped comment.
	 * @return string Wikitext-escaped comment.
	 */
	public static function decodeComment( string $comment ): string {
		// Undo HTML entity escaping to obtain "true value" of comment.
		$trueValue = Utils::decodeWtEntities( $comment );

		// ok, now encode this "true value" of the comment in such a way
		// that the string "-->" never shows up.  (See above.)
		return preg_replace_callback( '/--(&(amp;)*gt;|>)/', static function ( $m ) {
			$s = $m[0];
				return $s === '-->' ? '--&gt;' : '--&amp;' . substr( $s, 3 );
		}, $trueValue );
	}

	/**
	 * Utility function: we often need to know the wikitext DSR length for
	 * an HTML DOM comment value.
	 *
	 * @param Comment|CommentTk|string $node A comment node containing a DOM-escaped comment.
	 * @return int The wikitext length in UTF-8 bytes necessary to encode this
	 *   comment, including 7 characters for the `<!--` and `-->` delimiters.
	 */
	public static function decodedCommentLength( $node ): int {
		// Add 7 for the "<!--" and "-->" delimiters in wikitext.
		if ( $node instanceof Comment ) {
			$value = $node->nodeValue;
		} elseif ( $node instanceof CommentTk ) {
			$value = $node->value;
		} else {
			$value = $node;
		}
		return strlen( self::decodeComment( $value ) ) + 7;
	}

	/**
	 * @param Env $env
	 * @param Node $node
	 * @return ?ExtensionTagHandler
	 */
	public static function getNativeExt( Env $env, Node $node ): ?ExtensionTagHandler {
		$match = DOMUtils::matchTypeOf( $node, '#^mw:Extension/(.+?)$#D' );
		$matchingTag = $match ? substr( $match, strlen( 'mw:Extension/' ) ) : null;
		return $matchingTag ?
			$env->getSiteConfig()->getExtTagImpl( $matchingTag ) : null;
	}

	/**
	 * Is this an include directive?
	 * @param string $name
	 * @return bool
	 */
	public static function isIncludeTag( string $name ): bool {
		return $name === 'includeonly' || $name === 'noinclude' || $name === 'onlyinclude';
	}

	/**
	 * Check if tag is annotation or extension directive
	 * Adapted from similar grammar function
	 *
	 * @param Env $env
	 * @param string $name
	 * @return bool
	 */
	public static function isAnnOrExtTag( Env $env, string $name ): bool {
		$tagName = mb_strtolower( $name );
		$siteConfig = $env->getSiteConfig();
		$extTags = $siteConfig->getExtensionTagNameMap();
		$isInstalledExt = isset( $extTags[$tagName] );
		$isIncludeTag = self::isIncludeTag( $tagName );
		$isAnnotationTag = $siteConfig->isAnnotationTag( $tagName );

		if ( !$isAnnotationTag ) {
			// avoid crashing on <tvar|name> even if we don't support that syntax explicitly
			$pipepos = strpos( $tagName, '|' );
			if ( $pipepos ) {
				$strBeforePipe = substr( $tagName, 0, $pipepos );
				$isAnnotationTag = $siteConfig->isAnnotationTag( $strBeforePipe );
			}
		}
		return $isInstalledExt || $isIncludeTag || $isAnnotationTag;
	}

	/**
	 * @param Document $doc
	 * @param array $i18n With "key" and "params" for wfMessage
	 * @return DocumentFragment
	 */
	public static function createLocalizationFragment(
		Document $doc, array $i18n
	): DocumentFragment {
		$frag = $doc->createDocumentFragment();
		$span = $doc->createElement( 'span' );
		DOMUtils::addTypeOf( $span, 'mw:I18n' );
		$dp = DOMDataUtils::getDataParsoid( $span );
		$dp->getTemp()->i18n = $i18n;
		$frag->appendChild( $span );
		return $frag;
	}

	/** Check whether a node is an annotation meta; if yes, returns its type
	 * @param Node $node
	 * @return ?string
	 */
	public static function matchAnnotationMeta( Node $node ): ?string {
		return DOMUtils::matchNameAndTypeOf( $node, 'meta', self::ANNOTATION_META_TYPE_REGEXP );
	}

	/**
	 * Extract the annotation type, excluding potential "/End" suffix; returns null if not a valid
	 * annotation meta. &$isStart is set to true if the annotation is a start tag, false otherwise.
	 *
	 * @param Node $node
	 * @param bool &$isStart
	 * @return ?string The matched type, or null if no match.
	 */
	public static function extractAnnotationType( Node $node, bool &$isStart = false ): ?string {
		$t = DOMUtils::matchTypeOf( $node, self::ANNOTATION_META_TYPE_REGEXP );
		if ( $t !== null && preg_match( self::ANNOTATION_META_TYPE_REGEXP, $t, $matches ) ) {
			$isStart = !str_ends_with( $t, '/End' );
			return $matches[1];
		}
		return null;
	}

	/**
	 * Check whether a node is a meta signifying the start of an annotated part of the DOM
	 *
	 * @param Node $node
	 * @return bool
	 */
	public static function isAnnotationStartMarkerMeta( Node $node ): bool {
		if ( !$node instanceof Element || DOMCompat::nodeName( $node ) !== 'meta' ) {
			return false;
		}
		$isStart = false;
		$t = self::extractAnnotationType( $node, $isStart );
		return $t !== null && $isStart;
	}

	/**
	 * Check whether a node is a meta signifying the end of an annotated part of the DOM
	 *
	 * @param Node $node
	 * @return bool
	 */
	public static function isAnnotationEndMarkerMeta( Node $node ): bool {
		if ( !$node instanceof Element || DOMCompat::nodeName( $node ) !== 'meta' ) {
			return false;
		}
		$isStart = false;
		$t = self::extractAnnotationType( $node, $isStart );
		return $t !== null && !$isStart;
	}

	/**
	 * Check whether the meta tag was moved from its initial position
	 * @param Node $node
	 * @return bool
	 */
	public static function isMovedMetaTag( Node $node ): bool {
		if ( $node instanceof Element && self::matchAnnotationMeta( $node ) !== null ) {
			$parsoidData = DOMDataUtils::getDataParsoid( $node );
			if ( isset( $parsoidData->wasMoved ) ) {
				return $parsoidData->wasMoved;
			}
		}
		return false;
	}

	/** Returns true if a node is a (start or end) annotation meta tag
	 * @param ?Node $n
	 * @return bool
	 */
	public static function isMarkerAnnotation( ?Node $n ): bool {
		return $n !== null && self::matchAnnotationMeta( $n ) !== null;
	}

	/**
	 * Extracts the media format from the attribute string
	 *
	 * @param Element $node
	 * @return string
	 */
	public static function getMediaFormat( Element $node ): string {
		// TODO: Remove "Image|Video|Audio" when version 2.4.0 of the content
		// is no longer supported
		$mediaType = DOMUtils::matchTypeOf( $node, '#^mw:(File|Image|Video|Audio)(/|$)#' );
		$parts = explode( '/', $mediaType ?? '' );
		return $parts[1] ?? '';
	}

	/**
	 * @param Element $node
	 * @return bool
	 */
	public static function hasVisibleCaption( Element $node ): bool {
		$format = self::getMediaFormat( $node );
		return in_array(
			$format, [ 'Thumb', /* 'Manualthumb', FIXME(T305759) */ 'Frame' ], true
		);
	}

}
