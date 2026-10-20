/**
 * Panel: answer "recent_auth_required" with a password prompt instead of a dead error.
 *
 * Actions that grant privileges or rewire payments are refused by the API when the last
 * password check is more than a few minutes old (src/includes/api/bootstrap.php). This file
 * wraps FHApi so that such a refusal opens a small password dialog, confirms the password
 * through `user_reauth`, and then repeats the original request once. Cancelling hands the
 * original refusal back to the caller, which shows it as before.
 *
 * Load after api.js and before the panel scripts that use FHApi.
 */
(function () {
	'use strict';

	var api = window.FHApi;
	if (!api) {
		return;
	}
	var tr = function (key, params) {
		return typeof window.t === 'function' ? window.t(key, params) : key;
	};
	var original = { get: api.get, post: api.post, postForm: api.postForm };
	var pending = null; // one prompt at a time; parallel refusals wait for the same answer

	function el(tag, attrs, text) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (name) {
			node.setAttribute(name, attrs[name]);
		});
		if (text !== undefined) {
			node.textContent = text;
		}
		return node;
	}

	function buildDialog() {
		// `data-fh-runtime`: built here, never part of a server page (see the live language
		// switch in ui.js, which lines the two up).
		var bg = el('div', {
			class: 'modal-bg',
			id: 'reauthModal',
			role: 'dialog',
			'aria-modal': 'true',
			'data-fh-runtime': ''
		});
		var modal = el('div', { class: 'modal' });
		var header = el('div', { class: 'modal-header' });
		header.appendChild(el('h3', {}, tr('panel.reauth.title')));
		var body = el('div', { class: 'modal-body' });
		var intro = el('p', {}, tr('panel.reauth.action_msg'));
		var message = el('div', { class: 'auth-message', id: 'reauthModalMessage' });
		var form = el('form', { id: 'reauthModalForm', autocomplete: 'off' });
		var group = el('div', { class: 'form-group' });
		var label = el('label', { for: 'reauthModalPassword' }, tr('panel.modal.password'));
		var input = el('input', {
			type: 'password',
			id: 'reauthModalPassword',
			class: 'auth-input',
			required: 'required',
			maxlength: '1024',
			autocomplete: 'current-password'
		});
		group.appendChild(label);
		group.appendChild(input);
		var buttons = el('div', { class: 'modal-btns' });
		var cancel = el('button', { type: 'button', class: 'btn' }, tr('common.cancel'));
		var submit = el('button', { type: 'submit', class: 'btn btn-primary' }, tr('panel.modal.confirm'));
		buttons.appendChild(cancel);
		buttons.appendChild(submit);
		form.appendChild(group);
		form.appendChild(buttons);
		body.appendChild(intro);
		body.appendChild(message);
		body.appendChild(form);
		modal.appendChild(header);
		modal.appendChild(body);
		bg.appendChild(modal);
		document.body.appendChild(bg);
		return { bg: bg, form: form, input: input, cancel: cancel, submit: submit, message: message };
	}

	function open(dialog) {
		if (typeof window.openModal === 'function') {
			window.openModal('reauthModal');
		} else {
			dialog.bg.classList.add('show');
		}
		setTimeout(function () { dialog.input.focus(); }, 60);
	}

	function close(dialog) {
		if (typeof window.closeModal === 'function') {
			window.closeModal('reauthModal');
		} else {
			dialog.bg.classList.remove('show');
		}
		dialog.bg.remove();
	}

	/** Resolves true once the password was accepted, false when the user gave up. */
	function askForPassword() {
		if (pending) {
			return pending;
		}
		pending = new Promise(function (resolve) {
			var dialog = buildDialog();
			var done = false;
			var finish = function (ok) {
				if (done) {
					return;
				}
				done = true;
				close(dialog);
				pending = null;
				resolve(ok);
			};
			dialog.cancel.addEventListener('click', function () { finish(false); });
			dialog.bg.addEventListener('keydown', function (event) {
				if (event.key === 'Escape') {
					// This Escape is the prompt's. Left to bubble, the panel's own handler took
					// it as well and closed the form underneath — the one the admin was saving.
					if (typeof event.preventDefault === 'function') event.preventDefault();
					if (typeof event.stopPropagation === 'function') event.stopPropagation();
					finish(false);
				}
			});
			dialog.bg.addEventListener('click', function (event) {
				if (event.target === dialog.bg) {
					finish(false);
				}
			});
			// Closed some other way — the panel's Escape with focus outside the prompt, its
			// backdrop handling — the answer is "no" as well. Without this the shared promise
			// never settled and every later privileged action waited on it until a reload.
			dialog.bg.addEventListener('fh:modal-dismissed', function () { finish(false); });
			dialog.form.addEventListener('submit', function (event) {
				event.preventDefault();
				var password = dialog.input.value;
				if (!password) {
					return;
				}
				dialog.submit.disabled = true;
				original.post('user_reauth', { password: password }).then(function (data) {
					if (data && data.success) {
						finish(true);
						return;
					}
					dialog.message.textContent = (data && data.error) || tr('api.bad_password');
					dialog.message.className = 'auth-message error show';
					dialog.input.value = '';
					dialog.input.focus();
				}).catch(function () {
					dialog.message.textContent = tr('common.connection_error');
					dialog.message.className = 'auth-message error show';
				}).finally(function () {
					dialog.submit.disabled = false;
				});
			});
			open(dialog);
		});
		return pending;
	}

	function guard(call) {
		return function () {
			var args = arguments;
			return call.apply(api, args).then(function (data) {
				if (!data || data.code !== 'recent_auth_required') {
					return data;
				}
				return askForPassword().then(function (confirmed) {
					return confirmed ? call.apply(api, args) : data;
				});
			});
		};
	}

	api.get = guard(original.get);
	api.post = guard(original.post);
	api.postForm = guard(original.postForm);
}());
