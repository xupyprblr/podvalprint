/* Шапка и главная в дизайн-системе: выпадающее меню по клику, мобильное меню, карта по клику. */
(function () {
	'use strict';

	/* Выпадающие пункты меню: открываются по клику (aria-expanded), закрываются кликом мимо и Esc. */
	var toggles = document.querySelectorAll('.js-pp-drop');
	function closeAll(except) {
		toggles.forEach(function (btn) {
			if (btn === except) return;
			btn.setAttribute('aria-expanded', 'false');
			var menu = document.getElementById(btn.getAttribute('aria-controls'));
			if (menu) menu.hidden = true;
		});
	}
	toggles.forEach(function (btn) {
		btn.addEventListener('click', function (e) {
			e.stopPropagation();
			var open = btn.getAttribute('aria-expanded') === 'true';
			closeAll(btn);
			btn.setAttribute('aria-expanded', open ? 'false' : 'true');
			var menu = document.getElementById(btn.getAttribute('aria-controls'));
			if (menu) menu.hidden = open;
		});
	});
	document.addEventListener('click', function (e) {
		if (!e.target.closest('.pp-dropdown')) closeAll(null);
	});

	/* Мобильное меню под шапкой. */
	var burger = document.querySelector('.js-pp-burger');
	var panel = document.getElementById('pp-mnav');
	function setMenu(open) {
		if (!burger || !panel) return;
		burger.setAttribute('aria-expanded', open ? 'true' : 'false');
		panel.classList.toggle('is-open', open);
		panel.hidden = !open;
		var icon = burger.querySelector('.js-pp-burger-icon');
		if (icon && burger.dataset.iconClose && burger.dataset.iconMenu) {
			icon.innerHTML = open ? burger.dataset.iconClose : burger.dataset.iconMenu;
		}
	}
	if (burger && panel) {
		burger.addEventListener('click', function () {
			setMenu(burger.getAttribute('aria-expanded') !== 'true');
		});
	}

	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape') return;
		closeAll(null);
		if (burger && burger.getAttribute('aria-expanded') === 'true') {
			setMenu(false);
			burger.focus();
		}
	});

	/* Карта: iframe грузится только по клику, чтобы не тянуть скрипты карт на первом экране. */
	document.querySelectorAll('.js-pp-map').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var src = btn.getAttribute('data-src');
			if (!src) return;
			var frame = document.createElement('iframe');
			frame.src = src;
			frame.loading = 'lazy';
			frame.title = btn.getAttribute('data-title') || '';
			frame.referrerPolicy = 'no-referrer-when-downgrade';
			btn.parentNode.replaceChild(frame, btn);
		});
	});
})();
