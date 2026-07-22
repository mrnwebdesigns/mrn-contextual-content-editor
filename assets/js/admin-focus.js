(function () {
	'use strict';

	var config = window.MRNContextualContentEditorFocus || {};
	var highlightedClass = 'mrn-cce-admin-focus-target';

	function cssEscape(value) {
		if (window.CSS && window.CSS.escape) {
			return window.CSS.escape(value);
		}

		return String(value).replace(/["\\]/g, '\\$&');
	}

	function isVisible(element) {
		return Boolean(element && element.offsetParent !== null);
	}

	function clickElement(element) {
		if (!element) {
			return;
		}

		element.dispatchEvent(new MouseEvent('click', {
			bubbles: true,
			cancelable: true,
			view: window
		}));
	}

	function queryAll(scope, selector) {
		try {
			return Array.prototype.slice.call(scope.querySelectorAll(selector));
		} catch (error) {
			return [];
		}
	}

	function fieldSelector(step) {
		var selectors = [];
		if (step.key) {
			selectors.push('.acf-field[data-key="' + cssEscape(step.key) + '"]');
		}

		if (step.name) {
			selectors.push('.acf-field[data-name="' + cssEscape(step.name) + '"]');
		}

		return selectors.join(', ');
	}

	function revealRow(row) {
		if (!row || !row.classList || !row.classList.contains('-collapsed')) {
			return;
		}

		clickElement(row.querySelector('.acf-accordion-title, .acf-row-handle .acf-icon.-collapse, .acf-fc-layout-handle, .acf-fc-layout-order'));
	}

	function revealAcfContainers(field) {
		var current = field;
		while (current && current !== document.body) {
			if (current.classList && current.classList.contains('-collapsed')) {
				revealRow(current);
			}

			current = current.parentElement;
		}
	}

	function revealAcfTabs(field) {
		var scope = field.closest('.acf-postbox, .acf-fields') || document;
		var buttons = Array.prototype.slice.call(scope.querySelectorAll('.acf-tab-button, .acf-tab-wrap a[data-key]'));
		var attempts = 0;

		while (!isVisible(field) && attempts < 2) {
			buttons.some(function (button) {
				clickElement(button);
				return isVisible(field);
			});
			attempts += 1;
		}
	}

	function findFieldInScope(scope, step) {
		var selector = fieldSelector(step);
		if (!selector) {
			return null;
		}

		if (scope.matches && scope.matches(selector)) {
			return scope;
		}

		var fields = queryAll(scope, selector);
		if (!fields.length) {
			return null;
		}

		return fields.find(isVisible) || fields[0];
	}

	function getTopLevelRows(scope, kind) {
		var selectors = kind === 'flexible_content'
			? [
				':scope > .acf-input > .acf-flexible-content > .values > .layout:not(.acf-clone)',
				':scope > .acf-input > .acf-flexible-content > .values > .acf-row:not(.acf-clone)'
			]
			: [
				':scope > .acf-input > .acf-repeater > table > tbody > .acf-row:not(.acf-clone)',
				':scope > .acf-input > .acf-repeater .acf-row:not(.acf-clone)'
			];

		var rows = [];
		selectors.some(function (selector) {
			rows = queryAll(scope, selector);
			return rows.length > 0;
		});

		if (rows.length) {
			return rows;
		}

		return kind === 'flexible_content'
			? queryAll(scope, '.acf-flexible-content .layout:not(.acf-clone)')
			: queryAll(scope, '.acf-repeater .acf-row:not(.acf-clone)');
	}

	function findRowInScope(scope, step) {
		var kind = step.kind || 'repeater';
		var rows = getTopLevelRows(scope, kind);
		if (!rows.length) {
			return null;
		}

		var rowIndex = Number(step.index) || 0;
		var indexedRow = rows[rowIndex] || null;
		if (kind === 'flexible_content' && step.layout) {
			if (indexedRow && (indexedRow.getAttribute('data-layout') || '') === step.layout) {
				return indexedRow;
			}

			var layoutRows = rows.filter(function (row) {
				return (row.getAttribute('data-layout') || '') === step.layout;
			});

			return layoutRows[rowIndex] || indexedRow;
		}

		return indexedRow;
	}

	function findAcfFieldByPath(path) {
		if (!Array.isArray(path) || !path.length) {
			return null;
		}

		var scope = document;
		var field = null;

		for (var index = 0; index < path.length; index += 1) {
			var step = path[index] || {};
			if (step.type === 'field') {
				field = findFieldInScope(scope, step);
				if (!field) {
					return null;
				}

				revealAcfContainers(field);
				revealAcfTabs(field);
				scope = field;
				continue;
			}

			if (step.type === 'row') {
				var row = findRowInScope(scope, step);
				if (!row) {
					return null;
				}

				revealRow(row);
				scope = row;
			}
		}

		return field;
	}

	function focusInside(element) {
		var focusSelector = 'input:not([type="hidden"]), textarea, select, button, [contenteditable="true"], iframe';
		var focusable = element.matches && element.matches(focusSelector) ? element : element.querySelector(focusSelector);
		if (!focusable) {
			return;
		}

		if (focusable.tagName === 'IFRAME' && focusable.contentWindow) {
			focusable.contentWindow.focus();
			return;
		}

		try {
			focusable.focus({ preventScroll: true });
		} catch (error) {
			focusable.focus();
		}
	}

	function selectTextNode(root, text) {
		if (!root || !text) {
			return false;
		}

		var needle = text.toLocaleLowerCase();
		var child = root.firstChild;
		while (child) {
			if (child.nodeType === 3) {
				var value = child.nodeValue || '';
				var index = value.toLocaleLowerCase().indexOf(needle);
				if (index !== -1) {
					var ownerDocument = child.ownerDocument || document;
					var selection = ownerDocument.defaultView && ownerDocument.defaultView.getSelection ? ownerDocument.defaultView.getSelection() : null;
					if (!selection) {
						return false;
					}

					var range = ownerDocument.createRange();
					range.setStart(child, index);
					range.setEnd(child, index + text.length);
					selection.removeAllRanges();
					selection.addRange(range);
					if (child.parentElement) {
						child.parentElement.scrollIntoView({ block: 'center', behavior: 'smooth' });
					}
					return true;
				}
			} else if (child.nodeType === 1 && selectTextNode(child, text)) {
				return true;
			}

			child = child.nextSibling;
		}

		return false;
	}

	function selectMatchingText(field) {
		var text = String(config.focus_text || '').trim();
		if (!field || !text) {
			return false;
		}

		var textareas = queryAll(field, 'textarea');
		for (var index = 0; index < textareas.length; index += 1) {
			var textarea = textareas[index];
			var editor = textarea.id && window.tinymce && window.tinymce.get ? window.tinymce.get(textarea.id) : null;
			if (editor && editor.getBody && editor.getBody()) {
				editor.focus();
				if (selectTextNode(editor.getBody(), text)) {
					field.classList.add('mrn-cce-admin-focus-text-match');
					return true;
				}
			}

			var rawIndex = String(textarea.value || '').toLocaleLowerCase().indexOf(text.toLocaleLowerCase());
			if (rawIndex !== -1 && textarea.setSelectionRange) {
				if (isVisible(textarea)) {
					try {
						textarea.focus({ preventScroll: true });
					} catch (error) {
						textarea.focus();
					}
				}
				textarea.setSelectionRange(rawIndex, rawIndex + text.length);
				field.classList.add('mrn-cce-admin-focus-text-match');
				return true;
			}
		}

		var frames = queryAll(field, 'iframe');
		for (var frameIndex = 0; frameIndex < frames.length; frameIndex += 1) {
			var frame = frames[frameIndex];
			var frameBody = frame.contentDocument && frame.contentDocument.body ? frame.contentDocument.body : null;
			if (frameBody && selectTextNode(frameBody, text)) {
				if (frame.contentWindow) {
					frame.contentWindow.focus();
				}
				field.classList.add('mrn-cce-admin-focus-text-match');
				return true;
			}
		}

		return false;
	}

	function markAndScroll(element) {
		if (!element) {
			return false;
		}

		document.querySelectorAll('.' + highlightedClass).forEach(function (node) {
			node.classList.remove(highlightedClass);
		});

		element.classList.add(highlightedClass);
		element.scrollIntoView({ block: 'center', behavior: 'smooth' });
		focusInside(element);
		return true;
	}

	function findAcfField() {
		var pathField = findAcfFieldByPath(config.acf_path);
		if (pathField) {
			return pathField;
		}

		var selectors = [];
		if (config.acf_key) {
			selectors.push('.acf-field[data-key="' + cssEscape(config.acf_key) + '"]');
		}

		if (config.acf_name) {
			selectors.push('.acf-field[data-name="' + cssEscape(config.acf_name) + '"]');
		}

		if (!selectors.length) {
			return null;
		}

		return document.querySelector(selectors.join(', '));
	}

	function focusAcfField() {
		var field = findAcfField();
		if (!field) {
			return false;
		}

		revealAcfContainers(field);
		revealAcfTabs(field);
		var focused = markAndScroll(field);
		if (focused && config.focus_text) {
			window.setTimeout(function () {
				selectMatchingText(field);
			}, 80);
		}
		return focused;
	}

	function focusCoreField() {
		var target = null;
		if (config.core === 'title') {
			target = document.getElementById('title');
		} else if (config.core === 'content') {
			target = document.getElementById('wp-content-wrap') || document.getElementById('content');
			if (window.tinymce && window.tinymce.get('content')) {
				window.tinymce.get('content').focus();
			}
		} else if (config.core === 'excerpt') {
			target = document.getElementById('postexcerpt') || document.getElementById('excerpt');
		} else if (config.core === 'thumbnail') {
			target = document.getElementById('postimagediv');
		}

		return markAndScroll(target);
	}

	function runFocus() {
		if (config.acf_key || config.acf_name) {
			if (focusAcfField()) {
				return;
			}
		}

		if (config.core) {
			focusCoreField();
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		window.setTimeout(runFocus, 250);
		window.setTimeout(runFocus, 900);
		window.setTimeout(runFocus, 1600);
	});
}());
