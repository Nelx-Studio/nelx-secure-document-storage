(function () {
	'use strict';

	function showUpdateFeedback(message, type) {
		var feedback = document.getElementById('nelx-update-feedback');
		if (!feedback || !message) {
			return;
		}
		feedback.hidden = false;
		feedback.classList.remove('is-success', 'is-error');
		feedback.classList.add(type === 'error' ? 'is-error' : 'is-success');
		feedback.textContent = message;
	}

	function setUpdateSummary(count) {
		var countNode = document.getElementById('nelx-update-count');
		var labelNode = document.getElementById('nelx-update-label');
		if (countNode) {
			countNode.textContent = String(count);
		}
		if (labelNode) {
			labelNode.textContent = count === 1 ? 'Update available' : 'Updates available';
		}
	}

	function setProductStatus(slug, state) {
		var status = document.querySelector('[data-update-status="' + CSS.escape(slug) + '"]');
		var card = document.querySelector('[data-product-slug="' + CSS.escape(slug) + '"]');
		var hasUpdate = Boolean(state && state.hasUpdate);

		if (status) {
			status.classList.toggle('is-update', hasUpdate);
			status.classList.toggle('is-current', !hasUpdate);

			var icon = status.querySelector('.dashicons');
			var label = status.querySelector('.nelx-status-label');
			if (icon) {
				icon.className = 'dashicons ' + (hasUpdate ? 'dashicons-warning' : 'dashicons-yes-alt');
			}
			if (label) {
				label.textContent = hasUpdate ? 'Update available' : 'Up to date';
			}
		}

		if (card) {
			var updateButton = card.querySelector('[data-nelx-update-plugin]');
			if (updateButton) {
				updateButton.hidden = !hasUpdate;
			}

			var isActive = Boolean(state && state.active);
			var activity = card.querySelector('.nelx-activity');
			if (activity) {
				activity.classList.toggle('is-active', isActive);
				activity.classList.toggle('is-inactive', !isActive);
				var activityIcon = activity.querySelector('.dashicons');
				if (activityIcon) {
					activityIcon.className = 'dashicons ' + (isActive ? 'dashicons-yes-alt' : 'dashicons-marker');
				}
				var activityText = activity.querySelector('.nelx-activity-label');
				if (activityText) {
					activityText.textContent = isActive ? (nelxHub.i18n.active || 'Active') : (nelxHub.i18n.inactive || 'Inactive');
				}
			}

			var activateButton = card.querySelector('[data-nelx-activate-plugin]');
			if (activateButton) {
				activateButton.hidden = isActive;
			}

			var manageLink = card.querySelector('[data-nelx-manage-link]');
			if (manageLink) {
				if (state && state.manageUrl && isActive) {
					manageLink.setAttribute('href', state.manageUrl);
				}
				var manageLabel = manageLink.querySelector('.nelx-manage-label');
				if (manageLabel) {
					manageLabel.textContent = isActive ? (nelxHub.i18n.manage || 'Manage') : (nelxHub.i18n.viewPlugins || 'View Plugins');
				}
			}

			var version = card.querySelector('[data-product-version]');
			if (version && state && state.version) {
				version.textContent = 'v' + state.version;
			}
		}
	}

	function applyUpdateState(data) {
		if (!data) {
			return;
		}
		setUpdateSummary(Number(data.count || 0));
		Object.keys(data.statuses || {}).forEach(function (slug) {
			setProductStatus(slug, data.statuses[slug]);
		});
	}

	function refreshUpdates(manual) {
		if (typeof nelxHub === 'undefined') {
			return Promise.resolve();
		}

		var updateButton = document.getElementById('nelx-check-updates');
		if (updateButton && manual) {
			if (updateButton.disabled) {
				return Promise.resolve();
			}
			updateButton.disabled = true;
			updateButton.classList.add('is-checking');
		}

		var body = new URLSearchParams();
		body.append('action', 'nelx_hub_refresh_updates');
		body.append('nonce', nelxHub.nonce);
		body.append('force', manual ? '1' : '0');

		return fetch(nelxHub.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: body.toString()
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				if (!payload || !payload.success || !payload.data) {
					throw new Error(payload && payload.data && payload.data.message ? payload.data.message : nelxHub.i18n.error);
				}
				applyUpdateState(payload.data);
				if (manual) {
					showUpdateFeedback(payload.data.message, 'success');
				}
			})
			.catch(function (error) {
				if (manual) {
					showUpdateFeedback(error && error.message ? error.message : nelxHub.i18n.error, 'error');
				}
			})
			.finally(function () {
				if (updateButton && manual) {
					updateButton.disabled = false;
					updateButton.classList.remove('is-checking');
				}
			});
	}

	function updatePlugin(button) {
		if (!button || button.disabled || typeof nelxHub === 'undefined') {
			return;
		}

		var pluginFile = button.getAttribute('data-plugin-file') || '';
		if (!pluginFile) {
			return;
		}

		var label = button.querySelector('.nelx-update-button-label');
		button.disabled = true;
		button.classList.add('is-updating');
		if (label) {
			label.textContent = nelxHub.i18n.updating || 'Updating…';
		}

		var body = new URLSearchParams();
		body.append('action', 'nelx_hub_update_plugin');
		body.append('nonce', nelxHub.updateNonce);
		body.append('plugin', pluginFile);

		fetch(nelxHub.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: body.toString()
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				if (!payload || !payload.success || !payload.data) {
					throw new Error(payload && payload.data && payload.data.message ? payload.data.message : nelxHub.i18n.updateFailed);
				}
				applyUpdateState(payload.data);
				showUpdateFeedback(payload.data.message, 'success');
			})
			.catch(function (error) {
				showUpdateFeedback(error && error.message ? error.message : nelxHub.i18n.updateFailed, 'error');
			})
			.finally(function () {
				button.disabled = false;
				button.classList.remove('is-updating');
				if (label) {
					label.textContent = nelxHub.i18n.updateNow || 'Update now';
				}
			});
	}


	function activatePlugin(button) {
		if (!button || button.disabled || typeof nelxHub === 'undefined') {
			return;
		}

		var pluginFile = button.getAttribute('data-plugin-file') || '';
		if (!pluginFile) {
			return;
		}

		var label = button.querySelector('.nelx-activate-button-label');
		button.disabled = true;
		button.classList.add('is-activating');
		if (label) {
			label.textContent = nelxHub.i18n.activating || 'Activating…';
		}

		var body = new URLSearchParams();
		body.append('action', 'nelx_hub_activate_plugin');
		body.append('nonce', nelxHub.activateNonce);
		body.append('plugin', pluginFile);

		fetch(nelxHub.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: body.toString()
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				if (!payload || !payload.success || !payload.data) {
					throw new Error(payload && payload.data && payload.data.message ? payload.data.message : nelxHub.i18n.activateFailed);
				}
				applyUpdateState(payload.data);
				showUpdateFeedback(payload.data.message, 'success');
				window.setTimeout(function () {
					window.location.reload();
				}, 2000);
			})
			.catch(function (error) {
				showUpdateFeedback(error && error.message ? error.message : nelxHub.i18n.activateFailed, 'error');
			})
			.finally(function () {
				button.disabled = false;
				button.classList.remove('is-activating');
				if (label) {
					label.textContent = nelxHub.i18n.activate || 'Activate';
				}
			});
	}

	document.addEventListener('click', function (event) {
		var copyButton = event.target.closest('#nelx-copy-info');
		if (copyButton) {
			var field = document.getElementById('nelx-system-info');
			if (!field) {
				return;
			}

			var showSuccess = function () {
				var existing = copyButton.parentNode.querySelector('.nelx-copy-success');
				if (!existing) {
					existing = document.createElement('span');
					existing.className = 'nelx-copy-success';
					copyButton.parentNode.appendChild(existing);
				}
				existing.textContent = 'Copied';
				window.setTimeout(function () { existing.textContent = ''; }, 1800);
			};

			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(field.value).then(showSuccess);
				return;
			}

			field.focus();
			field.select();
			try {
				document.execCommand('copy');
				showSuccess();
			} catch (e) {
				// Leave the text selected so the user can copy manually.
			}
			return;
		}

		var activateNow = event.target.closest('[data-nelx-activate-plugin]');
		if (activateNow) {
			event.preventDefault();
			activatePlugin(activateNow);
			return;
		}

		var updateNow = event.target.closest('[data-nelx-update-plugin]');
		if (updateNow) {
			event.preventDefault();
			updatePlugin(updateNow);
			return;
		}

		var updateButton = event.target.closest('#nelx-check-updates');
		if (updateButton) {
			event.preventDefault();
			refreshUpdates(true);
		}
	});

	document.addEventListener('DOMContentLoaded', function () {
		if (typeof nelxHub !== 'undefined' && nelxHub.autoCheck && document.getElementById('nelx-products')) {
			window.setTimeout(function () {
				refreshUpdates(false);
			}, 350);
		}
	});
}());
