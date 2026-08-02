(function () {
    'use strict';

    const bootstrap = document.getElementById('downloadBootstrap');
    if (!bootstrap) return;

    let config = {};
    try {
        config = JSON.parse(bootstrap.dataset.config || '{}');
    } catch (_error) {
        config = {};
    }

    const byId = (id) => document.getElementById(id);
    const showToast = (message) => window.FHUi.toast(String(message || ''));
    let reportCaptchaRequired = Boolean(config.reportCaptchaRequired);
    let captchaVerified = false;
    let captchaWidgetId = null;
    let reportWidgetId = null;
    let pendingAction = null;
    let currentCaptchaProof = '';
    let filePassword = '';
    let passwordPromptMode = 'download';

    function startParallax() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        let mouseX = 0;
        let mouseY = 0;
        let currentX = 0;
        let currentY = 0;
        const orbs = [
            { element: byId('orb1'), speed: 0.02 },
            { element: byId('orb2'), speed: 0.015 },
        ];

        document.addEventListener('mousemove', (event) => {
            mouseX = (event.clientX / window.innerWidth - 0.5) * 2;
            mouseY = (event.clientY / window.innerHeight - 0.5) * 2;
        });

        function animate() {
            currentX += (mouseX - currentX) * 0.05;
            currentY += (mouseY - currentY) * 0.05;
            orbs.forEach(({ element, speed }) => {
                if (element) {
                    element.style.transform = `translate(${currentX * 1000 * speed}px, ${currentY * 1000 * speed}px)`;
                }
            });
            window.requestAnimationFrame(animate);
        }
        animate();
    }

    async function copyLink() {
        try {
            await navigator.clipboard.writeText(window.location.href);
            const label = byId('copyText');
            if (!label) return;
            label.textContent = window.t('common.copied');
            window.setTimeout(() => {
                label.textContent = window.t('download.copy_link');
            }, 1500);
        } catch (_error) {
            showToast(window.t('download.copy_failed'));
        }
    }

    function initializeEmbedCodes() {
        const codes = config.embedCodes || {};
        const targets = {
            direct: 'embDirect',
            bbcode: 'embBb',
            html: 'embHtml',
            markdown: 'embMd',
        };
        Object.entries(targets).forEach(([key, id]) => {
            const element = byId(id);
            if (element) element.textContent = codes[key] || '';
        });
    }

    function toggleEmbed() {
        const section = byId('embedSection');
        const button = byId('embedToggleBtn');
        if (!section) return;
        const willOpen = section.hidden;
        section.hidden = !willOpen;
        if (button) button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    }

    async function copyEmbed(button, key) {
        try {
            await navigator.clipboard.writeText((config.embedCodes || {})[key] || '');
            const previous = button.textContent;
            button.replaceChildren();
            const icon = document.createElement('i');
            icon.className = 'fa-solid fa-check';
            button.append(icon, document.createTextNode(` ${window.t('common.copied')}`));
            button.classList.add('copied');
            window.setTimeout(() => {
                button.textContent = previous;
                button.classList.remove('copied');
            }, 1500);
        } catch (_error) {
            showToast(window.t('download.copy_failed'));
        }
    }

    function hideCaptchaModal() {
        byId('captchaModal')?.classList.remove('show');
    }

    function onCaptchaError() {
        const error = byId('captchaError');
        if (error) error.style.display = 'block';
        if (window.grecaptcha && captchaWidgetId !== null) {
            window.grecaptcha.reset(captchaWidgetId);
        }
        if (window.grecaptcha && reportWidgetId !== null) {
            window.grecaptcha.reset(reportWidgetId);
        }
    }

    async function onCaptchaSuccess(response) {
        const error = byId('captchaError');
        if (error) error.style.display = 'none';
        try {
            const result = await window.FHApi.post('verify_captcha', {
                captcha_response: response,
            });
            if (!result.success) {
                onCaptchaError();
                return;
            }

            captchaVerified = true;
            if (result.token) currentCaptchaProof = result.token;
            const globalModal = byId('captchaModal');
            if (globalModal?.classList.contains('show')) {
                hideCaptchaModal();
                if (pendingAction === 'download') {
                    doDownload();
                } else if (pendingAction === 'delete') {
                    doDelete();
                }
            }

            const reportContainer = byId('reportCaptchaContainer');
            if (reportContainer?.querySelector('iframe')) {
                reportContainer.style.display = 'none';
            }
        } catch (_error) {
            onCaptchaError();
        }
    }

    function renderGlobalCaptcha() {
        if (!window.grecaptcha || !config.captchaSiteKey) return;
        const loading = byId('captchaLoading');
        if (loading) loading.style.display = 'none';
        if (captchaWidgetId === null) {
            captchaWidgetId = window.grecaptcha.render('captchaWidget', {
                sitekey: config.captchaSiteKey,
                theme: 'dark',
                callback: onCaptchaSuccess,
                'error-callback': onCaptchaError,
            });
        } else {
            window.grecaptcha.reset(captchaWidgetId);
        }
    }

    function renderReportCaptcha() {
        const container = byId('reportCaptchaContainer');
        if (!container || !reportCaptchaRequired || !config.captchaSiteKey) return;
        container.style.display = 'block';
        if (!window.grecaptcha) return;
        if (reportWidgetId === null) {
            reportWidgetId = window.grecaptcha.render('reportCaptchaContainer', {
                sitekey: config.captchaSiteKey,
                theme: 'dark',
                callback: onCaptchaSuccess,
                'error-callback': onCaptchaError,
            });
        } else {
            window.grecaptcha.reset(reportWidgetId);
        }
    }

    window.onCaptchaReady = function () {
        if (byId('captchaModal')?.classList.contains('show')) renderGlobalCaptcha();
        if (byId('reportModal')?.classList.contains('show')) renderReportCaptcha();
    };

    function loadCaptcha() {
        if (!config.captchaSiteKey) return;
        const script = document.createElement('script');
        script.src = 'https://www.google.com/recaptcha/api.js?onload=onCaptchaReady&render=explicit';
        script.async = true;
        script.defer = true;
        document.head.appendChild(script);
    }

    function showCaptchaModal(action) {
        pendingAction = action;
        byId('captchaModal')?.classList.add('show');
        renderGlobalCaptcha();
    }

    function hidePasswordPrompt() {
        byId('pwPromptOverlay')?.classList.remove('show');
    }

    function showPasswordPrompt(mode, showError) {
        passwordPromptMode = mode || 'download';
        const overlay = byId('pwPromptOverlay');
        const input = byId('pwPromptInput');
        const error = byId('pwPromptError');
        if (!overlay || !input || !error) return;

        if (showError && overlay.classList.contains('show')) {
            error.style.display = 'block';
            input.focus();
            input.select();
            const box = overlay.querySelector('.captcha-box');
            if (box) {
                box.classList.remove('shake');
                void box.offsetWidth;
                box.classList.add('shake');
            }
            return;
        }

        error.style.display = showError ? 'block' : 'none';
        input.value = '';
        overlay.classList.add('show');
        window.setTimeout(() => input.focus(), 100);
    }

    function submitPasswordPrompt() {
        const password = byId('pwPromptInput')?.value || '';
        if (passwordPromptMode === 'preview') {
            unlockPreviewWithPassword(password);
        } else {
            filePassword = password;
            doDownload();
        }
    }

    async function doDownload() {
        try {
            const result = await window.FHApi.post('get_download_token', {
                id: config.fileId,
                captcha_proof: currentCaptchaProof || undefined,
                pw: filePassword || undefined,
            });
            if (result.success && result.token) {
                hidePasswordPrompt();
                window.location.assign(`${config.downloadUrl}${encodeURIComponent(result.token)}`);
                refreshDownloadCount();
            } else if (result.require_password) {
                showPasswordPrompt('download', Boolean(filePassword));
            } else if (result.require_captcha) {
                hidePasswordPrompt();
                captchaVerified = false;
                currentCaptchaProof = '';
                showCaptchaModal('download');
            } else {
                showToast(`${window.t('download.link_failed')} ${result.error || window.t('download.unknown_error')}`);
            }
        } catch (_error) {
            showToast(window.t('download.link_conn_error'));
        }
    }

    async function unlockPreviewWithPassword(password) {
        try {
            const result = await window.FHApi.post('preview_auth', {
                id: config.fileId,
                pw: password,
            });
            if (result.success) {
                hidePasswordPrompt();
                renderPreview();
            } else if (result.require_password) {
                showPasswordPrompt('preview', true);
            } else {
                showToast(result.error || window.t('download.unlock_failed'));
            }
        } catch (_error) {
            showToast(window.t('common.connection_error'));
        }
    }

    function renderPreview() {
        const slot = byId('previewSlot');
        if (!slot) return;
        const url = new URL(config.previewUrl, window.location.href);
        url.searchParams.set('t', String(Date.now()));
        const sourceUrl = url.toString();
        let element = null;

        if (slot.dataset.type === 'image') {
            element = document.createElement('img');
            element.src = sourceUrl;
            element.alt = 'preview';
        } else if (slot.dataset.type === 'video') {
            element = document.createElement('video');
            element.controls = true;
            element.poster = config.thumbnailUrl;
            const source = document.createElement('source');
            source.src = sourceUrl;
            source.type = slot.dataset.mime || '';
            element.appendChild(source);
        } else if (slot.dataset.type === 'audio') {
            element = document.createElement('audio');
            element.controls = true;
            const source = document.createElement('source');
            source.src = sourceUrl;
            source.type = slot.dataset.mime || '';
            element.appendChild(source);
        } else if (slot.dataset.type === 'pdf') {
            element = document.createElement('iframe');
            element.src = sourceUrl;
        }

        slot.replaceChildren(...(element ? [element] : []));
        const locked = byId('previewLocked');
        if (locked) locked.hidden = true;
    }

    function refreshDownloadCount() {
        window.setTimeout(async () => {
            try {
                const result = await window.FHApi.get('info', { id: config.fileId });
                const element = byId('downloadCount');
                if (!result.success || !result.data || !element) return;
                const current = Number.parseInt(element.textContent, 10) || 0;
                if (result.data.downloads > current) {
                    element.textContent = result.data.downloads;
                }
            } catch (_error) {
                // The transfer itself must not depend on the cosmetic counter refresh.
            }
        }, 1500);
    }

    async function doDelete() {
        const token = byId('deleteToken')?.value.trim() || '';
        const resultBox = byId('deleteResult');
        if (!resultBox) return;
        try {
            const formData = new FormData();
            formData.append('id', config.fileId);
            formData.append('token', token);
            if (currentCaptchaProof) formData.append('captcha_proof', currentCaptchaProof);
            const result = await window.FHApi.postForm('delete', formData);
            if (result.success) {
                resultBox.className = 'delete-result success';
                resultBox.textContent = window.t('download.del_ok');
                window.setTimeout(() => window.location.assign(config.homeUrl), 2000);
            } else if (result.require_captcha) {
                captchaVerified = false;
                currentCaptchaProof = '';
                showCaptchaModal('delete');
            } else {
                captchaVerified = false;
                currentCaptchaProof = '';
                resultBox.className = 'delete-result error';
                resultBox.textContent = result.error || window.t('download.del_bad_token');
            }
        } catch (_error) {
            resultBox.className = 'delete-result error';
            resultBox.textContent = window.t('common.connection_error');
        }
    }

    function requestDelete() {
        const token = byId('deleteToken')?.value.trim() || '';
        const resultBox = byId('deleteResult');
        if (!resultBox) return;
        if (!token) {
            resultBox.className = 'delete-result error';
            resultBox.textContent = window.t('download.del_need_token');
            return;
        }
        doDelete();
    }

    async function openReportModal() {
        const modal = byId('reportModal');
        if (!modal) return;
        modal.classList.add('show');
        const linkInput = modal.querySelector('input[name="report_link"]');
        if (linkInput) linkInput.value = window.location.href;
        try {
            const result = await window.FHApi.get('get_report_config');
            if (result.success) reportCaptchaRequired = Boolean(result.require_captcha);
        } catch (_error) {
            // The server validates the requirement again on submit.
        }
        const container = byId('reportCaptchaContainer');
        if (reportCaptchaRequired && config.captchaSiteKey) {
            renderReportCaptcha();
        } else if (container) {
            container.style.display = 'none';
        }
    }

    function closeReportModal() {
        byId('reportModal')?.classList.remove('show');
        byId('reportForm')?.reset();
        const message = byId('reportMessage');
        if (message) message.style.display = 'none';
        if (window.grecaptcha && reportWidgetId !== null) {
            window.grecaptcha.reset(reportWidgetId);
        }
        captchaVerified = false;
        const container = byId('reportCaptchaContainer');
        if (container) {
            container.style.display = 'none';
        }
    }

    async function submitReport(event) {
        event.preventDefault();
        if (reportCaptchaRequired && !captchaVerified) {
            showToast(window.t('report.captcha_required'));
            return;
        }

        const form = byId('reportForm');
        const message = byId('reportMessage');
        if (!form || !message) return;
        const fields = new FormData(form);
        const payload = {
            file_id: config.fileId,
            name: fields.get('reporter_name') || '',
            email: fields.get('reporter_email') || '',
            entity: fields.get('reporter_entity') || '',
            org: fields.get('reporter_org') || '',
            title: fields.get('report_title') || '',
            link: fields.get('report_link') || '',
            info: fields.get('additional_info') || '',
            captcha_proof: currentCaptchaProof,
        };

        try {
            const result = await window.FHApi.post('report_file', payload);
            if (result.success) {
                message.className = 'report-message success';
                message.textContent = window.t('report.sent');
                message.style.display = 'block';
                form.reset();
                window.setTimeout(closeReportModal, 3000);
                captchaVerified = false;
                if (window.grecaptcha && reportWidgetId !== null) {
                    window.grecaptcha.reset(reportWidgetId);
                }
                return;
            }

            if (result.require_captcha) {
                reportCaptchaRequired = true;
                captchaVerified = false;
                const container = byId('reportCaptchaContainer');
                if (container) {
                    container.style.display = 'block';
                }
                renderReportCaptcha();
            }
            const errorText = result.error || window.t('report.generic_error');
            const validationMarkers = ['fill in', 'wypełnij', 'Invalid email', 'Nieprawidłowy'];
            message.className = validationMarkers.some((marker) => errorText.includes(marker))
                ? 'report-message warning'
                : 'report-message error';
            message.textContent = errorText;
            message.style.display = 'block';
        } catch (_error) {
            message.className = 'report-message error';
            message.textContent = window.t('common.connection_error');
            message.style.display = 'block';
        }
    }

    function bindBackdropDismissal(modal, close) {
        if (!modal) return;
        let downTarget = null;
        modal.addEventListener('mousedown', (event) => {
            downTarget = event.target;
        });
        modal.addEventListener('mouseup', (event) => {
            if (event.target === modal && downTarget === modal) close();
            downTarget = null;
        });
    }

    document.addEventListener('click', (event) => {
        const actionButton = event.target.closest?.('[data-download-action]');
        if (actionButton) {
            const actions = {
                'open-report': openReportModal,
                download: doDownload,
                'copy-link': copyLink,
                'toggle-embed': toggleEmbed,
                delete: requestDelete,
                'unlock-preview': () => showPasswordPrompt('preview', false),
                'close-report': closeReportModal,
                'close-password': hidePasswordPrompt,
                'close-captcha': hideCaptchaModal,
            };
            const action = actions[actionButton.dataset.downloadAction];
            if (action) {
                event.preventDefault();
                action();
            }
        }

        const embedButton = event.target.closest?.('[data-embed-key]');
        if (embedButton) {
            event.preventDefault();
            copyEmbed(embedButton, embedButton.dataset.embedKey);
        }
    });

    byId('reportForm')?.addEventListener('submit', submitReport);
    byId('pwPromptForm')?.addEventListener('submit', (event) => {
        event.preventDefault();
        submitPasswordPrompt();
    });
    bindBackdropDismissal(byId('reportModal'), closeReportModal);
    document.querySelectorAll('.captcha-modal').forEach((modal) => {
        bindBackdropDismissal(modal, () => modal.classList.remove('show'));
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        if (byId('reportModal')?.classList.contains('show')) closeReportModal();
        document.querySelectorAll('.captcha-modal.show').forEach((modal) => {
            modal.classList.remove('show');
        });
    });

    initializeEmbedCodes();
    startParallax();
    loadCaptcha();
}());
