/*
 * 通知ポップオーバー (ベル横のドロップダウン)
 *
 * topbar のベル (data-notification-popover-trigger) を押すと、
 * GET /api/v1/notifications で一覧を取得して描画する。
 * 管理者はサイドバーに /admin/users のリンクがあるため対象外。
 */

import { getJson, postJson } from '../utils/fetch-json';

const API_BASE = '/api/v1/notifications';
const LOGIN_URL = '/login';
const MSG_LOAD_FAILED = '通知の取得に失敗しました。';
const MSG_READ_FAILED = '既読化に失敗しました。';

const isAdmin = () => document.querySelector('[data-sidebar] a[href$="/admin/users"]') !== null;

const badgeText = (count) => (count > 99 ? '99+' : String(count));

export function initNotificationPopover() {
    const root = document.querySelector('[data-notification-popover-root]');
    if (!root || isAdmin()) {
        return;
    }

    const trigger = root.querySelector('[data-notification-popover-trigger]');
    const badge = root.querySelector('[data-notification-popover-badge]');
    const panel = root.querySelector('[data-notification-popover-panel]');
    const tabs = Array.from(root.querySelectorAll('[data-notification-popover-tab]'));
    const unreadCountEl = root.querySelector('[data-notification-popover-unread-count]');
    const markAllButton = root.querySelector('[data-notification-popover-mark-all]');
    const loading = root.querySelector('[data-notification-popover-loading]');
    const empty = root.querySelector('[data-notification-popover-empty]');
    const list = root.querySelector('[data-notification-popover-items]');
    const rowTemplate = root.querySelector('[data-notification-popover-row-template]');

    if (!trigger || !panel || !list || !rowTemplate) {
        return;
    }

    let currentTab = 'all';
    // 連続した取得の古い応答で画面を上書きしないための通し番号
    let requestSeq = 0;

    const isOpen = () => panel.style.display !== 'none';

    const handleError = (error, message) => {
        if (error?.status === 401) {
            window.location.href = LOGIN_URL;
            return;
        }
        window.alert(message);
    };

    const setUnreadCount = (count) => {
        if (badge) {
            badge.textContent = badgeText(count);
            badge.classList.toggle('hidden', count <= 0);
            trigger.setAttribute('aria-label', `通知 (${count} 件未読)`);
        }
        if (unreadCountEl) {
            unreadCountEl.textContent = String(count);
        }
    };

    const setPanelOpen = (open) => {
        panel.style.display = open ? 'flex' : 'none';
        panel.classList.toggle('opacity-0', !open);
        panel.classList.toggle('-translate-y-1', !open);
        panel.classList.toggle('opacity-100', open);
        panel.classList.toggle('translate-y-0', open);
        trigger.setAttribute('aria-expanded', String(open));
    };

    const markRowRead = (row) => {
        row.anchor.setAttribute('data-unread', 'false');
        row.dot.classList.add('hidden');
    };

    const renderEmpty = (isEmpty) => {
        empty?.classList.toggle('hidden', !isEmpty);
    };

    const buildRow = (item) => {
        const fragment = rowTemplate.content.firstElementChild.cloneNode(true);
        const anchor = fragment.querySelector('[data-notification-popover-row]');
        const dot = fragment.querySelector('[data-notification-popover-row-dot]');
        const row = { anchor, dot, item };

        anchor.href = item.url;
        anchor.setAttribute('data-unread', item.read ? 'false' : 'true');
        fragment.querySelector('[data-notification-popover-row-title]').textContent = item.title || '通知';
        fragment.querySelector('[data-notification-popover-row-message]').textContent = item.message ?? '';
        fragment.querySelector('[data-notification-popover-row-time]').textContent = item.created_at_human ?? '';
        if (item.read) {
            dot.classList.add('hidden');
        }

        anchor.addEventListener('click', (event) => {
            event.preventDefault();
            handleRowClick(row);
        });

        return fragment;
    };

    const handleRowClick = async (row) => {
        if (row.item.read) {
            window.location.href = row.item.url;
            return;
        }

        try {
            const result = await postJson(`${API_BASE}/${row.item.id}/read`, null);
            row.item.read = true;
            markRowRead(row);
            setUnreadCount(result.unread_count);
            window.location.href = row.item.url;
        } catch (error) {
            handleError(error, MSG_READ_FAILED);
        }
    };

    const load = async (tab) => {
        const seq = ++requestSeq;
        loading?.classList.remove('hidden');
        renderEmpty(false);

        try {
            const payload = await getJson(`${API_BASE}?tab=${encodeURIComponent(tab)}`);
            if (seq !== requestSeq) {
                return;
            }
            list.replaceChildren(...payload.data.map(buildRow));
            renderEmpty(payload.data.length === 0);
            setUnreadCount(payload.unread_count);
        } catch (error) {
            if (seq === requestSeq) {
                handleError(error, MSG_LOAD_FAILED);
            }
        } finally {
            if (seq === requestSeq) {
                loading?.classList.add('hidden');
            }
        }
    };

    const switchTab = (tab) => {
        currentTab = tab;
        tabs.forEach((button) => {
            button.setAttribute('aria-selected', String(button.dataset.notificationPopoverTab === tab));
        });
        load(tab);
    };

    const markAll = async () => {
        try {
            const result = await postJson(`${API_BASE}/read-all`, null);
            Array.from(list.children).forEach((element) => {
                markRowRead({
                    anchor: element.querySelector('[data-notification-popover-row]'),
                    dot: element.querySelector('[data-notification-popover-row-dot]'),
                });
            });
            if (currentTab === 'unread') {
                list.replaceChildren();
                renderEmpty(true);
            }
            setUnreadCount(result.unread_count);
        } catch (error) {
            handleError(error, MSG_READ_FAILED);
        }
    };

    trigger.addEventListener('click', (event) => {
        event.stopPropagation();
        const willOpen = !isOpen();
        setPanelOpen(willOpen);
        if (willOpen) {
            load(currentTab);
        }
    });

    tabs.forEach((button) => {
        button.addEventListener('click', () => switchTab(button.dataset.notificationPopoverTab));
    });

    markAllButton?.addEventListener('click', markAll);

    document.addEventListener('click', (event) => {
        if (isOpen() && !root.contains(event.target)) {
            setPanelOpen(false);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && isOpen()) {
            setPanelOpen(false);
            trigger.focus();
        }
    });

    setPanelOpen(false);
}
