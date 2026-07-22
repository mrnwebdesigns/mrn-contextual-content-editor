(function () {
	'use strict';

	var config = window.MRNContextualContentEditor || null;
	if (!config || !config.ajaxUrl || !config.nonce || !config.postId) {
		return;
	}

	var enabledStorageKey = 'mrn-cce-enabled-v2';
	var announcerTimer = null;
	var state = {
		abortController: null,
		enabled: getStoredEnabled(),
		expanded: false,
		menuLocked: false,
		pendingCloseTimer: null,
		pendingPositionFrame: null,
		pendingTarget: null,
		pendingTargetTimer: null,
		pointerX: 0,
		pointerY: 0,
		resultIndex: 0,
		resultScrollFrame: null,
		resultWheelLockedUntil: 0,
		target: null
	};

	var highlight = document.createElement('div');
	highlight.className = 'mrn-cce-highlight';
	highlight.setAttribute('aria-hidden', 'true');

	var menu = document.createElement('div');
	menu.className = 'mrn-cce-menu mrn-cce-menu--compact';
	menu.setAttribute('role', 'status');
	menu.setAttribute('aria-label', 'Click content to edit');
	menu.hidden = true;

	var menuTitle = document.createElement('span');
	menuTitle.className = 'mrn-cce-title';
	menuTitle.textContent = getString('quickEdit', 'Quick edit');

	var primaryButton = document.createElement('button');
	primaryButton.type = 'button';
	primaryButton.className = 'mrn-cce-button mrn-cce-button--primary';
	primaryButton.textContent = getString('editCompact', 'Edit');

	var editPageLink = document.createElement('a');
	editPageLink.className = 'mrn-cce-button';
	editPageLink.href = config.editPostUrl;
	editPageLink.target = '_blank';
	editPageLink.rel = 'noopener noreferrer';
	editPageLink.textContent = config.strings.editPage || 'Edit page';

	var closeButton = document.createElement('button');
	closeButton.type = 'button';
	closeButton.className = 'mrn-cce-icon-button';
	closeButton.setAttribute('aria-label', 'Close context edit menu');
	closeButton.textContent = '\u00d7';

	var actions = document.createElement('div');
	actions.className = 'mrn-cce-actions';
	actions.append(editPageLink, closeButton);

	var header = document.createElement('div');
	header.className = 'mrn-cce-header';
	header.append(menuTitle, actions);

	var status = document.createElement('div');
	status.className = 'mrn-cce-status';
	status.setAttribute('aria-live', 'polite');

	var results = document.createElement('div');
	results.className = 'mrn-cce-results';
	results.setAttribute('role', 'region');
	results.setAttribute('aria-label', 'Matching fields');
	results.tabIndex = 0;

	var resultProgress = document.createElement('div');
	resultProgress.className = 'mrn-cce-result-progress';
	resultProgress.setAttribute('aria-live', 'polite');
	resultProgress.hidden = true;

	var announcer = document.createElement('div');
	announcer.className = 'mrn-cce-announcer';
	announcer.setAttribute('aria-live', 'polite');
	announcer.setAttribute('role', 'status');
	announcer.hidden = true;

	menu.append(header, primaryButton, status, results, resultProgress);
	document.body.append(highlight, menu, announcer);

	function getString(key, fallback) {
		return (config.strings && config.strings[key]) || fallback;
	}

	function getStoredEnabled() {
		try {
			return window.sessionStorage.getItem(enabledStorageKey) === 'on';
		} catch (error) {
			return true;
		}
	}

	function storeEnabled() {
		try {
			window.sessionStorage.setItem(enabledStorageKey, state.enabled ? 'on' : 'off');
		} catch (error) {
			// Storage can be unavailable in privacy-restricted browser contexts.
		}
	}

	function shortcutLabel() {
		return /Mac|iPhone|iPad|iPod/i.test(window.navigator.platform || '') ? '⌘+Shift+E' : 'Ctrl+Shift+E';
	}

	function announceToggle() {
		if (announcerTimer) {
			window.clearTimeout(announcerTimer);
		}

		announcer.textContent = (state.enabled ? getString('pickerOn', 'Context edit menu on') : getString('pickerOff', 'Context edit menu off')) + ' · ' + shortcutLabel();
		announcer.hidden = false;
		announcerTimer = window.setTimeout(function () {
			announcer.hidden = true;
			announcerTimer = null;
		}, 1800);
	}

	function isPluginElement(element) {
		return Boolean(element && element.closest && element.closest('.mrn-cce-menu, #wpadminbar'));
	}

	function isEditableElement(element) {
		if (!element || !element.matches) {
			return false;
		}

		return element.matches('input, textarea, select, option, button, [contenteditable="true"]');
	}

	function hasUsableText(element) {
		return Boolean((element.innerText || element.textContent || '').trim());
	}

	function isCandidateTag(element) {
		if (!element || !element.tagName) {
			return false;
		}

		return /^(A|BLOCKQUOTE|CAPTION|DD|DT|FIGCAPTION|FIGURE|H1|H2|H3|H4|H5|H6|IMG|LI|P|SPAN|TD|TH)$/i.test(element.tagName);
	}

	function isCompactFallback(element) {
		if (!element || !element.matches || !element.matches('div')) {
			return false;
		}

		var rect = element.getBoundingClientRect();
		var text = (element.innerText || element.textContent || '').trim();
		return text.length > 0 && text.length <= 600 && element.childElementCount <= 3 && rect.height <= Math.max(180, window.innerHeight * 0.35);
	}

	function findExplicitTarget(element) {
		if (!element || !element.closest) {
			return null;
		}

		return element.closest('[data-mrn-cce-acf-key], [data-mrn-cce-acf-name], [data-mrn-cce-core]');
	}

	function findScopeTarget(element) {
		if (!element || !element.closest) {
			return null;
		}

		return element.closest('[data-mrn-cce-scope-field-key], [data-mrn-cce-scope-field-name]');
	}

	function findCandidate(element) {
		if (!element || isPluginElement(element) || isEditableElement(element)) {
			return null;
		}

		var explicit = findExplicitTarget(element);
		if (explicit) {
			return explicit;
		}

		var current = element.nodeType === 1 ? element : element.parentElement;
		var depth = 0;
		while (current && current !== document.body && depth < 5) {
			if (isPluginElement(current) || isEditableElement(current)) {
				return null;
			}

			if ((isCandidateTag(current) || isCompactFallback(current)) && (hasUsableText(current) || current.matches('img, figure') || current.querySelector('img'))) {
				return current;
			}

			current = current.parentElement;
			depth += 1;
		}

		return null;
	}

	function setExpanded(expanded) {
		state.expanded = expanded;
		menu.classList.toggle('mrn-cce-menu--compact', !expanded);
		menu.classList.toggle('mrn-cce-menu--expanded', expanded);
		menu.setAttribute('role', expanded ? 'dialog' : 'status');
		menu.setAttribute('aria-label', expanded ? 'Quick edit' : 'Click content to edit');
		primaryButton.textContent = expanded ? getString('finding', 'Finding field...') : getString('editCompact', 'Edit');
	}

	function setTarget(target) {
		clearPendingClose();
		clearPendingTarget();

		if (state.target === target) {
			updatePosition();
			return;
		}

		clearResults();
		setExpanded(false);
		state.target = target;
		if (!target) {
			menu.hidden = true;
			highlight.style.display = 'none';
			return;
		}

		menu.hidden = false;
		highlight.style.display = 'none';
		updatePosition();
	}

	function clearPendingClose() {
		if (!state.pendingCloseTimer) {
			return;
		}

		window.clearTimeout(state.pendingCloseTimer);
		state.pendingCloseTimer = null;
	}

	function scheduleClose() {
		if (menu.hidden || state.menuLocked) {
			return;
		}

		clearPendingClose();
		state.pendingCloseTimer = window.setTimeout(function () {
			state.pendingCloseTimer = null;
			if (!state.menuLocked) {
				closeMenu();
			}
		}, 350);
	}

	function clearPendingTarget() {
		state.pendingTarget = null;
		if (!state.pendingTargetTimer) {
			return;
		}

		window.clearTimeout(state.pendingTargetTimer);
		state.pendingTargetTimer = null;
	}

	function scheduleTarget(target) {
		if (!target || target === state.target) {
			return;
		}

		state.pendingTarget = target;
		if (state.pendingTargetTimer) {
			return;
		}

		state.pendingTargetTimer = window.setTimeout(function () {
			var nextTarget = state.pendingTarget;
			state.pendingTarget = null;
			state.pendingTargetTimer = null;

			if (!state.menuLocked && nextTarget) {
				setTarget(nextTarget);
			}
		}, 90);
	}

	function clearResults() {
		status.textContent = '';
		results.replaceChildren();
		results.scrollTop = 0;
		state.resultIndex = 0;
		resultProgress.hidden = true;
		resultProgress.textContent = '';
	}

	function updateResultProgress(index) {
		var count = results.children.length;
		if (count < 2) {
			resultProgress.hidden = true;
			resultProgress.textContent = '';
			return;
		}

		state.resultIndex = Math.max(0, Math.min(count - 1, index));
		resultProgress.hidden = false;
		resultProgress.textContent = String(state.resultIndex + 1) + ' of ' + String(count) + ' · Scroll for next match';
	}

	function scrollToResult(index, behavior) {
		var cards = results.children;
		if (!cards.length) {
			return;
		}

		var nextIndex = Math.max(0, Math.min(cards.length - 1, index));
		var nextCard = cards[nextIndex];
		var nextTop = nextCard.offsetTop - (nextCard.offsetParent === results ? 0 : results.offsetTop);
		results.scrollTo({
			behavior: behavior || 'smooth',
			top: nextTop
		});
		updateResultProgress(nextIndex);
	}

	function schedulePosition() {
		if (state.pendingPositionFrame) {
			return;
		}

		state.pendingPositionFrame = window.requestAnimationFrame(function () {
			state.pendingPositionFrame = null;
			updatePosition();
		});
	}

	function positionCompactMenu() {
		var menuRect = menu.getBoundingClientRect();
		var margin = 8;
		var left = state.pointerX + 14;
		var top = state.pointerY + 16;

		left = Math.min(window.innerWidth - menuRect.width - margin, Math.max(margin, left));
		top = Math.min(window.innerHeight - menuRect.height - margin, Math.max(margin, top));
		menu.style.left = Math.round(left) + 'px';
		menu.style.top = Math.round(top) + 'px';
	}

	function updatePosition() {
		if (!state.target || menu.hidden) {
			return;
		}

		var rect = state.target.getBoundingClientRect();
		if (rect.bottom < 0 || rect.top > window.innerHeight || rect.right < 0 || rect.left > window.innerWidth) {
			highlight.style.display = 'none';
			return;
		}

		if (!state.expanded) {
			highlight.style.display = 'none';
			positionCompactMenu();
			return;
		}

		highlight.style.display = 'block';
		highlight.style.left = Math.round(rect.left - 3) + 'px';
		highlight.style.top = Math.round(rect.top - 3) + 'px';
		highlight.style.width = Math.round(rect.width + 6) + 'px';
		highlight.style.height = Math.round(rect.height + 6) + 'px';

		var menuRect = menu.getBoundingClientRect();
		var margin = 12;
		var adminTop = document.body.classList.contains('admin-bar') ? 44 : margin;
		var gap = 10;
		var maxLeft = Math.max(margin, window.innerWidth - menuRect.width - margin);
		var maxTop = Math.max(adminTop, window.innerHeight - menuRect.height - margin);
		var left;
		var top;

		if (window.innerWidth - rect.right - gap - margin >= menuRect.width) {
			left = rect.right + gap;
			top = Math.min(maxTop, Math.max(adminTop, rect.top));
		} else if (rect.left - gap - margin >= menuRect.width) {
			left = rect.left - menuRect.width - gap;
			top = Math.min(maxTop, Math.max(adminTop, rect.top));
		} else if (window.innerHeight - rect.bottom - gap - margin >= menuRect.height) {
			left = Math.min(maxLeft, Math.max(margin, rect.left));
			top = rect.bottom + gap;
		} else {
			left = Math.min(maxLeft, Math.max(margin, rect.left));
			top = Math.max(adminTop, rect.top - menuRect.height - gap);
		}

		menu.style.top = Math.round(top) + 'px';
		menu.style.left = Math.round(left) + 'px';
	}

	function selectedTextInside(target) {
		var selection = window.getSelection ? window.getSelection() : null;
		if (!selection || selection.rangeCount === 0) {
			return '';
		}

		var text = selection.toString().trim();
		if (!text) {
			return '';
		}

		var anchor = selection.anchorNode;
		var focus = selection.focusNode;
		if ((anchor && target.contains(anchor)) || (focus && target.contains(focus))) {
			return text;
		}

		return '';
	}

	function getImageSource(target) {
		var image = target.matches && target.matches('img') ? target : target.querySelector('img');
		return image ? (image.currentSrc || image.src || '') : '';
	}

	function getImageText(target, attribute) {
		var image = target.matches && target.matches('img') ? target : target.querySelector('img');
		return image ? (image.getAttribute(attribute) || '') : '';
	}

	function getLinkHref(target) {
		var link = target.closest && target.closest('a[href]');
		if (!link && target.querySelector) {
			link = target.querySelector('a[href]');
		}

		return link ? link.href : '';
	}

	function getDirectContext(target) {
		var explicit = findExplicitTarget(target);
		var scope = findScopeTarget(target);
		if (!explicit && !scope) {
			return {};
		}

		return {
			acfKey: explicit ? (explicit.getAttribute('data-mrn-cce-acf-key') || '') : '',
			acfName: explicit ? (explicit.getAttribute('data-mrn-cce-acf-name') || '') : '',
			core: explicit ? (explicit.getAttribute('data-mrn-cce-core') || '') : '',
			label: explicit ? (explicit.getAttribute('data-mrn-cce-label') || '') : '',
			postId: (explicit && explicit.getAttribute('data-mrn-cce-post-id')) || (scope && scope.getAttribute('data-mrn-cce-post-id')) || '',
			scopeFieldKey: scope ? (scope.getAttribute('data-mrn-cce-scope-field-key') || '') : '',
			scopeFieldName: scope ? (scope.getAttribute('data-mrn-cce-scope-field-name') || '') : '',
			scopeLayout: scope ? (scope.getAttribute('data-mrn-cce-scope-layout') || '') : '',
			scopeRow: scope ? (scope.getAttribute('data-mrn-cce-scope-row') || '') : ''
		};
	}

	function buildContext(target) {
		var text = selectedTextInside(target) || (target.innerText || target.textContent || '').trim();
		var html = target.innerHTML || '';

		return {
			alt: getImageText(target, 'alt'),
			direct: getDirectContext(target),
			href: getLinkHref(target),
			html: html.slice(0, 3000),
			src: getImageSource(target),
			tagName: (target.tagName || '').toLowerCase(),
			text: text.slice(0, 1200),
			title: target.getAttribute('title') || getImageText(target, 'title') || ''
		};
	}

	function appendContext(formData, context) {
		Object.keys(context).forEach(function (key) {
			if (key === 'direct') {
				Object.keys(context.direct || {}).forEach(function (directKey) {
					formData.append('context[direct][' + directKey + ']', context.direct[directKey]);
				});
				return;
			}

			formData.append('context[' + key + ']', context[key]);
		});
	}

	function resolveTarget() {
		if (!state.target) {
			return;
		}

		if (state.abortController) {
			state.abortController.abort();
		}

		clearResults();
		status.textContent = getString('finding', 'Finding field...');
		state.menuLocked = true;
		clearPendingClose();

		var formData = new FormData();
		formData.append('action', config.action);
		formData.append('nonce', config.nonce);
		formData.append('post_id', String(config.postId));
		appendContext(formData, buildContext(state.target));

		state.abortController = new AbortController();
		fetch(config.ajaxUrl, {
			body: formData,
			credentials: 'same-origin',
			method: 'POST',
			signal: state.abortController.signal
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				if (!payload || !payload.success) {
					throw new Error('Resolve failed');
				}

				renderTargets(payload.data && payload.data.targets ? payload.data.targets : []);
			})
			.catch(function (error) {
				if (error.name === 'AbortError') {
					return;
				}

				status.textContent = getString('resolveFailed', 'Could not resolve this content.');
			})
			.finally(function () {
				window.requestAnimationFrame(updatePosition);
			});
	}

	function expandAndResolve() {
		if (!state.target || state.expanded) {
			return;
		}

		setExpanded(true);
		state.menuLocked = true;
		clearPendingClose();
		clearPendingTarget();
		highlight.style.display = 'block';
		updatePosition();
		resolveTarget();
	}

	function renderTargets(targets) {
		results.replaceChildren();

		if (!targets.length) {
			status.textContent = getString('noMatch', 'No exact field match found.');
			window.requestAnimationFrame(updatePosition);
			return;
		}

		status.textContent = '';
		targets.forEach(function (target, index) {
			var link = document.createElement('a');
			link.className = 'mrn-cce-result';
			link.href = target.editUrl || config.editPostUrl;
			link.target = '_blank';
			link.rel = 'noopener noreferrer';

			var topLine = document.createElement('span');
			topLine.className = 'mrn-cce-result__topline';

			var badge = document.createElement('span');
			badge.className = 'mrn-cce-result__badge';
			badge.textContent = index === 0 ? 'BEST' : String(index + 1);

			var label = document.createElement('span');
			label.className = 'mrn-cce-result__label';
			label.textContent = target.label || getString('openBest', 'Open field');

			topLine.append(badge, label);

			var detail = document.createElement('span');
			detail.className = 'mrn-cce-result__detail';
			detail.textContent = target.detail || '';

			link.append(topLine, detail);

			if (target.preview) {
				var preview = document.createElement('span');
				preview.className = 'mrn-cce-result__preview';
				preview.textContent = target.preview;
				link.append(preview);
			}
			results.appendChild(link);
		});
		results.scrollTop = 0;
		updateResultProgress(0);

		window.requestAnimationFrame(updatePosition);
	}

	function closeMenu() {
		clearPendingClose();
		clearPendingTarget();
		state.menuLocked = false;
		setExpanded(false);
		state.target = null;
		menu.hidden = true;
		highlight.style.display = 'none';
		clearResults();
	}

	function toggleEnabled(event) {
		if (event) {
			event.preventDefault();
		}

		state.enabled = !state.enabled;
		storeEnabled();
		updateAdminToggle();
		announceToggle();
		if (!state.enabled) {
			closeMenu();
			window.console && window.console.info && window.console.info(getString('pickerOff', 'Context edit menu off'));
			return;
		}

		window.console && window.console.info && window.console.info(getString('pickerOn', 'Context edit menu on'));
	}

	document.addEventListener('pointerover', function (event) {
		if (!state.enabled) {
			return;
		}

		if (isPluginElement(event.target)) {
			clearPendingClose();
			clearPendingTarget();
			return;
		}

		if (state.menuLocked) {
			return;
		}

		state.pointerX = event.clientX;
		state.pointerY = event.clientY;

		var candidate = findCandidate(event.target);
		if (candidate) {
			if (!state.target || menu.hidden || candidate === state.target) {
				setTarget(candidate);
				return;
			}

			scheduleTarget(candidate);
			return;
		}

		scheduleClose();
	});

	document.addEventListener('pointermove', function (event) {
		if (!state.enabled || state.expanded || menu.hidden || isPluginElement(event.target)) {
			return;
		}

		state.pointerX = event.clientX;
		state.pointerY = event.clientY;
		schedulePosition();
	});

	document.addEventListener('click', function (event) {
		if (!state.enabled || event.button !== 0 || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) {
			return;
		}

		var candidate = findCandidate(event.target);
		if (!candidate) {
			return;
		}

		event.preventDefault();
		event.stopImmediatePropagation();
		if (candidate === state.target && state.expanded) {
			return;
		}

		if (candidate !== state.target) {
			setTarget(candidate);
		}
		expandAndResolve();
	}, true);

	document.addEventListener('scroll', updatePosition, true);
	window.addEventListener('resize', updatePosition);
	primaryButton.addEventListener('click', expandAndResolve);
	closeButton.addEventListener('click', closeMenu);
	results.addEventListener('wheel', function (event) {
		if (results.children.length < 2 || Math.abs(event.deltaY) < 2) {
			return;
		}

		event.preventDefault();
		var now = Date.now();
		if (now < state.resultWheelLockedUntil) {
			return;
		}

		state.resultWheelLockedUntil = now + 260;
		scrollToResult(state.resultIndex + (event.deltaY > 0 ? 1 : -1), 'smooth');
	}, { passive: false });
	results.addEventListener('scroll', function () {
		if (state.resultScrollFrame) {
			return;
		}

		state.resultScrollFrame = window.requestAnimationFrame(function () {
			var closestIndex = 0;
			var closestDistance = Infinity;
			Array.prototype.forEach.call(results.children, function (card, index) {
				var cardTop = card.offsetTop - (card.offsetParent === results ? 0 : results.offsetTop);
				var distance = Math.abs(cardTop - results.scrollTop);
				if (distance < closestDistance) {
					closestDistance = distance;
					closestIndex = index;
				}
			});
			state.resultScrollFrame = null;
			updateResultProgress(closestIndex);
		});
	});
	results.addEventListener('keydown', function (event) {
		if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp' && event.key !== 'PageDown' && event.key !== 'PageUp') {
			return;
		}

		event.preventDefault();
		var direction = event.key === 'ArrowDown' || event.key === 'PageDown' ? 1 : -1;
		scrollToResult(state.resultIndex + direction, 'smooth');
	});
	menu.addEventListener('mouseenter', function () {
		clearPendingClose();
		clearPendingTarget();
		state.menuLocked = true;
	});
	menu.addEventListener('mouseleave', function () {
		state.menuLocked = false;
		scheduleClose();
	});
	menu.addEventListener('focusin', function () {
		clearPendingClose();
		clearPendingTarget();
		state.menuLocked = true;
	});
	menu.addEventListener('focusout', function () {
		window.setTimeout(function () {
			if (!menu.contains(document.activeElement)) {
				state.menuLocked = false;
				scheduleClose();
			}
		}, 0);
	});

	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape') {
			closeMenu();
			return;
		}

		if (!event.repeat && event.shiftKey && (event.ctrlKey || event.metaKey) && (event.key === 'e' || event.key === 'E')) {
			toggleEnabled(event);
		}
	});

	var adminRoot = document.querySelector('#wp-admin-bar-mrn-cce-root > .ab-item');
	var adminToggle = document.querySelector('#wp-admin-bar-mrn-cce-toggle > .ab-item');
	function updateAdminToggle() {
		if (adminRoot) {
			adminRoot.textContent = state.enabled ? getString('contextOn', 'Context Edit: On') : getString('contextOff', 'Context Edit: Off');
		}

		if (adminToggle) {
			adminToggle.textContent = (state.enabled ? getString('turnOff', 'Turn Context Edit off') : getString('turnOn', 'Turn Context Edit on')) + ' (' + shortcutLabel() + ')';
			adminToggle.setAttribute('aria-keyshortcuts', 'Control+Shift+E Meta+Shift+E');
		}
	}

	if (adminToggle) {
		adminToggle.addEventListener('click', toggleEnabled);
	}
	updateAdminToggle();
}());
