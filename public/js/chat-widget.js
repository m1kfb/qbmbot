/**
 * QBMBOT floating chat widget (bottom-left).
 */
(function () {
	'use strict';

	function readConfig() {
		if (window.qbmbotConfig && window.qbmbotConfig.restUrl) {
			return window.qbmbotConfig;
		}

		var jsonEl = document.getElementById('qbmbot-config');
		if (jsonEl && jsonEl.textContent) {
			try {
				var parsed = JSON.parse(jsonEl.textContent);
				if (parsed && parsed.restUrl) {
					return parsed;
				}
			} catch (e) {
				/* ignore */
			}
		}

		var root = document.getElementById('qbmbot-root');
		if (root && root.getAttribute('data-rest-url')) {
			return {
				restUrl: root.getAttribute('data-rest-url'),
				nonce: root.getAttribute('data-nonce') || '',
				faqs: [],
				appearance: {},
				i18n: {},
			};
		}

		return null;
	}

	function positionClass(config) {
		var appearance = (config && config.appearance) || {};
		return appearance.position === 'right' ? 'qbmbot-root--right' : 'qbmbot-root--left';
	}

	function ensureRoot(config) {
		var sideClass = positionClass(config);
		var root = document.getElementById('qbmbot-root');
		if (root) {
			root.classList.remove('qbmbot-root--left', 'qbmbot-root--right');
			root.classList.add(sideClass);
			return root;
		}
		if (!document.body) {
			return null;
		}
		root = document.createElement('div');
		root.id = 'qbmbot-root';
		root.className = 'qbmbot-root ' + sideClass;
		root.setAttribute('aria-live', 'polite');
		document.body.appendChild(root);
		return root;
	}

	function boot() {
		var config = readConfig();
		if (!config || !config.restUrl) {
			return;
		}

		var root = ensureRoot(config);
		if (!root) {
			return;
		}

		if (root.getAttribute('data-qbmbot-init') === '1') {
			return;
		}
		root.setAttribute('data-qbmbot-init', '1');

		var SESSION_KEY = 'qbmbot_session';
		var OPENED_KEY = 'qbmbot_opened_at';

		function uuid() {
			if (window.crypto && crypto.randomUUID) {
				return crypto.randomUUID();
			}
			return 's' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
		}

		function getSession() {
			try {
				var id = sessionStorage.getItem(SESSION_KEY);
				if (!id) {
					id = uuid();
					sessionStorage.setItem(SESSION_KEY, id);
				}
				return id;
			} catch (e) {
				return uuid();
			}
		}

		function ensureOpenedAt() {
			try {
				var existing = sessionStorage.getItem(OPENED_KEY);
				if (existing) {
					return parseInt(existing, 10) || Date.now();
				}
				var now = Date.now();
				sessionStorage.setItem(OPENED_KEY, String(now));
				return now;
			} catch (e) {
				return Date.now();
			}
		}

		var appearance = config.appearance || {};
		var i18n = config.i18n || {};
		var sessionId = getSession();
		var openedAt = ensureOpenedAt();
		var busy = false;

		root.innerHTML =
			'<button type="button" class="qbmbot-launcher" aria-expanded="false" aria-controls="qbmbot-panel" title="' +
			escapeAttr(i18n.openChat || 'Open chat') +
			'">' +
			(appearance.logo
				? '<img src="' + escapeAttr(appearance.logo) + '" alt="" class="qbmbot-launcher-icon" />'
				: '<span class="qbmbot-launcher-glyph" aria-hidden="true"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v7A2.5 2.5 0 0 1 17.5 16H10l-4.2 3.15A.75.75 0 0 1 4.6 18.6V16.2A2.5 2.5 0 0 1 4 13.5v-7Z" fill="currentColor"/></svg></span>') +
			'</button>' +
			'<div id="qbmbot-panel" class="qbmbot-panel" hidden>' +
			'<div class="qbmbot-header">' +
			'<div class="qbmbot-header-text">' +
			'<strong class="qbmbot-title"></strong>' +
			'<span class="qbmbot-subtitle"></span>' +
			'</div>' +
			'<button type="button" class="qbmbot-close" aria-label="' +
			escapeAttr(i18n.closeChat || 'Close chat') +
			'">×</button>' +
			'</div>' +
			'<div class="qbmbot-messages" role="log"></div>' +
			'<div class="qbmbot-suggestions"></div>' +
			'<form class="qbmbot-form" autocomplete="off">' +
			'<label class="qbmbot-hp" aria-hidden="true">' +
			'<input type="text" name="qbmbot_hp" tabindex="-1" autocomplete="off" />' +
			'</label>' +
			'<input type="text" class="qbmbot-input" name="message" maxlength="2000" placeholder="' +
			escapeAttr(i18n.placeholder || 'Type your message…') +
			'" required />' +
			'<button type="submit" class="qbmbot-send">' +
			escapeHtml(i18n.send || 'Send') +
			'</button>' +
			'</form>' +
			'</div>';

		var launcher = root.querySelector('.qbmbot-launcher');
		var panel = root.querySelector('.qbmbot-panel');
		var closeBtn = root.querySelector('.qbmbot-close');
		var messages = root.querySelector('.qbmbot-messages');
		var suggestions = root.querySelector('.qbmbot-suggestions');
		var form = root.querySelector('.qbmbot-form');
		var input = root.querySelector('.qbmbot-input');
		var hp = root.querySelector('.qbmbot-hp input');

		root.querySelector('.qbmbot-title').textContent = appearance.title || 'Chat with us';
		root.querySelector('.qbmbot-subtitle').textContent = appearance.subtitle || '';

		function escapeHtml(str) {
			return String(str)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;');
		}

		function escapeAttr(str) {
			return escapeHtml(str).replace(/'/g, '&#39;');
		}

		function setOpen(open) {
			if (open) {
				panel.hidden = false;
				launcher.setAttribute('aria-expanded', 'true');
				openedAt = ensureOpenedAt();
				input.focus();
			} else {
				panel.hidden = true;
				launcher.setAttribute('aria-expanded', 'false');
			}
		}

		function appendBubble(role, text) {
			var el = document.createElement('div');
			el.className = 'qbmbot-bubble qbmbot-bubble--' + role;
			el.textContent = text;
			messages.appendChild(el);
			messages.scrollTop = messages.scrollHeight;
		}

		function renderSuggestions() {
			suggestions.innerHTML = '';
			var faqs = config.faqs || [];
			faqs.forEach(function (faq) {
				if (!faq || !faq.question) {
					return;
				}
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'qbmbot-chip';
				btn.textContent = faq.question;
				btn.addEventListener('click', function () {
					sendMessage(faq.question, faq.id || '');
				});
				suggestions.appendChild(btn);
			});
		}

		function setBusy(state) {
			busy = state;
			input.disabled = state;
			form.querySelector('.qbmbot-send').disabled = state;
		}

		function sendMessage(text, faqId) {
			text = String(text || '').trim();
			if (!text || busy) {
				return;
			}

			appendBubble('user', text);
			suggestions.innerHTML = '';
			setBusy(true);

			var typing = document.createElement('div');
			typing.className = 'qbmbot-bubble qbmbot-bubble--assistant qbmbot-typing';
			typing.textContent = '…';
			messages.appendChild(typing);
			messages.scrollTop = messages.scrollHeight;

			fetch(config.restUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce || '',
				},
				body: JSON.stringify({
					message: text,
					session_id: sessionId,
					faq_id: faqId || '',
					honeypot: hp ? hp.value : '',
					opened_at_ms: openedAt,
				}),
			})
				.then(function (res) {
					return res.json().then(function (data) {
						return { ok: res.ok, status: res.status, data: data };
					});
				})
				.then(function (result) {
					typing.remove();
					if (result.status === 429) {
						appendBubble('assistant', i18n.rateLimited || 'Too many messages. Please wait a moment.');
						return;
					}
					if (!result.ok || !result.data || !result.data.reply) {
						var msg =
							(result.data && result.data.message) ||
							i18n.error ||
							'Something went wrong. Please try again.';
						appendBubble('assistant', msg);
						return;
					}
					appendBubble('assistant', result.data.reply);
				})
				.catch(function () {
					typing.remove();
					appendBubble('assistant', i18n.error || 'Something went wrong. Please try again.');
				})
				.finally(function () {
					setBusy(false);
				});
		}

		launcher.addEventListener('click', function () {
			setOpen(panel.hidden);
		});
		closeBtn.addEventListener('click', function () {
			setOpen(false);
		});

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var value = input.value;
			input.value = '';
			sendMessage(value, '');
		});

		appendBubble('assistant', appearance.welcome || 'Hi! How can we help today?');
		renderSuggestions();
	}

	function scheduleBoot() {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', boot);
		} else {
			boot();
		}
	}

	scheduleBoot();
})();
