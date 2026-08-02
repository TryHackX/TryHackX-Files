(function () {
    'use strict';

    const pageSize = 20;
    let currentPage = 1;
    let currentFilter = 'all';

    const t = (key, params) => window.t(key, params);
    const api = () => window.FHApi;
    const notify = (message, type = 'info', anchor = null) => {
        if (typeof window.showNotification === 'function') {
            window.showNotification(message, type, anchor);
        } else {
            window.FHUi?.toast(message, type, { anchor });
        }
    };

    function emptyRow(columns, text) {
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = columns;
        cell.className = 'empty';
        cell.textContent = text;
        row.appendChild(cell);
        return row;
    }

    function iconElement(iconName) {
        const icon = document.createElement('i');
        const safeNames = String(iconName || '')
            .split(/\s+/)
            .filter((name) => /^fa-[a-z0-9-]+$/.test(name));
        icon.className = ['fa-solid', ...safeNames].join(' ');
        icon.setAttribute('aria-hidden', 'true');
        return icon;
    }

    function toggle(className, checked) {
        const label = document.createElement('label');
        label.className = 'lang-toggle';
        const input = document.createElement('input');
        input.type = 'checkbox';
        input.className = className;
        input.checked = Boolean(checked);
        label.append(input, document.createElement('span'));
        return label;
    }

    function typeCell(record) {
        const cell = document.createElement('td');
        const wrapper = document.createElement('span');
        wrapper.className = 'notif-type';
        const text = document.createElement('span');
        text.append(document.createTextNode(t(`notif.type.${record.type}`)));
        const description = document.createElement('small');
        description.textContent = t(`notif.desc.${record.type}`);
        text.appendChild(description);
        wrapper.append(iconElement(record.icon), text);
        cell.appendChild(wrapper);
        return cell;
    }

    function unavailableCell() {
        const cell = document.createElement('td');
        const marker = document.createElement('span');
        marker.className = 'is-na';
        marker.textContent = '\u2014';
        cell.appendChild(marker);
        return cell;
    }

    function toggleCell(className, checked) {
        const cell = document.createElement('td');
        cell.appendChild(toggle(className, checked));
        return cell;
    }

    function renderPager(total) {
        const holder = document.getElementById('notifPagination');
        if (!holder) return;
        const totalPages = Math.max(1, Math.ceil(total / pageSize));
        holder.replaceChildren();
        if (totalPages <= 1) return;

        const label = document.createElement('span');
        label.className = 'pager-label';
        label.textContent = t('notif.pager_label', {
            page: currentPage,
            pages: totalPages,
            total,
        });
        const previous = document.createElement('button');
        previous.type = 'button';
        previous.className = 'btn btn-sm';
        previous.disabled = currentPage <= 1;
        previous.setAttribute('aria-label', t('notif.pager_label'));
        previous.textContent = '\u2039';
        previous.addEventListener('click', () => loadNotifications(currentPage - 1));
        const next = document.createElement('button');
        next.type = 'button';
        next.className = 'btn btn-sm';
        next.disabled = currentPage >= totalPages;
        next.setAttribute('aria-label', t('notif.pager_label'));
        next.textContent = '\u203a';
        next.addEventListener('click', () => loadNotifications(currentPage + 1));
        holder.append(previous, label, next);
    }

    async function loadNotifications(page = currentPage) {
        const holder = document.getElementById('notifPageList');
        if (!holder) return;
        currentPage = Math.max(1, Number.parseInt(page, 10) || 1);
        try {
            const response = await api().get('notifications', {
                page: currentPage,
                per_page: pageSize,
                filter: currentFilter,
            });
            if (!response.success) throw new Error('failed');

            const badge = document.getElementById('notifUnreadBadge');
            if (badge) {
                badge.textContent = response.unread;
                badge.style.display = response.unread ? '' : 'none';
            }
            window.FHNotify?.setBadge(response.unread);

            if (response.items.length) {
                holder.replaceChildren(...response.items.map((item) => {
                    const row = window.FHNotify.itemElement(item);
                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'notif-row-del';
                    remove.title = t('common.delete');
                    remove.appendChild(iconElement('fa-xmark'));
                    remove.addEventListener('click', (event) => deleteNotification(event, item.id));
                    row.appendChild(remove);
                    return row;
                }));
            } else {
                const empty = document.createElement('div');
                empty.className = 'notif-empty';
                empty.textContent = t('notif.empty');
                holder.replaceChildren(empty);
            }
            renderPager(Number(response.total) || 0);
        } catch (_error) {
            const error = document.createElement('div');
            error.className = 'notif-empty';
            error.textContent = t('common.connection_error');
            holder.replaceChildren(error);
        }
    }

    function goNotifPage(page) {
        loadNotifications(page);
    }

    function setNotifFilter(filter) {
        currentFilter = String(filter || 'all');
        document.querySelectorAll('#notifFilter .range-btn').forEach((button) => {
            button.classList.toggle('active', button.dataset.filter === currentFilter);
        });
        loadNotifications(1);
    }

    async function deleteNotification(event, id) {
        event?.preventDefault();
        event?.stopPropagation();
        try {
            await api().post('notification_delete', { ids: [id] });
        } catch (_error) {
            // Reloading below reconciles the UI with the server.
        }
        loadNotifications();
    }

    async function markAllNotificationsRead() {
        try {
            const response = await api().post('notification_read', { all: true });
            if (response.success) window.FHNotify?.setBadge(0);
        } catch (_error) {
            notify(t('common.connection_error'), 'error');
        }
        loadNotifications();
    }

    function clearNotifications() {
        window.showConfirm(t('notif.clear'), t('notif.clear_confirm'), async () => {
            try {
                await api().post('notification_delete', { all: true });
                window.FHNotify?.setBadge(0);
            } catch (_error) {
                notify(t('common.connection_error'), 'error');
            }
            loadNotifications(1);
        });
    }

    function bindHistoryRead() {
        document.getElementById('notifPageList')?.addEventListener('click', (event) => {
            const row = event.target.closest?.('[data-id]');
            if (!row?.classList.contains('is-unread')) return;
            row.classList.remove('is-unread');
            const badge = document.getElementById('notifUnreadBadge');
            if (badge) {
                const left = Math.max(0, (Number.parseInt(badge.textContent, 10) || 0) - 1);
                badge.textContent = left;
                if (!left) badge.style.display = 'none';
            }
            api().post('notification_read', { ids: [Number(row.dataset.id)] })
                .then(() => window.FHNotify?.refreshCount())
                .catch(() => {});
        });
    }

    let actionsBound = false;
    function bindActions() {
        if (actionsBound) return;
        actionsBound = true;
        bindHistoryRead();
        document.addEventListener('click', (event) => {
            const button = event.target.closest?.('[data-notification-action]');
            if (!button) return;
            const actions = {
                filter: () => setNotifFilter(button.dataset.filter),
                readAll: () => markAllNotificationsRead(),
                clear: () => clearNotifications(),
                savePrefs: () => saveNotificationPrefs(button),
                saveDefaults: () => saveNotificationDefaults(button),
                broadcast: () => sendNotificationBroadcast(button),
            };
            actions[button.dataset.notificationAction]?.();
        });
        document.getElementById('notifBroadcastChannel')
            ?.addEventListener('change', updateBroadcastFields);
        document.getElementById('notifBroadcastFormat')
            ?.addEventListener('change', updateBroadcastFields);
        updateBroadcastFields();
    }

    function updateBroadcastFields() {
        const channel = document.getElementById('notifBroadcastChannel')?.value || 'app';
        const format = document.getElementById('notifBroadcastFormat')?.value || 'standard';
        const hasApp = channel === 'app' || channel === 'both';
        const hasEmail = channel === 'email' || channel === 'both';
        const appGroup = document.getElementById('notifBroadcastAppGroup');
        const emailFields = document.getElementById('notifBroadcastEmailFields');
        const formatGroup = document.getElementById('notifBroadcastFormatGroup');
        const formatHint = document.getElementById('notifBroadcastFormatHint');
        if (appGroup) appGroup.hidden = !hasApp;
        if (emailFields) emailFields.hidden = !hasEmail;
        if (formatGroup) formatGroup.hidden = !hasEmail;
        if (formatHint) {
            formatHint.textContent = t(format === 'html'
                ? 'notif.broadcast_html_hint'
                : 'notif.broadcast_standard_hint');
        }
    }

    async function loadNotificationPrefs() {
        const body = document.getElementById('notifPrefsBody');
        if (!body) return;
        try {
            const response = await api().get('notification_prefs');
            if (!response.success || !response.types.length) {
                body.replaceChildren(emptyRow(3, t('notif.prefs_none')));
                return;
            }
            body.replaceChildren(...response.types.map((record) => {
                const row = document.createElement('tr');
                row.dataset.type = record.type;
                row.append(
                    typeCell(record),
                    toggleCell('np-app', record.app),
                    record.mailable ? toggleCell('np-mail', record.mail) : unavailableCell(),
                );
                return row;
            }));
        } catch (_error) {
            body.replaceChildren(emptyRow(3, t('common.connection_error')));
        }
    }

    async function saveNotificationPrefs(button = null) {
        const prefs = {};
        document.querySelectorAll('#notifPrefsBody tr[data-type]').forEach((row) => {
            const mail = row.querySelector('.np-mail');
            prefs[row.dataset.type] = {
                app: Boolean(row.querySelector('.np-app')?.checked),
                mail: mail ? Boolean(mail.checked) : false,
            };
        });
        try {
            const response = await api().post('notification_prefs_save', { prefs });
            notify(
                response.success ? t('panel.ctl.saved') : (response.error || t('common.error')),
                response.success ? 'success' : 'error',
                button,
            );
        } catch (_error) {
            notify(t('common.connection_error'), 'error', button);
        }
    }

    async function loadNotificationDefaults() {
        const body = document.getElementById('notifDefaultsBody');
        if (!body) return;
        try {
            const response = await api().get('admin_notification_defaults');
            if (!response.success) throw new Error('failed');
            body.replaceChildren(...response.types.map((record) => {
                const row = document.createElement('tr');
                row.dataset.type = record.type;
                row.append(
                    typeCell(record),
                    toggleCell('nd-enabled', record.enabled),
                    toggleCell('nd-app', record.app),
                    record.mailable ? toggleCell('nd-mail', record.mail) : unavailableCell(),
                );
                return row;
            }));
        } catch (_error) {
            body.replaceChildren(emptyRow(4, t('common.connection_error')));
        }
    }

    async function saveNotificationDefaults(button = null) {
        const defaults = {};
        document.querySelectorAll('#notifDefaultsBody tr[data-type]').forEach((row) => {
            const mail = row.querySelector('.nd-mail');
            defaults[row.dataset.type] = {
                enabled: Boolean(row.querySelector('.nd-enabled')?.checked),
                app: Boolean(row.querySelector('.nd-app')?.checked),
                mail: mail ? Boolean(mail.checked) : false,
            };
        });
        try {
            const response = await api().post('admin_notification_defaults_save', { defaults });
            notify(
                response.success ? t('panel.ctl.saved') : (response.error || t('common.error')),
                response.success ? 'success' : 'error',
                button,
            );
        } catch (_error) {
            notify(t('common.connection_error'), 'error', button);
        }
    }

    function sendNotificationBroadcast(button = null) {
        const input = document.getElementById('notifBroadcastText');
        const message = (input?.value || '').trim();
        const channel = document.getElementById('notifBroadcastChannel')?.value || 'app';
        const format = document.getElementById('notifBroadcastFormat')?.value || 'standard';
        const subjectInput = document.getElementById('notifBroadcastSubject');
        const bodyInput = document.getElementById('notifBroadcastEmailBody');
        const subject = (subjectInput?.value || '').trim();
        const emailBody = (bodyInput?.value || '').trim();
        if ((channel !== 'email' && !message)
            || (channel !== 'app' && (!subject || !emailBody))) {
            notify(t('api.notif_bad_message'), 'error', button);
            return;
        }
        window.showConfirm(t('notif.broadcast_title'), t('notif.broadcast_confirm'), async () => {
            try {
                const response = await api().post('admin_notification_broadcast', {
                    message,
                    channel,
                    format,
                    subject,
                    email_body: emailBody,
                });
                if (response.success) {
                    if (input) input.value = '';
                    if (subjectInput) subjectInput.value = '';
                    if (bodyInput) bodyInput.value = '';
                    notify(t('notif.broadcast_done_channels', {
                        app: response.appSent || 0,
                        mail: response.emailQueued || 0,
                    }), 'success', button);
                    window.FHNotify?.refreshCount();
                } else {
                    notify(response.error || t('common.error'), 'error', button);
                }
            } catch (_error) {
                notify(t('common.connection_error'), 'error', button);
            }
        });
    }

    const publicApi = {
        loadNotifications,
        goNotifPage,
        setNotifFilter,
        deleteNotification,
        markAllNotificationsRead,
        clearNotifications,
        bindActions,
        bindHistoryRead,
        loadNotificationPrefs,
        saveNotificationPrefs,
        loadNotificationDefaults,
        saveNotificationDefaults,
        sendNotificationBroadcast,
    };
    window.FHPanelNotifications = publicApi;
    Object.assign(window, publicApi);
}());
