'use strict';

const { DOMDataUtils } = require('../../../utils/DOMDataUtils.js');
const { DOMUtils } = require('../../../utils/DOMUtils.js');
const { WTUtils } = require('../../../utils/WTUtils.js');

// This is equivalent to WTUtils.emitsSolTransparentSingleLineWT except for the
// single line constraint.
const emitsSolTransparentWT = n => (DOMUtils.isText(n) && /^\s*$/.test(n.nodeValue)) || WTUtils.isRenderingTransparentNode(n);

class PWrap {
	isSplittableTag(n) {
		// Seems safe to split span, sub, sup, cite tags
		//
		// These are the only 4 tags that are in HTML5Depurate's
		// list of inline tags that are not self-closing and that
		// can embed tags inside them.
		//
		// However, if we want to mimic Parsoid and HTML5 spec
		// precisely, we should only use isFormattingElt(n)
		return DOMUtils.isFormattingElt(n);
	}

	// Flattens an array with other arrays for elements into
	// an array without nested arrays
	flatten(a) {
		var ret = [];
		for (var i = 0; i < a.length; i++) {
			ret = ret.concat(a[i]);
		}
		return ret;
	}

	isBlockNode(node) {
		// T167349: Special case introduced by template styles
		return DOMUtils.isBlockNode(node) || node.nodeName === 'STYLE';
	}

	// Does the subtree rooted at 'n' have a block tag in it?
	hasBlockTag(n) {
		var c = n.firstChild;
		while (c) {
			if (this.isBlockNode(n) || this.hasBlockTag(c)) {
				return true;
			}
			c = c.nextSibling;
		}
		return false;
	}

	// mergeRuns merges split subtrees that
	// have identical PWrap properties
	mergeRuns(n, a) {
		var curr = null;
		var ret = [];
		// This flag should be transferred to the rightmost
		// clone of this node in the loop below.
		var origAIEnd = DOMDataUtils.getDataParsoid(n).autoInsertedEnd;
		a.forEach(function(v) {
			if (!curr) {
				curr = { PWrap: v.PWrap, node: n };
				ret.push(curr);
			} else if (curr.PWrap === null) {
				curr.PWrap = v.PWrap;
			} else if (curr.PWrap !== v.PWrap && v.PWrap !== null) {
				DOMDataUtils.getDataParsoid(curr.node).autoInsertedEnd = true;
				curr = { PWrap: v.PWrap, node: n.clone() };
				DOMDataUtils.getDataParsoid(curr.node).autoInsertedStart = true;
				ret.push(curr);
			}
			curr.node.appendChild(v.node);
		});
		if (curr) {
			DOMDataUtils.getDataParsoid(curr.node).autoInsertedEnd = origAIEnd;
		}
		return ret;
	}

	// split does the split operation described in the outline of
	// the algorithm below.
	split(n) {
		if (emitsSolTransparentWT(n)) {
			// The null stuff here is mainly to support mw:EndTag metas getting in
			// the way of runs and causing unnecessary wrapping.
			return [ { PWrap: null, node: n } ];
		} else if (DOMUtils.isText(n)) {
			return [ { PWrap: true, node: n } ];
		} else if (!this.isSplittableTag(n) || !n.childNodes.length) {
			// block tag OR non-splittable inline tag
			return [ { PWrap: !this.isBlockNode(n) && !this.hasBlockTag(n), node: n } ];
		} else {
			// splittable inline tag
			// split for each child and merge runs
			return this.mergeRuns(n, this.flatten(n.childNodes.map(c => this.split(c))));
		}
	}

	// Wrap children of 'root' with paragraph tags while
	// so that the final output has the following properties:
	//
	// 1. A paragraph will have at least one non-whitespace text
	//    node or an non-block element node in its subtree.
	//
	// 2. Two paragraph nodes aren't siblings of each other.
	//
	// 3. If a child of root is not a paragraph node, it is one of:
	//    - a white-space only text node
	//    - a comment node
	//    - a block element
	//    - a splittable inline element which has some block node
	//      on *all* paths from it to all leaves in its subtree.
	//    - a non-splittable inline element which has some block node
	//      on *some* path from it to a leaf in its subtree.
	//
	//
	// This output is generated with the following algorithm
	//
	// 1. Block nodes are skipped over
	// 2. Non-splittable inline nodes that have a block tag
	//    in its subtree are skipped over.
	// 3. A splittable inline node, I, that has at least one block tag
	//    in its subtree is split into multiple tree such that
	//    * each new tree is rooted in I
	//    * the trees alternate between two kinds
	//      (a) it has no block node inside
	//          => PWrap is true
	//      (b) all paths from I to its leaves have some block node inside
	//          => PWrap is false
	// 4. A paragraph tag is wrapped around adjacent runs of comment nodes,
	//    text nodes, and an inline node that has no block node embedded inside.
	//    This paragraph tag does not start with a white-space-only text node
	//    or a comment node. The current algorithm does not ensure that it doesn't
	//    end with one of those either, but that is a potential future enhancement.

	pWrap(root) {
		var p = null;
		var c = root.firstChild;
		while (c) {
			var next = c.nextSibling;
			if (this.isBlockNode(c)) {
				p = null;
			} else {
				this.split(c).forEach(function(v) {
					var n = v.node;
					if (v.PWrap === false) {
						p = null;
						root.insertBefore(n, next);
					} else if (emitsSolTransparentWT(n)) {
						if (p) {
							p.appendChild(n);
						} else {
							root.insertBefore(n, next);
						}
					} else {
						if (!p) {
							p = root.ownerDocument.createElement('P');
							root.insertBefore(p, next);
						}
						p.appendChild(n);
					}
				});
			}
			c = next;
		}
	}

	// This function walks the DOM tree rooted at 'root'
	// and uses pWrap to add appropriate paragraph wrapper
	// tags around children of nodes with tag name 'tagName'.
	pWrapInsideTag(root, tagName) {
		var c = root.firstChild;
		while (c) {
			var next = c.nextSibling;
			if (c.nodeName === tagName) {
				this.pWrap(c);
			} else if (DOMUtils.isElt(c)) {
				this.pWrapInsideTag(c, tagName);
			}
			c = next;
		}
	}

	// Wrap children of <body> as well as children of
	// <blockquote> found anywhere in the DOM tree.
	run(root, env, options) {
		this.pWrap(root);
		this.pWrapInsideTag(root, 'BLOCKQUOTE');
	}
}

if (typeof module === "object") {
	module.exports.PWrap = PWrap;
}
