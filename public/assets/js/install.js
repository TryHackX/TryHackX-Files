'use strict';

(() => {
	const config = document.querySelector('meta[name="filehost-installer-csrf"]');
	if (!config) {
		return;
	}

	const csrf = config.content;
	const api = (name) => 'install.php?api=' + encodeURIComponent(name);
	const $ = (selector) => document.querySelector(selector);
	const $$ = (selector) => document.querySelectorAll(selector);

	async function apiPost(name, body = {}) {
		const response = await fetch(api(name), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-Installer-CSRF': csrf
			},
			body: JSON.stringify(body)
		});
		return response.json();
	}

	function goTo(step) {
		$$('.panel').forEach((panel) => panel.classList.remove('show'));
		const panel = $('#panel-' + step);
		if (!panel) {
			return;
		}
		void panel.offsetWidth;
		panel.classList.add('show');

		$$('.step-dot').forEach((dot) => {
			const dotStep = Number(dot.dataset.step);
			dot.classList.toggle('active', dotStep === step);
			dot.classList.toggle('done', dotStep < step);
		});
	}

	$$('[data-back]').forEach((button) => {
		button.addEventListener('click', () => goTo(Number(button.dataset.back)));
	});

	function statusIcon(status) {
		const icon = document.createElement('div');
		icon.className = 'ico';
		if (status === 'ok') {
			const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
			svg.setAttribute('viewBox', '0 0 24 24');
			const polyline = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
			polyline.setAttribute('points', '20 6 9 17 4 12');
			polyline.setAttribute('class', 'checkmark');
			svg.append(polyline);
			icon.append(svg);
		} else if (status === 'pending') {
			const spinner = document.createElement('div');
			spinner.className = 'spinner';
			icon.append(spinner);
		} else {
			icon.textContent = status === 'warn' ? '!' : '✕';
		}
		return icon;
	}

	function renderCheck(check, index) {
		const statuses = new Set(['ok', 'warn', 'fail', 'pending']);
		const status = statuses.has(check.status) ? check.status : 'fail';
		const row = document.createElement('div');
		row.className = 'check ' + status;
		row.style.animationDelay = (index * 70) + 'ms';
		row.append(statusIcon(status));

		const text = document.createElement('div');
		text.className = 'txt';
		const label = document.createElement('b');
		label.textContent = String(check.label ?? '');
		const detail = document.createElement('small');
		detail.textContent = String(check.detail ?? '');
		text.append(label, detail);
		row.append(text);
		return row;
	}

	async function runChecks() {
		const box = $('#checks');
		const alertBox = $('#env-alert');
		alertBox.classList.remove('show');
		$('#toStep2').disabled = true;
		box.replaceChildren(renderCheck({
			status: 'pending',
			label: 'Checking…',
			detail: 'Connecting to the server'
		}, 0));

		try {
			const [environment, python] = await Promise.all([
				apiPost('requirements'),
				apiPost('python_health')
			]);
			const checks = Array.isArray(environment.checks) ? environment.checks.slice() : [];
			checks.push({
				id: 'python',
				label: 'Python upload server (port 8001)',
				status: python.python && python.python.running ? 'ok' : 'warn',
				detail: python.python ? python.python.detail : 'No response'
			});
			box.replaceChildren(...checks.map(renderCheck));

			if (checks.some((check) => check.status === 'fail')) {
				alertBox.textContent = 'Fix the errors above and click “Check again”.';
				alertBox.classList.add('show');
			} else {
				$('#toStep2').disabled = false;
			}
		} catch (error) {
			box.replaceChildren(renderCheck({
				status: 'fail',
				label: 'Connection error',
				detail: String(error)
			}, 0));
		}
	}

	$('#recheckBtn').addEventListener('click', runChecks);
	$('#toStep2').addEventListener('click', () => goTo(2));
	void runChecks();

	let dbTested = false;

	function dbConfig() {
		return {
			host: $('#db_host').value.trim(),
			name: $('#db_name').value.trim(),
			user: $('#db_user').value.trim(),
			pass: $('#db_pass').value,
			prefix: $('#db_prefix').value.trim(),
			uploads_path: $('#uploads_path').value.trim(),
			lock_uploads_path: $('#lock_uploads_path').checked
		};
	}

	['db_host', 'db_name', 'db_user', 'db_pass', 'db_prefix'].forEach((id) => {
		$('#' + id).addEventListener('input', () => {
			dbTested = false;
			$('#toStep3').disabled = true;
			$('#db-alert').classList.remove('show');
		});
	});

	$('#testDbBtn').addEventListener('click', async () => {
		const button = $('#testDbBtn');
		const alertBox = $('#db-alert');
		button.classList.add('loading');
		button.disabled = true;
		alertBox.className = 'alert';

		try {
			const result = await apiPost('test_db', dbConfig());
			if (result.success) {
				dbTested = true;
				$('#toStep3').disabled = false;
				alertBox.classList.add('ok', 'show');
				alertBox.textContent = '✓ ' + result.message
					+ (result.tables_exist
						? ' Existing tables were found — their data will be preserved.'
						: '')
					+ (!result.db_exists ? ' The database will be created.' : '');
			} else {
				alertBox.classList.add('err', 'show');
				alertBox.textContent = result.error || 'Could not connect.';
			}
		} catch (error) {
			alertBox.classList.add('err', 'show');
			alertBox.textContent = 'Network error: ' + error;
		} finally {
			button.classList.remove('loading');
			button.disabled = false;
		}
	});

	$('#toStep3').addEventListener('click', () => {
		if (dbTested) {
			goTo(3);
		}
	});

	const passInput = $('#adm_pass');
	const requirementTests = {
		len: (value) => value.length >= 8,
		upper: (value) => /[A-Z]/.test(value),
		digit: (value) => /[0-9]/.test(value),
		special: (value) => /[^a-zA-Z0-9]/.test(value)
	};

	function validateAdmin() {
		const password = passInput.value;
		let met = 0;

		for (const [key, test] of Object.entries(requirementTests)) {
			const valid = test(password);
			document.querySelector(`[data-req="${key}"]`).classList.toggle('met', valid);
			if (valid) {
				met++;
			}
		}

		const bar = $('#strengthBar');
		bar.style.width = (met * 25) + '%';
		bar.style.background = met <= 1
			? 'var(--fail)'
			: met <= 3 ? 'var(--warn)' : 'var(--ok)';

		const userValid = $('#adm_user').value.trim().length >= 3;
		const emailValid = /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test($('#adm_email').value.trim());
		let canonicalValid = false;
		try {
			const canonical = new URL($('#canonical_url').value.trim());
			canonicalValid = ['http:', 'https:'].includes(canonical.protocol)
				&& canonical.username === ''
				&& canonical.password === ''
				&& canonical.search === ''
				&& canonical.hash === '';
		} catch {
			canonicalValid = false;
		}

		const passwordValid = met === 4;
		const confirmationValid = password !== '' && password === $('#adm_pass2').value;
		$('#toStep4').disabled = !(
			userValid
			&& emailValid
			&& canonicalValid
			&& passwordValid
			&& confirmationValid
		);
	}

	['canonical_url', 'adm_user', 'adm_email', 'adm_pass', 'adm_pass2'].forEach((id) => {
		$('#' + id).addEventListener('input', validateAdmin);
	});

	$('#toStep4').addEventListener('click', () => {
		goTo(4);
		void runInstall();
	});

	const installSteps = [
		{ id: 'db_prepare', label: 'Creating the database' },
		{ id: 'create_tables', label: 'Creating and migrating tables' },
		{ id: 'default_settings', label: 'Saving default settings' },
		{ id: 'create_admin', label: 'Creating the administrator account' },
		{ id: 'write_config', label: 'Preparing config.local.php' },
		{ id: 'finalize', label: 'Publishing configuration and locking the installer' }
	];

	function renderInstallTasks(container) {
		const rows = installSteps.map((step) => {
			const row = document.createElement('div');
			row.className = 'task';
			row.id = 'task-' + step.id;
			row.append(document.createTextNode(step.label));
			const status = document.createElement('span');
			status.className = 'status';
			row.append(status);
			return row;
		});
		container.replaceChildren(...rows);
	}

	function showTaskSpinner(status) {
		const spinner = document.createElement('div');
		spinner.className = 'spinner';
		status.replaceChildren(spinner);
	}

	async function runInstall() {
		const tasksBox = $('#tasks');
		const alertBox = $('#install-alert');
		const progress = $('#progressBar');

		$('#retryActions').style.display = 'none';
		alertBox.classList.remove('show');
		$('#success').style.display = 'none';
		$('#installing').style.display = 'block';
		renderInstallTasks(tasksBox);

		const payloads = {
			default_settings: {
				app_name: $('#app_name').value.trim(),
				canonical_url: $('#canonical_url').value.trim()
			},
			create_admin: {
				username: $('#adm_user').value.trim(),
				email: $('#adm_email').value.trim(),
				password: passInput.value
			}
		};

		let finalizeData = null;
		for (let index = 0; index < installSteps.length; index++) {
			const step = installSteps[index];
			const row = $('#task-' + step.id);
			const status = row.querySelector('.status');
			row.classList.add('running');
			showTaskSpinner(status);

			try {
				const result = await apiPost(step.id, payloads[step.id] || {});
				if (!result.success) {
					throw new Error(result.error || 'Unknown error');
				}

				row.classList.remove('running');
				row.classList.add('done');
				status.textContent = result.skipped ? '↷ skipped' : '✓';
				progress.style.width = Math.round(
					((index + 1) / installSteps.length) * 100
				) + '%';
				if (step.id === 'finalize') {
					finalizeData = result;
				}
				await new Promise((resolve) => setTimeout(resolve, 180));
			} catch (error) {
				row.classList.remove('running');
				row.classList.add('error');
				status.textContent = '✕';
				alertBox.textContent = error instanceof Error ? error.message : String(error);
				alertBox.classList.add('show');
				$('#retryActions').style.display = 'flex';
				return;
			}
		}

		showSuccess(finalizeData);
	}

	function summaryRow(labelText, valueText) {
		const row = document.createElement('div');
		const label = document.createElement('span');
		label.textContent = labelText;
		const value = document.createElement('b');
		value.textContent = valueText;
		row.append(label, value);
		return row;
	}

	function showSuccess(finalizeData) {
		const db = dbConfig();
		$('#summaryBox').replaceChildren(
			summaryRow('Service', $('#app_name').value.trim() || 'TryHackX Files'),
			summaryRow('Database', db.name + ' @ ' + db.host),
			summaryRow('Table prefix', db.prefix || '(none)'),
			summaryRow('Administrator', $('#adm_user').value.trim()),
			summaryRow(
				'Python server',
				finalizeData && finalizeData.python_reloaded
					? '✓ reloaded'
					: '— not running'
			)
		);

		if (!finalizeData || !finalizeData.python_reloaded) {
			$('#pythonHint').style.display = 'block';
		}
		$('#installing').style.display = 'none';
		$('#success').style.display = 'block';
	}

	$('#retryBtn').addEventListener('click', () => {
		void runInstall();
	});
})();
