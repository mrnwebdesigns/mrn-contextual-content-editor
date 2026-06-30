(function () {
	'use strict';

	var config = window.MRNContextualContentEditor || null;
	if (!config || !config.ajaxUrl || !config.nonce || !config.postId) {
		return;
	}

	var state = {
		abortController: null,
		enabled: true,
		menuLocked: false,
		pendingCloseTimer: null,
		pendingTarget: null,
		pendingTargetTimer: null,
		target: null
	};

	var menu = document.createElement('div');
	menu.className = 'mrn-cce-menu';
	menu.setAttribute('role', 'toolbar');
	menu.setAttribute('aria-label', 'Context edit');
	menu.hidden = true;

	var primaryButton = document.createElement('button');
	primaryButton.type = 'button';
	primaryButton.className = 'mrn-cce-button mrn-cce-button--primary';
	primaryButton.textContent = config.strings.editThis || 'Edit this';

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
	closeButton.textContent = 'x';

	var actions = document.createElement('div');
	actions.className = 'mrn-cce-actions';
	actions.append(primaryButton, editPageLink, closeButton);

	var status = document.createElement('div');
	status.className = 'mrn-cce-status';
	status.setAttribute('aria-live', 'polite');

	var results = document.createElement('div');
	results.className = 'mrn-cce-results';

	menu.append(actions, status, results);
	document.body.appendChild(menu);

	function getString(key, fallback) {
		return (config.strings && config.strings[key]) || fallback;
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

		return /^(A|ARTICLE|ASIDE|BLOCKQUOTE|CAPTION|DD|DIV|DT|FIGCAPTION|FIGURE|H1|H2|H3|H4|H5|H6|IMG|LI|P|SECTION|SPAN|TD|TH)$/i.test(element.tagName);
	}

	function findExplicitTarget(element) {
		if (!element || !element.closest) {
			return null;
		}

		return element.closest('[data-mrn-cce-acf-key], [data-mrn-cce-acf-name], [data-mrn-cce-core]');
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

			if (isCandidateTag(current) && (hasUsableText(current) || current.matches('img, figure') || current.querySelector('img'))) {
				return current;
			}

			current = current.parentElement;
			depth += 1;
		}

		return null;
	}

	function setTarget(target) {
		clearPendingClose();
		clearPendingTarget();

		if (state.target === target) {
			positionMenu();
			return;
		}

		clearResults();
		if (state.target) {
			state.target.classList.remove('mrn-cce-hover-target');
		}

		state.target = target;
		if (!target) {
			menu.hidden = true;
			return;
		}

		target.classList.add('mrn-cce-hover-target');
		menu.hidden = false;
		positionMenu();
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
		}, 220);
	}

	function clearResults() {
		status.textContent = '';
		results.replaceChildren();
	}

	function positionMenu() {
		if (!state.target || menu.hidden) {
			return;
		}

		var rect = state.target.getBoundingClientRect();
		var menuRect = menu.getBoundingClientRect();
		var top = Math.max(44, rect.top - menuRect.height - 10);
		if (top <= 48 && rect.bottom + menuRect.height + 10 < window.innerHeight) {
			top = rect.bottom + 10;
		}

		var left = Math.min(window.innerWidth - menuRect.width - 12, Math.max(12, rect.left));
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
		if (!explicit) {
			return {};
		}

		return {
			acfKey: explicit.getAttribute('data-mrn-cce-acf-key') || '',
			acfName: explicit.getAttribute('data-mrn-cce-acf-name') || '',
			core: explicit.getAttribute('data-mrn-cce-core') || '',
			label: explicit.getAttribute('data-mrn-cce-label') || '',
			postId: explicit.getAttribute('data-mrn-cce-post-id') || ''
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
			});
	}

	function renderTargets(targets) {
		results.replaceChildren();

		if (!targets.length) {
			status.textContent = getString('noMatch', 'No exact field match found.');
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

			var preview = document.createElement('span');
			preview.className = 'mrn-cce-result__preview';
			preview.textContent = target.preview || target.label || getString('openBest', 'Open best match');

			topLine.append(badge, preview);

			var meta = document.createElement('span');
			meta.className = 'mrn-cce-result__meta';
			meta.textContent = target.label || '';

			var detail = document.createElement('span');
			detail.className = 'mrn-cce-result__detail';
			detail.textContent = target.detail || '';

			link.append(topLine, meta, detail);
			results.appendChild(link);
		});
	}

	function closeMenu() {
		clearPendingClose();
		clearPendingTarget();
		state.menuLocked = false;
		if (state.target) {
			state.target.classList.remove('mrn-cce-hover-target');
		}

		state.target = null;
		menu.hidden = true;
		clearResults();
	}

	function toggleEnabled(event) {
		if (event) {
			event.preventDefault();
		}

		state.enabled = !state.enabled;
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

	document.addEventListener('scroll', positionMenu, true);
	window.addEventListener('resize', positionMenu);
	primaryButton.addEventListener('click', resolveTarget);
	closeButton.addEventListener('click', closeMenu);
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

		if (event.altKey && (event.key === 'e' || event.key === 'E')) {
			toggleEnabled(event);
		}
	});

	var adminToggle = document.querySelector('#wp-admin-bar-mrn-cce-toggle a');
	if (adminToggle) {
		adminToggle.addEventListener('click', toggleEnabled);
	}
}());
