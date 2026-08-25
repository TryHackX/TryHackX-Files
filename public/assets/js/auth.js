'use strict';

    const authBootstrap = document.getElementById('authBootstrap');
    const authApiUrl = authBootstrap ? authBootstrap.dataset.api : '/api.php';
    let currentUser = JSON.parse(
        authBootstrap && authBootstrap.dataset.user ? authBootstrap.dataset.user : 'null'
    );

    let appConfig = {
        registration_enabled: true,
        recaptcha_enabled: false,
        recaptcha_site_key: '',
        recaptcha_on_login: false,
        recaptcha_register_always: false,
        input_limits: { username_min: 3, username_max: 32, email_max: 254, password_min: 8, password_max: 72 }
    };

    function clearAuthNode(node) {
        if (node) node.replaceChildren();
    }

    function authTextElement(tag, text, className) {
        const element = document.createElement(tag);
        if (className) element.className = className;
        element.textContent = String(text);
        return element;
    }

    function renderResendButton(container) {
        clearAuthNode(container);
        const button = authTextElement('button', t('js.resend_button'), 'btn btn-resend');
        button.type = 'button';
        button.addEventListener('click', () => requestResendVerification(button));
        container.appendChild(button);
    }

    function renderResendCountdown(container, remaining) {
        clearAuthNode(container);
        const label = authTextElement('span', t('js.resend_available_in') + ' ');
        label.style.color = 'var(--text-muted)';
        label.style.fontSize = '0.9em';
        const countdown = authTextElement('span', formatDuration(remaining), 'mono');
        countdown.id = 'resendCountdown';
        label.appendChild(countdown);
        container.appendChild(label);
    }

    function bindAuthEvents() {
        const modal = document.getElementById('authModal');
        if (!modal || modal.dataset.authBound === '1') return;
        modal.dataset.authBound = '1';
        modal.addEventListener('click', event => {
            const trigger = event.target.closest('[data-auth-action], [data-auth-tab]');
            if (!trigger || !modal.contains(trigger)) return;
            event.preventDefault();
            if (trigger.dataset.authTab) {
                showAuthTab(trigger.dataset.authTab);
                return;
            }
            switch (trigger.dataset.authAction) {
                case 'close': hideAuthModal(); break;
                case 'recovery': showRecoveryForm(); break;
                case 'cancel-2fa': cancel2faLogin(); break;
                case 'logout': handleLogout(); break;
            }
        });
        const submitHandlers = {
            loginForm: handleLogin,
            twofaForm: handle2faLogin,
            registerForm: handleRegister,
            recoveryForm: handleRecovery,
            resetForm: handleResetPassword
        };
        Object.keys(submitHandlers).forEach(id => {
            const form = document.getElementById(id);
            if (form) form.addEventListener('submit', submitHandlers[id]);
        });
    }

    function showAuthModal() {
        document.getElementById('authModal').classList.add('show');
        updateAuthUI();
        if (currentUser) {
            fetchUserStats();
        }
        // Ensure config is loaded (if not already)
        if (!appConfig.account_loaded) {
            fetchConfig();
        }
    }

    function hideAuthModal() {
        document.getElementById('authModal').classList.remove('show');
        clearAuthMessage();
    }

    function clearAuthMessage() {
        const msg = document.getElementById('authMessage');
        if (msg) {
            msg.style.display = 'none';
            msg.textContent = '';
            msg.className = 'auth-message';
        }
    }

    function showAuthTab(tab) {
        document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));

        if (tab === 'login') {
            document.querySelectorAll('.auth-tab')[0].classList.add('active');
            document.getElementById('loginForm').classList.add('active');

            // Check if login captcha needed based on config (initial check)
            if (appConfig.recaptcha_enabled && appConfig.recaptcha_on_login && appConfig.login_captcha_required) {
                initLoginCaptcha();
            }
        } else {
            if (!appConfig.registration_enabled) {
                showAuthMessage(t('js.reg_disabled'), 'error');
                return;
            }
            document.querySelectorAll('.auth-tab')[1].classList.add('active');
            document.getElementById('registerForm').classList.add('active');

            // Check if registration captcha needed
            if (appConfig.recaptcha_register_always && appConfig.recaptcha_enabled) {
                initRegisterCaptcha();
            }
        }
    }

    function showRecoveryForm() {
        document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));
        const form = document.getElementById('recoveryForm');
        if (form) form.classList.add('active');
        clearAuthMessage();
    }

    let configLoading = false;
    async function fetchConfig() {
        if (appConfig.account_loaded || configLoading) return;
        configLoading = true;
        try {
            const conf = await FHApi.get('config');
            appConfig = { ...appConfig, ...conf, account_loaded: true };
			applyConfiguredInputLimits();

            // Update UI based on config
            const regTab = document.querySelectorAll('.auth-tab')[1];
            if (regTab && !appConfig.registration_enabled) {
                regTab.style.display = 'none';
            }

            // Re-eval captcha requirements after config load
            if (appConfig.recaptcha_register_always && appConfig.recaptcha_enabled) {
                // If we are currently on register tab or just init it anyway (it's hidden by CSS if tab not active? No, container is inside form)
                // Safest is to just init if enabled, it won't render if widget usage is explicit/lazy, 
                // but here we want to ensure container is visible if needed.
                // Actually initRegisterCaptchachecks display none? No it sets it.
                // We should only init if the form is active OR just pre-init.
                // Let's check if register form is active.
                if (document.getElementById('registerForm').classList.contains('active')) {
                    initRegisterCaptcha();
                }
            }

            if (appConfig.recaptcha_enabled && appConfig.recaptcha_on_login && appConfig.login_captcha_required) {
                if (document.getElementById('loginForm').classList.contains('active')) {
                    initLoginCaptcha();
                }
            }
        } catch (e) {
            console.error('Failed to load config', e);
        } finally {
            configLoading = false;
        }
    }

    function updateAuthUI() {
        const guest = document.getElementById('authGuest');
        const user = document.getElementById('authUser');

        if (currentUser) {
            if (guest) guest.style.display = 'none';
            if (user) user.style.display = 'block';

            const initialEl = document.getElementById('userInitial');
            if (initialEl) initialEl.textContent = currentUser.username.charAt(0).toUpperCase();

            const nameEl = document.getElementById('userName');
            if (nameEl) nameEl.textContent = currentUser.username;

            const roleEl = document.getElementById('userRole');
            if (roleEl) roleEl.textContent = currentUser.is_admin ? t('auth.role_admin') : t('auth.role_user');

            const adminLink = document.getElementById('adminLink');
            if (adminLink) adminLink.style.display = currentUser.is_admin ? 'flex' : 'none';

            const loginPrompt = document.getElementById('loginPrompt');
            if (loginPrompt) loginPrompt.style.display = 'none';
        } else {
            if (guest) guest.style.display = 'block';
            if (user) user.style.display = 'none';

            const loginPrompt = document.getElementById('loginPrompt');
            if (loginPrompt) loginPrompt.style.display = 'block';
        }

        // Update upload limits if function exists (index.php)
        if (typeof updateLimits === 'function') {
            updateLimits(currentUser);
        }
    }

    async function fetchUserStats() {
        const statsRow = document.getElementById('userStats');
        if (!statsRow) return;

        try {
            const data = await FHApi.get('user_stats');
            if (data.success && data.stats) {
                if (document.getElementById('statFiles')) document.getElementById('statFiles').textContent = data.stats.files_count;
                if (document.getElementById('statSize')) document.getElementById('statSize').textContent = formatBytes(data.stats.total_size);
                if (document.getElementById('statDownloads')) document.getElementById('statDownloads').textContent = data.stats.total_downloads;
                statsRow.style.display = '';
            }
        } catch (e) {
            console.error('Error fetching stats:', e);
        }
    }

    function formatBytes(bytes, decimals = 2) {
        if (!+bytes) return '0 B';
        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return `${parseFloat((bytes / Math.pow(k, i)).toFixed(dm))} ${sizes[i]}`;
    }

    let loginCaptchaWidgetId = null;
    let registerCaptchaWidgetId = null;

    async function handleLogin(e) {
        e.preventDefault();
        const form = e.target;
        const btn = document.getElementById('loginBtn');
        const username = form.username.value;
        const password = form.password.value;
        const originalText = btn.textContent;

        // Check if captcha is visible and get response
        let captchaResponse = '';
        if (loginCaptchaWidgetId !== null && document.getElementById('recaptchaContainer').style.display !== 'none') {
            captchaResponse = grecaptcha.getResponse(loginCaptchaWidgetId);
        }

        btn.disabled = true;
        btn.textContent = t('js.logging_in');

        try {
            // How long the sign-in should survive the browser closing. Absent (the setting is
            // off) or unparsable means this browser session only, which is the old behaviour.
            const rememberField = document.getElementById('loginRemember');
            const remember = Number.parseInt(rememberField?.value ?? '0', 10);

            const data = await FHApi.post('user_login', {
                username,
                password,
                remember: Number.isFinite(remember) ? remember : 0,
                captcha_response: captchaResponse,
                upload_token: sessionStorage.getItem('uploadToken') || ''
            });

            // 2FA: the password checked out but the account needs a code — swap to step two.
            // No session is granted until handle2faLogin() succeeds.
            if (data.require_2fa) {
                show2faLoginForm();
                return;
            }

            if (data.success) {
                currentUser = data.user;
                updateAuthUI();
                showAuthMessage(t('auth.login_success'), 'success');

                // Fetch stats immediately to populate the view
                fetchUserStats();

                // User requested to keep the modal open instead of reloading
                // setTimeout(() => {
                //     hideAuthModal();
                //     window.location.reload();
                // }, 1000);
            } else {
                showAuthMessage(data.error || t('auth.login_error'), 'error');

                if (data.require_captcha) {
                    appConfig.login_captcha_required = true;
                    initLoginCaptcha();
                }

                // If inactive, show Resend option (ID is in session)
                if (data.inactive) {
                    const msgDiv = document.getElementById('authMessage');
                    const resendDiv = document.createElement('div');
                    resendDiv.className = 'resend-activation-container';

                    const now = data.server_time || Math.floor(Date.now() / 1000);
                    const last = data.last_activation_sent || 0;
                    const cd = (data.cooldown_minutes || 30) * 60;
                    const diff = now - last;
                    const remaining = cd - diff;

                    if (remaining > 0) {
                        renderResendCountdown(resendDiv, remaining);
                        startResendCountdown(remaining, resendDiv);
                    } else {
                        renderResendButton(resendDiv);
                    }
                    msgDiv.appendChild(resendDiv);
                }
            }

            // Reset captcha if it was used and failed
            if (loginCaptchaWidgetId !== null) {
                grecaptcha.reset(loginCaptchaWidgetId);
            }
        } catch (err) {
            showAuthMessage(t('common.connection_error'), 'error');
            console.error(err);
        } finally {
            btn.disabled = false;
            btn.textContent = originalText;
        }
    }

    /* ---- 2FA second login step (Faza 4.4) ---- */

    function show2faLoginForm() {
        document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));
        const form = document.getElementById('twofaForm');
        if (form) form.classList.add('active');
        const msg = document.getElementById('authMessage');
        if (msg) { msg.textContent = ''; msg.className = 'auth-message'; }
        const input = document.getElementById('twofaLoginCode');
        if (input) { input.value = ''; setTimeout(() => input.focus(), 80); }
    }

    function cancel2faLogin() {
        document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));
        const login = document.getElementById('loginForm');
        if (login) login.classList.add('active');
        const msg = document.getElementById('authMessage');
        if (msg) { msg.textContent = ''; msg.className = 'auth-message'; }
    }

    async function handle2faLogin(e) {
        e.preventDefault();
        const btn = document.getElementById('twofaLoginBtn');
        const originalText = btn.textContent;
        btn.disabled = true;
        btn.textContent = t('auth.verifying');

        try {
            const data = await FHApi.post('user_2fa_login', { code: document.getElementById('twofaLoginCode').value.trim() });

            if (data.success) {
                currentUser = data.user;
                updateAuthUI();
                cancel2faLogin(); // put the login form back for next time
                showAuthMessage(t('auth.login_success'), 'success');
                fetchUserStats();
            } else {
                // require_2fa still set = wrong code, stay here; otherwise the parked login
                // expired, so fall back to the password form.
                if (!data.require_2fa) cancel2faLogin();
                showAuthMessage(data.error || t('auth.2fa_bad_code'), 'error');
                const input = document.getElementById('twofaLoginCode');
                if (input) { input.value = ''; input.focus(); }
            }
        } catch (err) {
            showAuthMessage(t('common.connection_error'), 'error');
            console.error(err);
        } finally {
            btn.disabled = false;
            btn.textContent = originalText;
        }
    }

    function requestResendVerification(btn) {
        if (!btn) return;
        const container = btn.closest('.resend-activation-container');
        if (!container) return;

        clearAuthNode(container);
        const prompt = authTextElement(
            'div',
            'Czy na pewno wysłać ponownie?',
            'resend-confirm-prompt'
        );
        const actions = document.createElement('div');
        actions.className = 'resend-confirm-actions';
        const confirm = authTextElement('button', 'Tak', 'btn btn-sm btn-primary');
        confirm.type = 'button';
        confirm.addEventListener('click', () => executeResendVerification(container));
        const cancel = authTextElement('button', 'Nie', 'btn btn-sm');
        cancel.type = 'button';
        cancel.addEventListener('click', () => cancelResendVerification(cancel));
        actions.append(confirm, cancel);
        container.append(prompt, actions);
    }

	function applyConfiguredInputLimits() {
		const limits = appConfig.input_limits || {};
		const assignments = [
			['regUsername', 'minLength', limits.username_min],
			['regUsername', 'maxLength', limits.username_max],
			['regEmail', 'maxLength', limits.email_max],
			['regPassword', 'minLength', limits.password_min],
			['regPassword', 'maxLength', limits.password_max],
			['regPassword2', 'maxLength', limits.password_max],
			['resetPass', 'minLength', limits.password_min],
			['resetPass', 'maxLength', limits.password_max],
			['resetPass2', 'maxLength', limits.password_max],
			['recoveryInput', 'maxLength', Math.max(Number(limits.username_max) || 0, Number(limits.email_max) || 0)]
		];
		assignments.forEach(([id, property, value]) => {
			const input = document.getElementById(id);
			if (input && Number(value) > 0) input[property] = Number(value);
		});
		const requirement = document.getElementById('reqLen');
		if (requirement) requirement.textContent = t('pwd.req_len_configured', { min: limits.password_min || 8 });
	}

    function cancelResendVerification(btn) {
        const container = btn.closest('.resend-activation-container');
        if (!container) return;

        renderResendButton(container);
    }

    async function executeResendVerification(container) {
        clearAuthNode(container);
        container.appendChild(authTextElement('div', 'Wysyłanie...', 'mono resend-status'));

        try {
            const data = await FHApi.post('resend_verification', {});
            if (data.success) {
                showAuthMessage(data.message, 'success');
                // Start cooldown manually or just hide
                clearAuthNode(container);
                container.appendChild(
                    authTextElement('div', 'Wysłano! Sprawdź email.', 'resend-status success')
                );
            } else {
                showAuthMessage(data.error || t('js.generic_error'), 'error');
                // Restore button after delay
                setTimeout(() => {
                    renderResendButton(container);
                }, 2000);
            }
        } catch (e) {
            showAuthMessage(t('common.connection_error'), 'error');
            setTimeout(() => {
                renderResendButton(container);
            }, 2000);
        }
    }

    function initLoginCaptcha() {
        if (!appConfig.recaptcha_enabled || !appConfig.recaptcha_on_login || !appConfig.login_captcha_required) {
            return;
        }

        const container = document.getElementById('recaptchaContainer');
        if (!container) return;

        container.style.display = 'flex';

        // Load script if needed
        if (typeof grecaptcha === 'undefined') {
            const script = document.createElement('script');
            script.src = 'https://www.google.com/recaptcha/api.js?onload=onLoginCaptchaReady&render=explicit';
            script.async = true;
            script.defer = true;
            document.head.appendChild(script);
        } else if (loginCaptchaWidgetId === null) {
            renderLoginCaptcha();
        }
    }

    function onLoginCaptchaReady() {
        renderLoginCaptcha();
    }

    function renderLoginCaptcha() {
        // Need site key. We can fetch it or inject it.
        // Let's fetch config first if we don't have it.
        FHApi.get('captcha_config')
            .then(config => {
                if (config.recaptcha_enabled && config.recaptcha_site_key) {
                    if (loginCaptchaWidgetId === null && document.getElementById('authCaptchaWidget')) {
                        loginCaptchaWidgetId = grecaptcha.render('authCaptchaWidget', {
                            'sitekey': config.recaptcha_site_key,
                            'theme': 'dark'
                        });
                    }
                }
            });
    }

    function initRegisterCaptcha() {
        const container = document.getElementById('recaptchaRegisterContainer');
        if (!container) return;

        container.style.display = 'flex';

        // Load script if needed
        if (typeof grecaptcha === 'undefined') {
            const script = document.createElement('script');
            script.src = 'https://www.google.com/recaptcha/api.js?onload=onRegisterCaptchaReady&render=explicit';
            script.async = true;
            script.defer = true;
            document.head.appendChild(script);
        } else if (registerCaptchaWidgetId === null) {
            renderRegisterCaptcha();
        }
    }

    function onRegisterCaptchaReady() {
        renderRegisterCaptcha();
    }

    function renderRegisterCaptcha() {
        FHApi.get('captcha_config')
            .then(config => {
                if (config.recaptcha_enabled && config.recaptcha_site_key) {
                    if (registerCaptchaWidgetId === null && document.getElementById('authCaptchaRegisterWidget')) {
                        registerCaptchaWidgetId = grecaptcha.render('authCaptchaRegisterWidget', {
                            'sitekey': config.recaptcha_site_key,
                            'theme': 'dark'
                        });
                    }
                }
            });
    }

    async function handleRegister(e) {
        e.preventDefault();
        const form = e.target;
        const btn = document.getElementById('registerBtn');
        const username = form.username.value;
        const email = form.email.value;
        const password = form.password.value;
        const originalText = btn.textContent;

        btn.disabled = true;
        btn.textContent = t('js.registering');

        try {
            const data = await FHApi.post('user_register', {
                username,
                email,
                password,
                captcha_response: (registerCaptchaWidgetId !== null) ? grecaptcha.getResponse(registerCaptchaWidgetId) : ''
            });

            if (data.success) {
                // Check if activation required
                if (data.activation_required) {
                    // Custom messages based on context (assuming backend sends 'activation_mode' or we determine from message)
                    // But simplest is to trust the message from backend or generic one
                    // Update: User requested specific messages. We should get mode from backend or infer.
                    // For now, let's look at data.message provided by backend registerUser.

                    showAuthMessage(data.message, 'success');
                } else {
                    showAuthMessage(t('js.account_created'), 'success');
                    setTimeout(() => showAuthTab('login'), 1500);
                }
            } else {
                showAuthMessage(data.error || t('js.generic_error'), 'error');
            }
        } catch (err) {
            showAuthMessage(t('common.connection_error'), 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = originalText;
        }
    }

    async function handleRecovery(e) {
        e.preventDefault();
        const form = e.target;
        const btn = document.getElementById('recoveryBtn');
        const input = form.input.value;

        btn.disabled = true;
        btn.textContent = t('js.sending');

        try {
            const data = await FHApi.post('recover_password', { input });

            if (data.success) {
                // Show attempts remaining if provided
                let msg = data.message || t('js.recovery_sent');
                if (data.remaining_attempts !== undefined) {
                    msg += ` — Pozostało prób: ${Number(data.remaining_attempts) || 0}`;
                }
                showAuthMessage(msg, 'success');
                form.reset();
            } else {
                let errorMsg = data.error || t('js.generic_error');
                if (data.remaining_attempts !== undefined) {
                    errorMsg += ` — Pozostało prób: ${Number(data.remaining_attempts) || 0}`;
                }
                showAuthMessage(errorMsg, 'error');
            }
        } catch (err) {
            showAuthMessage(t('common.connection_error'), 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = t('auth.recovery_btn'); // Reset text
        }
    }

    async function handleLogout() {
        // Show immediate feedback
        showAuthMessage(t('js.logging_out'), 'success');

        try {
            await FHApi.post('user_logout', {});
            currentUser = null;
            updateAuthUI();

            showAuthMessage(t('js.logged_out'), 'success');

            // Redirect only if on panel.php (protected page)
            if (window.location.pathname.includes('panel.php')) {
                setTimeout(() => {
                    const baseUrl = authApiUrl.replace('/api.php', '');
                    window.location.href = baseUrl || './';
                }, 1000);
            }
            // For other pages: Stay on page (modal switches to login form via updateAuthUI)

        } catch (e) {
            console.error('Logout error', e);
            if (window.location.pathname.includes('panel.php')) {
                const baseUrl = authApiUrl.replace('/api.php', '');
                window.location.href = baseUrl || './';
            } else {
                currentUser = null;
                updateAuthUI();
            }
        }
    }

    function formatDuration(seconds) {
        if (seconds < 60) return seconds + 's';
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        return m + 'm ' + s + 's';
    }

    function startResendCountdown(seconds, container) {
        let left = seconds;
        const timer = setInterval(() => {
            left--;
            const el = document.getElementById('resendCountdown');
            if (el) el.textContent = formatDuration(left);

            if (left <= 0) {
                clearInterval(timer);
                renderResendButton(container);
            }
        }, 1000);
    }

    function showAuthMessage(text, type) {
        const msg = document.getElementById('authMessage');
        if (!msg) return;
        msg.textContent = String(text);
        msg.className = 'auth-message show ' + type;
        msg.style.display = 'block';
    }

    // Theme toggle helper (if not already defined)
    if (typeof window.toggleTheme !== 'function') {
        window.toggleTheme = function () {
            document.body.classList.toggle('light');
            const isLight = document.body.classList.contains('light');
            localStorage.setItem('theme', isLight ? 'light' : 'dark');
            document.cookie = "theme=" + (isLight ? 'light' : 'dark') + "; path=/; max-age=31536000";

            // Try to update icons if they exist
            updateThemeIcons();
        };
    }

    function updateThemeIcons() {
        const suns = document.querySelectorAll('.sun');
        const moons = document.querySelectorAll('.moon');
        const isLight = document.body.classList.contains('light');

        suns.forEach(el => el.style.display = isLight ? 'none' : 'block');
        moons.forEach(el => el.style.display = isLight ? 'block' : 'none');
    }

    // Init theme
    if (localStorage.getItem('theme') === 'light' && !document.body.classList.contains('light')) {
        document.body.classList.add('light');
    }
    // Auth Validation Logic
    function initAuthValidation() {
        const regUsername = document.getElementById('regUsername');
        const regEmail = document.getElementById('regEmail');
        const regPassword = document.getElementById('regPassword');
        const regPassword2 = document.getElementById('regPassword2');

        if (!regUsername || !regEmail || !regPassword || !regPassword2) return;

        let usernameTimer;
        regUsername.addEventListener('input', function () {
            clearTimeout(usernameTimer);
            const status = document.getElementById('usernameStatus');
            const val = this.value.trim();

			const usernameMin = Number(regUsername.minLength) || 3;
			if (val.length < usernameMin) {
				status.textContent = val.length > 0 ? t('js.name_min_configured', { min: usernameMin }) : '';
                status.className = 'field-status' + (val.length > 0 ? ' status-bad show' : '');
                return;
            }

            status.textContent = t('js.checking');
            status.className = 'field-status status-load show';

            usernameTimer = setTimeout(async () => {
                try {
                    const data = await FHApi.get('check_username', { username: val });

                    if (data.available) {
                        status.textContent = t('js.name_free');
                        status.className = 'field-status status-ok show';
                        regUsername.classList.remove('error');
                        regUsername.classList.add('success');
                    } else {
                        status.textContent = t('js.name_taken');
                        status.className = 'field-status status-bad show';
                        regUsername.classList.remove('success');
                        regUsername.classList.add('error');
                    }
                } catch (e) {
                    status.textContent = '';
                    status.className = 'field-status';
                }
            }, 500);
        });

        let emailTimer;
        regEmail.addEventListener('input', function () {
            clearTimeout(emailTimer);
            const status = document.getElementById('emailStatus');
            const val = this.value.trim();

            if (!val) {
                status.className = 'field-status';
                regEmail.classList.remove('error', 'success');
                return;
            }

            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(val)) {
                status.textContent = t('js.bad_format');
                status.className = 'field-status status-bad show';
                regEmail.classList.remove('success');
                regEmail.classList.add('error');
                return;
            }

            status.textContent = t('js.checking');
            status.className = 'field-status status-load show';

            emailTimer = setTimeout(async () => {
                try {
                    const data = await FHApi.get('check_email', { email: val });

                    if (data.available) {
                        status.textContent = t('js.email_free');
                        status.className = 'field-status status-ok show';
                        regEmail.classList.remove('error');
                        regEmail.classList.add('success');
                    } else {
                        status.textContent = t('js.email_taken');
                        status.className = 'field-status status-bad show';
                        regEmail.classList.remove('success');
                        regEmail.classList.add('error');
                    }
                } catch (e) {
                    // Network/parse error: we genuinely don't know if the email is
                    // free. Show a neutral notice — never a green "available".
                    status.textContent = t('js.check_failed');
                    status.className = 'field-status status-load show';
                    regEmail.classList.remove('error', 'success');
                }
            }, 500);
        });

        regPassword.addEventListener('input', function () {
            const val = this.value;
            const bar = document.getElementById('pwdBar');
            let score = 0;
            let max = 4;

            const lenEl = document.getElementById('reqLen');
            if (lenEl) {
				if (val.length >= (Number(regPassword.minLength) || 8)) {
                    score++;
                    lenEl.classList.add('valid');
                } else {
                    lenEl.classList.remove('valid');
                }
            }

            const upperEl = document.getElementById('reqUpper');
            if (upperEl) {
                if (/[A-Z]/.test(val)) {
                    score++;
                    upperEl.classList.add('valid');
                } else {
                    upperEl.classList.remove('valid');
                }
            }

            const digitEl = document.getElementById('reqDigit');
            if (digitEl) {
                if (/\d/.test(val)) {
                    score++;
                    digitEl.classList.add('valid');
                } else {
                    digitEl.classList.remove('valid');
                }
            }

            const specEl = document.getElementById('reqSpec');
            if (specEl) {
                if (/[^a-zA-Z0-9]/.test(val)) {
                    score++;
                    specEl.classList.add('valid');
                } else {
                    specEl.classList.remove('valid');
                }
            }

            const pct = (score / max) * 100;
            if (bar) {
                bar.style.width = pct + '%';
                if (pct < 25) {
                    bar.style.backgroundColor = 'var(--danger)';
                } else if (pct < 50) {
                    bar.style.backgroundColor = 'var(--warning)';
                } else if (pct < 75) {
                    bar.style.backgroundColor = '#f59e0b';
                } else {
                    bar.style.backgroundColor = 'var(--success)';
                }
            }

            checkPasswordMatch();
        });

        regPassword2.addEventListener('input', checkPasswordMatch);
    }

    let passMatchTimer;
    function checkPasswordMatch() {
        clearTimeout(passMatchTimer);
        const status = document.getElementById('passMatchStatus');
        const regPassword = document.getElementById('regPassword');
        const regPassword2 = document.getElementById('regPassword2');

        if (!regPassword || !regPassword2) return;

        const p1 = regPassword.value;
        const p2 = regPassword2.value;

        if (!p2) {
            status.className = 'field-status';
            regPassword2.classList.remove('error', 'success');
            return;
        }

        passMatchTimer = setTimeout(() => {
            if (p1 === p2) {
                status.textContent = t('pwd.match_ok');
                status.className = 'field-status status-ok show';
                regPassword2.classList.remove('error');
                regPassword2.classList.add('success');
            } else {
                status.textContent = t('pwd.match_bad');
                status.className = 'field-status status-bad show';
                regPassword2.classList.remove('success');
                regPassword2.classList.add('error');
            }
        }, 300);
    }

    function resetPasswordMeter() {
        const bar = document.getElementById('pwdBar');
        if (bar) bar.style.width = '0%';

        document.querySelectorAll('.pwd-reqs li').forEach(li => li.classList.remove('valid'));

        const passStatus = document.getElementById('passMatchStatus');
        if (passStatus) passStatus.className = 'field-status';

        const userStatus = document.getElementById('usernameStatus');
        if (userStatus) userStatus.className = 'field-status';

        const emailStatus = document.getElementById('emailStatus');
        if (emailStatus) emailStatus.className = 'field-status';

        const regUsername = document.getElementById('regUsername');
        if (regUsername) regUsername.classList.remove('error', 'success');

        const regEmail = document.getElementById('regEmail');
        if (regEmail) regEmail.classList.remove('error', 'success');

        const regPassword2 = document.getElementById('regPassword2');
        if (regPassword2) regPassword2.classList.remove('error', 'success');
    }

    function initAuthScripts() {

        updateThemeIcons();
        initAuthValidation();
        fetchConfig(); // Load config immediately

        // Modal closing listeners
        const modal = document.getElementById('authModal');
        if (modal) {
            let downTarget = null;
            modal.addEventListener('mousedown', function (e) {
                downTarget = e.target;
            });
            modal.addEventListener('mouseup', function (e) {
                if (e.target === modal && downTarget === modal) {
                    hideAuthModal();
                }
                downTarget = null;
            });
            // Close on Escape (matches click-outside behaviour).
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && modal.classList.contains('show')) {
                    hideAuthModal();
                }
            });
        }

        // Check for URL params to auto-open modal
        const urlParams = new URLSearchParams(window.location.search);
        const action = urlParams.get('action');



        if (action === 'login') {
            showAuthModal();
            showAuthTab('login');
            // Clean URL
            const url = new URL(window.location);
            url.searchParams.delete('action');
            window.history.replaceState({}, '', url);
        } else if (action === 'register') {
            showAuthModal();
            showAuthTab('register');
            // Clean URL
            const url = new URL(window.location);
            url.searchParams.delete('action');
            window.history.replaceState({}, '', url);
        } else if (action === 'reset') {
            const token = urlParams.get('token');

            if (token) {
                // Ensure auth scripts are ready
                if (typeof showAuthModal === 'function') {
                    showAuthModal();

                    // Delay slightly to ensure modal is rendered and scripts are ready
                    setTimeout(() => {
                        document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
                        document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));

                        // Activate reset form
                        const form = document.getElementById('resetForm');
                        if (form) {
                            form.classList.add('active');

                        } else {
                            console.error('Reset form not found');
                        }

                        const tokenInput = document.getElementById('resetToken');
                        if (tokenInput) tokenInput.value = token;

                        // Clean URL - keep token for refresh safety? No, better clean.
                        // But wait until user sees it? No, clean immediately to avoid sharing link.
                        const url = new URL(window.location);
                        url.searchParams.delete('action');
                        url.searchParams.delete('token');
                        window.history.replaceState({}, '', url);
                    }, 100);
                } else {
                    console.error('showAuthModal not defined');
                }
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAuthScripts);
    } else {
        initAuthScripts();
    }

    async function handleResetPassword(e) {
        e.preventDefault();
        const form = e.target;
        const btn = document.getElementById('resetBtn');
        const token = form.token.value;
        const password = form.password.value;
        const password_confirm = form.password_confirm.value;
        const originalText = btn.textContent;

        if (password !== password_confirm) {
            showAuthMessage(t('pwd.match_bad'), 'error');
            return;
        }

        btn.disabled = true;
        btn.textContent = t('js.sending');

        try {
            const data = await FHApi.post('reset_password_submit', { token, password });

            if (data.success) {
                showAuthMessage(t('js.password_changed_login'), 'success');
                setTimeout(() => showAuthTab('login'), 2000);
                form.reset();
            } else {
                showAuthMessage(data.error || t('api.pass_change_error'), 'error');
            }
        } catch (err) {
            showAuthMessage(t('common.connection_error'), 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = originalText;
        }
    }

    /* ------------------------------------------------------------------ *
     * A11y (U3): focus-trap + ARIA for every overlay on the public pages
     * (account modal, captcha, QR, per-file password; download password
     * gate, captcha, report). They all share the `.show` convention, so a
     * MutationObserver traps/releases generically — no need to touch each
     * open/close path. Loaded only on index.php / download.php (the panel
     * has its own trap in panel.js), so there is no double-trapping.
     * ------------------------------------------------------------------ */
    (function () {
        var OVERLAY_SEL = '.auth-modal, .captcha-overlay, .captcha-modal';
        var trap = { el: null, prev: null, handler: null };

        function focusables(c) {
            return Array.prototype.slice.call(c.querySelectorAll(
                'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
            )).filter(function (el) { return el.offsetWidth > 0 || el.offsetHeight > 0 || el === document.activeElement; });
        }

        function release(restore) {
            if (trap.el && trap.handler) trap.el.removeEventListener('keydown', trap.handler);
            if (restore && trap.prev && typeof trap.prev.focus === 'function') { try { trap.prev.focus(); } catch (e) { } }
            trap = { el: null, prev: null, handler: null };
        }

        function engage(el) {
            if (trap.el === el) return;
            release(false);
            trap.el = el;
            trap.prev = document.activeElement;
            var f = focusables(el);
            var target = f.filter(function (x) { return /^(INPUT|SELECT|TEXTAREA)$/.test(x.tagName); })[0] || f[0];
            if (target) setTimeout(function () { try { target.focus(); } catch (e) { } }, 60);
            trap.handler = function (e) {
                if (e.key !== 'Tab') return;
                var ff = focusables(el);
                if (!ff.length) return;
                var first = ff[0], last = ff[ff.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            };
            el.addEventListener('keydown', trap.handler);
        }

        function scan() {
            var open = null;
            document.querySelectorAll(OVERLAY_SEL).forEach(function (el) {
                if (el.classList.contains('show')) open = el; // last (topmost in DOM) wins
            });
            if (open) engage(open);
            else if (trap.el) release(true);
        }

        function initA11y() {
            document.querySelectorAll(OVERLAY_SEL).forEach(function (modal) {
                modal.setAttribute('role', 'dialog');
                modal.setAttribute('aria-modal', 'true');
                var h = modal.querySelector('h1, h2, h3, .captcha-title');
                if (h) {
                    if (!h.id) h.id = 'mh_' + Math.random().toString(36).slice(2, 9);
                    modal.setAttribute('aria-labelledby', h.id);
                }
            });
            document.querySelectorAll('button[title], a[title]').forEach(function (el) {
                if (!el.getAttribute('aria-label') && !el.textContent.trim()) el.setAttribute('aria-label', el.getAttribute('title'));
            });
            document.querySelectorAll('.auth-close, .modal-close, .captcha-close').forEach(function (el) {
                if (!el.getAttribute('aria-label') && !el.textContent.trim()) el.setAttribute('aria-label', t('js.close'));
            });
        }

        function start() {
            bindAuthEvents();
            initA11y();
            var overlays = document.querySelectorAll(OVERLAY_SEL);
            if (window.MutationObserver && overlays.length) {
                var obs = new MutationObserver(function (muts) {
                    for (var i = 0; i < muts.length; i++) {
                        if (muts[i].attributeName === 'class') { scan(); return; }
                    }
                });
                overlays.forEach(function (el) { obs.observe(el, { attributes: true, attributeFilter: ['class'] }); });
            }
            scan(); // catch overlays server-rendered open (e.g. download password gate)
        }

        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
        else start();
    })();
