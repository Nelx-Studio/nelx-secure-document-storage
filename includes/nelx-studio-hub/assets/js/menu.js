(function () {
	'use strict';

	function pageFromUrl(url) {
		try {
			return new URL(url, window.location.href).searchParams.get('page') || '';
		} catch (e) {
			return '';
		}
	}

	function findProductLink(menu, menuSlug) {
		var links = menu.querySelectorAll(':scope > li > a');
		for (var i = 0; i < links.length; i++) {
			if (pageFromUrl(links[i].href) === menuSlug) {
				return links[i];
			}
		}
		return null;
	}

	function buildFlyout(parentItem, group, currentPage) {
		if (!parentItem || !group || !Array.isArray(group.children) || !group.children.length) {
			return;
		}

		if (parentItem.querySelector(':scope > .nelx-hub-third-level')) {
			return;
		}

		parentItem.classList.add('nelx-hub-menu-has-children');
		var parentLink = parentItem.querySelector(':scope > a');
		if (!parentLink) {
			return;
		}

		parentLink.setAttribute('aria-haspopup', 'true');
		parentLink.setAttribute('aria-expanded', 'false');

		var arrow = document.createElement('span');
		arrow.className = 'nelx-hub-menu-arrow';
		arrow.setAttribute('aria-hidden', 'true');
		parentLink.appendChild(arrow);

		var flyout = document.createElement('ul');
		flyout.className = 'nelx-hub-third-level';
		flyout.setAttribute('role', 'menu');

		group.children.forEach(function (child) {
			if (!child || !child.title || !child.target) {
				return;
			}

			var item = document.createElement('li');
			var link = document.createElement('a');
			link.href = child.target;
			link.textContent = child.title;
			link.setAttribute('role', 'menuitem');

			if (currentPage && (child.page || child.slug) === currentPage) {
				item.classList.add('current');
				link.setAttribute('aria-current', 'page');
				parentItem.classList.add('current', 'nelx-hub-child-current');
			}

			item.appendChild(link);
			flyout.appendChild(item);
		});

		parentItem.appendChild(flyout);

		parentItem.addEventListener('mouseenter', function () {
			parentLink.setAttribute('aria-expanded', 'true');
		});
		parentItem.addEventListener('mouseleave', function () {
			parentLink.setAttribute('aria-expanded', 'false');
		});
		parentItem.addEventListener('focusin', function () {
			parentLink.setAttribute('aria-expanded', 'true');
		});
		parentItem.addEventListener('focusout', function (event) {
			if (!parentItem.contains(event.relatedTarget)) {
				parentLink.setAttribute('aria-expanded', 'false');
			}
		});
	}

	function initNelxHubMenu() {
		if (typeof nelxHubMenu === 'undefined' || !Array.isArray(nelxHubMenu.groups)) {
			return;
		}

		var top = document.getElementById('toplevel_page_nelx-studio');
		if (!top) {
			return;
		}

		var menu = top.querySelector(':scope > ul.wp-submenu');
		if (!menu) {
			return;
		}

		var currentPage = pageFromUrl(window.location.href);

		nelxHubMenu.groups.forEach(function (group) {
			if (!group || !group.menuSlug || !Array.isArray(group.children) || !group.children.length) {
				return;
			}

			var productLink = findProductLink(menu, group.menuSlug);
			if (!productLink) {
				return;
			}

			buildFlyout(productLink.parentElement, group, currentPage);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initNelxHubMenu);
	} else {
		initNelxHubMenu();
	}
}());
