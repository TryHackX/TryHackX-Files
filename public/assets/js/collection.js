(function () {
    'use strict';

    const bootstrap = document.getElementById('collectionBootstrap');
    if (!bootstrap) return;

    let config = {};
    try {
        config = JSON.parse(bootstrap.dataset.config || '{}');
    } catch (_error) {
        config = {};
    }

    let passwordToken = '';
    let memberModalPreviousFocus = null;
    let memberModalPreviousOverflow = '';
    let memberModalDownTarget = null;

    function memberModalFocusable(modal) {
        return Array.from(modal.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )).filter((element) => element.offsetWidth > 0 || element.offsetHeight > 0 || element === document.activeElement);
    }

    function showError(message) {
        if (window.FHUi && typeof window.FHUi.toast === 'function') {
            window.FHUi.toast(String(message || config.connectionError || ''), 'error');
            return;
        }
        window.alert(String(message || config.connectionError || ''));
    }

    async function fetchZipToken(passwords = null) {
        const body = { id: config.collectionId };
        if (passwords !== null) {
            body.passwords = passwords;
            body.confirm_member_passwords = true;
        }
        const response = await window.FHApi.post('collection_zip_token', {
            ...body,
        });
        if (response && response.require_member_passwords) {
            openMemberPasswords(response.protected || []);
            return null;
        }
        if (!response || !response.success) {
            throw new Error((response && response.error) || 'token');
        }
        return response;
    }

    function openMemberPasswords(files) {
        const modal = document.getElementById('collMembersModal');
        const list = document.getElementById('collMemberPasswordList');
        if (!modal || !list) return;
        if (!modal.classList.contains('show')) {
            memberModalPreviousFocus = document.activeElement;
            memberModalPreviousOverflow = document.body.style.overflow;
        }
        list.replaceChildren(...files.map((file, index) => {
            const row = document.createElement('div');
            row.className = 'coll-member-row';
            const label = document.createElement('label');
            label.textContent = String(file.name || file.id || '');
            label.title = label.textContent;
            const input = document.createElement('input');
            input.id = `collMemberPassword_${index}`;
            input.type = 'password';
            input.maxLength = 1024;
            input.autocomplete = 'current-password';
            input.dataset.fileId = String(file.id || '');
            label.htmlFor = input.id;
            row.append(label, input);
            return row;
        }));
		modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        requestAnimationFrame(() => list.querySelector('input')?.focus());
    }

    function closeMemberPasswords() {
        const modal = document.getElementById('collMembersModal');
        if (!modal?.classList.contains('show')) return;
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = memberModalPreviousOverflow;
        try { memberModalPreviousFocus?.focus?.(); } catch (_error) { /* detached trigger */ }
        memberModalPreviousFocus = null;
    }

    function followZip(response) {
        if (!response) return;
        const url = new URL(config.zipUrl, window.location.href);
        url.searchParams.set('dt', response.token);
        if (passwordToken) url.searchParams.set('t', passwordToken);
        if (response.member_token) url.searchParams.set('m', response.member_token);
        if (Array.isArray(response.skipped) && response.skipped.length && window.FHUi?.toast) {
            window.FHUi.toast(String(config.skippedNotice || '').replace(':n', response.skipped.length), 'info');
        }
        window.location.assign(url.toString());
    }

    async function startZip(event) {
        event.preventDefault();
        try {
            followZip(await fetchZipToken());
        } catch (error) {
            showError(error instanceof Error ? error.message : error);
        }
    }

    async function unlockCollection(event) {
        event.preventDefault();
        const input = document.getElementById('collPwInput');
        const errorBox = document.getElementById('collPwError');
        const button = document.getElementById('collPwBtn');
        if (!input || !errorBox || !button) return;

        errorBox.hidden = true;
        button.disabled = true;
        try {
            const response = await window.FHApi.post('verify_collection_password', {
                id: config.collectionId,
                password: input.value,
            });
            if (!response || !response.success) {
                errorBox.textContent = response && response.error ? response.error : '';
                errorBox.hidden = false;
                input.select();
                return;
            }

            passwordToken = response.token || '';
            const lock = document.querySelector('.coll-lock');
            const unlocked = document.getElementById('collUnlocked');
            if (lock) lock.hidden = true;
            if (unlocked) unlocked.hidden = false;
        } catch (_error) {
            errorBox.textContent = config.connectionError || '';
            errorBox.hidden = false;
        } finally {
            button.disabled = false;
        }
    }

    document.querySelectorAll('.cthumb').forEach((thumbnail) => {
        thumbnail.addEventListener('error', () => thumbnail.remove(), { once: true });
    });

    document.querySelectorAll('[data-collection-download]').forEach((link) => {
        link.addEventListener('click', startZip);
    });

    const passwordForm = document.getElementById('collPwForm');
    if (passwordForm) passwordForm.addEventListener('submit', unlockCollection);

    document.querySelectorAll('[data-member-password-close]').forEach((button) => {
        button.addEventListener('click', closeMemberPasswords);
    });

    const memberModal = document.getElementById('collMembersModal');
    memberModal?.addEventListener('keydown', (event) => {
        if (event.key !== 'Tab') return;
        const focusable = memberModalFocusable(memberModal);
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || event.repeat || !memberModal?.classList.contains('show')) return;
        event.preventDefault();
        closeMemberPasswords();
    });
    memberModal?.addEventListener('mousedown', (event) => {
        memberModalDownTarget = event.target;
    });
    memberModal?.addEventListener('mouseup', (event) => {
        if (event.target === memberModal && memberModalDownTarget === memberModal) closeMemberPasswords();
        memberModalDownTarget = null;
    });

    document.getElementById('collMembersForm')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const passwords = {};
        document.querySelectorAll('#collMemberPasswordList input[data-file-id]').forEach((input) => {
            passwords[input.dataset.fileId] = input.value;
        });
        try {
            const response = await fetchZipToken(passwords);
            closeMemberPasswords();
            followZip(response);
        } catch (error) {
            showError(error instanceof Error ? error.message : error);
        }
    });
}());
