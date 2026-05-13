/**
 * wpvdb Playground demo UI.
 *
 * Reads window.wpvdbDemo (localized in PHP), renders preset query buttons,
 * dispatches the precomputed vector to /wpvdb/v1/query, and renders the
 * ranked results inline. No build step. No dependencies.
 *
 * Only loads on the Vector DB dashboard under Plugin::is_playground_demo();
 * see Admin::maybe_enqueue_demo_assets().
 */
(function () {
	'use strict';

	var cfg = window.wpvdbDemo;
	if (!cfg || !Array.isArray(cfg.presets) || cfg.presets.length === 0) {
		return;
	}

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	function el(tag, props, children) {
		var node = document.createElement(tag);
		if (props) {
			for (var k in props) {
				if (!Object.prototype.hasOwnProperty.call(props, k)) continue;
				if (k === 'className') {
					node.className = props[k];
				} else if (k === 'text') {
					node.textContent = props[k];
				} else if (k === 'href') {
					node.setAttribute('href', props[k]);
				} else if (k.indexOf('data-') === 0) {
					node.setAttribute(k, props[k]);
				} else {
					node[k] = props[k];
				}
			}
		}
		if (children) {
			for (var i = 0; i < children.length; i++) {
				if (children[i] != null) node.appendChild(children[i]);
			}
		}
		return node;
	}

	function formatDistance(d) {
		var n = Number(d);
		if (!isFinite(n)) return '';
		return n.toFixed(4);
	}

	function previewText(s, n) {
		if (typeof s !== 'string') return '';
		if (s.length <= n) return s;
		return s.slice(0, n).replace(/\s+\S*$/, '') + '…';
	}

	function renderResults(container, results) {
		container.innerHTML = '';
		if (!results || results.length === 0) {
			container.appendChild(el('p', { text: cfg.i18n && cfg.i18n.noResults ? cfg.i18n.noResults : 'No results.' }));
			return;
		}

		var heading = el('h3', { text: cfg.i18n && cfg.i18n.results ? cfg.i18n.results : 'Results' });
		container.appendChild(heading);

		var table = el('table', { className: 'widefat striped wpvdb-demo-presets__table' });
		var thead = el('thead', null, [
			el('tr', null, [
				el('th', { text: '#' }),
				el('th', { text: cfg.i18n && cfg.i18n.document ? cfg.i18n.document : 'Document' }),
				el('th', { text: cfg.i18n && cfg.i18n.preview ? cfg.i18n.preview : 'Preview' }),
				el('th', { text: cfg.i18n && cfg.i18n.distance ? cfg.i18n.distance : 'Distance' })
			])
		]);
		var tbody = el('tbody');
		var byDoc = cfg.postsByDocId || {};
		for (var i = 0; i < results.length; i++) {
			var r = results[i];
			var docInfo = byDoc[String(r.doc_id)] || byDoc[r.doc_id] || null;
			var titleCell;
			if (docInfo && docInfo.permalink) {
				titleCell = el('td', null, [
					el('a', { href: docInfo.permalink, text: docInfo.title || ('#' + r.doc_id), target: '_blank', rel: 'noopener' })
				]);
			} else if (docInfo) {
				titleCell = el('td', { text: docInfo.title || ('#' + r.doc_id) });
			} else {
				titleCell = el('td', { text: '#' + r.doc_id });
			}
			tbody.appendChild(el('tr', null, [
				el('td', { text: String(i + 1) }),
				titleCell,
				el('td', { text: previewText(r.chunk_content || '', 120) }),
				el('td', { text: formatDistance(r.distance) })
			]));
		}
		table.appendChild(thead);
		table.appendChild(tbody);
		container.appendChild(table);
	}

	function renderError(container, message) {
		container.innerHTML = '';
		var notice = el('div', { className: 'notice notice-error inline' }, [
			el('p', { text: message })
		]);
		container.appendChild(notice);
	}

	function runPreset(preset, resultsContainer, buttonNode) {
		var originalText = buttonNode.textContent;
		buttonNode.setAttribute('disabled', 'disabled');
		buttonNode.textContent = originalText + '…';

		var body = JSON.stringify({
			vector: preset.vector,
			limit: cfg.limit || 5,
			model: cfg.model || 'wpvdb-demo-deterministic-768'
		});

		fetch(cfg.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce || ''
			},
			body: body
		}).then(function (res) {
			return res.text().then(function (text) {
				return { status: res.status, ok: res.ok, text: text };
			});
		}).then(function (envelope) {
			if (envelope.status === 403) {
				renderError(resultsContainer, cfg.i18n && cfg.i18n.errorNonce ? cfg.i18n.errorNonce : 'Session expired. Reload the page.');
				return;
			}
			if (!envelope.ok) {
				renderError(resultsContainer, (cfg.i18n && cfg.i18n.errorGeneric) || 'Query failed.');
				return;
			}
			var data;
			try {
				data = JSON.parse(envelope.text);
			} catch (e) {
				renderError(resultsContainer, (cfg.i18n && cfg.i18n.errorGeneric) || 'Query failed.');
				return;
			}
			renderResults(resultsContainer, (data && data.results) || []);
		}).catch(function () {
			renderError(resultsContainer, (cfg.i18n && cfg.i18n.errorGeneric) || 'Query failed.');
		}).then(function () {
			buttonNode.removeAttribute('disabled');
			buttonNode.textContent = originalText;
		});
	}

	function init() {
		var root = document.getElementById('wpvdb-demo-presets');
		if (!root) return;
		var buttonsContainer = root.querySelector('.wpvdb-demo-presets__buttons');
		var resultsContainer = root.querySelector('.wpvdb-demo-presets__results');
		if (!buttonsContainer || !resultsContainer) return;

		buttonsContainer.innerHTML = '';
		cfg.presets.forEach(function (preset) {
			var btn = el('button', {
				type: 'button',
				className: 'button button-secondary',
				text: preset.label,
				'data-id': preset.id
			});
			btn.addEventListener('click', function () {
				runPreset(preset, resultsContainer, btn);
			});
			buttonsContainer.appendChild(btn);
		});
	}

	ready(init);
})();
