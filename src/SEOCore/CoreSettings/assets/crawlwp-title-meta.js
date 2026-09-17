(function ($) {

	'use strict';

	/**
	 * CrawlWP SEO "Title & Meta" settings enhancer.
	 *
	 * Progressive enhancement for every [data-cwp-tm] input/textarea rendered by
	 * the WordPress Settings API. For each field we add:
	 *
	 *   - a live resolved preview of the template,
	 *   - an "insert variable" dropdown anchored inside the field.
	 *
	 * The script never throws: a missing or malformed window.crawlwpTitleMeta
	 * simply means no enhancement happens.
	 */

	var DATA_ATTR  = 'data-cwp-tm';
	var INIT_FLAG  = 'cwpTmReady';
	var openPanel  = null;

	/* ---------- configuration ---------- */

	/** Read the localized payload defensively; every key is optional. */
	function getConfig() {
		var raw = window.crawlwpTitleMeta;

		if (!raw || typeof raw !== 'object') {
			return null;
		}

		return {
			separator: typeof raw.separator === 'string' && raw.separator !== '' ? raw.separator : '-',
			variables: $.isArray(raw.variables) ? raw.variables : [],
			entityVariables: raw.entityVariables && typeof raw.entityVariables === 'object' ? raw.entityVariables : {},
			samples: raw.samples && typeof raw.samples === 'object' ? raw.samples : {},
			i18n: raw.i18n && typeof raw.i18n === 'object' ? raw.i18n : {}
		};
	}

	function text(config, key, fallback) {
		var value = config.i18n[key];

		return typeof value === 'string' && value !== '' ? value : fallback;
	}

	/* ---------- template resolution ---------- */

	function escapeRegExp(value) {
		return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	}

	/**
	 * Resolve a template into its preview string.
	 *
	 * Tokens are written as {{ token }} with optional inner spaces. A token is
	 * looked up in samples[entity] first, then in samples.global; anything still
	 * unknown resolves to an empty string.
	 *
	 * Dropping tokens leaves debris behind ("A - - B", " - B", "A  B"), so the
	 * substituted string is cleaned up afterwards:
	 *   1. runs of 2+ separators (and the spaces around them) become one,
	 *   2. a leading or trailing separator is stripped,
	 *   3. repeated whitespace collapses to a single space.
	 */
	function resolve(config, template, entity) {
		var entitySamples = config.samples[entity] && typeof config.samples[entity] === 'object' ? config.samples[entity] : {};
		var globalSamples = config.samples.global && typeof config.samples.global === 'object' ? config.samples.global : {};

		var resolved = String(template == null ? '' : template).replace(/\{\{\s*([^{}]+?)\s*\}\}/g, function (match, token) {
			var key = token.trim();

			if (Object.prototype.hasOwnProperty.call(entitySamples, key)) {
				return entitySamples[key] == null ? '' : String(entitySamples[key]);
			}

			if (Object.prototype.hasOwnProperty.call(globalSamples, key)) {
				return globalSamples[key] == null ? '' : String(globalSamples[key]);
			}

			return '';
		});

		return cleanup(config, resolved);
	}

	function cleanup(config, value) {
		var sep = escapeRegExp(config.separator);

		/* "A - - B" -> "A - B" */
		value = value.replace(new RegExp('(?:\\s*' + sep + '\\s*){2,}', 'g'), ' ' + config.separator + ' ');

		/* " - B" / "A - " -> "B" / "A" */
		value = value.replace(new RegExp('^\\s*(?:' + sep + '\\s*)+'), '');
		value = value.replace(new RegExp('(?:\\s*' + sep + ')+\\s*$'), '');

		/* "A  B" -> "A B" */
		value = value.replace(/[ \t]{2,}/g, ' ');

		return $.trim(value);
	}

	/* ---------- field controller ---------- */

	function initField(config, control) {
		var $control = $(control);

		/* guard against double initialisation (refresh() may be called again) */
		if ($control.data(INIT_FLAG) || $control.attr('data-cwp-tm-ready') === '1') {
			return;
		}

		$control.data(INIT_FLAG, true);
		$control.attr('data-cwp-tm-ready', '1');

		var entity = $control.attr('data-cwp-entity') || '';

		/* 1. wrap the control so the trigger button can be positioned inside it */
		var $field = $('<div>', { 'class': 'cwp-tm-field' });
		$control.wrap($field);
		$field = $control.parent();

		var $trigger = $('<button>', {
			'class': 'cwp-tm-vars',
			type: 'button',
			'aria-haspopup': 'true',
			'aria-expanded': 'false',
			title: text(config, 'insertVariable', 'Insert variable'),
			text: '\u2026'
		});
		$field.append($trigger);

		/* 2. readout goes right after the field, before any p.description hint */
		var $readout = $('<div>', { 'class': 'cwp-tm-readout' });
		var $preview = $('<div>', { 'class': 'cwp-tm-preview' });

		$readout.append($preview);
		$field.after($readout);

		function recompute() {
			var resolved = resolve(config, $control.val(), entity);
			var length   = resolved.length;

			$preview.empty();

			var $label = $('<span>', { 'class': 'cwp-tm-preview__label', text: text(config, 'previewLabel', 'Preview:') + ' ' });
			$preview.append($label);

			if (length === 0) {
				var $empty = $('<span>', { 'class': 'cwp-tm-preview__empty', text: text(config, 'emptyPreview', 'Nothing will be output.') });
				$preview.append($empty);
			} else {
				$preview.append(document.createTextNode(resolved));
			}
		}

		$control.on('input change', recompute);

		$trigger.on('click', function (event) {
			event.preventDefault();
			event.stopPropagation();
			togglePanel(config, $field, $control, $trigger, entity, recompute);
		});

		recompute();
	}

	/* ---------- variables dropdown ---------- */

	function togglePanel(config, $field, $control, $trigger, entity, recompute) {
		if (openPanel && openPanel.$trigger.is($trigger)) {
			closePanel();

			return;
		}

		closePanel();

		var panel = $trigger.data(INIT_FLAG + 'Panel');

		if (!panel) {
			panel = buildPanel(config, $control, $trigger, entity, recompute);
			$trigger.data(INIT_FLAG + 'Panel', panel);
			$field.append(panel.$root);
		}

		panel.$root.show();
		$trigger.attr('aria-expanded', 'true');
		openPanel = panel;
		panel.activeIndex = -1;
		panel.$search.val('');
		panel.filter('');
		panel.$search.focus();
	}

	function closePanel(returnFocus) {
		if (!openPanel) {
			return;
		}

		var panel = openPanel;
		openPanel = null;

		panel.$root.hide();
		panel.$trigger.attr('aria-expanded', 'false');

		if (returnFocus) {
			panel.$trigger.focus();
		}
	}

	function buildPanel(config, $control, $trigger, entity, recompute) {
		var $root = $('<div>', { 'class': 'cwp-tm-panel' }).hide();

		var $search = $('<input>', {
			'class': 'cwp-tm-panel__search',
			type: 'search',
			placeholder: text(config, 'searchVariables', 'Search variables\u2026')
		});
		$root.append($search);

		var $empty = $('<div>', {
			'class': 'cwp-tm-panel__empty',
			text: text(config, 'noVariables', 'No matching variables.')
		}).hide();

		var groups = [];
		var items  = [];

		/* Use entity-specific variable groups when available; fall back to the global list. */
		var variableList = (entity && config.entityVariables[entity] && $.isArray(config.entityVariables[entity]))
			? config.entityVariables[entity]
			: config.variables;

		$.each(variableList, function (index, group) {
			if (!group || typeof group !== 'object') {
				return;
			}

			var $groupRoot  = $('<div>', { 'class': 'cwp-tm-panel__group' });
			var $groupLabel = $('<div>', { 'class': 'cwp-tm-panel__label', text: group.label == null ? '' : String(group.label) });
			$groupRoot.append($groupLabel);

			var groupItems = [];

			$.each($.isArray(group.items) ? group.items : [], function (idx, item) {
				if (!item || typeof item !== 'object' || item.token == null) {
					return;
				}

				var token = String(item.token);
				var desc  = item.desc == null ? '' : String(item.desc);

				var $button = $('<button>', {
					'class': 'cwp-tm-panel__item',
					type: 'button',
					'data-token': token
				});

				var $code = $('<code>', { text: '{{ ' + token + ' }}' });
				$button.append($code);

				var $descNode = $('<span>', { text: desc });
				$button.append($descNode);

				$button.on('click', function (event) {
					event.preventDefault();
					insertToken($control, token, recompute);
					closePanel();
				});

				$groupRoot.append($button);
				groupItems.push({ $node: $button, haystack: (token + ' ' + desc).toLowerCase() });
				items.push({ $node: $button, haystack: (token + ' ' + desc).toLowerCase() });
			});

			$root.append($groupRoot);
			groups.push({ $root: $groupRoot, items: groupItems });
		});

		$root.append($empty);

		var panel = {
			$root: $root,
			$search: $search,
			$trigger: $trigger,
			items: items,
			visible: [],
			activeIndex: -1
		};

		/* filter items by token AND description, hiding empty groups */
		panel.filter = function (query) {
			var needle = $.trim(String(query)).toLowerCase();
			var shown  = [];

			$.each(groups, function (index, group) {
				var groupShown = 0;

				$.each(group.items, function (idx, item) {
					var match = needle === '' || item.haystack.indexOf(needle) !== -1;

					item.$node.toggle(match);
					item.$node.removeClass('is-active');

					if (match) {
						groupShown++;
						shown.push(item.$node);
					}
				});

				group.$root.toggle(groupShown > 0);
			});

			$empty.toggle(shown.length === 0);
			panel.visible = shown;
			panel.activeIndex = -1;
		};

		panel.setActive = function (index) {
			if (!panel.visible.length) {
				return;
			}

			if (index < 0) {
				index = panel.visible.length - 1;
			}

			if (index >= panel.visible.length) {
				index = 0;
			}

			$.each(panel.visible, function (idx, $node) {
				$node.removeClass('is-active');
			});

			panel.activeIndex = index;

			var $active = panel.visible[index];
			$active.addClass('is-active');
			$active.focus();
		};

		$search.on('input', function () {
			panel.filter($search.val());
		});

		$root.on('click', function (event) {
			event.stopPropagation();
		});

		$root.on('keydown', function (event) {
			if (event.key === 'Escape' || event.keyCode === 27) {
				event.preventDefault();
				closePanel(true);

				return;
			}

			if (event.key === 'ArrowDown' || event.keyCode === 40) {
				event.preventDefault();
				panel.setActive(panel.activeIndex + 1);

				return;
			}

			if (event.key === 'ArrowUp' || event.keyCode === 38) {
				event.preventDefault();
				panel.setActive(panel.activeIndex - 1);

				return;
			}

			if ((event.key === 'Enter' || event.keyCode === 13) && !$(event.target).is($search)) {
				event.preventDefault();

				var $target = $(event.target);
				if ($target.attr('data-token')) {
					insertToken($control, $target.attr('data-token'), recompute);
					closePanel(true);
				}
			}
		});

		return panel;
	}

	/** Insert "{{ token }}" at the caret, keeping focus and caret position sane. */
	function insertToken($control, token, recompute) {
		var control = $control[0];
		var snippet = '{{ ' + token + ' }}';
		var value   = $control.val() == null ? '' : String($control.val());
		var start   = typeof control.selectionStart === 'number' ? control.selectionStart : null;
		var end     = typeof control.selectionEnd === 'number' ? control.selectionEnd : null;
		var caret;

		if (start === null || end === null) {
			$control.val(value + snippet);
			caret = $control.val().length;
		} else {
			$control.val(value.slice(0, start) + snippet + value.slice(end));
			caret = start + snippet.length;
		}

		$control.focus();

		if (control.setSelectionRange) {
			try {
				control.setSelectionRange(caret, caret);
			} catch (e) {
				/* some controls disallow selection ranges; harmless */
			}
		}

		recompute();

		$control.trigger('input');
	}

	/* ---------- bootstrap ---------- */

	function initAll() {
		var config = getConfig();

		if (!config) {
			return;
		}

		$('[' + DATA_ATTR + ']').each(function () {
			try {
				initField(config, this);
			} catch (e) {
				/* never let one broken row break the rest of the screen */
			}
		});
	}

	$(document).on('click', function () {
		closePanel();
	});

	$(document).on('keydown', function (event) {
		if (event.key === 'Escape' || event.keyCode === 27) {
			closePanel(true);
		}
	});

	$(function () {
		initAll();

		if (window.crawlwpTitleMeta && typeof window.crawlwpTitleMeta === 'object') {
			window.crawlwpTitleMeta.refresh = initAll;
		}
	});

}(jQuery));
