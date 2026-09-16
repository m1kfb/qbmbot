/**
 * QBMBOT admin scripts.
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		if (window.jQuery && jQuery.fn.wpColorPicker) {
			jQuery('.qbmbot-color').wpColorPicker();
		}

		var list = document.getElementById('qbmbot-faq-list');
		var addBtn = document.getElementById('qbmbot-faq-add');
		var tpl = document.getElementById('qbmbot-faq-template');
		if (!list || !addBtn || !tpl) {
			return;
		}

		addBtn.addEventListener('click', function () {
			var index = list.querySelectorAll('.qbmbot-faq-item').length;
			var html = tpl.innerHTML.replace(/__I__/g, String(index));
			var wrap = document.createElement('div');
			wrap.innerHTML = html.trim();
			list.appendChild(wrap.firstElementChild);
		});

		list.addEventListener('click', function (e) {
			var target = e.target;
			if (!(target instanceof Element)) {
				return;
			}
			if (target.classList.contains('qbmbot-faq-remove')) {
				var item = target.closest('.qbmbot-faq-item');
				if (item && list.querySelectorAll('.qbmbot-faq-item').length > 1) {
					item.remove();
				} else if (item) {
					item.querySelectorAll('input[type="text"], textarea').forEach(function (el) {
						el.value = '';
					});
				}
			}
		});
	});
})();
