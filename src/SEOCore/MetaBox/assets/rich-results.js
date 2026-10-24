/* Rich result form in the CrawlWP SEO box (Schema tab). Data: window.crawlwpRich. */
(function () {
    'use strict';

    var cfg = window.crawlwpRich;
    var app = document.getElementById('cwpRichApp');
    var input = document.getElementById('cwpRichValue');

    if (!cfg || !app || !input) return;

    var i18n = cfg.i18n || {};
    var state = { type: cfg.value.type || '', fields: {} };
    var memory = {}; // values per type, kept while switching types

    if (state.type) memory[state.type] = cfg.value.fields || {};

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'text') node.textContent = attrs[k];
            else if (k === 'className') node.className = attrs[k];
            else node.setAttribute(k, attrs[k]);
        });
        (children || []).forEach(function (c) { if (c) node.appendChild(c); });
        return node;
    }

    function current() {
        return memory[state.type] || (memory[state.type] = {});
    }

    function value(key, field) {
        var v = current()[key] || '';
        if (v === '' && field.default) v = cfg.defaults[field.default] || '';
        return v;
    }

    function isRequired(field, values) {
        var r = field.required;
        if (!r) return false;
        if (r === true) return true;
        var other = values[r.field] || '';
        if (r.nonzero) return other !== '' && parseFloat(other) !== 0;
        return (r.in || []).indexOf(other) !== -1;
    }

    function missing(type) {
        var values = {}, out = [];
        Object.keys(type.fields).forEach(function (k) { values[k] = value(k, type.fields[k]); });
        Object.keys(type.fields).forEach(function (k) {
            if (isRequired(type.fields[k], values) && values[k] === '') out.push(type.fields[k].label);
        });
        if (type.requiredAny && type.requiredAny.length) {
            var met = type.requiredAny.some(function (group) {
                return group.every(function (k) { return values[k] !== ''; });
            });
            if (!met) out.push(type.requiredAnyMessage);
        }
        return out;
    }

    function save() {
        if (!state.type) { input.value = ''; return; }
        input.value = JSON.stringify({ type: state.type, fields: current() });
    }

    function control(key, field) {
        var id = 'cwpRich_' + key;
        var stored = current()[key] || '';
        var c;

        if (field.type === 'select') {
            c = el('select', { className: 'cwp-select', id: id });
            c.appendChild(el('option', { value: '', text: i18n.choose }));
            Object.keys(field.options || {}).forEach(function (k) {
                var o = el('option', { value: k, text: field.options[k] });
                if (k === stored) o.selected = true;
                c.appendChild(o);
            });
        } else if (field.type === 'textarea' || field.type === 'list') {
            c = el('textarea', { className: 'cwp-textarea', id: id, rows: field.type === 'list' ? '5' : '3' });
            c.value = stored;
        } else {
            var types = { url: 'url', number: 'number', minutes: 'number', datetime: 'datetime-local', date: 'date' };
            c = el('input', { className: 'cwp-input', id: id, type: types[field.type] || 'text' });
            if (field.type === 'number') c.setAttribute('step', 'any');
            if (field.type === 'minutes') { c.setAttribute('min', '0'); c.setAttribute('step', '1'); }
            if (field.type === 'currency') { c.setAttribute('maxlength', '3'); c.style.textTransform = 'uppercase'; }
            c.value = stored;
        }

        var ph = field.placeholder || (field.default ? cfg.defaults[field.default] || '' : '');
        if (ph && c.tagName !== 'SELECT') c.setAttribute('placeholder', ph);

        c.addEventListener(c.tagName === 'SELECT' ? 'change' : 'input', function () {
            var v = c.value.trim();
            if (field.type === 'currency') v = v.toUpperCase();
            if (v === '') delete current()[key]; else current()[key] = v;
            save();
            status();
        });

        return c;
    }

    function fieldNode(key, field, values) {
        var label = el('label', { className: 'cwp-label', for: 'cwpRich_' + key, text: field.label });
        var star = el('span', { className: 'cwp-rich-req', 'data-req': key, title: i18n.required, text: '*' });
        star.hidden = !isRequired(field, values);
        label.appendChild(star);
        var wide = field.type === 'textarea' || field.type === 'list';
        return el('div', { className: 'cwp-field' + (wide ? ' cwp-rich-wide' : '') }, [
            el('div', { className: 'cwp-label-row' }, [label]),
            control(key, field),
            field.hint ? el('p', { className: 'cwp-hint', text: field.hint }) : null
        ]);
    }

    var statusBox;

    function status() {
        if (!statusBox) return;
        var type = cfg.types[state.type];
        var miss = missing(type);
        var values = {};
        Object.keys(type.fields).forEach(function (k) { values[k] = value(k, type.fields[k]); });
        Array.prototype.forEach.call(app.querySelectorAll('[data-req]'), function (star) {
            star.hidden = !isRequired(type.fields[star.getAttribute('data-req')], values);
        });
        statusBox.className = 'cwp-rich-status ' + (miss.length ? 'is-warn' : 'is-ok');
        statusBox.innerHTML = '';
        statusBox.appendChild(el('strong', { text: miss.length ? i18n.missing : i18n.ready }));
        statusBox.appendChild(el('span', { text: miss.length ? miss.join(', ') : i18n.readyText }));
    }

    function render() {
        app.innerHTML = '';
        statusBox = null;

        var select = el('select', { className: 'cwp-select cwp-rich-type', id: 'cwpRichType' });
        select.appendChild(el('option', { value: '', text: i18n.none }));
        var free = el('optgroup', { label: i18n.free }), premium = el('optgroup', { label: i18n.premium });
        Object.keys(cfg.types).forEach(function (id) {
            var t = cfg.types[id];
            var o = el('option', { value: id, text: t.label });
            if (id === state.type) o.selected = true;
            (t.premium ? premium : free).appendChild(o);
        });
        select.appendChild(free);
        if (premium.children.length) select.appendChild(premium);
        select.addEventListener('change', function () { state.type = select.value; save(); render(); });

        app.appendChild(el('div', { className: 'cwp-field' }, [
            el('div', { className: 'cwp-label-row' }, [el('label', { className: 'cwp-label', for: 'cwpRichType', text: i18n.type })]),
            select,
            el('p', { className: 'cwp-hint', text: i18n.faqNote })
        ]));

        if (!state.type || !cfg.types[state.type]) return;

        var type = cfg.types[state.type];

        if (type.locked) {
            var box = el('div', { className: 'cwp-rich-lock' }, [
                el('strong', { text: i18n.lockedTitle.replace('%s', type.label) }),
                el('p', { text: type.pitch }),
                el('a', { className: 'button button-primary', href: cfg.upgradeUrl, target: '_blank', rel: 'noopener', text: cfg.proActive ? i18n.update : i18n.upgrade })
            ]);
            app.appendChild(box);
            return;
        }

        if (type.hint) app.appendChild(el('p', { className: 'cwp-hint cwp-rich-typehint', text: type.hint }));

        statusBox = el('div', { className: 'cwp-rich-status', role: 'status' });
        app.appendChild(statusBox);
        status();

        var values = {};
        Object.keys(type.fields).forEach(function (k) { values[k] = value(k, type.fields[k]); });

        var grid = el('div', { className: 'cwp-rich-grid' }), group = '';
        Object.keys(type.fields).forEach(function (k) {
            var f = type.fields[k];
            if ((f.group || '') !== group) {
                group = f.group || '';
                if (group) grid.appendChild(el('div', { className: 'cwp-rich-group', text: group }));
            }
            grid.appendChild(fieldNode(k, f, values));
        });
        app.appendChild(grid);

        app.appendChild(el('p', { className: 'cwp-hint' }, [
            el('a', { href: cfg.testUrl, target: '_blank', rel: 'noopener', text: i18n.test })
        ]));
    }

    render();
})();
