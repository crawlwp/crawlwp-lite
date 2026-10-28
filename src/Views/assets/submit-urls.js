/* Settings > Submit URLs. ES5. */
(function ($) {
	'use strict';

	var cfg = window.crawlwpSubmitUrls;
	var $root = $('[data-cwps]');

	if (!cfg || !$root.length) return;

	var L = cfg.i18n;
	var $urls = $root.find('[data-cwps-urls]');
	var $count = $root.find('[data-cwps-count]');
	var $submit = $root.find('[data-cwps-submit]');
	var $form = $root.find('[data-cwps-form]');
	var $results = $root.find('[data-cwps-results]');

	function fmt(str) {
		var args = Array.prototype.slice.call(arguments, 1), i = 0;
		return String(str).replace(/%(\d+\$)?[sd]/g, function (m, pos) {
			return pos ? args[parseInt(pos, 10) - 1] : args[i++];
		});
	}

	function host(url) {
		var m = /^https?:\/\/([^\/?#:]+)/i.exec(url);
		return m ? m[1].toLowerCase() : null;
	}

	/* Lines sorted into URLs to send and lines to skip, without duplicates. */
	function parse() {
		var seen = {}, out = { send: [], foreign: [], invalid: [] };

		$.each(($urls.val() || '').split(/\r?\n/), function (i, line) {
			var url = $.trim(line);
			if (!url || seen[url]) return;
			seen[url] = true;

			var h = host(url);
			if (!h) out.invalid.push(url);
			else if (h !== cfg.host) out.foreign.push(url);
			else out.send.push(url);
		});

		return out;
	}

	function kind() {
		return $root.find('input[name="cwps-kind"]:checked').val();
	}

	function services() {
		var removed = kind() === 'removed', ids = [];
		$root.find('[data-cwps-service] input:checked:enabled').each(function () {
			var $opt = $(this).closest('[data-cwps-service]');
			if (removed && $opt.data('removed') !== 1) return;
			ids.push(this.value);
		});
		return ids;
	}

	function refresh() {
		var p = parse(), n = p.send.length, left = [], right = [];

		if (n || p.foreign.length || p.invalid.length) {
			left.push(n === 1 ? L.countOne : fmt(L.count, n));
			left.push(L.max);
		}
		if (n > cfg.max) right.push('<b>' + L.tooMany + '</b>');
		if (p.foreign.length) right.push('<b>' + fmt(L.foreign, p.foreign.length) + '</b>');
		if (p.invalid.length) right.push('<b>' + fmt(L.invalid, p.invalid.length) + '</b>');

		$count.html('<span>' + left.join(' · ') + '</span><span>' + right.join(' · ') + '</span>');

		/* Removed pages only go to services that accept them; the others say so. */
		var removed = kind() === 'removed';
		$root.find('[data-cwps-service]').each(function () {
			var $opt = $(this), skip = removed && $opt.data('removed') !== 1;
			$opt.find('[data-cwps-note]').toggle(!skip);
			$opt.find('.cwps-removed-note').toggle(skip);
			$opt.css('opacity', skip ? 0.6 : '');
		});

		var ok = n > 0 && n <= cfg.max && services().length > 0;
		$submit.prop('disabled', !ok).text(n === 1 ? L.submitOne : (n ? fmt(L.submit, n) : L.submitNone));
		$submit.attr('title', n && !services().length ? L.noService : '');
	}

	function pill(cls, text) {
		return $('<span class="cwps-pill"></span>').addClass(cls).text(text);
	}

	function cell(result) {
		var $td = $('<td></td>');
		if (!result) return $td.append(pill('cwps-skip', L.waiting));
		$td.append(pill(result.ok ? 'cwps-ok' : 'cwps-bad', result.ok ? L.sent : L.notSent));
		if (!result.ok && result.message) $td.append($('<span class="cwps-msg"></span>').text(result.message));
		return $td;
	}

	function row(url, ids, result) {
		var $tr = $('<tr></tr>').append($('<td class="cwps-u"></td>').text(url));

		if (result && result.skipped) {
			return $tr.append($('<td></td>').attr('colspan', ids.length).append(pill('cwps-skip', L.skipped), $('<span class="cwps-msg"></span>').text(result.skipped)));
		}

		$.each(ids, function (i, id) {
			$tr.append(cell(result && result.engines ? result.engines[id] : null));
		});

		return $tr;
	}

	function summary(totals, done, total) {
		var $sum = $root.find('[data-cwps-sum]').empty();

		if (done < total) {
			$sum.text(fmt(L.progress, done, total));
			return;
		}

		if (totals.sent) $sum.append(pill('cwps-ok', fmt(L.sumSent, totals.sent)));
		if (totals.problem) $sum.append(pill('cwps-bad', fmt(L.sumProblem, totals.problem)));
		if (totals.skipped) $sum.append(pill('cwps-skip', fmt(L.sumSkipped, totals.skipped)));
	}

	function run() {
		var p = parse(), ids = services(), removed = kind() === 'removed';
		var $head = $root.find('[data-cwps-head]').empty(), $rows = $root.find('[data-cwps-rows]').empty();
		var rows = {}, totals = { sent: 0, problem: 0, skipped: 0 }, done = 0, total = p.send.length;

		var $tr = $('<tr></tr>').append($('<th></th>').text(L.url));
		$.each(ids, function (i, id) { $tr.append($('<th></th>').text(cfg.labels[id])); });
		$head.append($tr);

		$.each(p.send, function (i, url) { rows[url] = row(url, ids, null); $rows.append(rows[url]); });
		$.each(p.foreign, function (i, url) { $rows.append(row(url, ids, { skipped: L.notOnSite })); totals.skipped++; });
		$.each(p.invalid, function (i, url) { $rows.append(row(url, ids, { skipped: L.notUrl })); totals.skipped++; });

		$form.hide();
		$results.show().find('[data-cwps-again]').hide();
		summary(totals, 0, total);

		var chunks = [];
		for (var i = 0; i < p.send.length; i += cfg.chunk) chunks.push(p.send.slice(i, i + cfg.chunk));

		function next() {
			if (!chunks.length) {
				summary(totals, total, total);
				$results.find('[data-cwps-again]').show();
				return;
			}

			var chunk = chunks.shift();

			$.post(cfg.ajaxUrl, { action: 'crawlwp_submit_urls', nonce: cfg.nonce, urls: chunk, removed: removed ? 1 : 0, services: ids })
				.done(function (res) {
					var results = res && res.success ? res.data.results : {};
					$.each(chunk, function (i, url) {
						var r = results[url] || { skipped: L.failed }, any = false, bad = false;
						if (r.skipped) totals.skipped++;
						$.each(r.engines || {}, function (id, e) { if (e.ok) any = true; else bad = true; });
						if (any) totals.sent++;
						if (bad) totals.problem++;
						var $new = row(url, ids, r);
						rows[url].replaceWith($new);
						rows[url] = $new;
					});
				})
				.fail(function () {
					var failed = { engines: {} };
					$.each(ids, function (i, id) { failed.engines[id] = { ok: false, message: L.failed }; });
					$.each(chunk, function (i, url) {
						totals.problem++;
						var $new = row(url, ids, failed);
						rows[url].replaceWith($new);
						rows[url] = $new;
					});
				})
				.always(function () {
					done += chunk.length;
					summary(totals, done, total);
					next();
				});
		}

		next();
	}

	$urls.on('input', refresh);
	$root.on('change', 'input', refresh);
	$submit.on('click', function () { if (!$submit.prop('disabled')) run(); });
	$root.on('click', '[data-cwps-again]', function () {
		$results.hide();
		$urls.val('');
		$form.show();
		refresh();
		$urls.trigger('focus');
	});

	refresh();
})(jQuery);
