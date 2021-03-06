$.Redactor.prototype.WoltLabKeyup = function() {
	"use strict";
	
	return {
		init: function () {
			this.WoltLabEvent.register('keyup', (function (data) {
				if (data.event.originalEvent.which === this.keyCode.ENTER) {
					this.WoltLabKeyup._keyupEnter();
				}
			}).bind(this));
		},
		
		_keyupEnter: function () {
			var editor = this.$editor[0];
			
			var selection = window.getSelection();
			var node = selection.anchorNode;
			var parent = null;
			while (node.parentNode) {
				if (node.parentNode === editor) {
					parent = node;
					break;
				}
				
				node = node.parentNode;
			}
			
			if (parent !== null && parent.nodeName === 'P') {
				this.WoltLabKeyup._rebuildEmptyParagraph(parent, false);
				
				parent = parent.previousElementSibling;
				if (parent !== null && parent.nodeName === 'P') {
					this.WoltLabKeyup._rebuildEmptyParagraph(parent, true);
				}
			}
		},
		
		/**
		 * Rebuilds an empty paragraph, that is a paragraph that only contains
		 * a zero-width whitespace, optionally nested inside inline elements. No
		 * node in the entire tree may contain more than one element child node.
		 * 
		 * @param       {Element}       p
		 * @param       {boolean}       isPreviousElement
		 * @protected
		 */
		_rebuildEmptyParagraph: function (p, isPreviousElement) {
			if (p.textContent.replace(/\u200B/g, '').trim().length > 0) {
				return;
			}
			
			var node = p;
			while (node.nodeType === Node.ELEMENT_NODE) {
				// element contains no nodes
				if (node.childNodes.length === 0) break;
				
				// more than two elements are present
				if (node.children.length > 1) return;
				
				if (node.children.length === 1) {
					node = node.children[0];
				}
				else {
					node = node.childNodes[0];
				}
			}
			
			if (node.nodeType === Node.TEXT_NODE) {
				var br = elCreate('br');
				node.parentNode.appendChild(br);
				
				if (isPreviousElement) elRemove(node);
			}
		}
	}
};
