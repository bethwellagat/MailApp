(function () {
    'use strict';

    /* ---------- CSRF ---------- */
    // Minted server-side per session and emitted as window.__CSRF__. Every
    // state-changing request must echo it back via the X-CSRF-Token header.
    const CSRF_TOKEN = (window.__CSRF__ || '');
    function csrfHeaders(extra) {
        return Object.assign({ 'X-CSRF-Token': CSRF_TOKEN }, extra || {});
    }

    /* ---------- State ---------- */
    const state = {
        // Multi-account model. accounts mirrors account_list() from the server.
        // currentAccount is the focused account id, or '' for the unified
        // ("All accounts") view. currentMsgAcct is the account that owns the
        // message open in the reading pane (matters in the unified view, where
        // rows from different accounts coexist). accountFolders caches each
        // account's folder list, fetched lazily when its group is expanded.
        accounts: [],
        primaryAccount: '',
        currentAccount: '',
        currentMsgAcct: '',
        expandedAccounts: new Set(),
        accountFolders: {},
        accountOutbox: {}, // acctId -> { messages, count, loaded } — the client-side Outbox folder
        bgAcctCursor: 0,   // rotates non-focused accounts through the poll cycle
        attachFlags: {},          // uid -> true, filled lazily after the list renders
        attachChecked: new Set(), // uids already probed (avoids re-asking on poll)
        folders: [],
        folderDelimiter: '.',
        currentFolder: 'INBOX',
        currentPage: 1,
        totalPages: 1,
        totalMessages: 0,
        messages: [],
        currentUid: null,
        currentMessage: null,
        currentThread: [],
        searchQuery: '',
        searchActive: false,
        searchResults: [],
        listFilter: 'all', // 'all' | 'unread' | 'flagged' | 'attachments'
        selectedUids: new Set(),
        lastSelectedIndex: -1,
        composeOpen: false,
        composeAttachments: [],
        pollTimer: null,
        pollIntervalMs: 60000,
        pollInflight: false,
    };

    let searchDebounceTimer = null;
    let searchAbortCtrl     = null;
    const searchCache       = new Map();
    let searchResultScope   = 'headers'; // scope of the results currently shown ('headers' | 'full')

    const ATTACH_MAX_PER_FILE = 25 * 1024 * 1024;  // 25 MB
    const ATTACH_MAX_TOTAL    = 25 * 1024 * 1024;
    // Effective caps = the app's own 25 MB limit ∩ whatever THIS server's PHP
    // config actually allows (exposed via window.__LIMITS__). This is what makes
    // compose reject an oversized file up front, instead of the user waiting out
    // an upload the server rejects with "PHP error code 1". 0/absent = unknown →
    // fall back to the app cap. Leave headroom under post_max for the form fields
    // + multipart boundaries that also ride in the request.
    const _SRV_LIMITS   = (window.__LIMITS__ || {});
    const EFF_PER_FILE  = Math.min(ATTACH_MAX_PER_FILE, _SRV_LIMITS.upload_max > 0 ? _SRV_LIMITS.upload_max : Infinity);
    const EFF_TOTAL     = Math.min(ATTACH_MAX_TOTAL,    _SRV_LIMITS.post_max   > 0 ? Math.floor(_SRV_LIMITS.post_max * 0.9) : Infinity);
    const EFF_MAX_FILES = _SRV_LIMITS.max_files > 0 ? _SRV_LIMITS.max_files : Infinity;

    const AVATAR_COLORS = [
        '#2c5e9e', '#6b4c93', '#2a7a7a', '#b3464a',
        '#4f7d2e', '#b6571f', '#b73670', '#7a5536',
    ];

    const $ = (id) => document.getElementById(id);

    /* ---------- Utilities ---------- */
    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function escapeAttr(s) { return escapeHtml(s); }

    function avatarInitial(name, addr) {
        const src = (name && name.trim()) || addr || '?';
        const parts = src.replace(/<.*?>/g, '').split(/[\s_\-\.]+/).filter(p => /[A-Za-z0-9]/.test(p));
        if (parts.length >= 2) {
            return (parts[0].charAt(0) + parts[1].charAt(0)).toUpperCase();
        }
        const clean = src.replace(/[^A-Za-z0-9]/g, '');
        return (clean.substring(0, 2) || '?').toUpperCase();
    }
    function avatarColor(seed) {
        const s = String(seed || '');
        let hash = 0;
        for (let i = 0; i < s.length; i++) hash = (hash * 31 + s.charCodeAt(i)) | 0;
        return AVATAR_COLORS[Math.abs(hash) % AVATAR_COLORS.length];
    }

    function formatTime(ts) {
        if (!ts) return '';
        const d = new Date(ts * 1000);
        const now = new Date();
        const sameDay = d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth() && d.getDate() === now.getDate();
        if (sameDay) return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        const diffDays = Math.floor((now - d) / 86400000);
        if (diffDays === 1) return 'Yesterday';
        if (diffDays < 7 && diffDays > 0) return d.toLocaleDateString([], { weekday: 'short' });
        if (d.getFullYear() === now.getFullYear()) return d.toLocaleDateString([], { day: '2-digit', month: '2-digit' });
        return d.toLocaleDateString([], { day: '2-digit', month: '2-digit', year: '2-digit' });
    }
    function formatFullDate(ts) {
        if (!ts) return '';
        const d = new Date(ts * 1000);
        const now = new Date();
        const sameDay = d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth() && d.getDate() === now.getDate();
        if (sameDay) return 'Today at ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        const yesterday = new Date(now); yesterday.setDate(yesterday.getDate() - 1);
        if (d.toDateString() === yesterday.toDateString()) return 'Yesterday at ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        return d.toLocaleString([], { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }
    function dateGroupLabel(ts) {
        if (!ts) return 'Older';
        const d = new Date(ts * 1000);
        const now = new Date();
        const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const startOfYesterday = new Date(startOfToday); startOfYesterday.setDate(startOfYesterday.getDate() - 1);
        const startOfWeek = new Date(startOfToday); startOfWeek.setDate(startOfWeek.getDate() - now.getDay());
        const startOfLastWeek = new Date(startOfWeek); startOfLastWeek.setDate(startOfLastWeek.getDate() - 7);
        const startOfMonth = new Date(now.getFullYear(), now.getMonth(), 1);
        const startOfLastMonth = new Date(now.getFullYear(), now.getMonth() - 1, 1);
        if (d >= startOfToday) return 'Today';
        if (d >= startOfYesterday) return 'Yesterday';
        if (d >= startOfWeek) return 'This Week';
        if (d >= startOfLastWeek) return 'Last Week';
        if (d >= startOfMonth) return 'Earlier This Month';
        if (d >= startOfLastMonth) return 'Last Month';
        if (d.getFullYear() === now.getFullYear()) return d.toLocaleDateString([], { month: 'long' });
        return d.toLocaleDateString([], { year: 'numeric', month: 'long' });
    }

    function displayFolderName(name) {
        if (!name) return '';
        if (name.toLowerCase() === 'inbox') return 'Inbox';
        const parts = name.split(/[\.\/]/);
        const last = parts[parts.length - 1];
        return last.charAt(0).toUpperCase() + last.slice(1);
    }
    function folderType(name) {
        if (!name) return 'folder';
        // Use leaf segment so e.g. "INBOX.Drafts" classifies as drafts, not inbox.
        const leaf = name.split(/[\.\/]/).pop().toLowerCase();
        if (leaf === 'inbox' || leaf === 'in box') return 'inbox';
        if (leaf.includes('sent')) return 'sent';
        if (leaf.includes('draft')) return 'drafts';
        if (leaf.includes('trash') || leaf.includes('deleted') || leaf.includes('bin')) return 'trash';
        if (leaf.includes('spam') || leaf.includes('junk')) return 'spam';
        if (leaf.includes('archive')) return 'archive';
        if (leaf.includes('later') || leaf.includes('snooze') || leaf.includes('scheduled')) return 'later';
        return 'folder';
    }
    function folderIcon(name) {
        switch (folderType(name)) {
            case 'inbox':   return 'ic-inbox';
            case 'sent':    return 'ic-send';
            case 'drafts':  return 'ic-draft';
            case 'trash':   return 'ic-trash';
            case 'spam':    return 'ic-spam';
            case 'archive': return 'ic-archive';
            case 'later':   return 'ic-clock';
            default:        return 'ic-folder';
        }
    }

    function plainPreviewFromBody(html) {
        if (!html) return '';
        const tmp = document.createElement('div');
        tmp.innerHTML = html;
        return tmp.textContent.replace(/\s+/g, ' ').trim().slice(0, 140);
    }

    /* ---------- Outbox (client-side virtual folder) ----------
     * The outbox (data/outbox/, per account) holds messages waiting to go out:
     * the brief Undo-Send hold, Scheduled sends, and anything that failed and is
     * retrying — or gave up. It is NOT an IMAP folder, so it uses a sentinel id
     * and a bespoke renderer, and is never routed through the IMAP list/status
     * code paths (see the guards in loadMessages / the poll / drag-drop).
     */
    const OUTBOX_FOLDER = '__outbox__';

    async function loadOutbox(acctId) {
        let msgs = null;
        try {
            const r = await fetch('ajax/outbox.php?action=list' + acctParam(acctId), { credentials: 'same-origin' });
            if (r.status === 401) return;
            const d = await r.json();
            if (d && !d.error && Array.isArray(d.messages)) msgs = d.messages;
        } catch (e) { return; } // best-effort; keep whatever was cached
        if (msgs === null) return;
        const prev = state.accountOutbox[acctId];
        const changed = !prev || prev.count !== msgs.length;
        state.accountOutbox[acctId] = { messages: msgs, count: msgs.length, loaded: true };
        // Only rebuild the sidebar when the count actually moved — the poll calls
        // this every cycle and an unchanged count is the common case.
        if (changed) renderSidebar();
        if (state.currentFolder === OUTBOX_FOLDER && viewAcct() === acctId) renderOutboxList(acctId);
    }

    // The Outbox entry shown inside an expanded account group. Always present (so
    // the user knows where in-progress sends live), with a count badge when it
    // holds anything.
    function outboxItemHtml(acctId) {
        const ob = state.accountOutbox[acctId];
        const count = ob ? ob.count : 0;
        const isActive = !state.searchActive && acctId === state.currentAccount && state.currentFolder === OUTBOX_FOLDER;
        const badge = count > 0 ? '<span class="folder-badge folder-badge-outbox">' + count + '</span>' : '';
        return (
            '<button class="folder-item' + (isActive ? ' active' : '') + '" data-folder="' + OUTBOX_FOLDER + '" data-acct="' + escapeAttr(acctId) + '" data-folder-type="outbox" style="padding-left:30px">' +
                '<svg class="icon folder-icon"><use href="#ic-outbox"/></svg>' +
                '<span class="folder-label">Outbox</span>' +
                badge +
            '</button>'
        );
    }

    function fmtOutboxWhen(iso) {
        if (!iso) return '';
        const d = new Date(iso);
        if (isNaN(d.getTime())) return '';
        const now = new Date();
        return d.toDateString() === now.toDateString()
            ? d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
            : d.toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    }

    // Classify a queued message into a user-facing status from its outbox record.
    function outboxStatus(m) {
        const now = Date.now();
        const sendAt = m.send_at ? Date.parse(m.send_at) : 0;
        if (m.failed) {
            return { key: 'failed', label: 'Failed',
                     detail: m.last_error ? ('Couldn’t send — ' + m.last_error) : 'Could not be sent', retry: true };
        }
        if (m.attempts > 0) {
            const next = m.next_attempt_at ? (' · next try ' + fmtOutboxWhen(m.next_attempt_at)) : '';
            return { key: 'retrying', label: 'Retrying', detail: 'Attempt ' + m.attempts + ' didn’t go through' + next, retry: true };
        }
        if (sendAt && (sendAt - now) > 45000) {
            return { key: 'scheduled', label: 'Scheduled', detail: 'Sends ' + fmtOutboxWhen(m.send_at), retry: false };
        }
        return { key: 'sending', label: 'Sending', detail: 'Going out in a few seconds…', retry: false };
    }

    function renderOutboxList(acctId) {
        const ob = state.accountOutbox[acctId];
        const msgs = (ob && ob.messages) || [];
        const listEl = $('messageList');
        if (!listEl) return;
        if (!msgs.length) {
            listEl.innerHTML =
                '<div class="list-empty outbox-empty">' +
                    '<svg class="icon outbox-empty-icon"><use href="#ic-outbox"/></svg>' +
                    '<p class="outbox-empty-title">Your Outbox is empty</p>' +
                    '<p class="outbox-empty-sub">Messages waiting to be sent — scheduled sends, or any that couldn’t go out — show up here.</p>' +
                '</div>';
            return;
        }
        const rows = msgs.map(function (m) {
            const st = outboxStatus(m);
            const to = escapeHtml(m.to || (Array.isArray(m.rcpts) ? m.rcpts.join(', ') : '') || '(no recipient)');
            const subj = escapeHtml(m.subject || '(no subject)');
            const retryBtn = st.retry
                ? '<button class="outbox-btn outbox-btn-retry" data-outbox-retry="' + escapeAttr(m.id) + '">Retry</button>'
                : '';
            const cancelLabel = (st.key === 'scheduled' || st.key === 'sending') ? 'Cancel' : 'Delete';
            return (
                '<div class="outbox-item outbox-' + st.key + '" data-outbox-id="' + escapeAttr(m.id) + '">' +
                    '<div class="outbox-item-main">' +
                        '<div class="outbox-item-top">' +
                            '<span class="outbox-pill outbox-pill-' + st.key + '">' + escapeHtml(st.label) + '</span>' +
                            '<span class="outbox-to">To: ' + to + '</span>' +
                        '</div>' +
                        '<div class="outbox-subject">' + subj + '</div>' +
                        '<div class="outbox-detail">' + escapeHtml(st.detail) + '</div>' +
                    '</div>' +
                    '<div class="outbox-item-actions">' +
                        retryBtn +
                        '<button class="outbox-btn outbox-btn-cancel" data-outbox-cancel="' + escapeAttr(m.id) + '">' + cancelLabel + '</button>' +
                    '</div>' +
                '</div>'
            );
        }).join('');
        listEl.innerHTML = '<div class="outbox-list">' + rows + '</div>';
    }

    async function renderOutboxView(opts) {
        opts = opts || {};
        const acctId = viewAcct();
        if ($('listFolderName')) $('listFolderName').textContent = 'Outbox';
        if ($('pagination')) $('pagination').hidden = true;
        const ob = state.accountOutbox[acctId];
        if (!opts.silent && !(ob && ob.loaded)) {
            $('messageList').innerHTML = '<div class="list-loading">Loading…</div>';
        }
        await loadOutbox(acctId);
        // loadOutbox renders on success; render again (from cache/empty) so an
        // offline refresh still shows something rather than a stuck spinner.
        if (state.currentFolder === OUTBOX_FOLDER && viewAcct() === acctId) renderOutboxList(acctId);
    }

    async function cancelOutbox(acctId, id) {
        if (!id) return;
        try {
            await fetch('ajax/outbox.php?action=cancel' + acctParam(acctId), {
                method: 'POST', credentials: 'same-origin',
                headers: csrfHeaders({ 'Content-Type': 'application/json' }),
                body: JSON.stringify({ id: id }),
            });
        } catch (e) { /* best-effort */ }
        await loadOutbox(acctId);
    }

    async function retryOutbox(acctId, id) {
        if (!id) return;
        try {
            await fetch('ajax/outbox.php?action=retry' + acctParam(acctId), {
                method: 'POST', credentials: 'same-origin',
                headers: csrfHeaders({ 'Content-Type': 'application/json' }),
                body: JSON.stringify({ id: id }),
            });
            // Try to flush it right away rather than waiting for the next sweep.
            await fetch('ajax/outbox.php?action=process' + acctParam(acctId), {
                method: 'POST', credentials: 'same-origin', headers: csrfHeaders(),
            });
        } catch (e) { /* best-effort */ }
        await loadOutbox(acctId);
    }

    /* ---------- API ---------- */
    // A transient network failure (offline, server restart, mobile handoff)
    // makes fetch() reject. Without a catch that rejection is swallowed and the
    // caller's `if (data.error)` never fires, so the action silently no-ops —
    // the worst failure mode for mail. Normalize it into a surfaced error.
    const NET_ERR = 'Network error — check your connection and try again.';
    async function apiGet(action, params) {
        const q = new URLSearchParams({ action, ...(params || {}) }).toString();
        let r;
        try { r = await fetch('ajax/fetch.php?' + q, { credentials: 'same-origin' }); }
        catch (e) { return { error: NET_ERR, network: true }; }
        if (r.status === 401) { window.location = 'index'; return { error: 'Not authenticated' }; }
        try { return await r.json(); } catch (e) { return { error: 'Invalid server response' }; }
    }
    async function apiPost(action, params) {
        const body = new URLSearchParams();
        body.append('action', action);
        for (const [k, v] of Object.entries(params || {})) {
            if (Array.isArray(v)) {
                for (const item of v) body.append(k + '[]', String(item));
            } else if (v !== undefined && v !== null) {
                body.append(k, String(v));
            }
        }
        let r;
        try {
            r = await fetch('ajax/fetch.php?action=' + encodeURIComponent(action), {
                method: 'POST', credentials: 'same-origin', headers: csrfHeaders(), body
            });
        } catch (e) { return { error: NET_ERR, network: true }; }
        if (r.status === 401) { window.location = 'index'; return { error: 'Not authenticated' }; }
        try { return await r.json(); } catch (e) { return { error: 'Invalid server response' }; }
    }
    async function apiSend(payload, files) {
        const body = new FormData();
        for (const [k, v] of Object.entries(payload || {})) {
            body.append(k, v == null ? '' : v);
        }
        if (Array.isArray(files)) {
            for (const f of files) body.append('attachments[]', f, f.name);
        }
        let r;
        try { r = await fetch('ajax/send.php', { method: 'POST', credentials: 'same-origin', headers: csrfHeaders(), body }); }
        catch (e) { return { error: NET_ERR, network: true }; }
        if (r.status === 401) { window.location = 'index'; return { error: 'Not authenticated' }; }
        try { return await r.json(); } catch (e) { return { error: 'Invalid server response' }; }
    }
    // Merge an `acct` param into a request only when one is given — the backend
    // treats a missing/empty acct as "use the active account", so we never send
    // an empty value. This is how every account-scoped call routes to the right
    // mailbox without each endpoint needing per-account code.
    function withAcct(params, acct) {
        params = params || {};
        return acct ? Object.assign({}, params, { acct: acct }) : params;
    }
    // The account that list-loading / folder operations target (focused account,
    // or '' for the unified view → server falls back to the active account).
    function viewAcct() { return state.currentAccount || ''; }
    // True when the unified ("All accounts") inbox is the active view. Folder
    // moves/archive are disabled here because the destination folder is
    // account-specific; per-message actions still fan out via groupTargets().
    function isUnifiedView() { return state.currentAccount === '' && state.accounts.length > 1; }
    // POST to the multi-account management endpoint (add / remove / switch).
    // Distinct from apiPost because it targets ajax/account.php, not fetch.php.
    async function accountApi(action, params) {
        const body = new FormData();
        body.append('action', action);
        for (const [k, v] of Object.entries(params || {})) body.append(k, v == null ? '' : String(v));
        let r;
        try { r = await fetch('ajax/account.php', { method: 'POST', credentials: 'same-origin', headers: csrfHeaders(), body }); }
        catch (e) { return { error: NET_ERR, network: true }; }
        if (r.status === 401) { window.location = 'index'; return { error: 'Not authenticated' }; }
        try { return await r.json(); } catch (e) { return { error: 'Invalid server response' }; }
    }
    // Persist the focused account as the server-side active account so the
    // background processors (outbox, snooze, OOO, rules) and every flat-key
    // endpoint target it too. Best-effort; the per-request acct param already
    // routes the foreground calls.
    async function persistActiveAccount(acctId) {
        if (!acctId) return;
        try { await accountApi('switch', { id: acctId }); } catch (e) {}
    }
    // Group a set of uids by their owning (account, folder) so an action can fan
    // out one request per group. In a single-account folder view every message
    // shares the same pair; in the unified view they may differ.
    function groupTargets(uids) {
        const groups = new Map();
        for (const uid of uids) {
            const m = state.messages.find(x => x.uid === uid);
            const acct   = (m && m.acct)   || state.currentAccount || '';
            const folder = (m && m.folder) || state.currentFolder;
            const key = acct + ' ' + folder;
            if (!groups.has(key)) groups.set(key, { acct: acct, folder: folder, uids: [] });
            groups.get(key).uids.push(uid);
        }
        return Array.from(groups.values());
    }

    /* ---------- Accounts + folders (Outlook-style grouped sidebar) ---------- */
    function initAccounts() {
        state.accounts = Array.isArray(window.__ACCOUNTS__) ? window.__ACCOUNTS__.slice() : [];
        state.primaryAccount = window.__PRIMARY_ACCOUNT__ || '';
        // Focus the active (logged-in) account by default — same single-account
        // experience as before; the unified view is opt-in via "All accounts".
        const active = window.__ACTIVE_ACCOUNT__ || state.primaryAccount ||
                       (state.accounts[0] && state.accounts[0].id) || '';
        state.currentAccount = active;
        state.currentMsgAcct = active;
        if (active) state.expandedAccounts.add(active);
    }

    // Refresh the focused account's folder list (or the primary account's when
    // viewing the unified inbox), then re-render. Used after actions to keep
    // unread counts and move targets current.
    async function loadFolders() {
        const acct = viewAcct() || state.primaryAccount || '';
        const data = await apiGet('folders', withAcct({}, acct));
        if (data.error) { renderSidebar(); return; }
        state.folders = data.folders || [];
        if (data.delimiter) state.folderDelimiter = data.delimiter;
        if (acct) state.accountFolders[acct] = state.folders;
        renderSidebar();
        renderMoveDropdown();
        loadOutbox(acct); // refresh the Outbox badge (best-effort; local file read, no IMAP)
    }

    // Lazily fetch one account's folders when its group is first expanded.
    async function loadAccountFolders(acctId) {
        const data = await apiGet('folders', withAcct({}, acctId));
        state.accountFolders[acctId] = data.error ? [] : (data.folders || []);
        if (acctId === state.currentAccount && !data.error) {
            state.folders = state.accountFolders[acctId];
            if (data.delimiter) state.folderDelimiter = data.delimiter;
            renderMoveDropdown();
        }
        renderSidebar();
        loadOutbox(acctId); // show this account's Outbox badge once its group is expanded
    }

    function folderDepth(name) {
        if (!name) return 0;
        const parts = name.split(/[.\\/]/);
        return Math.max(0, parts.length - 1);
    }
    function isStandardFolder(name) {
        // Every folder that maps to a known leaf-type is "standard" and protected from delete.
        // Folders that classify as 'folder' are user-created and deletable.
        return folderType(name) !== 'folder';
    }
    function accountUnread(acctId) {
        const fs = state.accountFolders[acctId];
        if (!fs) return 0;
        let n = 0;
        for (const f of fs) { if (folderType(f.name) !== 'drafts') n += (f.unread || 0); }
        return n;
    }
    function folderItemHtml(f, acctId) {
        const isActive = !state.searchActive && acctId === state.currentAccount && f.name === state.currentFolder;
        const type  = folderType(f.name);
        const depth = folderDepth(f.name);
        const userFolder = !isStandardFolder(f.name);
        // Outlook convention: Drafts shows total count; everything else unread.
        const count = (type === 'drafts') ? (f.total || 0) : (f.unread || 0);
        const badge = count > 0 ? '<span class="folder-badge">' + count + '</span>' : '';
        const indentStyle = ' style="padding-left:' + (30 + depth * 14) + 'px"';
        return (
            '<button class="folder-item' + (isActive ? ' active' : '') + '" data-folder="' + escapeAttr(f.name) + '" data-acct="' + escapeAttr(acctId) + '" data-folder-type="' + type + '" data-folder-user="' + (userFolder ? '1' : '0') + '" data-folder-depth="' + depth + '"' + indentStyle + '>' +
                '<svg class="icon folder-icon"><use href="#' + folderIcon(f.name) + '"/></svg>' +
                '<span class="folder-label">' + escapeHtml(displayFolderName(f.name)) + '</span>' +
                badge +
            '</button>'
        );
    }
    function renderSidebar() {
        const accounts = state.accounts;
        let html = '';

        // Unified "All accounts" entry — only meaningful with 2+ accounts.
        if (accounts.length > 1) {
            html +=
                '<button class="acct-unified' + (state.currentAccount === '' ? ' active' : '') + '" data-unified="1">' +
                    '<svg class="icon folder-icon"><use href="#ic-inbox"/></svg>' +
                    '<span class="folder-label">All accounts</span>' +
                '</button>';
        }

        for (const a of accounts) {
            const expanded  = state.expandedAccounts.has(a.id);
            const isCurrent = a.id === state.currentAccount;
            const folders   = state.accountFolders[a.id] || null;
            const name      = a.name || a.email;
            const unread    = accountUnread(a.id);

            html += '<div class="acct-group' + (expanded ? ' expanded' : '') + (isCurrent ? ' current' : '') + '" data-acct="' + escapeAttr(a.id) + '">';
            html +=   '<div class="acct-group-header" data-acct-header="' + escapeAttr(a.id) + '" role="button" tabindex="0" aria-expanded="' + (expanded ? 'true' : 'false') + '">';
            html +=     '<svg class="icon acct-chev" width="11" height="11"><use href="#ic-chev-down"/></svg>';
            html +=     '<div class="acct-identity">';
            html +=       '<span class="acct-name">' + escapeHtml(name) + '</span>';
            html +=       '<span class="acct-email">' + escapeHtml(a.email) + '</span>';
            html +=     '</div>';
            if (unread > 0) html += '<span class="acct-unread">' + unread + '</span>';
            if (!a.primary) {
                html += '<button class="acct-remove" data-acct-remove="' + escapeAttr(a.id) + '" title="Remove account" aria-label="Remove account" tabindex="-1">' +
                            '<svg class="icon" width="12" height="12"><use href="#ic-x"/></svg>' +
                        '</button>';
            }
            html +=   '</div>';
            if (expanded) {
                html += '<div class="acct-folders">';
                if (!folders) {
                    html += '<div class="acct-folders-loading">Loading…</div>';
                } else if (folders.length === 0) {
                    html += '<div class="acct-folders-loading">No folders</div>';
                } else {
                    html += folders.map(f => folderItemHtml(f, a.id)).join('');
                }
                html += outboxItemHtml(a.id); // virtual Outbox folder (waiting/failed sends)
                html += '</div>';
            }
            html += '</div>';
        }

        html +=
            '<button class="acct-add" data-add-account="1">' +
                '<svg class="icon" width="13" height="13"><use href="#ic-plus"/></svg>' +
                '<span>Add account</span>' +
            '</button>';

        $('folderList').innerHTML = html;
    }
    // Back-compat alias: existing callers that updated "the folder list" now
    // re-render the whole grouped sidebar.
    // Surface total inbox-unread in the tab title and the installed-PWA app badge
    // so new mail is noticeable while the tab is backgrounded. Additive + silent:
    // no permission prompt, degrades to a no-op where setAppBadge is unsupported.
    const BASE_TITLE = document.title;
    function inboxUnreadCount() {
        let n = 0;
        for (const f of (state.folders || [])) { if (folderType(f.name) === 'inbox') n += (f.unread || 0); }
        return n;
    }
    function applyUnreadIndicators() {
        const n = inboxUnreadCount();
        document.title = n > 0 ? '(' + n + ') ' + BASE_TITLE : BASE_TITLE;
        try {
            if (navigator.setAppBadge) { if (n > 0) navigator.setAppBadge(n); else navigator.clearAppBadge(); }
        } catch (e) {}
    }
    function notifySupported() { return typeof Notification !== 'undefined'; }
    function notifyEnabled() {
        return !!(window.__PREFS__ && window.__PREFS__.notifications) && notifySupported() && Notification.permission === 'granted';
    }
    // Fire a desktop notification for new inbox mail — but never while the user is
    // actively looking at the app (visible AND focused); that would just be noise.
    function maybeNotifyNewMail(delta, totalUnread) {
        if (delta <= 0 || !notifyEnabled()) return;
        const inFocus = !document.hidden && (document.hasFocus ? document.hasFocus() : true);
        if (inFocus) return;
        const brand = (BASE_TITLE.split('·').pop() || '').trim() || 'Mail';
        try {
            const n = new Notification(brand, {
                body: (delta === 1 ? 'You have a new message' : 'You have ' + delta + ' new messages') + ' · ' + totalUnread + ' unread',
                tag: 'wm-newmail', renotify: true,
            });
            n.onclick = function () { try { window.focus(); n.close(); } catch (e) {} };
        } catch (e) {}
    }
    function renderFolders() { renderSidebar(); applyUnreadIndicators(); }
    function renderMoveDropdown() {
        const html = state.folders
            .filter(f => f.name !== state.currentFolder)
            .map(f =>
                '<button class="dropdown-item" data-target="' + escapeAttr(f.name) + '">' +
                    '<svg class="icon"><use href="#' + folderIcon(f.name) + '"/></svg>' +
                    '<span>' + escapeHtml(displayFolderName(f.name)) + '</span>' +
                '</button>'
            ).join('');
        $('moveDropdown').innerHTML = html;
    }

    /* ---------- Account selection ---------- */
    // Shared reset when the focused account changes: clear any selection, drop
    // the open message, and remember the new owner for compose/reply defaults.
    function focusAccountState(acctId) {
        state.currentAccount = acctId;
        state.currentMsgAcct = acctId;
        state.currentPage = 1;
        state.searchQuery = '';
        if ($('searchInput')) $('searchInput').value = '';
        state.searchActive = false;
        state.listFilter = 'all';
        state.selectedUids.clear();
        state.lastSelectedIndex = -1;
        clearReadingPane();
    }

    // "All accounts": the unified inbox. No single account is focused.
    function selectUnified() {
        if (state.currentAccount === '') return;
        focusAccountState('');
        state.currentFolder = 'INBOX';
        renderSidebar();
        renderMoveDropdown();
        loadMessages();
    }

    // Click on an account's header row: toggle its folder group open/closed, and
    // when opening an account that isn't the focused one, focus it (load its
    // INBOX) and persist it as the active account.
    async function onAccountHeaderClick(acctId) {
        const nowExpanded = !state.expandedAccounts.has(acctId);
        if (nowExpanded) state.expandedAccounts.add(acctId);
        else state.expandedAccounts.delete(acctId);

        const switching = nowExpanded && acctId !== state.currentAccount;
        if (switching) {
            focusAccountState(acctId);
            state.currentFolder = 'INBOX';
            persistActiveAccount(acctId);
        }
        renderSidebar();

        if (nowExpanded && !state.accountFolders[acctId]) {
            await loadAccountFolders(acctId);
        } else if (switching) {
            state.folders = state.accountFolders[acctId] || state.folders;
            renderMoveDropdown();
        }
        if (switching) loadMessages();
    }

    // Click on a folder inside an account group: focus that account (if needed)
    // then open the folder.
    async function selectFolder(acctId, folderName) {
        const switching = acctId && acctId !== state.currentAccount;
        if (switching) {
            focusAccountState(acctId);
            persistActiveAccount(acctId);
            if (!state.accountFolders[acctId]) await loadAccountFolders(acctId);
            state.folders = state.accountFolders[acctId] || state.folders;
        } else if (folderName === state.currentFolder) {
            return;
        }
        state.currentAccount = acctId || state.currentAccount;
        state.currentMsgAcct = state.currentAccount;
        state.currentFolder = folderName;
        state.currentPage = 1;
        state.searchQuery = '';
        if ($('searchInput')) $('searchInput').value = '';
        state.searchActive = false;
        state.listFilter = 'all';
        state.selectedUids.clear();
        state.lastSelectedIndex = -1;
        renderSidebar();
        renderMoveDropdown();
        loadMessages();
    }

    async function removeAccount(acctId) {
        const a = state.accounts.find(x => x.id === acctId);
        const label = a ? (a.name || a.email) : 'this account';
        if (!window.confirm('Remove ' + label + '? Its credentials are dropped from this session; the mailbox itself is untouched.')) return;
        const data = await accountApi('remove', { id: acctId });
        if (data.error) { alert(data.error); return; }
        state.accounts = Array.isArray(data.accounts) ? data.accounts : state.accounts.filter(x => x.id !== acctId);
        state.expandedAccounts.delete(acctId);
        delete state.accountFolders[acctId];
        // If we were viewing the removed account, fall back to the active one.
        if (state.currentAccount === acctId) {
            const fallback = data.active || state.primaryAccount || (state.accounts[0] && state.accounts[0].id) || '';
            focusAccountState(fallback);
            state.currentFolder = 'INBOX';
            state.expandedAccounts.add(fallback);
            await loadFolders();
            loadMessages();
        } else {
            renderSidebar();
        }
    }

    /* ---------- Add-account modal ---------- */
    function openAddAccount() {
        const m = $('addAccountModal');
        if (!m) return;
        $('addAccountForm').reset();
        $('aaStatus').textContent = '';
        $('aaStatus').className = 'acct-form-status';
        $('aaAdvanced').classList.add('hidden');
        $('aaAdvancedToggle').setAttribute('aria-expanded', 'false');
        const submit = $('aaSubmit');
        submit.disabled = false;
        submit.querySelector('.aa-submit-label').textContent = 'Add account';
        m.classList.remove('hidden');
        m.setAttribute('aria-hidden', 'false');
        modalTrap.activate(m, { focus: false });
        setTimeout(() => $('aaEmail').focus(), 30);
    }
    function closeAddAccount() {
        const m = $('addAccountModal');
        if (!m) return;
        m.classList.add('hidden');
        m.setAttribute('aria-hidden', 'true');
        modalTrap.deactivate(m);
    }
    async function submitAddAccount(e) {
        if (e) e.preventDefault();
        const status = $('aaStatus');
        const submit = $('aaSubmit');
        const label  = submit.querySelector('.aa-submit-label');
        const email    = $('aaEmail').value.trim();
        const password = $('aaPassword').value;
        if (!email || !password) {
            status.className = 'acct-form-status error';
            status.textContent = 'Enter the email address and password.';
            return;
        }
        status.className = 'acct-form-status';
        status.textContent = 'Verifying…';
        submit.disabled = true;
        label.textContent = 'Verifying…';

        // Optional advanced overrides; blank fields let the server infer them
        // exactly as the login screen does.
        const data = await accountApi('add', {
            email,
            password,
            display_name: $('aaName').value.trim(),
            imap_host: $('aaImapHost').value.trim(),
            imap_port: $('aaImapPort').value.trim(),
            imap_ssl: $('aaImapSsl').checked ? '1' : '0',
            smtp_host: $('aaSmtpHost').value.trim(),
        });

        if (data.error) {
            status.className = 'acct-form-status error';
            status.textContent = data.error;
            submit.disabled = false;
            label.textContent = 'Add account';
            return;
        }

        state.accounts = Array.isArray(data.accounts) ? data.accounts : state.accounts;
        closeAddAccount();
        // Focus the freshly added account so the user lands in its inbox.
        const newId = data.added;
        if (newId) {
            state.expandedAccounts.add(newId);
            focusAccountState(newId);
            state.currentFolder = 'INBOX';
            persistActiveAccount(newId);
            await loadFolders();
            loadMessages();
        } else {
            renderSidebar();
        }
    }

    /* ---------- Folder pane ---------- */
    function toggleFolderPane() {
        const main = $('appMain');
        const isMobile = window.matchMedia('(max-width: 820px)').matches;
        if (isMobile) {
            main.classList.toggle('mobile-folders-open');
        } else {
            main.classList.toggle('folders-collapsed');
        }
    }

    /* ---------- Messages list ---------- */
    let loadMessagesSeq = 0;

    // After the list paints, probe which visible messages actually carry an
    // attachment and fill the paperclips in. Kept off the initial render so the
    // inbox appears immediately. Only un-probed UIDs are requested, so silent
    // polls cost nothing unless new mail arrived. Not used for unified/search.
    async function loadAttachFlags(seq) {
        if (isUnifiedView() || state.searchActive) return;
        // Probe only the representative (shown) UID of each conversation — one
        // structure fetch per visible row, not per thread member. msgHasAttachment
        // matches on it via thread_uids (the representative is always a member).
        const want = [];
        for (const m of state.messages) {
            const u = m.uid;
            if (u == null || state.attachChecked.has(u)) continue;
            want.push(u);
        }
        if (!want.length) return;
        const data = await apiGet('attach_flags',
            withAcct({ folder: state.currentFolder, uids: want.slice(0, 200).join(',') }, viewAcct()));
        want.forEach(u => state.attachChecked.add(u)); // remember (incl. negatives) so we don't re-ask
        if (seq !== loadMessagesSeq) return;            // a newer load superseded us
        if (!data || data.error || !data.flags) return;
        let changed = false;
        for (const k in data.flags) { if (!state.attachFlags[k]) { state.attachFlags[k] = true; changed = true; } }
        if (changed) renderMessages();
    }

    async function loadMessages(opts) {
        opts = opts || {};
        // The Outbox is a virtual folder backed by data/outbox/, not IMAP — render
        // it here so every existing caller (folder switch, poll, refresh) does the
        // right thing without touching the mail server.
        if (state.currentFolder === OUTBOX_FOLDER) { await renderOutboxView(opts); return; }
        const keepReading = !!opts.keepReading;
        const silent = !!opts.silent;
        const seq = ++loadMessagesSeq;
        // A fresh (non-silent) view drops stale paperclip flags; a silent poll
        // keeps them so only newly-arrived messages get re-probed.
        if (!silent) { state.attachFlags = {}; state.attachChecked = new Set(); }
        const list = $('messageList');
        const prevScroll = silent ? list.scrollTop : 0;
        const prevUids = silent ? new Set(state.messages.map(m => m.uid)) : null;
        const unified = isUnifiedView();
        const viewLabel = unified ? 'All accounts' : displayFolderName(state.currentFolder);
        if (!silent) {
            $('listFolderName').textContent = viewLabel + ' · loading…';
            list.innerHTML = '<div class="list-loading">Loading messages…</div>';
            $('pagination').hidden = true;
        }

        const data = unified
            ? await apiGet('unified', {})
            : await apiGet('messages', withAcct({ folder: state.currentFolder, page: state.currentPage }, viewAcct()));
        if (seq !== loadMessagesSeq) return; // a newer load (folder/page switch) superseded this one
        if (data.error) {
            if (!silent) {
                list.innerHTML = '<div class="list-empty">' + escapeHtml(data.error) + '</div>';
                $('listFolderName').textContent = viewLabel;
            }
            return;
        }
        const msgs = data.messages || [];
        // Stamp owning account + folder onto every row so subsequent opens and
        // actions route to the correct mailbox. Unified rows already carry these
        // from the server; per-account rows inherit the focused context.
        for (const m of msgs) {
            if (!m.acct)   m.acct   = unified ? (m.acct || '') : viewAcct();
            if (!m.folder) m.folder = unified ? 'INBOX' : state.currentFolder;
        }
        state.messages = msgs;
        state.totalPages = data.pages || 1;
        state.totalMessages = data.total || 0;
        state.currentPage = data.page || 1;
        $('listFolderName').textContent =
            viewLabel + ' · ' +
            data.total + (data.total === 1 ? ' message' : ' messages');
        renderMessages();
        renderPagination();
        loadAttachFlags(seq); // fire-and-forget; fills paperclips once the list is up
        if (!keepReading) clearReadingPane();
        if (silent) {
            list.scrollTop = prevScroll;
            // Briefly highlight rows that weren't there before this refresh
            if (prevUids) {
                state.messages.forEach(m => {
                    if (!prevUids.has(m.uid)) {
                        const row = list.querySelector('.msg-item[data-uid="' + m.uid + '"]');
                        if (row) {
                            row.classList.add('msg-item-fresh');
                            setTimeout(() => row.classList.remove('msg-item-fresh'), 1800);
                        }
                    }
                });
            }
        }
    }

    function filterMessages(msgs) {
        let out = msgs.slice();
        if (state.searchQuery) {
            const q = state.searchQuery.toLowerCase();
            out = out.filter(m =>
                (m.subject || '').toLowerCase().includes(q) ||
                (m.from_name || '').toLowerCase().includes(q) ||
                (m.from_addr || '').toLowerCase().includes(q)
            );
        }
        return out;
    }

    function renderSearchResults() {
        const q = state.searchQuery;
        const results = state.searchResults;
        toggleFilterPane(false);
        $('listFolderName').textContent =
            'Search "' + q + '" · ' + results.length + ' result' + (results.length === 1 ? '' : 's');

        // Default search is headers-only (fast). Offer a one-click deep search of
        // the full message text when only headers were searched.
        const fullCta = (searchResultScope === 'headers')
            ? '<div class="search-fulltext-row"><button type="button" class="search-fulltext-cta" data-full-search="1">' +
                  '<svg class="icon" width="13" height="13"><use href="#ic-search"/></svg>' +
                  'Search full message text for "' + escapeHtml(q) + '"' +
              '</button></div>'
            : '';

        if (results.length === 0) {
            $('messageList').innerHTML =
                '<div class="list-empty">No matches in subjects, senders, or recipients.</div>' + fullCta;
            $('pagination').hidden = true;
            return;
        }

        let html = '';
        let lastGroup = null;
        for (const m of results) {
            const group = dateGroupLabel(m.timestamp);
            if (group !== lastGroup) {
                html += '<div class="list-group-header">' + escapeHtml(group) + '</div>';
                lastGroup = group;
            }
            const cls = ['msg-item', 'search-result'];
            if (!m.seen) cls.push('unread');
            if (m.uid === state.currentUid) cls.push('active');
            const seed       = m.from_addr || m.from_name || '?';
            const initial    = avatarInitial(m.from_name, m.from_addr);
            const color      = avatarColor(seed);
            const fromDisp   = m.from_name || m.from_addr || '(unknown)';
            const folderTag  = '<span class="msg-folder-badge" data-folder-type="' + folderType(m.folder) + '">' + escapeHtml(displayFolderName(m.folder)) + '</span>';
            html +=
                '<div class="' + cls.join(' ') + '" data-uid="' + m.uid + '" data-folder="' + escapeAttr(m.folder) + '">' +
                    '<span class="msg-dot"></span>' +
                    '<div class="msg-avatar" style="background:' + color + '">' + escapeHtml(initial) + '</div>' +
                    '<div class="msg-body">' +
                        '<div class="msg-row1">' +
                            '<span class="msg-from">' + escapeHtml(fromDisp) + folderTag + '</span>' +
                            '<span class="msg-meta">' +
                                '<span class="msg-time">' + escapeHtml(formatTime(m.timestamp)) + '</span>' +
                            '</span>' +
                        '</div>' +
                        '<div class="msg-subject">' + escapeHtml(m.subject || '(no subject)') + '</div>' +
                    '</div>' +
                '</div>';
        }
        $('messageList').innerHTML = html + fullCta;
        $('pagination').hidden = true;
    }

    async function runServerSearch(q, scope) {
        scope = scope || 'headers'; // default = fast header search (subject/from/to)
        const cacheKey = scope + '|' + q.toLowerCase();
        if (searchCache.has(cacheKey)) {
            state.searchActive  = true;
            searchResultScope   = scope;
            state.searchResults = searchCache.get(cacheKey);
            renderSearchResults();
            return;
        }

        if (searchAbortCtrl) searchAbortCtrl.abort();
        searchAbortCtrl = new AbortController();

        $('listFolderName').textContent = 'Searching for "' + q + '"…';
        $('messageList').innerHTML = '<div class="list-loading">' +
            (scope === 'full' ? 'Searching full message text…' : 'Searching Inbox &amp; Sent…') + '</div>';
        $('pagination').hidden = true;

        try {
            const url = 'ajax/fetch.php?action=search&q=' + encodeURIComponent(q) +
                        (scope === 'full' ? '&scope=full' : '');
            const r = await fetch(url, { credentials: 'same-origin', signal: searchAbortCtrl.signal });
            if (r.status === 401) { window.location = 'index'; return; }
            const data = await r.json();
            if (data.error) {
                $('messageList').innerHTML = '<div class="list-empty">Search failed: ' + escapeHtml(data.error) + '</div>';
                return;
            }
            const results = data.results || [];
            // LRU-ish cap of 20 cached searches per session (keyed by scope+query)
            if (searchCache.size >= 20) searchCache.delete(searchCache.keys().next().value);
            searchCache.set(cacheKey, results);
            state.searchActive  = true;
            searchResultScope   = scope;
            state.searchResults = results;
            if (state.searchQuery === q) renderSearchResults(); // ignore stale response
        } catch (e) {
            if (e.name !== 'AbortError') {
                $('messageList').innerHTML = '<div class="list-empty">Search error: ' + escapeHtml(e.message) + '</div>';
            }
        }
    }

    function exitSearchMode() {
        if (!state.searchActive && state.searchResults.length === 0) return;
        state.searchActive  = false;
        state.searchResults = [];
        renderMessages();
    }

    // Attachment presence is resolved lazily (loadAttachFlags) so the list can
    // render instantly without a per-message IMAP structure fetch. A row counts
    // as having an attachment if any message in its thread does.
    function msgHasAttachment(m) {
        if (!m) return false;
        if (m.has_attachments) return true; // unified/search rows may carry it inline
        const uids = (m.thread_uids && m.thread_uids.length) ? m.thread_uids : [m.uid];
        for (const u of uids) { if (state.attachFlags[u]) return true; }
        return false;
    }

    function applyListFilter(msgs) {
        switch (state.listFilter) {
            case 'unread':      return msgs.filter(m => !m.seen);
            case 'flagged':     return msgs.filter(m => m.flagged);
            case 'attachments': return msgs.filter(m => msgHasAttachment(m));
            default:            return msgs;
        }
    }

    function updateFilterChips() {
        const chips = document.querySelectorAll('#listFilters .list-filter-chip');
        if (!chips.length) return;
        const counts = {
            all:         state.messages.length,
            unread:      state.messages.filter(m => !m.seen).length,
            flagged:     state.messages.filter(m => m.flagged).length,
            attachments: state.messages.filter(m => msgHasAttachment(m)).length,
        };
        chips.forEach(chip => {
            const f = chip.dataset.filter;
            chip.classList.toggle('active', f === state.listFilter);
            const c = counts[f];
            let badge = chip.querySelector('.list-filter-count');
            if (c > 0 && f !== 'all') {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'list-filter-count';
                    chip.appendChild(badge);
                }
                badge.textContent = c;
            } else if (badge) {
                badge.remove();
            }
        });
    }

    function toggleFilterPane(visible) {
        const filterPane = $('listFilters');
        if (filterPane) filterPane.style.display = visible ? '' : 'none';
    }

    function renderMessages() {
        if (state.searchActive) { renderSearchResults(); return; }
        toggleFilterPane(true);
        updateFilterChips();
        const baseFiltered = filterMessages(state.messages);
        const filtered = applyListFilter(baseFiltered);
        if (filtered.length === 0) {
            let reason;
            if (state.searchQuery)               reason = 'No messages match your search.';
            else if (state.listFilter !== 'all') reason = 'No ' + state.listFilter + ' messages on this page.';
            else                                 reason = 'This folder is empty.';
            $('messageList').innerHTML = '<div class="list-empty">' + reason + '</div>';
            return;
        }

        const showPinnedSection = state.listFilter === 'all' || state.listFilter === 'flagged';
        const pinned = showPinnedSection ? filtered.filter(m => m.flagged) : [];
        const others = showPinnedSection ? filtered.filter(m => !m.flagged) : filtered;

        let html = '';
        if (pinned.length) {
            html += '<div class="list-group-header">Pinned</div>';
            for (const m of pinned) html += renderMsgItem(m);
        }
        let lastGroup = null;
        for (const m of others) {
            const group = dateGroupLabel(m.timestamp);
            if (group !== lastGroup) {
                html += '<div class="list-group-header">' + escapeHtml(group) + '</div>';
                lastGroup = group;
            }
            html += renderMsgItem(m);
        }
        $('messageList').innerHTML = html;
        $('messageList').classList.toggle('msg-list-selecting', state.selectedUids.size > 0);
        updateSelectionBar();
    }

    function renderMsgItem(m) {
        const cls = ['msg-item'];
        if (!m.seen) cls.push('unread');
        if (m.uid === state.currentUid) cls.push('active');
        if (m.flagged) cls.push('pinned');
        if (m.thread_count > 1) cls.push('grouped');
        if (state.selectedUids.has(m.uid)) cls.push('selected');

        const seed    = m.from_addr || m.from_name || '?';
        const initial = avatarInitial(m.from_name, m.from_addr);
        const color   = avatarColor(seed);
        const fromDisplay = m.from_name || m.from_addr || '(unknown)';

        let indicators = '';
        if (msgHasAttachment(m)) indicators += '<svg class="msg-indicator" width="12" height="12"><use href="#ic-paperclip"/></svg>';
        if (m.answered)        indicators += '<svg class="msg-indicator" width="12" height="12"><use href="#ic-replied"/></svg>';
        if (m.flagged)         indicators += '<svg class="msg-indicator msg-indicator-pin" width="12" height="12"><use href="#ic-pin"/></svg>';

        const countBadge = m.thread_count > 1
            ? '<span class="msg-thread-count" title="' + m.thread_count + ' messages in conversation">' + m.thread_count + '</span>'
            : '';

        // In the unified inbox, tag each row with the owning account so the
        // reader can tell at a glance which mailbox a message landed in. The
        // colour keys off the account id so it stays stable per account.
        let acctBadge = '';
        if (isUnifiedView()) {
            const a = state.accounts.find(x => x.id === m.acct);
            const label = a ? (a.name || a.email) : (m.acct_email || '');
            if (label) {
                acctBadge = '<span class="msg-acct-badge" style="--acct-color:' + avatarColor(m.acct || label) + '">' +
                            escapeHtml(label) + '</span>';
            }
        }

        const rowAttrs = ' data-uid="' + m.uid + '"' +
                         ' data-acct="' + escapeAttr(m.acct || '') + '"' +
                         ' data-folder="' + escapeAttr(m.folder || state.currentFolder) + '"';

        return (
            '<div class="' + cls.join(' ') + '"' + rowAttrs + ' draggable="true">' +
                '<span class="msg-dot"></span>' +
                '<button class="msg-select" type="button" data-action="select" tabindex="-1" aria-label="Select message">' +
                    '<span class="msg-avatar" style="background:' + color + '">' + escapeHtml(initial) + '</span>' +
                    '<svg class="msg-select-check" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>' +
                '</button>' +
                '<div class="msg-body">' +
                    '<div class="msg-row1">' +
                        '<span class="msg-from">' + escapeHtml(fromDisplay) + countBadge + '</span>' +
                        '<span class="msg-meta">' +
                            indicators +
                            '<span class="msg-time">' + escapeHtml(formatTime(m.timestamp)) + '</span>' +
                        '</span>' +
                    '</div>' +
                    '<div class="msg-subject">' + escapeHtml(m.subject || '(no subject)') + acctBadge + '</div>' +
                    '<div class="msg-preview">' + escapeHtml(m.preview || '') + '</div>' +
                '</div>' +
            '</div>'
        );
    }

    function renderPagination() {
        const pg = $('pagination');
        if (state.totalPages <= 1) { pg.hidden = true; return; }
        pg.hidden = false;
        $('prevPage').disabled = state.currentPage <= 1;
        $('nextPage').disabled = state.currentPage >= state.totalPages;
        $('pagerInfo').textContent = 'Page ' + state.currentPage + ' of ' + state.totalPages;
    }

    /* ---------- Reading pane ---------- */
    function clearReadingPane() {
        state.currentUid = null;
        state.currentMessage = null;
        $('readingEmpty').classList.remove('hidden');
        $('readingContent').classList.add('hidden');
        $('readingPane').classList.remove('mobile-active');
        updateUnsubscribeBtn(null);
        updateActionBar();
    }

    let loadMessageSeq = 0;
    async function loadMessage(uid) {
        const seq = ++loadMessageSeq;
        state.currentUid = uid;
        renderMessages();

        // Route the thread fetch to the mailbox that actually owns this row.
        // In the unified inbox each row carries its own acct/folder; in a single
        // account view they fall back to the focused account/current folder.
        const srcMsg   = state.messages.find(x => x.uid === uid);
        const msgAcct  = (srcMsg && srcMsg.acct)   || viewAcct();
        const msgFolder = (srcMsg && srcMsg.folder) || state.currentFolder;
        state.currentMsgAcct = msgAcct;

        $('readingEmpty').classList.add('hidden');
        $('readingContent').classList.remove('hidden');
        $('readingPane').classList.add('mobile-active');
        $('readSubject').textContent = '';
        $('readThreadSummary').hidden = true;
        $('readThread').innerHTML = '<div class="list-loading" style="text-align:left;padding:8px 0;">Loading conversation…</div>';

        const data = await apiGet('thread', withAcct({ folder: msgFolder, uid }, msgAcct));
        if (seq !== loadMessageSeq) return; // a newer message open superseded this one
        if (data.error) {
            $('readThread').innerHTML = '<div style="color:var(--c-error)">' + escapeHtml(data.error) + '</div>';
            updateActionBar();
            return;
        }

        const thread = data.thread || [];
        const focused = thread.find(m => m.uid === uid && (!m.folder || m.folder === msgFolder)) || thread[0];
        state.currentThread = thread;
        state.currentMessage = focused;

        renderThread(data, focused);
        updateUnsubscribeBtn(focused);

        const m = state.messages.find(x => x.uid === uid);
        if (m && !m.seen) {
            m.seen = true;
            renderMessages();
            // Decrement the unread badge on the owning account's folder (its own
            // cached folder list, which the grouped sidebar renders from).
            const fs = (msgAcct && state.accountFolders[msgAcct]) || state.folders;
            const folder = fs.find(f => f.name.toLowerCase() === msgFolder.toLowerCase());
            if (folder && folder.unread > 0) { folder.unread--; renderSidebar(); }
        }
        updateActionBar();
    }

    function attachIconClass(type, ext) {
        if (type && type.startsWith('image/')) return 'att-icon-img';
        const e = (ext || '').toLowerCase();
        if (e === 'pdf') return 'att-icon-pdf';
        if (['doc','docx','rtf','odt','pages'].includes(e)) return 'att-icon-doc';
        if (['xls','xlsx','csv','tsv','ods','numbers'].includes(e)) return 'att-icon-xls';
        if (['ppt','pptx','odp','key'].includes(e)) return 'att-icon-ppt';
        if (['zip','rar','7z','tar','gz','bz2'].includes(e)) return 'att-icon-zip';
        if (['mp3','wav','m4a','flac','ogg','aac'].includes(e)) return 'att-icon-aud';
        if (['mp4','mov','avi','mkv','webm','wmv'].includes(e)) return 'att-icon-vid';
        if (['txt','md','log'].includes(e)) return 'att-icon-txt';
        return 'att-icon-generic';
    }
    function attachExtLabel(name, type) {
        const ext = (name || '').split('.').pop();
        if (ext && ext.length <= 5 && ext !== name) return ext.toUpperCase();
        if (type && type.indexOf('/') > 0) return type.split('/')[1].slice(0, 4).toUpperCase();
        return 'FILE';
    }
    function attachUrl(folder, uid, section, preview, acct) {
        const params = new URLSearchParams({
            action: 'attachment',
            folder: folder,
            uid: String(uid),
            section: String(section),
        });
        if (preview) params.append('preview', '1');
        if (acct) params.append('acct', acct);
        return 'ajax/fetch.php?' + params.toString();
    }
    function renderMsgAttachments(msg) {
        if (!Array.isArray(msg.attachments) || msg.attachments.length === 0) return '';
        const visible = msg.attachments.filter(a => !(a.inline && a.content_id));
        if (visible.length === 0) return '';

        const cards = visible.map((a, idx) => {
            const acct       = msg.acct || state.currentMsgAcct || '';
            const ext        = (a.name || '').split('.').pop().toLowerCase();
            const iconCls    = attachIconClass(a.type, ext);
            const isImage    = a.type && a.type.indexOf('image/') === 0;
            const dlHref     = attachUrl(msg.folder, msg.uid, a.section, false, acct);
            const previewUrl = attachUrl(msg.folder, msg.uid, a.section, true, acct);
            const sizeStr    = formatBytes(a.size || 0);
            const safeName   = escapeHtml(a.name);
            // Stash the metadata directly on the card so the click handler
            // doesn't have to re-derive it from msg.attachments.
            const dataAttrs = ' data-attach-name="' + escapeAttr(a.name) +
                              '" data-attach-type="' + escapeAttr(a.type || '') +
                              '" data-attach-section="' + escapeAttr(a.section) +
                              '" data-attach-folder="' + escapeAttr(msg.folder) +
                              '" data-attach-uid="' + msg.uid +
                              '" data-attach-acct="' + escapeAttr(acct) +
                              '" data-attach-size="' + (a.size || 0) + '"';

            if (isImage) {
                return (
                    '<button class="msg-attachment image" type="button"' + dataAttrs + '>' +
                        '<div class="msg-attachment-thumb" style="background-image:url(\'' + previewUrl + '\')"></div>' +
                        '<div class="msg-attachment-meta">' +
                            '<div class="msg-attachment-name" title="' + escapeAttr(a.name) + '">' + safeName + '</div>' +
                            '<div class="msg-attachment-size">' + escapeHtml(sizeStr) + '</div>' +
                        '</div>' +
                    '</button>'
                );
            }
            return (
                '<button class="msg-attachment" type="button"' + dataAttrs + '>' +
                    '<div class="msg-attachment-icon ' + iconCls + '">' + escapeHtml(attachExtLabel(a.name, a.type)) + '</div>' +
                    '<div class="msg-attachment-meta">' +
                        '<div class="msg-attachment-name" title="' + escapeAttr(a.name) + '">' + safeName + '</div>' +
                        '<div class="msg-attachment-size">' + escapeHtml(sizeStr) + '</div>' +
                    '</div>' +
                    '<a class="msg-attachment-dl" href="' + dlHref + '" download="' + escapeAttr(a.name) + '" target="_blank" rel="noopener" title="Download" data-stop="1">' +
                        '<svg class="icon" width="14" height="14"><use href="#ic-download"/></svg>' +
                    '</a>' +
                '</button>'
            );
        }).join('');

        const headerLabel = visible.length === 1 ? '1 attachment' : visible.length + ' attachments';
        return (
            '<div class="msg-attachments-wrap">' +
                '<div class="msg-attachments-header">' +
                    '<svg class="icon" width="14" height="14"><use href="#ic-paperclip"/></svg>' +
                    '<span>' + headerLabel + '</span>' +
                '</div>' +
                '<div class="msg-attachments">' + cards + '</div>' +
            '</div>'
        );
    }

    /* ---------- Attachment preview ---------- */
    function loadCDNScript(url, globalKey, errorLabel) {
        if (window[globalKey]) return Promise.resolve(window[globalKey]);
        const cacheKey = '_promise_' + globalKey;
        if (window[cacheKey]) return window[cacheKey];
        window[cacheKey] = new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = url;
            s.async = true;
            s.onload = () => window[globalKey] ? resolve(window[globalKey]) : reject(new Error(errorLabel + ' failed to initialise'));
            s.onerror = () => { delete window[cacheKey]; reject(new Error('Failed to load ' + errorLabel)); };
            document.head.appendChild(s);
        });
        return window[cacheKey];
    }
    const loadSheetJS = () => loadCDNScript('https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js', 'XLSX', 'spreadsheet viewer');
    const loadJSZip = () => loadCDNScript('https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js', 'JSZip', 'archive utility');
    const loadDocxPreview = () => loadJSZip().then(() => loadCDNScript('https://cdn.jsdelivr.net/npm/docx-preview@0.3.5/dist/docx-preview.min.js', 'docx', 'document viewer'));
    function setPreviewBodyClass(body, mode) {
        body.classList.remove('attach-preview-image', 'attach-preview-iframe', 'attach-preview-fallback', 'attach-preview-spreadsheet', 'attach-preview-document');
        if (mode) body.classList.add('attach-preview-' + mode);
    }
    function renderSpreadsheetPreview(body, name, prevUrl) {
        body.innerHTML = '<div class="attach-preview-loading"><div class="spinner dark"></div><span>Loading spreadsheet…</span></div>';
        Promise.all([loadSheetJS(), fetch(prevUrl, { credentials: 'same-origin' }).then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.arrayBuffer();
        })]).then(([XLSX, buf]) => {
            const wb = XLSX.read(buf, { type: 'array' });
            const sheets = wb.SheetNames;
            if (!sheets.length) throw new Error('Workbook has no sheets');
            let active = 0;
            const tabsHtml = sheets.length > 1
                ? '<div class="ssheet-tabs">' + sheets.map((n, i) =>
                    '<button class="ssheet-tab' + (i === 0 ? ' active' : '') + '" data-idx="' + i + '" type="button">' + escapeHtml(n) + '</button>'
                ).join('') + '</div>'
                : '';
            const renderSheet = (i) => {
                const html = XLSX.utils.sheet_to_html(wb.Sheets[sheets[i]], { editable: false });
                return '<div class="ssheet-content">' + html + '</div>';
            };
            body.innerHTML = tabsHtml + renderSheet(active);
            if (sheets.length > 1) {
                body.querySelectorAll('.ssheet-tab').forEach(t => {
                    t.addEventListener('click', () => {
                        const i = parseInt(t.dataset.idx, 10);
                        if (i === active) return;
                        active = i;
                        body.querySelectorAll('.ssheet-tab').forEach(x => x.classList.toggle('active', parseInt(x.dataset.idx, 10) === i));
                        const old = body.querySelector('.ssheet-content');
                        if (old) old.remove();
                        body.insertAdjacentHTML('beforeend', renderSheet(i));
                    });
                });
            }
        }).catch(err => {
            renderFallbackPreview(body, name, '', err.message);
        });
    }
    function renderDocumentPreview(body, name, prevUrl) {
        body.innerHTML = '<div class="attach-preview-loading"><div class="spinner dark"></div><span>Loading document…</span></div>';
        Promise.all([
            loadDocxPreview(),
            fetch(prevUrl, { credentials: 'same-origin' }).then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.blob();
            })
        ]).then(([docx, blob]) => {
            body.innerHTML = '';
            const container = document.createElement('div');
            container.className = 'docx-render';
            body.appendChild(container);
            return docx.renderAsync(blob, container, null, {
                inWrapper: true,
                className: 'docx',
                ignoreWidth: false,
                ignoreHeight: false,
                ignoreFonts: false,
                breakPages: true,
                ignoreLastRenderedPageBreak: true,
                useBase64URL: true,
                renderHeaders: true,
                renderFooters: true,
                renderFootnotes: true,
                renderEndnotes: true
            });
        }).catch(err => {
            renderFallbackPreview(body, name, '', err.message);
        });
    }
    function renderFallbackPreview(body, name, type, errorMsg) {
        const ext = (name.split('.').pop() || '').toLowerCase();
        const bg = ({pdf:'#d1342f',doc:'#2a5599',docx:'#2a5599',xls:'#1f7244',xlsx:'#1f7244',ppt:'#d04423',pptx:'#d04423',zip:'#6f4ba0'}[ext] || '#6e6e6e');
        // Tailor the note: legacy/binary Office formats genuinely can't be shown
        // in the browser (only modern .docx/.xlsx and PDF/images preview), so be
        // specific rather than a vague "this file type".
        const officeApp = { doc: 'Word', dot: 'Word', ppt: 'PowerPoint', pps: 'PowerPoint',
                            odt: 'a word processor', odp: 'a presentation app', ods: 'a spreadsheet app',
                            rtf: 'Word' }[ext];
        const note = officeApp
            ? "This older Office format can’t be previewed in the browser. Click <strong>Download</strong> above to open it in " + officeApp + '.'
            : "Preview isn’t available for this file type. Click <strong>Download</strong> above to save it locally.";
        const errLine = errorMsg ? '<p class="attach-preview-error">' + escapeHtml(errorMsg) + '</p>' : '';
        body.innerHTML =
            '<div class="attach-preview-fallback-inner">' +
                '<div class="att-icon-generic" style="width:64px;height:64px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;color:white;font-weight:700;background:' + bg + '">' + escapeHtml(attachExtLabel(name, type)) + '</div>' +
                '<h3>' + escapeHtml(name) + '</h3>' +
                '<p>' + note + '</p>' +
                errLine +
            '</div>';
        setPreviewBodyClass(body, 'fallback');
    }
    function openAttachmentPreview(card) {
        const name    = card.dataset.attachName;
        const type    = card.dataset.attachType || 'application/octet-stream';
        const section = card.dataset.attachSection;
        const folder  = card.dataset.attachFolder;
        const acct    = card.dataset.attachAcct || '';
        const uid     = parseInt(card.dataset.attachUid, 10);
        const size    = parseInt(card.dataset.attachSize, 10) || 0;
        const dlUrl   = attachUrl(folder, uid, section, false, acct);
        const prevUrl = attachUrl(folder, uid, section, true, acct);

        $('attachPreviewName').textContent = name;
        $('attachPreviewMeta').textContent = (type || '') + ' · ' + formatBytes(size);
        const dl = $('attachPreviewDownload');
        dl.href = dlUrl;
        dl.setAttribute('download', name);

        const ext = (name.split('.').pop() || '').toLowerCase();
        const isSpreadsheet = ['xls', 'xlsx', 'xlsm', 'xlsb', 'csv', 'ods', 'tsv'].indexOf(ext) !== -1
            || type === 'application/vnd.ms-excel'
            || type === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            || type === 'application/vnd.oasis.opendocument.spreadsheet'
            || type === 'text/csv';
        const isDocument = ext === 'docx'
            || type === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

        const body = $('attachPreviewBody');
        body.innerHTML = '';
        if (type.indexOf('image/') === 0) {
            setPreviewBodyClass(body, 'image');
            body.innerHTML = '<div class="attach-preview-loading"><div class="spinner"></div><span>Loading…</span></div>';
            const img = new Image();
            img.alt = name;
            img.onload = () => { body.innerHTML = ''; body.appendChild(img); };
            img.onerror = () => renderFallbackPreview(body, name, type);
            img.src = prevUrl;
        } else if (type === 'application/pdf' || ext === 'pdf') {
            setPreviewBodyClass(body, 'iframe');
            body.innerHTML = '<div class="attach-preview-loading"><div class="spinner"></div><span>Loading…</span></div>';
            const f = document.createElement('iframe');
            f.title = name;
            f.onload = () => { const l = body.querySelector('.attach-preview-loading'); if (l) l.remove(); };
            body.appendChild(f);
            f.src = prevUrl;
        } else if (isSpreadsheet) {
            setPreviewBodyClass(body, 'spreadsheet');
            renderSpreadsheetPreview(body, name, prevUrl);
        } else if (isDocument) {
            setPreviewBodyClass(body, 'document');
            renderDocumentPreview(body, name, prevUrl);
        } else if (type === 'text/plain') {
            // The server serves ONLY text/plain inline (any other text/* — notably
            // text/html — is forced to octet-stream + download to prevent script
            // execution on our origin). Sandbox the frame as defense-in-depth.
            const f = document.createElement('iframe');
            f.src = prevUrl;
            f.title = name;
            f.setAttribute('sandbox', '');
            body.appendChild(f);
            setPreviewBodyClass(body, 'iframe');
        } else {
            renderFallbackPreview(body, name, type);
        }

        $('attachPreview').classList.remove('hidden');
        $('attachPreview').setAttribute('aria-hidden', 'false');
    }
    function closeAttachmentPreview() {
        $('attachPreview').classList.add('hidden');
        $('attachPreview').setAttribute('aria-hidden', 'true');
        $('attachPreviewBody').innerHTML = '';
    }

    // Inline caution/suspicious chip shown next to the sender name.
    function trustBadge(trust) {
        if (!trust || !trust.level || trust.level === 'none') return '';
        const label = trust.level === 'danger' ? 'Suspicious' : 'Caution';
        const tip = Array.isArray(trust.reasons) ? trust.reasons.join(' ') : '';
        return '<span class="trust-badge trust-' + escapeAttr(trust.level) + '" title="' + escapeAttr(tip) + '">' +
            '<svg width="12" height="12"><use href="#ic-shield-alert"/></svg>' + label + '</span>';
    }

    // Full warning strip rendered as a sibling of .thread-msg-body so the
    // lazy body-load (which replaces the body's innerHTML) can't wipe it.
    function renderTrustBanner(trust) {
        if (!trust || !trust.level || trust.level === 'none') return '';
        const isDanger = trust.level === 'danger';
        const title = isDanger
            ? 'This message looks like a phishing attempt.'
            : 'Be careful with this message.';
        const reasons = Array.isArray(trust.reasons) ? trust.reasons : [];
        const items = reasons.map(r => '<li>' + escapeHtml(r) + '</li>').join('');
        return (
            '<div class="trust-banner trust-' + escapeAttr(trust.level) + '" role="alert">' +
                '<svg class="trust-banner-icon" width="20" height="20"><use href="#ic-shield-alert"/></svg>' +
                '<div class="trust-banner-text">' +
                    '<div class="trust-banner-title">' + escapeHtml(title) + '</div>' +
                    (items ? '<ul class="trust-banner-reasons">' + items + '</ul>' : '') +
                '</div>' +
            '</div>'
        );
    }

    /* ---------- Calendar invitations (RSVP) ---------- */
    function formatInviteWhen(inv) {
        try {
            const s = new Date(inv.start);
            if (isNaN(s.getTime())) return '';
            const e = inv.end ? new Date(inv.end) : null;
            const dOpts = { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' };
            const tOpts = { hour: 'numeric', minute: '2-digit' };
            if (inv.all_day) return s.toLocaleDateString([], dOpts) + ' · All day';
            const date = s.toLocaleDateString([], dOpts);
            const sTime = s.toLocaleTimeString([], tOpts);
            if (!e || isNaN(e.getTime())) return date + ' · ' + sTime;
            if (s.toDateString() === e.toDateString()) {
                return date + ' · ' + sTime + ' – ' + e.toLocaleTimeString([], tOpts);
            }
            return s.toLocaleString([], Object.assign({}, dOpts, tOpts)) +
                   ' – ' + e.toLocaleString([], Object.assign({}, dOpts, tOpts));
        } catch (_) { return ''; }
    }

    function renderInviteCard(msg) {
        const inv = msg.invite;
        if (!inv) return '';
        const cancelled = inv.method === 'CANCEL';
        const kind = cancelled ? 'Event cancelled'
                   : (inv.method === 'PUBLISH' ? 'Event' : 'Invitation');

        const rows = [];
        const when = formatInviteWhen(inv);
        if (when) rows.push('<div class="invite-row"><span class="invite-row-k">When</span><span class="invite-row-v">' + escapeHtml(when) + '</span></div>');
        if (inv.location) rows.push('<div class="invite-row"><span class="invite-row-k">Where</span><span class="invite-row-v">' + escapeHtml(inv.location) + '</span></div>');
        const org = inv.organizer && (inv.organizer.name || inv.organizer.email);
        if (org) rows.push('<div class="invite-row"><span class="invite-row-k">Organizer</span><span class="invite-row-v">' + escapeHtml(org) + '</span></div>');
        if (inv.attendee_count > 1) rows.push('<div class="invite-row"><span class="invite-row-k">Guests</span><span class="invite-row-v">' + (inv.attendee_count | 0) + '</span></div>');

        const psLabel = { ACCEPTED: 'Going', DECLINED: 'Not going', TENTATIVE: 'Maybe' };
        const ps = (inv.my_partstat || '').toUpperCase();
        const statusHtml = psLabel[ps]
            ? '<div class="invite-status" data-ps="' + ps + '">Your response: ' + psLabel[ps] + '</div>'
            : '';

        const actionsHtml = cancelled ? '' : (
            '<div class="invite-actions">' +
                '<button type="button" class="invite-btn invite-yes" data-rsvp="accept" aria-pressed="' + (ps === 'ACCEPTED') + '">Accept</button>' +
                '<button type="button" class="invite-btn invite-maybe" data-rsvp="tentative" aria-pressed="' + (ps === 'TENTATIVE') + '">Maybe</button>' +
                '<button type="button" class="invite-btn invite-no" data-rsvp="decline" aria-pressed="' + (ps === 'DECLINED') + '">Decline</button>' +
            '</div>'
        );

        return (
            '<div class="invite-card' + (cancelled ? ' invite-cancelled' : '') + '" data-invite-uid="' + (msg.uid | 0) + '" data-invite-folder="' + escapeAttr(msg.folder) + '">' +
                '<div class="invite-head">' +
                    '<svg class="icon invite-ic"><use href="#ic-calendar"/></svg>' +
                    '<div class="invite-headings">' +
                        '<div class="invite-kind">' + escapeHtml(kind) + '</div>' +
                        '<div class="invite-title">' + escapeHtml(inv.summary || '(no title)') + '</div>' +
                    '</div>' +
                '</div>' +
                (rows.length ? '<div class="invite-body">' + rows.join('') + '</div>' : '') +
                statusHtml +
                actionsHtml +
                '<div class="invite-feedback" role="status" hidden></div>' +
            '</div>'
        );
    }

    async function handleRsvp(card, btn) {
        if (!card || card.dataset.busy === '1') return;
        const folder   = card.dataset.inviteFolder;
        const uid      = parseInt(card.dataset.inviteUid, 10);
        const response = btn.dataset.rsvp;
        if (!folder || !uid || !response) return;

        const btns = card.querySelectorAll('.invite-actions button');
        const fb   = card.querySelector('.invite-feedback');
        card.dataset.busy = '1';
        btns.forEach(b => { b.disabled = true; });
        btn.classList.add('is-sending');
        if (fb) { fb.hidden = true; fb.textContent = ''; fb.classList.remove('invite-feedback-err'); }

        try {
            const r = await fetch('ajax/calendar.php?action=rsvp', {
                method: 'POST',
                headers: csrfHeaders({ 'Content-Type': 'application/json' }),
                credentials: 'same-origin',
                body: JSON.stringify({ folder, uid, response }),
            }).then(res => res.json());

            btn.classList.remove('is-sending');
            if (r.error) {
                btns.forEach(b => { b.disabled = false; });
                if (fb) { fb.hidden = false; fb.textContent = r.error; fb.classList.add('invite-feedback-err'); }
                return;
            }

            const ps = (r.partstat || '').toUpperCase();
            const label = { ACCEPTED: 'Going', DECLINED: 'Not going', TENTATIVE: 'Maybe' }[ps] || 'Sent';
            let status = card.querySelector('.invite-status');
            if (!status) {
                status = document.createElement('div');
                status.className = 'invite-status';
                card.querySelector('.invite-actions').insertAdjacentElement('beforebegin', status);
            }
            status.dataset.ps = ps;
            status.textContent = 'Your response: ' + label;
            btns.forEach(b => {
                b.disabled = false;
                b.setAttribute('aria-pressed', String(b.dataset.rsvp === response));
            });
            if (fb) {
                fb.hidden = false;
                fb.textContent = 'Reply sent to the organizer.';
                fb.classList.remove('invite-feedback-err');
            }
            // Keep the in-memory thread copy in sync so a re-render shows the choice.
            if (state.thread) {
                const m = state.thread.find(x => (x.uid | 0) === uid && x.folder === folder);
                if (m && m.invite) m.invite.my_partstat = ps;
            }
        } catch (err) {
            btn.classList.remove('is-sending');
            btns.forEach(b => { b.disabled = false; });
            if (fb) { fb.hidden = false; fb.textContent = 'Network error — please retry.'; fb.classList.add('invite-feedback-err'); }
        } finally {
            card.dataset.busy = '';
        }
    }

    function renderThread(data, focused) {
        const thread = data.thread || [];
        state.thread = thread;
        const subject = data.subject || (focused && focused.subject) || '(no subject)';

        $('readSubject').textContent = subject;

        if (thread.length > 1) {
            $('readThreadSummary').textContent =
                thread.length + ' messages in this conversation';
            $('readThreadSummary').hidden = false;
        } else {
            $('readThreadSummary').hidden = true;
        }

        const html = thread.map((msg, i) => {
            // Thread is newest-first, so i === 0 is the latest message: it sits on
            // top and is the only one expanded by default (Gmail-style). Older
            // messages collapse. A specifically-opened (focused) message — e.g.
            // reached from a search result — also stays expanded.
            const isFocused = msg.uid === focused.uid && msg.folder === focused.folder;
            const expanded = i === 0 || isFocused;
            const initial = avatarInitial(msg.from_name, msg.from_addr);
            const color = avatarColor(msg.from_addr || msg.from_name || '?');
            const folderBadge = thread.length > 1
                ? '<span class="thread-folder-badge">' + escapeHtml(displayFolderName(msg.folder)) + '</span>'
                : '';
            const emailPart = msg.from_addr
                ? '<span class="thread-msg-from-email">&lt;' + escapeHtml(msg.from_addr) + '&gt;</span>'
                : '';
            const hasBody    = msg.has_body && msg.body;
            const loadedAttr = hasBody ? ' data-loaded="true"' : '';
            const bodyHtml   = hasBody ? msg.body : '';
            const attsHtml   = hasBody ? renderMsgAttachments(msg) : '';
            const inviteHtml = renderInviteCard(msg);
            const badgeHtml  = trustBadge(msg.trust);
            const bannerHtml = renderTrustBanner(msg.trust);
            // Show a paperclip on the header so a collapsed message still reveals
            // which message in the conversation carries an attachment.
            const attachIcon = msg.has_attachments
                ? '<svg class="thread-msg-attach icon" width="14" height="14" aria-label="Has attachments"><use href="#ic-paperclip"/></svg>'
                : '';
            return (
                '<div class="thread-msg' + (expanded ? ' expanded' : '') + '" data-uid="' + msg.uid + '" data-folder="' + escapeAttr(msg.folder) + '">' +
                    '<button class="thread-msg-header" type="button">' +
                        '<div class="thread-msg-avatar" style="background:' + color + '">' + escapeHtml(initial) + '</div>' +
                        '<div class="thread-msg-meta">' +
                            '<div class="thread-msg-from">' +
                                '<span class="thread-msg-from-name">' + escapeHtml(msg.from_name || msg.from_addr || '(unknown)') + '</span>' +
                                emailPart +
                                badgeHtml +
                                folderBadge +
                            '</div>' +
                            '<div class="thread-msg-to">To: <span>' + escapeHtml(msg.to || '') + '</span></div>' +
                        '</div>' +
                        attachIcon +
                        '<div class="thread-msg-date">' + escapeHtml(formatFullDate(msg.timestamp)) + '</div>' +
                    '</button>' +
                    bannerHtml +
                    '<div class="thread-msg-body"' + loadedAttr + '>' + inviteHtml + attsHtml + bodyHtml + '</div>' +
                '</div>'
            );
        }).join('');

        $('readThread').innerHTML = html;
    }

    function updateActionBar() {
        const uids = actionTargetUids();
        const has = uids.length > 0;
        ['abDelete', 'abArchive', 'abMove', 'abFlag', 'abMarkRead', 'abMarkUnread'].forEach(id => {
            $(id).disabled = !has;
        });
        if (!has) return;
        const targets = state.selectedUids.size > 0
            ? targetMessages(uids)
            : (state.currentUid != null ? [state.messages.find(m => m.uid === state.currentUid)].filter(Boolean) : []);
        const anyFlagged = targets.some(m => m.flagged);
        $('abFlag').innerHTML =
            '<svg class="icon"><use href="#ic-flag"/></svg>' +
            '<span>' + (anyFlagged ? 'Unflag' : 'Flag') + '</span>';
    }
    function updateSelectionBar() {
        const bar = $('listSelection');
        if (!bar) return;
        const n = state.selectedUids.size;
        if (n === 0) {
            bar.classList.add('hidden');
            bar.setAttribute('aria-hidden', 'true');
            return;
        }
        bar.classList.remove('hidden');
        bar.setAttribute('aria-hidden', 'false');
        $('listSelectionCount').textContent = n + (n === 1 ? ' selected' : ' selected');
        const selectAll = $('listSelectAll');
        const visible = visibleMessages();
        const allSelected = visible.length > 0 && visible.every(m => state.selectedUids.has(m.uid));
        if (selectAll) {
            selectAll.checked = allSelected;
            selectAll.indeterminate = !allSelected && n > 0;
        }
    }

    /* ---------- Compose attachments ---------- */
    function formatBytes(n) {
        if (n < 1024) return n + ' B';
        if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
        return (n / 1024 / 1024).toFixed(1) + ' MB';
    }
    function addAttachment(file) {
        if (!file) return;
        if (file.size > EFF_PER_FILE) {
            const serverBound = EFF_PER_FILE < ATTACH_MAX_PER_FILE;
            alert('"' + file.name + '" (' + formatBytes(file.size) + ') is larger than the ' +
                  formatBytes(EFF_PER_FILE) + ' per-file limit' + (serverBound ? ' this server allows' : '') +
                  ', so it wasn’t attached.' +
                  (serverBound ? ' Your host can raise upload_max_filesize to allow bigger files.' : ''));
            return;
        }
        if (state.composeAttachments.length >= EFF_MAX_FILES) {
            alert('You can attach at most ' + EFF_MAX_FILES + ' files per message on this server.');
            return;
        }
        const total = state.composeAttachments.reduce((s, a) => s + a.size, 0) + file.size;
        if (total > EFF_TOTAL) {
            alert('Total attachments would exceed the ' + formatBytes(EFF_TOTAL) +
                  ' per-message limit on this server. "' + file.name + '" not added.');
            return;
        }
        state.composeAttachments.push(file);
        renderAttachments();
    }
    function removeAttachmentAt(i) {
        state.composeAttachments.splice(i, 1);
        renderAttachments();
    }
    function renderAttachments() {
        const list = $('composeAttachmentsList');
        if (!list) return;
        if (state.composeAttachments.length === 0) {
            list.innerHTML = '';
            return;
        }
        list.innerHTML = state.composeAttachments.map((f, i) =>
            '<span class="attach-chip" data-index="' + i + '">' +
                '<svg class="icon" width="13" height="13"><use href="#ic-paperclip"/></svg>' +
                '<span class="attach-name" title="' + escapeAttr(f.name) + '">' + escapeHtml(f.name) + '</span>' +
                '<span class="attach-size">' + escapeHtml(formatBytes(f.size)) + '</span>' +
                '<button class="attach-remove" type="button" data-remove="' + i + '" aria-label="Remove">' +
                    '<svg class="icon" width="11" height="11"><use href="#ic-x"/></svg>' +
                '</button>' +
            '</span>'
        ).join('');
    }
    function clearAttachments() {
        state.composeAttachments = [];
        renderAttachments();
    }

    /* ---------- Compose ---------- */
    /* ---------- Modal focus management ----------
       Generic, single-level focus trap shared by the compose / filter /
       shortcuts / calendar dialogs. activate() records the element that had
       focus so deactivate() can restore it; a capture-phase Tab handler keeps
       keyboard focus inside the open dialog. Modals that focus their own field
       on open pass { focus: false } so we don't override that. */
    const modalTrap = {
        el: null,
        prevFocus: null,
        _selector: 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[contenteditable="true"],[tabindex]:not([tabindex="-1"])',
        activate(el, opts) {
            opts = opts || {};
            if (!el) return;
            if (this.el && this.el !== el) this.el.removeAttribute('aria-modal');
            const cur = document.activeElement;
            if (cur && cur !== document.body) this.prevFocus = cur;
            this.el = el;
            el.setAttribute('aria-modal', 'true');
            if (!el.getAttribute('role')) el.setAttribute('role', 'dialog');
            if (opts.focus !== false) {
                const f = this.focusables();
                if (f.length) f[0].focus();
                else { el.tabIndex = -1; el.focus(); }
            }
        },
        deactivate(el) {
            if (el && this.el !== el) return;
            if (this.el) this.el.removeAttribute('aria-modal');
            const prev = this.prevFocus;
            this.el = null;
            this.prevFocus = null;
            if (prev && document.contains(prev) && typeof prev.focus === 'function') prev.focus();
        },
        focusables() {
            if (!this.el) return [];
            return Array.from(this.el.querySelectorAll(this._selector))
                .filter(n => n.getClientRects().length > 0);
        },
        handleTab(e) {
            if (!this.el || e.key !== 'Tab') return;
            const f = this.focusables();
            if (!f.length) { e.preventDefault(); this.el.focus(); return; }
            const first = f[0], last = f[f.length - 1];
            const active = document.activeElement;
            if (e.shiftKey && (active === first || !this.el.contains(active))) {
                e.preventDefault(); last.focus();
            } else if (!e.shiftKey && (active === last || !this.el.contains(active))) {
                e.preventDefault(); first.focus();
            }
        },
    };
    document.addEventListener('keydown', (e) => modalTrap.handleTab(e), true);

    // Fill the compose "From" picker with every signed-in account, preselecting
    // the requested one (reply/forward default to the message's account). The row
    // stays hidden for single-account sessions so nothing changes for them.
    function populateComposeFrom(selectedAcctId) {
        const row = $('composeFromRow');
        const sel = $('composeFrom');
        if (!row || !sel) return;
        if (!state.accounts || state.accounts.length <= 1) {
            row.hidden = true;
            sel.innerHTML = '';
            return;
        }
        const want = selectedAcctId || state.currentMsgAcct || viewAcct() || state.primaryAccount || '';
        sel.innerHTML = state.accounts.map(a => {
            const label = a.name ? (a.name + ' <' + a.email + '>') : a.email;
            return '<option value="' + escapeAttr(a.id) + '"' + (a.id === want ? ' selected' : '') + '>' +
                   escapeHtml(label) + '</option>';
        }).join('');
        if (sel.selectedIndex < 0 && sel.options.length) sel.selectedIndex = 0;
        row.hidden = false;
    }
    // The account a compose should send as: the picked From, or the focused/
    // primary account when the picker is hidden (single account).
    function composeSendAcct() {
        const row = $('composeFromRow');
        const sel = $('composeFrom');
        if (row && !row.hidden && sel && sel.value) return sel.value;
        return viewAcct() || state.primaryAccount || '';
    }

    // Bcc row is collapsed behind a "Bcc" link (Gmail/Outlook pattern) so the
    // compose header stays compact; it auto-reveals when a draft being
    // restored already carries Bcc recipients.
    function setBccVisible(show) {
        $('composeBccRow').hidden = !show;
        $('composeBccToggle').hidden = show;
        $('composeBccToggle').setAttribute('aria-expanded', show ? 'true' : 'false');
    }

    // Gmail-style compose window states: 'docked' (default bottom-right),
    // 'minimized' (title bar only), 'expanded' (large centered dialog).
    function setComposeMode(mode) {
        const m = $('composeModal');
        if (!m) return;
        state.composeMode = mode;
        m.classList.toggle('compose-minimized', mode === 'minimized');
        m.classList.toggle('compose-expanded', mode === 'expanded');
        const ex = $('composeExpand');
        if (ex) {
            const use = ex.querySelector('use');
            if (use) use.setAttribute('href', mode === 'expanded' ? '#ic-collapse' : '#ic-expand');
            const label = mode === 'expanded' ? 'Exit full screen' : 'Full screen';
            ex.setAttribute('title', label); ex.setAttribute('aria-label', label);
        }
    }

    function openCompose(opts) {
        opts = opts || {};
        const isReplyOrForward = !!opts.quoted;
        const prefs = window.__PREFS__ || {};
        const sig   = prefs.signature || '';
        const wantSig = sig && (
            (!isReplyOrForward && prefs.auto_append) ||
            (isReplyOrForward  && prefs.append_on_replies)
        );

        let body = '';
        if (opts.body != null) {
            // Resuming a saved draft: no signature/quote. Clean it so the editor
            // behaves — drop any stray doctype (breaks contenteditable line breaks)
            // and guarantee a trailing editable line so the caret isn't trapped
            // after a list / quote / table (which stops Enter from working).
            body = String(opts.body).replace(/<!doctype[^>]*>/gi, '').trim();
            if (/<\/(?:ul|ol|blockquote|table|pre|h[1-6]|div)>$/i.test(body)) body += '<p><br></p>';
        } else {
            if (wantSig) body += '<br><br><div class="email-signature">' + sig + '</div>';
            if (opts.quoted) body += '<br><br>' + opts.quoted;
        }

        $('composeTitle').textContent  = opts.title || 'New Message';
        $('composeTo').value           = opts.to      || '';
        $('composeCc').value           = opts.cc      || '';
        $('composeBcc').value          = opts.bcc     || '';
        setBccVisible(!!opts.bcc);
        $('composeSubject').value      = opts.subject || '';
        $('composeBody').innerHTML     = body;
        // Baseline for autosave: the signature / quoted text compose opens with.
        // The body only counts as "content" once it differs from this, so a fresh
        // compose that only holds the signature never autosaves an empty draft.
        state.composeInitialText = $('composeBody').textContent.replace(/\s+/g, ' ').trim();
        $('composeStatus').textContent = '';
        $('composeStatus').className   = 'compose-status';
        populateComposeFrom(opts.acct);
        state.composeReply = {
            in_reply_to: opts.in_reply_to || '',
            references: opts.references  || '',
        };
        state.composeDraft = { uid: opts.draftUid || 0, folder: opts.draftFolder || '', acct: opts.acct || '' };
        clearTimeout(draftSaveTimer);
        clearAttachments();
        $('sendBtn').disabled = false;
        $('sendBtn').querySelector('.btn-send-label').textContent = 'Send';
        setComposeMode('docked'); // always open docked; clears any prior minimize/expand
        $('composeModal').classList.add('open');
        state.composeOpen = true;
        modalTrap.activate($('composeModal'), { focus: false });

        const target = $('composeTo').value ? $('composeBody') : $('composeTo');
        target.focus();
        if (target === $('composeBody')) {
            try {
                const range = document.createRange();
                range.setStart($('composeBody'), 0);
                range.collapse(true);
                const sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(range);
            } catch (e) {}
        }
    }
    let draftSaveTimer = null;
    let suppressDraftSave = false;
    function closeCompose() {
        acClose();
        clearTimeout(draftSaveTimer);
        // Autosave a half-written message to Drafts on close (unless we just sent).
        if (!suppressDraftSave) saveDraft(true);
        suppressDraftSave = false;
        $('composeModal').classList.remove('open');
        state.composeOpen = false;
        modalTrap.deactivate($('composeModal'));
    }
    // ---- Draft autosave (to the IMAP Drafts folder; see ajax/draft.php) ----
    async function draftApi(action, params, acct) {
        const body = new URLSearchParams();
        body.append('action', action);
        for (const [k, v] of Object.entries(params || {})) if (v !== undefined && v !== null) body.append(k, String(v));
        const url = 'ajax/draft.php' + (acct ? ('?acct=' + encodeURIComponent(acct)) : '');
        try {
            const r = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: csrfHeaders(), body });
            if (r.status === 401) { window.location = 'index'; return { error: 'Not authenticated' }; }
            return await r.json();
        } catch (e) { return { error: NET_ERR, network: true }; }
    }
    function composeHasContent() {
        // A recipient or subject is enough on its own.
        if ($('composeTo').value.trim() || $('composeCc').value.trim() ||
            $('composeBcc').value.trim() || $('composeSubject').value.trim()) return true;
        // Otherwise the body only counts if the user typed something beyond the
        // signature / quoted text that compose opened with.
        const bodyText = $('composeBody').textContent.replace(/\s+/g, ' ').trim();
        return bodyText !== '' && bodyText !== (state.composeInitialText || '');
    }
    async function saveDraft(force) {
        if (!state.composeOpen && !force) return;
        if (!composeHasContent()) return;
        const d = state.composeDraft || {};
        const reply = state.composeReply || {};
        const acct = composeSendAcct();
        const r = await draftApi('save', {
            to: $('composeTo').value.trim(),
            cc: $('composeCc').value.trim(),
            bcc: $('composeBcc').value.trim(),
            subject: $('composeSubject').value.trim(),
            body: $('composeBody').innerHTML,
            in_reply_to: reply.in_reply_to || '',
            references: reply.references || '',
            prev_uid: d.uid || 0,
            prev_folder: d.folder || '',
        }, acct);
        if (r && r.ok && r.uid) state.composeDraft = { uid: r.uid, folder: r.folder || '', acct: acct };
    }
    function scheduleDraftSave() {
        if (!state.composeOpen) return;
        clearTimeout(draftSaveTimer);
        draftSaveTimer = setTimeout(() => saveDraft(false), 12000); // 12s after the last edit
    }
    // Open a saved draft back into the composer (reuses the message parser, which
    // now returns bcc). Bound to clicks on rows in a Drafts-type folder.
    async function resumeDraft(uid) {
        const acct = viewAcct();
        const folder = state.currentFolder;
        const openThread = (u) => apiGet('thread', withAcct({ uid: u, folder: folder }, acct));
        const ok = (d) => d && !d.error && Array.isArray(d.thread) && d.thread.length;

        let data = await openThread(uid);
        // Autosave replaces a draft (append new + delete old), so its UID changes
        // and the list row can point at a message that no longer exists ("Message
        // not found"). Recover without a full page reload: refresh the folder,
        // re-find the SAME draft by Message-ID, and retry with its current UID.
        if (!ok(data)) {
            const row = state.messages.find(x => x.uid === uid);
            const mid = (row && row.message_id) ? row.message_id : '';
            if (mid) {
                await loadMessages({ keepReading: true, silent: true });
                const fresh = state.messages.find(x => x.message_id === mid);
                if (fresh && fresh.uid !== uid) { uid = fresh.uid; data = await openThread(uid); }
            }
        }
        if (!ok(data)) { showToast('Could not open draft'); return; }
        const m = data.thread.find(x => x.uid === uid) || data.thread[data.thread.length - 1];
        openCompose({
            title: 'Draft',
            to: m.to || '', cc: m.cc || '', bcc: m.bcc || '',
            subject: (m.subject === '(no subject)' ? '' : (m.subject || '')),
            body: m.body || '',
            acct: acct,
            draftUid: uid, draftFolder: state.currentFolder,
            in_reply_to: m.in_reply_to || '', references: m.references || '',
        });
    }
    // Discard: delete any autosaved draft for this compose, then close without
    // re-saving on the way out.
    function discardDraft() {
        const d = state.composeDraft;
        const had = !!(d && d.uid);
        if (had) draftApi('delete', { uid: d.uid, folder: d.folder }, d.acct);
        state.composeDraft = { uid: 0, folder: '', acct: '' };
        suppressDraftSave = true; // skip the autosave-on-close
        closeCompose();
        if (had) {
            showToast('Draft discarded');
            loadFolders();
            if (folderType(state.currentFolder) === 'drafts') loadMessages({ keepReading: true, silent: true });
        }
    }

    /* ---------- Recipient autocomplete (To / Cc / Bcc) ----------
       Suggests addresses from the per-user book harvested by the backend
       (ajax/contacts.php). Operates on the token under the caret so a single
       field can hold a comma-separated list. All server text is escaped on
       render — ajax/fetch.php's ok() uses plain json_encode. */
    const ac = {
        input: null, box: null, items: [], active: -1,
        debounce: null, abort: null, seq: 0, open: false,
    };

    function acEnsureBox() {
        if (ac.box) return ac.box;
        const box = document.createElement('div');
        box.className = 'compose-autocomplete';
        box.setAttribute('role', 'listbox');
        // mousedown fires before the input's blur — preventDefault keeps focus
        // so the click handler can read selection state and refocus cleanly.
        box.addEventListener('mousedown', (e) => e.preventDefault());
        box.addEventListener('click', (e) => {
            const row = e.target.closest('.ac-item');
            if (!row) return;
            const idx = parseInt(row.getAttribute('data-idx'), 10);
            if (!isNaN(idx)) acChoose(idx);
        });
        ac.box = box;
        return box;
    }

    // Boundaries of the recipient token surrounding the caret (delimited by , or ;).
    function acTokenBounds(value, caret) {
        let start = 0;
        for (let i = caret - 1; i >= 0; i--) {
            if (value[i] === ',' || value[i] === ';') { start = i + 1; break; }
        }
        let end = value.length;
        for (let i = caret; i < value.length; i++) {
            if (value[i] === ',' || value[i] === ';') { end = i; break; }
        }
        return { start, end };
    }

    function acCaret(input) {
        return input.selectionStart != null ? input.selectionStart : input.value.length;
    }

    function acClose() {
        if (ac.box && ac.box.parentNode) ac.box.parentNode.removeChild(ac.box);
        if (ac.abort) { ac.abort.abort(); ac.abort = null; }
        ac.open = false; ac.items = []; ac.active = -1;
    }

    function acPosition() {
        const input = ac.input, box = ac.box;
        if (!input || !box) return;
        box.style.left  = input.offsetLeft + 'px';
        box.style.top   = (input.offsetTop + input.offsetHeight + 2) + 'px';
        box.style.width = input.offsetWidth + 'px';
    }

    function acRender() {
        if (!ac.items.length) { acClose(); return; }
        const box = acEnsureBox();
        box.innerHTML = ac.items.map((c, i) => {
            const name = c.name && c.name.trim() ? c.name.trim() : '';
            const main = name ? escapeHtml(name) : escapeHtml(c.email);
            const sub  = name ? '<span class="ac-item-email">' + escapeHtml(c.email) + '</span>' : '';
            return '<div class="ac-item' + (i === ac.active ? ' active' : '') + '" role="option" data-idx="' + i + '">' +
                       '<span class="ac-item-name">' + main + '</span>' + sub +
                   '</div>';
        }).join('');
        const row = ac.input.closest('.compose-row') || ac.input.parentNode;
        if (box.parentNode !== row) row.appendChild(box);
        acPosition();
        ac.open = true;
    }

    function acChoose(idx) {
        const c = ac.items[idx];
        if (!c || !ac.input) return;
        const input = ac.input;
        const { start, end } = acTokenBounds(input.value, acCaret(input));
        const formatted = (c.name && c.name.trim()) ? (c.name.trim() + ' <' + c.email + '>') : c.email;
        const left  = input.value.slice(0, start).replace(/[\s,;]+$/, '');
        const right = input.value.slice(end).replace(/^[\s,;]+/, '');
        let result = '';
        if (left) result += left + ', ';
        result += formatted + ', ';
        const caretPos = result.length;
        if (right) result += right;
        input.value = result;
        try { input.setSelectionRange(caretPos, caretPos); } catch (e) {}
        acClose();
        input.focus();
    }

    function acQuery(input) {
        const { start, end } = acTokenBounds(input.value, acCaret(input));
        const token = input.value.slice(start, end).trim();
        if (token.length < 1) { acClose(); return; }
        const seq = ++ac.seq;
        if (ac.abort) ac.abort.abort();
        ac.abort = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        fetch('ajax/contacts.php?q=' + encodeURIComponent(token) + '&limit=8',
              { credentials: 'same-origin', signal: ac.abort ? ac.abort.signal : undefined })
            .then(r => r.json())
            .then(data => {
                if (seq !== ac.seq || ac.input !== input) return; // stale response
                if (!data || !data.ok || !Array.isArray(data.contacts) || !data.contacts.length) { acClose(); return; }
                ac.items = data.contacts;
                ac.active = -1;
                acRender();
            })
            .catch(() => {});
    }

    function acAttach(input) {
        if (!input) return;
        input.addEventListener('input', () => {
            ac.input = input;
            clearTimeout(ac.debounce);
            ac.debounce = setTimeout(() => acQuery(input), 140);
        });
        input.addEventListener('keydown', (e) => {
            if (!ac.open) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                ac.active = Math.min(ac.active + 1, ac.items.length - 1);
                acRender();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                ac.active = Math.max(ac.active - 1, 0);
                acRender();
            } else if (e.key === 'Enter' || e.key === 'Tab') {
                if (ac.active >= 0) { e.preventDefault(); acChoose(ac.active); }
                else acClose();
            } else if (e.key === 'Escape') {
                acClose();
            }
        });
        input.addEventListener('blur', () => {
            // Delay close so a click on a suggestion registers first.
            setTimeout(() => { if (ac.input === input) acClose(); }, 150);
        });
    }

    /**
     * Always queue the message via the outbox: a normal Send queues with a
     * 10-second delay (the "Undo Send" window), schedule send queues for a
     * future time. The outbox processor flushes due messages on poll cycles.
     */
    async function sendMessage(scheduleIsoUtc) {
        const to = $('composeTo').value.trim();
        if (!to) {
            $('composeStatus').className = 'compose-status error';
            $('composeStatus').textContent = 'Please add at least one recipient.';
            $('composeTo').focus();
            return;
        }
        const reply = state.composeReply || {};
        const isImmediate = !scheduleIsoUtc;
        const sendAt = scheduleIsoUtc || new Date(Date.now() + 10000).toISOString().replace(/\.\d{3}Z$/, 'Z');
        // The account this message is sent as. send.php mirrors it (From header +
        // SMTP creds + which outbox the message is queued in); the later commit /
        // cancel must target the same account, so we thread it through Undo too.
        const sendAcct = composeSendAcct();
        const payload = {
            to,
            cc: $('composeCc').value.trim(),
            bcc: $('composeBcc').value.trim(),
            subject: $('composeSubject').value.trim(),
            body: $('composeBody').innerHTML,
            in_reply_to: reply.in_reply_to || '',
            references: reply.references || '',
            queue_send_at: sendAt,
            acct: sendAcct,
        };
        const attachments = state.composeAttachments.slice();
        const btn = $('sendBtn');
        btn.disabled = true;
        btn.querySelector('.btn-send-label').textContent = isImmediate ? 'Sending…' : 'Scheduling…';
        $('composeStatus').className = 'compose-status';
        $('composeStatus').textContent = '';

        const result = await apiSend(payload, attachments);
        if (result.error) {
            $('composeStatus').className = 'compose-status error';
            $('composeStatus').textContent = result.error;
            btn.disabled = false;
            btn.querySelector('.btn-send-label').textContent = 'Send';
            return;
        }

        // Capture compose state for potential Undo
        const undoState = {
            to: payload.to,
            cc: payload.cc,
            bcc: payload.bcc,
            subject: payload.subject,
            body: payload.body,
            in_reply_to: payload.in_reply_to,
            references: payload.references,
            acct: sendAcct,
            attachments,
        };

        // The message is sent — drop its autosaved draft and don't re-save on close.
        const _sentDraft = state.composeDraft;
        if (_sentDraft && _sentDraft.uid) draftApi('delete', { uid: _sentDraft.uid, folder: _sentDraft.folder }, _sentDraft.acct);
        state.composeDraft = { uid: 0, folder: '', acct: '' };
        suppressDraftSave = true;
        closeCompose();

        if (isImmediate) {
            showUndoToast(result.id, undoState, sendAcct);
        } else {
            const when = new Date(sendAt).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
            showToast('Scheduled for ' + when, 4500);
        }
    }

    function showUndoToast(queueId, capturedState, sendAcct) {
        // Own DOM node (separate from the generic #appToast) so a later showToast()
        // during the 10s window can't overwrite the toast and destroy the Undo button.
        let el = $('appToastUndo');
        if (!el) {
            el = document.createElement('div');
            el.id = 'appToastUndo';
            el.className = 'app-toast app-toast-undo';
            document.body.appendChild(el);
        }
        let remaining = 10;
        let timer = null;
        let undone = false;
        // The queued message lives in this account's outbox; both the commit
        // (flush) and the cancel must mirror the same account. accounts_boot()
        // reads the acct query param, so append it to the URL.
        const acctQS = sendAcct ? ('&acct=' + encodeURIComponent(sendAcct)) : '';

        async function commitSend() {
            try {
                await fetch('ajax/outbox.php?action=process' + acctQS, { method: 'POST', credentials: 'same-origin', headers: csrfHeaders() });
            } catch (e) {}
            const tasks = [loadFolders()];
            if (state.currentFolder.toLowerCase().includes('sent')) {
                tasks.push(loadMessages({ keepReading: true, silent: true }));
            }
            await Promise.all(tasks);
        }

        async function undo() {
            undone = true;
            if (timer) clearInterval(timer);
            try {
                await fetch('ajax/outbox.php?action=cancel' + acctQS, {
                    method: 'POST',
                    headers: csrfHeaders({ 'Content-Type': 'application/json' }),
                    credentials: 'same-origin',
                    body: JSON.stringify({ id: queueId }),
                });
            } catch (e) {}
            el.classList.remove('visible');
            reopenCompose(capturedState);
        }

        function render() {
            el.innerHTML = '';
            const txt = document.createElement('span');
            txt.textContent = 'Message sent. ';
            const btn = document.createElement('button');
            btn.className = 'app-toast-action';
            btn.type = 'button';
            btn.textContent = 'Undo';
            btn.addEventListener('click', undo);
            const counter = document.createElement('span');
            counter.className = 'app-toast-counter';
            counter.textContent = remaining + 's';
            el.appendChild(txt);
            el.appendChild(btn);
            el.appendChild(counter);
        }

        render();
        el.classList.add('visible');
        timer = setInterval(() => {
            remaining--;
            if (remaining <= 0) {
                clearInterval(timer);
                el.classList.remove('visible');
                if (!undone) commitSend();
                return;
            }
            const c = el.querySelector('.app-toast-counter');
            if (c) c.textContent = remaining + 's';
        }, 1000);
    }

    function reopenCompose(s) {
        openCompose({ acct: s.acct });
        $('composeTo').value      = s.to || '';
        $('composeCc').value      = s.cc || '';
        $('composeBcc').value     = s.bcc || '';
        setBccVisible(!!s.bcc);
        $('composeSubject').value = s.subject || '';
        $('composeBody').innerHTML= s.body || '';
        state.composeReply = { in_reply_to: s.in_reply_to || '', references: s.references || '' };
        state.composeAttachments  = (s.attachments || []).slice();
        renderAttachments();
    }

    function buildQuoted(msg) {
        return (
            '<div style="color:#605e5c;font-size:12px;margin-bottom:6px;">' +
                'On ' + escapeHtml(formatFullDate(msg.timestamp)) + ', ' +
                escapeHtml(msg.from_name || msg.from_addr || '') + ' wrote:' +
            '</div>' +
            '<blockquote style="border-left:3px solid #e1dfdd;padding-left:12px;color:#605e5c;">' +
                (msg.body || '') +
            '</blockquote>'
        );
    }
    async function printOpenThread() {
        if (!state.currentMessage) return;
        const btn = $('printBtn');
        btn.style.opacity = '0.5';

        // Make sure every thread card has its body so the print contains
        // the full conversation, not just the two cards expanded by default.
        const cards = document.querySelectorAll('#readThread .thread-msg');
        const tasks = [];
        for (const card of cards) {
            const body = card.querySelector('.thread-msg-body');
            if (!body || body.dataset.loaded === 'true') continue;
            const uid    = parseInt(card.dataset.uid, 10);
            const folder = card.dataset.folder;
            if (!uid || !folder) continue;
            tasks.push((async () => {
                const data = await apiGet('message', withAcct({ folder, uid }, state.currentMsgAcct));
                if (!data.error) {
                    body.innerHTML = data.body || '';
                    body.dataset.loaded = 'true';
                }
            })());
        }
        if (tasks.length) await Promise.all(tasks);

        // Mark every card expanded for the print run; CSS @media print
        // will force them open regardless, but expanding here also makes
        // the on-screen print preview clear.
        cards.forEach(c => c.classList.add('expanded'));

        document.body.classList.add('printing');
        // Slight delay lets the layout settle before the print dialog opens.
        setTimeout(() => {
            window.print();
            document.body.classList.remove('printing');
            btn.style.opacity = '';
        }, 120);
    }

    function buildReferences(parent) {
        const existing = (parent.references || '').trim();
        const parentId = (parent.message_id || '').trim();
        if (!parentId) return existing;
        if (!existing) return parentId;
        if (existing.indexOf(parentId) !== -1) return existing;
        return existing + ' ' + parentId;
    }

    // Extract the bare address from a "Name <addr>" token (or the token itself).
    function bareEmail(s) {
        const m = String(s || '').match(/<([^>]+)>/);
        return (m ? m[1] : String(s || '')).trim();
    }
    // Split a header address list on comma/semicolon (matches the server's
    // recipient parsing in ajax/send.php).
    function splitAddressList(s) {
        return String(s || '').split(/[,;]/).map(x => x.trim()).filter(Boolean);
    }
    function startReply(includeAll) {
        if (!state.currentMessage) return;
        const m = state.currentMessage;
        const subj = /^re:/i.test(m.subject || '') ? m.subject : 'Re: ' + (m.subject || '');
        let cc = '';
        if (includeAll) {
            // Reply-All: To stays the original sender; Cc becomes everyone else
            // from the original To and Cc, minus the sender and any of the
            // user's own account addresses, de-duplicated by bare address.
            // (Previously only the original Cc was carried, silently dropping
            // every other recipient on the To line.)
            const exclude = new Set(
                state.accounts.map(a => (a.email || '').toLowerCase()).filter(Boolean)
            );
            exclude.add(bareEmail(m.from_addr || '').toLowerCase());
            const seen = new Set();
            const recips = [];
            for (const token of splitAddressList((m.to || '') + ',' + (m.cc || ''))) {
                const addr = bareEmail(token).toLowerCase();
                if (!addr || exclude.has(addr) || seen.has(addr)) continue;
                seen.add(addr);
                recips.push(token);
            }
            cc = recips.join(', ');
        }
        openCompose({
            title: includeAll ? 'Reply All' : 'Reply',
            to: m.from_addr,
            cc,
            subject: subj,
            quoted: buildQuoted(m),
            in_reply_to: m.message_id || '',
            references: buildReferences(m),
            acct: state.currentMsgAcct,
        });
    }
    async function startForward() {
        if (!state.currentMessage) return;
        const m = state.currentMessage;
        const subj = /^fwd?:/i.test(m.subject || '') ? m.subject : 'Fwd: ' + (m.subject || '');
        // Forwards start a new conversation; no In-Reply-To, no References.
        openCompose({ title: 'Forward', subject: subj, quoted: buildQuoted(m), acct: state.currentMsgAcct });

        // Carry the original (non-inline) attachments so a forwarded invoice /
        // contract actually includes the file — Gmail/Outlook behaviour. We fetch
        // each part as a blob and add it to the compose list, which then rides the
        // normal apiSend() FormData path. cid: inline images are skipped (they're
        // part of the quoted body, not real attachments).
        const atts = Array.isArray(m.attachments)
            ? m.attachments.filter(a => a.section && !(a.inline && a.content_id))
            : [];
        if (!atts.length) return;
        const acct   = m.acct || state.currentMsgAcct || '';
        const status = $('composeStatus');
        if (status) { status.className = 'compose-status'; status.textContent = 'Attaching ' + atts.length + ' file' + (atts.length > 1 ? 's' : '') + '…'; }
        const CAP = 26214400; // 25 MB — matches send.php's total cap
        let added = 0, failed = 0, bytes = state.composeAttachments.reduce((s, f) => s + (f.size || 0), 0);
        for (const a of atts) {
            try {
                const r = await fetch(attachUrl(m.folder, m.uid, a.section, false, acct), { credentials: 'same-origin' });
                if (!r.ok) { failed++; continue; }
                const blob = await r.blob();
                if (bytes + blob.size > CAP) { failed++; continue; }
                bytes += blob.size;
                state.composeAttachments.push(new File([blob], a.name || 'attachment', { type: a.type || blob.type || 'application/octet-stream' }));
                added++;
            } catch (e) { failed++; }
        }
        if (!state.composeOpen) return; // user closed/sent while we were fetching
        renderAttachments();
        if (status) {
            if (failed) { status.className = 'compose-status error'; status.textContent = added + ' of ' + atts.length + ' attachments added — ' + failed + ' too large or unavailable.'; }
            else { status.textContent = ''; }
        }
    }

    /* ---------- Action bar actions ---------- */
    function currentThreadUids() {
        const cur = state.messages.find(m => m.uid === state.currentUid);
        if (cur && Array.isArray(cur.thread_uids) && cur.thread_uids.length) return cur.thread_uids.slice();
        if (state.currentMessage && state.currentMessage.uid) return [state.currentMessage.uid];
        return [];
    }
    /** UIDs an action should operate on: selection if any, else open message. */
    function actionTargetUids() {
        if (state.selectedUids.size > 0) return Array.from(state.selectedUids);
        return currentThreadUids();
    }
    function targetMessages(uids) {
        if (!Array.isArray(uids)) uids = actionTargetUids();
        const set = new Set(uids);
        return state.messages.filter(m => set.has(m.uid));
    }
    function clearSelection() {
        if (state.selectedUids.size === 0) return;
        state.selectedUids.clear();
        state.lastSelectedIndex = -1;
        renderMessages();
        updateActionBar();
        updateSelectionBar();
    }
    function setSelected(uid, selected) {
        if (selected) state.selectedUids.add(uid);
        else state.selectedUids.delete(uid);
    }
    function toggleSelectionAt(uid, index, shift) {
        if (shift && state.lastSelectedIndex >= 0) {
            const visible = visibleMessages();
            const lo = Math.min(state.lastSelectedIndex, index);
            const hi = Math.max(state.lastSelectedIndex, index);
            for (let i = lo; i <= hi; i++) {
                if (visible[i]) state.selectedUids.add(visible[i].uid);
            }
        } else {
            const next = !state.selectedUids.has(uid);
            setSelected(uid, next);
            state.lastSelectedIndex = next ? index : -1;
        }
        renderMessages();
        updateActionBar();
        updateSelectionBar();
    }
    function visibleMessages() {
        // Mirrors renderMessages' filter pipeline so indexes line up
        return applyListFilter(filterMessages(state.messages));
    }

    // Fan an action out across the (account, folder) groups its uids belong to —
    // one request per group, each routed with its own acct. In a single-account
    // folder view there is exactly one group; in the unified inbox messages from
    // different accounts each go to their own mailbox. `folderKey` names the
    // param the endpoint expects the source folder under ('folder' for
    // delete/flag/read, 'from' for move). Returns the first error, or null.
    async function fanOutAction(action, uids, extra, folderKey) {
        folderKey = folderKey || 'folder';
        const groups = groupTargets(uids);
        const results = await Promise.all(groups.map(g => {
            const params = Object.assign({ uids: g.uids }, extra || {});
            params[folderKey] = g.folder;
            return apiPost(action, withAcct(params, g.acct));
        }));
        const err = results.find(r => r && r.error);
        return err ? err.error : null;
    }

    // ---- Undo support for delete / archive / move (all reversible IMAP moves) ----
    // Capture the Message-IDs of the target rows BEFORE the action, so we can find
    // them again in their new folder (their uid changes when moved).
    function capturedMsgIds(uids) {
        const out = [];
        for (const u of uids) {
            const m = state.messages.find(x => x.uid === u);
            if (m && m.message_id) out.push(m.message_id);
        }
        return out;
    }
    async function restoreMessages(fromFolder, toFolder, messageIds, acct) {
        if (!fromFolder || !toFolder || !messageIds.length) return;
        const r = await apiPost('restore', withAcct({ from: fromFolder, to: toFolder, message_ids: messageIds }, acct));
        if (r && r.error) { showToast('Could not undo — ' + r.error); return; }
        await Promise.all([loadMessages({ keepReading: true, silent: true }), loadFolders()]);
    }
    const _countWord = (n) => (n > 1 ? n + ' messages' : 'Message');

    async function actDelete(uids) {
        if (!Array.isArray(uids)) uids = actionTargetUids(); // tolerate being called as a click handler (event arg)
        if (!uids.length) return;
        // Undo is offered only in a single-account view WITH a Trash folder, so the
        // delete is a reversible move. Without a Trash folder the server expunges
        // permanently — keep the confirmation in that case.
        const trash = !isUnifiedView() ? state.folders.find(f => /trash|deleted|bin/i.test(f.name)) : null;
        if (!trash) {
            const word = uids.length > 1 ? (uids.length + ' messages') : 'this message';
            if (!window.confirm('Move ' + word + ' to Trash?')) return;
        }
        const from = state.currentFolder, acct = viewAcct();
        const ids  = trash ? capturedMsgIds(uids) : [];
        const err = await fanOutAction('delete', uids);
        if (err) { alert(err); return; }
        if (state.currentUid && uids.includes(state.currentUid)) clearReadingPane();
        clearSelection();
        await Promise.all([loadMessages({ keepReading: true }), loadFolders()]);
        if (trash && ids.length) {
            showUndoActionToast(_countWord(uids.length) + ' moved to Trash.', () => restoreMessages(trash.name, from, ids, acct));
        }
    }
    async function actArchive(uids) {
        if (!Array.isArray(uids)) uids = actionTargetUids(); // tolerate being called as a click handler (event arg)
        if (!uids.length) return;
        // Folders are per-account, so archiving needs a single focused account.
        if (isUnifiedView()) { alert('Open a specific account to archive its messages.'); return; }
        const archive = state.folders.find(f => /archive/i.test(f.name));
        if (!archive) {
            alert('No Archive folder found. Create one in your mail server first.');
            return;
        }
        const from = state.currentFolder, acct = viewAcct();
        const ids  = capturedMsgIds(uids);
        const err = await fanOutAction('move', uids, { to: archive.name }, 'from');
        if (err) { alert(err); return; }
        if (state.currentUid && uids.includes(state.currentUid)) clearReadingPane();
        clearSelection();
        await Promise.all([loadMessages({ keepReading: true }), loadFolders()]);
        if (ids.length) showUndoActionToast(_countWord(uids.length) + ' archived.', () => restoreMessages(archive.name, from, ids, acct));
    }
    async function actMoveTo(target, uids) {
        if (!target) return;
        if (!Array.isArray(uids)) uids = actionTargetUids(); // tolerate being called as a click handler (event arg)
        if (!uids.length) return;
        // The destination folder belongs to one account; block cross-account
        // moves from the unified view where rows may span accounts.
        if (isUnifiedView()) { alert('Open a specific account to move its messages.'); return; }
        const from = state.currentFolder, acct = viewAcct();
        const ids  = capturedMsgIds(uids);
        const err = await fanOutAction('move', uids, { to: target }, 'from');
        if (err) { alert(err); return; }
        if (state.currentUid && uids.includes(state.currentUid)) clearReadingPane();
        clearSelection();
        await Promise.all([loadMessages({ keepReading: true }), loadFolders()]);
        if (ids.length) showUndoActionToast(_countWord(uids.length) + ' moved.', () => restoreMessages(target, from, ids, acct));
    }
    async function actFlag(uids) {
        if (!Array.isArray(uids)) uids = actionTargetUids(); // tolerate being called as a click handler (event arg)
        if (!uids.length) return;
        const targets = targetMessages(uids);
        const setFlag = targets.some(m => !m.flagged);
        const err = await fanOutAction('flag', uids, { set: setFlag ? '1' : '' });
        if (err) { alert(err); return; }
        targets.forEach(m => m.flagged = setFlag);
        renderMessages();
        updateActionBar();
    }
    async function actReadToggle(uids) {
        if (!Array.isArray(uids)) uids = actionTargetUids(); // tolerate being called as a click handler (event arg)
        if (!uids.length) return;
        const targets = targetMessages(uids);
        const anyUnread = targets.some(m => !m.seen);
        const action = anyUnread ? 'read' : 'unread';
        const err = await fanOutAction(action, uids);
        if (err) { alert(err); return; }
        targets.forEach(m => m.seen = anyUnread);
        renderMessages();
        updateActionBar();
        await loadFolders();
    }
    // Explicit, unambiguous batch read-state setters for the two toolbar buttons
    // (no toggle guessing). actReadToggle above still serves the 'u' shortcut and
    // the right-click menu.
    async function actSetReadState(seen, uids) {
        if (!Array.isArray(uids)) uids = actionTargetUids();
        if (!uids.length) return;
        const err = await fanOutAction(seen ? 'read' : 'unread', uids);
        if (err) { alert(err); return; }
        targetMessages(uids).forEach(m => m.seen = seen);
        renderMessages();
        updateActionBar();
        await loadFolders();
    }
    async function actSync() {
        const btn = $('abSync');
        btn.style.opacity = '0.5';
        await Promise.all([loadFolders(), loadMessages({ keepReading: true })]);
        btn.style.opacity = '';
    }

    /* ---------- Filter ("rule") modal ---------- */
    let _filterFolderListLoaded = false;
    async function loadFilterFolderList() {
        if (_filterFolderListLoaded) return;
        try {
            // We already have folders in state; render from there.
            const sel = $('filterMoveTo');
            const cur = sel.value;
            sel.innerHTML = '<option value="">(no folder change)</option>' +
                state.folders.map(f =>
                    '<option value="' + escapeAttr(f.name) + '">' + escapeHtml(displayFolderName(f.name)) + '</option>'
                ).join('');
            sel.value = cur;
            _filterFolderListLoaded = true;
        } catch (e) {}
    }
    function openFilterModal(prefill) {
        const m = $('filterModal');
        if (!m) return;
        $('filterFrom').value     = prefill?.from    || '';
        $('filterSubject').value  = prefill?.subject || '';
        $('filterHasAttachment').checked = !!prefill?.has_attachment;
        $('filterMoveTo').value          = prefill?.move_to || '';
        $('filterMarkRead').checked      = !!prefill?.mark_read;
        $('filterStar').checked          = !!prefill?.star;
        $('filterApplyExisting').checked = true;
        loadFilterFolderList();
        m.classList.remove('hidden');
        m.setAttribute('aria-hidden', 'false');
        modalTrap.activate(m, { focus: false });
        setTimeout(() => $('filterFrom').focus(), 30);
    }
    function closeFilterModal() {
        const m = $('filterModal');
        if (!m) return;
        m.classList.add('hidden');
        m.setAttribute('aria-hidden', 'true');
        modalTrap.deactivate(m);
    }
    /** Pull the address (or name+address) for the message identified by uid. */
    function getMessageMetaForFilter(uid) {
        const msg = state.messages.find(m => m.uid === uid);
        if (!msg) return null;
        return {
            from:    msg.from_addr || msg.from_name || '',
            subject: msg.subject || '',
        };
    }
    function openFilterFromMessage(uid) {
        const meta = getMessageMetaForFilter(uid);
        if (!meta) return;
        openFilterModal({ from: meta.from });
    }
    async function saveFilterFromModal() {
        const body = {
            enabled: true,
            apply_existing: $('filterApplyExisting').checked,
            match: {
                from:           $('filterFrom').value.trim(),
                subject:        $('filterSubject').value.trim(),
                has_attachment: $('filterHasAttachment').checked,
            },
            actions: {
                mark_read: $('filterMarkRead').checked,
                star:      $('filterStar').checked,
                move_to:   $('filterMoveTo').value,
            },
        };
        // Sanity: require at least one match and one action
        const hasMatch = body.match.from || body.match.subject || body.match.has_attachment;
        const hasAction = body.actions.mark_read || body.actions.star || body.actions.move_to;
        if (!hasMatch)  { alert('Add at least one criterion (From, Subject, or Has attachment).'); return; }
        if (!hasAction) { alert('Pick at least one action (move to folder, mark read, or star).'); return; }

        const r = await fetch('ajax/rules.php?action=add', {
            method: 'POST',
            headers: csrfHeaders({ 'Content-Type': 'application/json' }),
            credentials: 'same-origin',
            body: JSON.stringify(body),
        }).then(r => r.json());
        if (r.error) { alert(r.error); return; }
        closeFilterModal();
        if (r.applied > 0) {
            // Refresh folders + current view in parallel
            await Promise.all([loadFolders(), loadMessages({ keepReading: true, silent: true })]);
        }
        showToast(r.applied > 0
            ? 'Filter created. Applied to ' + r.applied + ' existing message' + (r.applied === 1 ? '' : 's') + '.'
            : 'Filter created. New matching mail will be filtered automatically.');
    }

    /* ---------- Block sender ---------- */
    // Creates a server-side rule (reusing the filter engine) that diverts the
    // sender's mail to Junk/Spam — or deletes it when no junk folder exists —
    // and applies it to existing inbox mail immediately.
    async function blockSender(uids) {
        uids = (uids && uids.length) ? uids : actionTargetUids();
        const targets = targetMessages(uids);
        const seen = new Set();
        const senders = [];
        targets.forEach(m => {
            const addr = (m.from_addr || '').trim();
            const key = addr.toLowerCase();
            if (addr && !seen.has(key)) { seen.add(key); senders.push(addr); }
        });
        if (!senders.length) { alert('Could not determine the sender address for this message.'); return; }

        const junk = state.folders.find(f => /junk|spam/i.test(f.name));
        const dest = junk ? junk.name : null;
        const who  = senders.length === 1 ? senders[0] : (senders.length + ' senders');
        const fate = dest ? 'moved to ' + displayFolderName(dest) : 'deleted';
        if (!window.confirm('Block ' + who + '?\n\nExisting and future messages will be ' + fate + ' and marked as read.')) return;

        let applied = 0;
        for (const addr of senders) {
            const body = {
                enabled: true,
                apply_existing: true,
                match: { from: addr },
                actions: dest ? { move_to: dest, mark_read: true } : { delete: true, mark_read: true },
            };
            const r = await fetch('ajax/rules.php?action=add', {
                method: 'POST',
                headers: csrfHeaders({ 'Content-Type': 'application/json' }),
                credentials: 'same-origin',
                body: JSON.stringify(body),
            }).then(res => res.json()).catch(() => ({ error: 'Network error' }));
            if (r.error) { alert(r.error); return; }
            applied += (r.applied || 0);
        }
        if (state.currentUid && uids.includes(state.currentUid)) clearReadingPane();
        clearSelection();
        await Promise.all([loadFolders(), loadMessages({ keepReading: true, silent: true })]);
        showToast('Blocked ' + who + '. ' + (applied > 0
            ? (applied + ' existing message' + (applied === 1 ? '' : 's') + ' ' + fate + '.')
            : 'New mail will be filtered automatically.'));
    }

    /* ---------- One-click unsubscribe ---------- */
    function parseMailto(s) {
        const out = { to: '', subject: '', body: '' };
        if (!s) return out;
        s = s.replace(/^mailto:/i, '');
        const qi = s.indexOf('?');
        try { out.to = decodeURIComponent(qi >= 0 ? s.slice(0, qi) : s); }
        catch (e) { out.to = qi >= 0 ? s.slice(0, qi) : s; }
        if (qi >= 0) {
            const p = new URLSearchParams(s.slice(qi + 1));
            out.subject = p.get('subject') || '';
            out.body    = p.get('body') || '';
        }
        return out;
    }
    function updateUnsubscribeBtn(msg) {
        const btn = $('unsubscribeBtn');
        if (!btn) return;
        const u = msg && msg.unsubscribe;
        btn.hidden = !(u && (u.http || u.mailto));
    }
    // We never auto-fetch the unsubscribe URL server-side (that would be an SSRF
    // vector and could confirm the address to a spammer). http links open in a
    // new tab only after the user confirms the full URL; mailto opens compose.
    function handleUnsubscribe() {
        const m = state.currentMessage;
        const u = m && m.unsubscribe;
        if (!u) return;
        const sender = (m && (m.from_name || m.from_addr)) || 'this sender';
        if (u.http) {
            if (!window.confirm('Open the unsubscribe page for ' + sender + '?\n\n' + u.http +
                '\n\nIt opens in a new tab. Continue only if you trust this sender.')) return;
            window.open(u.http, '_blank', 'noopener,noreferrer');
            return;
        }
        if (u.mailto) {
            const p = parseMailto(u.mailto);
            openCompose({
                title:   'Unsubscribe',
                to:      p.to,
                subject: p.subject || 'Unsubscribe',
            });
        }
    }

    function showToast(msg, durationMs = 3500) {
        let el = $('appToast');
        if (!el) {
            el = document.createElement('div');
            el.id = 'appToast';
            el.className = 'app-toast';
            document.body.appendChild(el);
        }
        el.textContent = msg;
        el.classList.add('visible');
        clearTimeout(showToast._t);
        showToast._t = setTimeout(() => el.classList.remove('visible'), durationMs);
    }

    // Generic "action done · Undo" toast (its own node, separate from the Undo-Send
    // toast). The action has already been applied; clicking Undo calls onUndo().
    function showUndoActionToast(text, onUndo, seconds) {
        seconds = seconds || 7;
        let el = $('appToastAction');
        if (!el) { el = document.createElement('div'); el.id = 'appToastAction'; el.className = 'app-toast app-toast-undo'; document.body.appendChild(el); }
        let remaining = seconds, timer = null, used = false;
        const close = () => { if (timer) { clearInterval(timer); timer = null; } el.classList.remove('visible'); };
        el.innerHTML = '';
        const txt = document.createElement('span'); txt.textContent = text + ' ';
        const btn = document.createElement('button'); btn.className = 'app-toast-action'; btn.type = 'button'; btn.textContent = 'Undo';
        btn.addEventListener('click', () => { if (used) return; used = true; close(); Promise.resolve(onUndo()).catch(() => {}); });
        const counter = document.createElement('span'); counter.className = 'app-toast-counter'; counter.textContent = remaining + 's';
        el.appendChild(txt); el.appendChild(btn); el.appendChild(counter);
        el.classList.add('visible');
        timer = setInterval(() => { remaining--; if (remaining <= 0) { close(); return; } const c = el.querySelector('.app-toast-counter'); if (c) c.textContent = remaining + 's'; }, 1000);
    }

    /* ---------- Snooze ---------- */
    function snoozeQuickOptions() {
        const now = new Date();
        const tomorrow = new Date(now); tomorrow.setDate(now.getDate() + 1);
        const setTime = (d, h, m) => { const x = new Date(d); x.setHours(h, m || 0, 0, 0); return x; };

        const opts = [];
        // Later today (only if before 4pm)
        if (now.getHours() < 16) {
            opts.push({ label: 'Later today',     sub: '6:00 PM', when: setTime(now, 18, 0) });
        }
        opts.push({ label: 'Tomorrow morning',     sub: tomorrow.toLocaleDateString(undefined, { weekday: 'short' }) + ' 9:00 AM', when: setTime(tomorrow, 9, 0) });
        opts.push({ label: 'Tomorrow afternoon',   sub: tomorrow.toLocaleDateString(undefined, { weekday: 'short' }) + ' 1:00 PM', when: setTime(tomorrow, 13, 0) });

        // This weekend = next Saturday
        const sat = new Date(now);
        const daysToSat = (6 - now.getDay() + 7) % 7 || 7;
        sat.setDate(now.getDate() + daysToSat);
        opts.push({ label: 'This weekend', sub: sat.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }) + ' 9:00 AM', when: setTime(sat, 9, 0) });

        // Next week = next Monday
        const mon = new Date(now);
        const daysToMon = (1 - now.getDay() + 7) % 7 || 7;
        mon.setDate(now.getDate() + daysToMon);
        opts.push({ label: 'Next week', sub: mon.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }) + ' 9:00 AM', when: setTime(mon, 9, 0) });

        return opts;
    }
    function openSnoozePopover(x, y, uids) {
        let menu = $('snoozeMenu');
        if (!menu) {
            menu = document.createElement('div');
            menu.id = 'snoozeMenu';
            menu.className = 'snooze-menu';
            menu.hidden = true;
            document.body.appendChild(menu);
        }
        const opts = snoozeQuickOptions();
        menu.innerHTML =
            '<div class="snooze-menu-header">Snooze until…</div>' +
            opts.map((o, i) =>
                '<button type="button" class="snooze-item" data-idx="' + i + '"><span class="snooze-item-label">' + escapeHtml(o.label) + '</span><span class="snooze-item-sub">' + escapeHtml(o.sub) + '</span></button>'
            ).join('') +
            '<div class="snooze-divider"></div>' +
            '<div class="snooze-custom">' +
                '<label class="snooze-item-label">Pick date and time</label>' +
                '<input type="datetime-local" class="snooze-custom-input" id="snoozeCustomInput">' +
                '<button type="button" class="snooze-custom-go" id="snoozeCustomGo">Snooze</button>' +
            '</div>';
        menu.dataset.uids = uids.join(',');

        menu.hidden = false;
        const r = menu.getBoundingClientRect();
        const winW = window.innerWidth, winH = window.innerHeight;
        const left = Math.min(x, winW - r.width  - 8);
        const top  = Math.min(y, winH - r.height - 8);
        menu.style.left = left + 'px';
        menu.style.top  = top  + 'px';

        // Wire option clicks
        menu.querySelectorAll('.snooze-item').forEach((btn, i) => {
            btn.addEventListener('click', () => doSnooze(uids, opts[i].when));
        });
        const customBtn = $('snoozeCustomGo');
        const customIn  = $('snoozeCustomInput');
        if (customBtn && customIn) {
            const minDt = new Date(Date.now() + 60000);
            customIn.min = minDt.toISOString().slice(0, 16);
            customBtn.addEventListener('click', () => {
                if (!customIn.value) { customIn.focus(); return; }
                const local = new Date(customIn.value);
                if (isNaN(local) || local <= new Date()) { alert('Pick a time in the future.'); return; }
                doSnooze(uids, local);
            });
        }
    }
    function closeSnoozePopover() {
        const menu = $('snoozeMenu');
        if (menu) menu.hidden = true;
    }
    async function doSnooze(uids, whenLocal) {
        closeSnoozePopover();
        if (!uids || !uids.length) return;
        const wakeAt = new Date(whenLocal).toISOString().replace(/\.\d{3}Z$/, 'Z');
        const r = await fetch('ajax/snooze.php?action=add', {
            method: 'POST',
            headers: csrfHeaders({ 'Content-Type': 'application/json' }),
            credentials: 'same-origin',
            body: JSON.stringify({ uids, folder: state.currentFolder, wake_at: wakeAt }),
        }).then(r => r.json());
        if (r.error) { alert(r.error); return; }
        if (state.currentUid && uids.includes(state.currentUid)) clearReadingPane();
        clearSelection();
        await Promise.all([loadMessages({ keepReading: true }), loadFolders()]);
    }
    // Background jobs (outbox flush, OOO, rules, snooze wake) only ever ran for
    // the active account, so scheduled sends / wakes / auto-replies for any
    // OTHER signed-in account silently stalled until the user switched to it.
    // Fan each job out per account, but bound shared-host load: always sweep the
    // focused account, plus ONE rotating other account per cycle.
    function acctParam(id) { return id ? ('&acct=' + encodeURIComponent(id)) : ''; }
    function bgPollAccounts() {
        const ids = state.accounts.map(a => a.id).filter(Boolean);
        if (ids.length <= 1) return ['']; // single account: plain path, no acct param
        const active = viewAcct() || state.primaryAccount || ids[0];
        const others = ids.filter(id => id !== active);
        if (!others.length) return [active];
        const pick = others[state.bgAcctCursor % others.length];
        state.bgAcctCursor = (state.bgAcctCursor + 1) % others.length;
        return [active, pick];
    }
    // The account currently on screen, for deciding whether a wake should refresh
    // the visible list. '' (single-account plain path) counts as focused.
    function bgIsFocused(id) {
        return id === '' || id === (viewAcct() || state.primaryAccount || '');
    }

    async function processOutbox() {
        for (const id of bgPollAccounts()) {
            try {
                await fetch('ajax/outbox.php?action=process' + acctParam(id), { method: 'POST', credentials: 'same-origin', headers: csrfHeaders() });
            } catch (e) { /* best-effort */ }
        }
    }

    async function runOutOfOffice() {
        for (const id of bgPollAccounts()) {
            try {
                await fetch('ajax/out_of_office.php?action=process' + acctParam(id), { method: 'POST', credentials: 'same-origin', headers: csrfHeaders() });
            } catch (e) { /* best-effort */ }
        }
    }

    async function runRulesOnNew() {
        for (const id of bgPollAccounts()) {
            try {
                await fetch('ajax/rules.php?action=run_new' + acctParam(id), { method: 'POST', credentials: 'same-origin', headers: csrfHeaders() });
                // Moves are picked up by the next folders+messages refresh.
            } catch (e) { /* best-effort */ }
        }
    }

    async function checkSnoozeWake() {
        for (const id of bgPollAccounts()) {
            try {
                const r = await fetch('ajax/snooze.php?action=wake' + acctParam(id), { method: 'POST', credentials: 'same-origin', headers: csrfHeaders() });
                const data = await r.json();
                // Only refresh the on-screen list for the account being viewed;
                // other accounts' wakes surface when the user switches to them.
                if (bgIsFocused(id) && data && data.count > 0) {
                    const tasks = [loadFolders()];
                    if (state.currentFolder === 'INBOX') tasks.push(loadMessages({ keepReading: true, silent: true }));
                    await Promise.all(tasks);
                }
            } catch (e) { /* best-effort */ }
        }
    }

    /* ---------- Drag and drop messages → folders ---------- */
    let _dragUids = null;
    function wireDragAndDrop() {
        const list = $('messageList');
        if (!list) return;
        list.addEventListener('dragstart', (e) => {
            const item = e.target.closest('.msg-item');
            if (!item) return;
            const uid = parseInt(item.dataset.uid, 10);
            if (isNaN(uid)) return;
            // If the dragged row is part of a selection, drag the whole selection;
            // otherwise drag just this row.
            _dragUids = state.selectedUids.has(uid) && state.selectedUids.size > 1
                ? Array.from(state.selectedUids)
                : [uid];
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', _dragUids.join(','));
            document.body.classList.add('dragging-msg');
        });
        list.addEventListener('dragend', () => {
            _dragUids = null;
            document.body.classList.remove('dragging-msg');
            document.querySelectorAll('.folder-item.drop-target').forEach(el => el.classList.remove('drop-target'));
        });

        const folderList = $('folderList');
        if (!folderList) return;
        folderList.addEventListener('dragover', (e) => {
            const target = e.target.closest('.folder-item');
            if (!target || !target.dataset.folder) return;
            if (target.dataset.folder === OUTBOX_FOLDER) return; // can't move mail into the Outbox
            if (target.dataset.folder === state.currentFolder) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            // Highlight only the current target
            folderList.querySelectorAll('.folder-item.drop-target').forEach(el => {
                if (el !== target) el.classList.remove('drop-target');
            });
            target.classList.add('drop-target');
        });
        folderList.addEventListener('dragleave', (e) => {
            const target = e.target.closest('.folder-item');
            if (target) target.classList.remove('drop-target');
        });
        folderList.addEventListener('drop', async (e) => {
            const target = e.target.closest('.folder-item');
            if (!target || !target.dataset.folder) return;
            e.preventDefault();
            target.classList.remove('drop-target');
            const folderName = target.dataset.folder;
            if (folderName === OUTBOX_FOLDER) return; // not a real mailbox
            const targetAcct = target.dataset.acct || '';
            const uids = _dragUids || [];
            _dragUids = null;
            if (!uids.length) return;
            // Moves stay within one account: reject drops that would cross
            // accounts (the unified inbox, or dropping onto another account's
            // expanded folder).
            const groups = groupTargets(uids);
            if (groups.length !== 1 || (targetAcct && groups[0].acct && groups[0].acct !== targetAcct)) {
                alert('Messages can only be moved within the same account.');
                return;
            }
            if (folderName === groups[0].folder) return;
            await actMoveTo(folderName, uids);
        });
    }

    /* ---------- Keyboard shortcuts ---------- */
    function navigateMessage(direction) {
        const visible = visibleMessages();
        if (!visible.length) return;
        let idx = visible.findIndex(m => m.uid === state.currentUid);
        if (idx < 0) idx = direction > 0 ? -1 : visible.length;
        idx += direction;
        if (idx < 0) idx = 0;
        if (idx >= visible.length) idx = visible.length - 1;
        const target = visible[idx];
        if (!target) return;
        loadMessage(target.uid);
        // Scroll the row into view
        setTimeout(() => {
            const row = $('messageList').querySelector('.msg-item[data-uid="' + target.uid + '"]');
            if (row && typeof row.scrollIntoView === 'function') {
                row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }
        }, 30);
    }
    function isTypingTarget(t) {
        if (!t) return false;
        if (t.isContentEditable) return true;
        const tag = t.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
        return false;
    }
    function openShortcutsHelp() {
        const m = $('shortcutsModal');
        if (!m) return;
        m.classList.remove('hidden');
        m.setAttribute('aria-hidden', 'false');
        modalTrap.activate(m);
    }
    function closeShortcutsHelp() {
        const m = $('shortcutsModal');
        if (!m) return;
        m.classList.add('hidden');
        m.setAttribute('aria-hidden', 'true');
        modalTrap.deactivate(m);
    }
    function handleShortcut(e) {
        if (e.metaKey || e.ctrlKey || e.altKey) return;
        if (isTypingTarget(e.target)) return;
        // If a modal is open (compose, attachment preview, ctx menus, calendar, shortcuts) skip
        if (state.composeOpen) return;
        if ($('attachPreview') && !$('attachPreview').classList.contains('hidden')) return;
        if ($('shortcutsModal') && !$('shortcutsModal').classList.contains('hidden')) {
            if (e.key === 'Escape') { e.preventDefault(); closeShortcutsHelp(); }
            return;
        }
        if ($('calModal') && !$('calModal').classList.contains('hidden')) return;
        // Also suppress while any other modal / popover is open, so a shortcut key
        // can't act on the message hidden behind it (mirrors the Escape handler).
        if ($('addAccountModal') && !$('addAccountModal').classList.contains('hidden')) return;
        if ($('filterModal') && !$('filterModal').classList.contains('hidden')) return;
        if ($('snoozeMenu')    && !$('snoozeMenu').hidden)    return;
        if ($('msgCtxMenu')    && !$('msgCtxMenu').hidden)    return;
        if ($('folderCtxMenu') && !$('folderCtxMenu').hidden) return;

        const k = e.key;
        switch (k) {
            case 'j': case 'ArrowDown': e.preventDefault(); navigateMessage(+1); return;
            case 'k': case 'ArrowUp':   e.preventDefault(); navigateMessage(-1); return;
            case '/':                   e.preventDefault(); $('searchInput').focus(); return;
            case 'c':                   e.preventDefault(); openCompose({}); return;
            case '?':                   e.preventDefault(); openShortcutsHelp(); return;
        }

        // The remaining shortcuts need a target — selection or open message
        const targets = actionTargetUids();
        if (!targets.length && k !== 'Enter') return;

        switch (k) {
            case 'Enter':
                if (state.currentUid) { e.preventDefault(); /* already viewing */ return; }
                e.preventDefault();
                navigateMessage(+1);
                return;
            case 'r':                  e.preventDefault(); if (state.currentMessage) startReply(false); return;
            case 'a':                  e.preventDefault(); if (state.currentMessage) startReply(true);  return;
            case 'f':                  e.preventDefault(); if (state.currentMessage) startForward();    return;
            case 'e':                  e.preventDefault(); actArchive(); return;
            case '#':
            case 'Backspace':
            case 'Delete':             e.preventDefault(); actDelete();  return;
            case 'u':                  e.preventDefault(); actReadToggle(); return;
            case 's':                  e.preventDefault(); actFlag(); return;
        }
    }

    /* ---------- Event wiring ---------- */
    function wire() {
        $('drawerToggle').addEventListener('click', toggleFolderPane);

        if ($('readingBackBtn')) {
            $('readingBackBtn').addEventListener('click', () => {
                $('readingPane').classList.remove('mobile-active');
            });
        }

        $('topbarLogo').addEventListener('click', async (e) => {
            e.preventDefault();
            state.currentFolder = 'INBOX';
            state.currentPage   = 1;
            state.searchQuery   = '';
            $('searchInput').value = '';
            clearReadingPane();
            renderFolders();
            renderMoveDropdown();
            await Promise.all([loadFolders(), loadMessages()]);
        });

        $('folderList').addEventListener('click', (e) => {
            // Add a new account.
            if (e.target.closest('[data-add-account]')) { openAddAccount(); return; }
            // Remove an account (its X button sits inside the header — stop the
            // event so it doesn't also toggle/focus the group).
            const rm = e.target.closest('[data-acct-remove]');
            if (rm) { e.stopPropagation(); removeAccount(rm.dataset.acctRemove); return; }
            // Unified "All accounts" view.
            if (e.target.closest('[data-unified]')) { selectUnified(); return; }
            // Account header → expand/collapse + focus.
            const header = e.target.closest('[data-acct-header]');
            if (header) { onAccountHeaderClick(header.dataset.acctHeader); return; }
            // A folder inside an account group.
            const btn = e.target.closest('.folder-item');
            if (!btn || !btn.dataset.folder) return;
            selectFolder(btn.dataset.acct || '', btn.dataset.folder);
        });

        // Keyboard affordance for the account header (rendered as role=button).
        $('folderList').addEventListener('keydown', (e) => {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            const header = e.target.closest('[data-acct-header]');
            if (!header) return;
            e.preventDefault();
            onAccountHeaderClick(header.dataset.acctHeader);
        });

        // Add-account modal.
        if ($('addAccountModal')) {
            $('addAccountClose').addEventListener('click', closeAddAccount);
            $('aaCancel').addEventListener('click', closeAddAccount);
            $('addAccountForm').addEventListener('submit', submitAddAccount);
            $('aaAdvancedToggle').addEventListener('click', () => {
                const adv = $('aaAdvanced');
                const open = adv.classList.toggle('hidden');
                $('aaAdvancedToggle').setAttribute('aria-expanded', open ? 'false' : 'true');
            });
            // Click on the dimmed backdrop closes the modal.
            $('addAccountModal').addEventListener('click', (e) => {
                if (e.target.closest('[data-close]')) closeAddAccount();
            });
        }

        $('listFilters').addEventListener('click', (e) => {
            const chip = e.target.closest('.list-filter-chip');
            if (!chip) return;
            const f = chip.dataset.filter || 'all';
            if (f === state.listFilter) return;
            state.listFilter = f;
            state.selectedUids.clear();
            state.lastSelectedIndex = -1;
            renderMessages();
            updateActionBar();
        });

        $('messageList').addEventListener('click', (e) => {
            // Outbox view: rows are queued sends, not messages — handle Cancel/Retry
            // and swallow other clicks (nothing to open).
            if (state.currentFolder === OUTBOX_FOLDER) {
                const cancelEl = e.target.closest('[data-outbox-cancel]');
                if (cancelEl) { cancelOutbox(viewAcct(), cancelEl.dataset.outboxCancel); return; }
                const retryEl = e.target.closest('[data-outbox-retry]');
                if (retryEl) { retryOutbox(viewAcct(), retryEl.dataset.outboxRetry); return; }
                return;
            }
            // "Search full message text" affordance shown after a fast header search.
            if (e.target.closest('[data-full-search]')) {
                if (state.searchQuery) runServerSearch(state.searchQuery, 'full');
                return;
            }
            const item = e.target.closest('.msg-item');
            if (!item) return;
            const uid = parseInt(item.dataset.uid, 10);
            if (isNaN(uid)) return;

            // Selection clicks: avatar/checkbox area, OR meta/ctrl-click anywhere in row,
            // OR plain click anywhere when selection is already active.
            const onSelectArea  = !!e.target.closest('[data-action="select"]');
            const modifierClick = e.metaKey || e.ctrlKey || e.shiftKey;
            const inSelectionMode = state.selectedUids.size > 0;
            if (onSelectArea || modifierClick || inSelectionMode) {
                e.preventDefault();
                const visible = visibleMessages();
                const idx = visible.findIndex(m => m.uid === uid);
                toggleSelectionAt(uid, idx, e.shiftKey);
                return;
            }

            // Drafts: clicking a saved draft reopens it in the composer to keep
            // editing (rather than the read-only reading pane).
            if (!state.searchActive && folderType(state.currentFolder) === 'drafts') {
                e.preventDefault();
                resumeDraft(uid);
                return;
            }

            // Search results carry their origin folder; switch to it before opening
            // so the thread fetch and any subsequent action operates in the right
            // scope. Regular list rows (including the unified inbox) must NOT
            // trigger a reload — loadMessage() already routes to the row's own
            // account/folder, and reloading would replace the unified list.
            const itemFolder = item.dataset.folder;
            if (state.searchActive && itemFolder && itemFolder !== state.currentFolder) {
                state.currentFolder = itemFolder;
                renderFolders();
                renderMoveDropdown();
                loadMessages({ keepReading: true });
            }
            loadMessage(uid);
        });

        // Right-click → message context menu
        $('messageList').addEventListener('contextmenu', (e) => {
            const item = e.target.closest('.msg-item');
            if (!item) return;
            const uid = parseInt(item.dataset.uid, 10);
            if (isNaN(uid)) return;
            e.preventDefault();
            openMsgCtxMenu(e.clientX, e.clientY, uid);
        });

        $('searchInput').addEventListener('input', (e) => {
            const q = e.target.value.trim();
            state.searchQuery = q;

            clearTimeout(searchDebounceTimer);
            if (searchAbortCtrl) searchAbortCtrl.abort();

            if (q.length < 2) { exitSearchMode(); return; }

            // Debounce so the server isn't hit on every keystroke; cached
            // results render instantly without a debounce wait. Key must match
            // runServerSearch's `scope + '|' + q` (default scope = headers), or
            // this fast path never hits and every repeat search re-waits 300ms.
            const cacheKey = 'headers|' + q.toLowerCase();
            if (searchCache.has(cacheKey)) {
                state.searchActive  = true;
                state.searchResults = searchCache.get(cacheKey);
                renderSearchResults();
                return;
            }
            searchDebounceTimer = setTimeout(() => runServerSearch(q), 300);
        });

        $('prevPage').addEventListener('click', () => {
            if (state.currentPage > 1) { state.currentPage--; loadMessages(); }
        });
        $('nextPage').addEventListener('click', () => {
            if (state.currentPage < state.totalPages) { state.currentPage++; loadMessages(); }
        });

        $('composeBtn').addEventListener('click', () => openCompose());
        $('composeClose').addEventListener('click', closeCompose);
        if ($('composeDiscard')) $('composeDiscard').addEventListener('click', discardDraft);
        if ($('composeMinimize')) $('composeMinimize').addEventListener('click', () => setComposeMode(state.composeMode === 'minimized' ? 'docked' : 'minimized'));
        if ($('composeExpand')) $('composeExpand').addEventListener('click', () => setComposeMode(state.composeMode === 'expanded' ? 'docked' : 'expanded'));
        // Click the header of a minimized compose to restore it (Gmail behaviour).
        const cmpHeader = document.querySelector('.compose-header');
        if (cmpHeader) cmpHeader.addEventListener('click', (e) => {
            if (state.composeMode === 'minimized' && !e.target.closest('.compose-header-actions')) setComposeMode('docked');
        });
        $('sendBtn').addEventListener('click', () => sendMessage());
        acAttach($('composeTo'));
        acAttach($('composeCc'));
        acAttach($('composeBcc'));
        $('composeBccToggle').addEventListener('click', () => {
            setBccVisible(true);
            $('composeBcc').focus();
        });
        // Autosave the compose to Drafts a few seconds after the last edit.
        ['composeTo', 'composeCc', 'composeBcc', 'composeSubject', 'composeBody'].forEach((id) => {
            const el = $(id); if (el) el.addEventListener('input', scheduleDraftSave);
        });

        if ($('sendChevBtn')) {
            $('sendChevBtn').addEventListener('click', (e) => {
                e.stopPropagation();
                const menu = $('sendMenu');
                if (!menu) return;
                menu.classList.toggle('hidden');
                if (!menu.classList.contains('hidden')) {
                    const inp = $('sendScheduleInput');
                    if (inp) {
                        const min = new Date(Date.now() + 60000);
                        const pad = n => String(n).padStart(2, '0');
                        inp.min = min.getFullYear() + '-' + pad(min.getMonth()+1) + '-' + pad(min.getDate()) + 'T' + pad(min.getHours()) + ':' + pad(min.getMinutes());
                        if (!inp.value) {
                            const def = new Date(Date.now() + 3600000);
                            inp.value = def.getFullYear() + '-' + pad(def.getMonth()+1) + '-' + pad(def.getDate()) + 'T' + pad(def.getHours()) + ':' + pad(def.getMinutes());
                        }
                    }
                }
            });
            document.addEventListener('click', (e) => {
                const menu = $('sendMenu');
                if (!menu || menu.classList.contains('hidden')) return;
                if (!e.target.closest('#sendMenu') && !e.target.closest('#sendChevBtn')) {
                    menu.classList.add('hidden');
                }
            });
            $('sendScheduleConfirm').addEventListener('click', () => {
                const inp = $('sendScheduleInput');
                if (!inp || !inp.value) return;
                const local = new Date(inp.value);
                if (isNaN(local) || local <= new Date()) { alert('Pick a time in the future.'); return; }
                $('sendMenu').classList.add('hidden');
                const iso = local.toISOString().replace(/\.\d{3}Z$/, 'Z');
                sendMessage(iso);
            });
        }

        // Attachments: file picker, chips, drag-drop on the modal
        const fileInput = $('composeFileInput');
        $('composeAttach').addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', (e) => {
            for (const f of e.target.files) addAttachment(f);
            e.target.value = '';
        });
        $('composeAttachmentsList').addEventListener('click', (e) => {
            const btn = e.target.closest('.attach-remove');
            if (!btn) return;
            removeAttachmentAt(parseInt(btn.dataset.remove, 10));
        });

        const modal   = $('composeModal');
        const overlay = $('composeDropOverlay');
        let dragDepth = 0;
        modal.addEventListener('dragenter', (e) => {
            if (!e.dataTransfer || !Array.from(e.dataTransfer.types || []).includes('Files')) return;
            e.preventDefault();
            dragDepth++;
            modal.classList.add('drag-active');
        });
        modal.addEventListener('dragover', (e) => {
            if (!e.dataTransfer || !Array.from(e.dataTransfer.types || []).includes('Files')) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'copy';
        });
        modal.addEventListener('dragleave', () => {
            dragDepth = Math.max(0, dragDepth - 1);
            if (dragDepth === 0) modal.classList.remove('drag-active');
        });
        modal.addEventListener('drop', (e) => {
            dragDepth = 0;
            modal.classList.remove('drag-active');
            if (!e.dataTransfer || !e.dataTransfer.files || e.dataTransfer.files.length === 0) return;
            e.preventDefault();
            for (const f of e.dataTransfer.files) addAttachment(f);
        });

        // Remember the caret/selection inside the compose body so tools that move
        // focus away (the colour picker, the link prompt) can restore it before
        // applying — otherwise the command lands on nothing.
        let composeSavedRange = null;
        function composeSaveSelection() {
            const sel = window.getSelection();
            if (!sel || sel.rangeCount === 0) return;
            const r = sel.getRangeAt(0);
            const body = $('composeBody');
            if (body && body.contains(r.commonAncestorContainer)) composeSavedRange = r.cloneRange();
        }
        function composeRestoreSelection() {
            const body = $('composeBody');
            if (body) body.focus();
            if (composeSavedRange) {
                const sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(composeSavedRange);
            }
        }
        document.addEventListener('selectionchange', composeSaveSelection);

        document.querySelectorAll('.compose-tool[data-cmd]').forEach((b) => {
            b.addEventListener('mousedown', (e) => {
                e.preventDefault(); // keep the body's selection while the button takes the click
                const cmd = b.dataset.cmd;
                if (cmd === 'createLink') {
                    composeSaveSelection();
                    const url = (window.prompt('Link URL:', 'https://') || '').trim();
                    composeRestoreSelection();
                    if (url && url !== 'https://') {
                        const sel = window.getSelection();
                        if (sel && sel.isCollapsed) {
                            // Nothing selected — insert the URL itself as a link.
                            document.execCommand('insertHTML', false,
                                '<a href="' + url.replace(/"/g, '&quot;') + '">' + escapeHtml(url) + '</a>');
                        } else {
                            document.execCommand('createLink', false, url);
                        }
                    }
                } else if (cmd === 'removeFormat') {
                    document.execCommand('removeFormat', false, null);
                    document.execCommand('formatBlock', false, 'div'); // also drop blockquote/heading blocks
                } else if (b.dataset.val) {
                    document.execCommand(cmd, false, b.dataset.val); // formatBlock → blockquote
                } else {
                    document.execCommand(cmd, false, null);
                }
                $('composeBody').focus();
            });
        });

        // Text colour: a native <input type=color> overlays the "A" button, so a
        // click opens the OS picker directly (reliable across browsers). Restore
        // the saved selection first so the colour lands on the selected text.
        const composeColorInput = $('composeColorInput');
        const composeColorBtn = $('composeColorBtn');
        if (composeColorInput && composeColorBtn) {
            const colorBar = composeColorBtn.querySelector('.compose-color-bar');
            if (colorBar) colorBar.style.background = composeColorInput.value;
            composeColorInput.addEventListener('input', () => {
                if (colorBar) colorBar.style.background = composeColorInput.value;
                composeRestoreSelection();
                try { document.execCommand('styleWithCSS', false, true); } catch (e) {}
                document.execCommand('foreColor', false, composeColorInput.value);
            });
        }

        // Enter reliability: pasted/resumed draft HTML (wrapped by wrap_html) and
        // some nested structures can leave the browser's native Enter unable to
        // start a new line. Handle Enter explicitly so it always inserts a line —
        // but leave list items to their native "new bullet/number" behaviour.
        const composeBodyEl = $('composeBody');
        if (composeBodyEl) {
            composeBodyEl.addEventListener('keydown', (e) => {
                if (e.key !== 'Enter' || e.isComposing || e.ctrlKey || e.metaKey || e.altKey) return;
                const sel = window.getSelection();
                let n = sel && sel.rangeCount ? sel.anchorNode : null;
                while (n && n !== composeBodyEl) {
                    const nm = n.nodeName;
                    if (nm === 'LI' || nm === 'UL' || nm === 'OL') return; // native list behaviour
                    n = n.parentNode;
                }
                e.preventDefault();
                document.execCommand(e.shiftKey ? 'insertLineBreak' : 'insertParagraph');
            });
        }

        $('replyBtn').addEventListener('click', () => startReply(false));
        $('replyAllBtn').addEventListener('click', () => startReply(true));
        $('forwardBtn').addEventListener('click', startForward);
        $('printBtn').addEventListener('click', printOpenThread);
        $('unsubscribeBtn').addEventListener('click', handleUnsubscribe);

        $('readThread').addEventListener('click', async (e) => {
            // RSVP button inside an invitation card.
            const rsvpBtn = e.target.closest('.invite-actions [data-rsvp]');
            if (rsvpBtn) {
                e.preventDefault();
                e.stopPropagation();
                await handleRsvp(rsvpBtn.closest('.invite-card'), rsvpBtn);
                return;
            }
            // Attachment download arrow (inside a card) — let the link handle it,
            // but stop propagation so the parent card doesn't open the preview.
            const dl = e.target.closest('.msg-attachment-dl');
            if (dl) { e.stopPropagation(); return; }
            // Click on an attachment card → open preview modal.
            const attCard = e.target.closest('.msg-attachment');
            if (attCard) { e.preventDefault(); e.stopPropagation(); openAttachmentPreview(attCard); return; }

            // Gmail-style "···" toggle for the quoted/trimmed trail within a body.
            const trimToggle = e.target.closest('.email-quote-toggle');
            if (trimToggle) {
                e.stopPropagation();
                const wrap    = trimToggle.parentElement;
                const content = wrap.querySelector('.email-quote-content');
                if (!content) return;
                const isOpen = !content.hasAttribute('hidden');
                if (isOpen) {
                    content.setAttribute('hidden', '');
                    trimToggle.classList.remove('open');
                    trimToggle.setAttribute('aria-expanded', 'false');
                    trimToggle.setAttribute('aria-label', 'Show trimmed content');
                    trimToggle.setAttribute('title', 'Show trimmed content');
                } else {
                    content.removeAttribute('hidden');
                    trimToggle.classList.add('open');
                    trimToggle.setAttribute('aria-expanded', 'true');
                    trimToggle.setAttribute('aria-label', 'Hide trimmed content');
                    trimToggle.setAttribute('title', 'Hide trimmed content');
                }
                return;
            }

            const header = e.target.closest('.thread-msg-header');
            if (!header) return;
            const card = header.parentElement;
            if (!card) return;
            const wasExpanded = card.classList.contains('expanded');
            card.classList.toggle('expanded');
            if (wasExpanded) return;

            const body = card.querySelector('.thread-msg-body');
            if (!body || body.dataset.loaded === 'true') return;
            const uid = parseInt(card.dataset.uid, 10);
            const folder = card.dataset.folder;
            if (!uid || !folder) return;
            body.innerHTML = '<div class="list-loading" style="text-align:left;padding:8px 0;">Loading message…</div>';
            const data = await apiGet('message', withAcct({ folder, uid }, state.currentMsgAcct));
            if (data.error) {
                body.innerHTML = '<div style="color:var(--c-error)">' + escapeHtml(data.error) + '</div>';
                return;
            }
            const msgForAtts = { folder, uid, acct: state.currentMsgAcct, attachments: data.attachments || [], invite: data.invite };
            body.innerHTML = renderInviteCard(msgForAtts) + renderMsgAttachments(msgForAtts) + (data.body || '');
            body.dataset.loaded = 'true';
            if (data.invite && state.thread) {
                const m = state.thread.find(x => (x.uid | 0) === uid && x.folder === folder);
                if (m) m.invite = data.invite;
            }
        });

        $('abDelete').addEventListener('click', actDelete);
        $('abArchive').addEventListener('click', actArchive);
        $('abFlag').addEventListener('click', actFlag);
        $('abMarkRead').addEventListener('click', () => actSetReadState(true));
        $('abMarkUnread').addEventListener('click', () => actSetReadState(false));
        $('abSync').addEventListener('click', actSync);

        $('abMove').addEventListener('click', (e) => {
            e.stopPropagation();
            if ($('abMove').disabled) return;
            const dd = $('moveDropdown');
            if (dd.classList.contains('open')) { dd.classList.remove('open'); return; }
            const r = $('abMove').getBoundingClientRect();
            dd.style.top = (r.bottom + 2) + 'px';
            dd.style.left = r.left + 'px';
            dd.classList.add('open');
        });
        $('moveDropdown').addEventListener('click', (e) => {
            const btn = e.target.closest('.dropdown-item');
            if (!btn) return;
            $('moveDropdown').classList.remove('open');
            actMoveTo(btn.dataset.target);
        });
        document.addEventListener('click', () => {
            $('moveDropdown').classList.remove('open');
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if (!$('attachPreview').classList.contains('hidden')) closeAttachmentPreview();
                else if ($('addAccountModal') && !$('addAccountModal').classList.contains('hidden')) closeAddAccount();
                else if ($('filterModal') && !$('filterModal').classList.contains('hidden')) closeFilterModal();
                else if ($('composeModal').classList.contains('open')) closeCompose();
                else if ($('snoozeMenu') && !$('snoozeMenu').hidden) closeSnoozePopover();
                else if ($('msgCtxMenu') && !$('msgCtxMenu').hidden) closeMsgCtxMenu();
                else if (!$('folderCtxMenu').hidden) hideFolderCtx();
                else if ($('moveDropdown').classList.contains('open')) $('moveDropdown').classList.remove('open');
                else if (state.selectedUids.size > 0) clearSelection();
            }
        });
        document.addEventListener('click', (e) => {
            const menu = $('snoozeMenu');
            if (menu && !menu.hidden && !e.target.closest('#snoozeMenu')) closeSnoozePopover();
        });

        // Message context menu wiring
        if ($('msgCtxMenu')) {
            $('msgCtxMenu').addEventListener('click', async (e) => {
                const btn = e.target.closest('.ctx-item');
                if (!btn) return;
                e.stopPropagation();                  // keep snooze popover from being closed by the outside-click handler
                const action = btn.dataset.msgAction;
                const target = _msgCtxTarget;         // capture BEFORE close, then clear
                closeMsgCtxMenu();
                await dispatchMsgCtxAction(action, target);
            });
            document.addEventListener('click', (e) => {
                const menu = $('msgCtxMenu');
                if (!menu.hidden && !e.target.closest('#msgCtxMenu')) closeMsgCtxMenu();
            });
        }
        // Selection bar wiring
        if ($('listSelectionClose')) {
            $('listSelectionClose').addEventListener('click', clearSelection);
        }
        if ($('listSelectAll')) {
            $('listSelectAll').addEventListener('change', (e) => {
                const visible = visibleMessages();
                if (e.target.checked) {
                    visible.forEach(m => state.selectedUids.add(m.uid));
                } else {
                    visible.forEach(m => state.selectedUids.delete(m.uid));
                }
                renderMessages();
                updateActionBar();
                updateSelectionBar();
            });
        }

        $('attachPreview').addEventListener('click', (e) => {
            if (e.target.closest('[data-close="1"]')) closeAttachmentPreview();
        });

        // ----- Folder context menu (right-click on a folder) -----
        let ctxFolder = null;
        function showFolderCtx(name, x, y) {
            ctxFolder = name;
            const menu = $('folderCtxMenu');
            // Hide Delete (and its divider) for standard folders.
            const userFolder = !isStandardFolder(name);
            const delBtn = menu.querySelector('[data-action="delete-folder"]');
            const delDiv = menu.querySelector('.ctx-divider-folder-delete');
            if (delBtn) delBtn.style.display = userFolder ? '' : 'none';
            if (delDiv) delDiv.style.display = userFolder ? '' : 'none';

            menu.hidden = false;
            const r = menu.getBoundingClientRect();
            const winW = window.innerWidth, winH = window.innerHeight;
            const left = Math.min(x, winW - r.width  - 8);
            const top  = Math.min(y, winH - r.height - 8);
            menu.style.left = left + 'px';
            menu.style.top  = top  + 'px';
        }
        function hideFolderCtx() {
            $('folderCtxMenu').hidden = true;
            ctxFolder = null;
        }

        $('folderList').addEventListener('contextmenu', (e) => {
            const btn = e.target.closest('.folder-item');
            if (!btn || !btn.dataset.folder) return;
            if (btn.dataset.folder === OUTBOX_FOLDER) return; // no rename/delete/mark-read on the virtual Outbox
            e.preventDefault();
            showFolderCtx(btn.dataset.folder, e.clientX, e.clientY);
        });
        document.addEventListener('click', (e) => {
            if (!$('folderCtxMenu').hidden && !e.target.closest('#folderCtxMenu')) hideFolderCtx();
        });
        $('folderCtxMenu').addEventListener('click', async (e) => {
            const item = e.target.closest('.ctx-item');
            if (!item) return;
            e.stopPropagation();
            const action = item.dataset.action;
            const folderName = ctxFolder;
            hideFolderCtx();
            if (!folderName) return;

            if (action === 'mark-read') {
                const display = displayFolderName(folderName);
                if (!window.confirm('Mark every message in "' + display + '" as read?')) return;
                const r = await apiPost('mark_folder_read', { folder: folderName });
                if (r.error) { alert(r.error); return; }
                const tasks = [loadFolders()];
                if (folderName === state.currentFolder) tasks.push(loadMessages({ keepReading: true }));
                await Promise.all(tasks);
            } else if (action === 'new-subfolder') {
                createFolder(folderName);
            } else if (action === 'delete-folder') {
                if (isStandardFolder(folderName)) return;
                const display = displayFolderName(folderName);
                if (!window.confirm('Delete folder "' + display + '"? Messages inside will also be removed by your mail server.')) return;
                const r = await apiPost('delete_folder', { name: folderName });
                if (r.error) { alert(r.error); return; }
                if (state.currentFolder === folderName) {
                    state.currentFolder = 'INBOX';
                    state.currentPage = 1;
                    clearReadingPane();
                }
                await Promise.all([loadFolders(), loadMessages({ keepReading: true })]);
            }
        });

        // ----- Create folder -----
        async function createFolder(parent) {
            const promptText = parent
                ? 'New subfolder under "' + displayFolderName(parent) + '":'
                : 'New folder name:';
            const name = window.prompt(promptText, '');
            if (!name || !name.trim()) return;
            const r = await apiPost('create_folder', { name: name.trim(), parent: parent || '' });
            if (r.error) { alert(r.error); return; }
            await loadFolders();
        }
        $('folderNewBtn').addEventListener('click', () => createFolder(''));

        // Keyboard shortcuts
        document.addEventListener('keydown', handleShortcut);
        if ($('shortcutsModal')) {
            $('shortcutsModal').addEventListener('click', (e) => {
                if (e.target.closest('[data-shortcuts-close="1"]')) closeShortcutsHelp();
            });
        }
        // Filter ("rule") modal
        if ($('filterModal')) {
            $('filterModal').addEventListener('click', (e) => {
                if (e.target.closest('[data-filter-close="1"]')) closeFilterModal();
            });
            $('filterSaveBtn').addEventListener('click', saveFilterFromModal);
        }
    }

    /* ---------- Auto-refresh polling ---------- */
    async function pollForUpdates() {
        if (state.pollInflight) return;
        if (state.composeOpen) return;
        if (document.hidden) return;                          // pause entirely while backgrounded (saves host memory)
        if (state.pollSkip > 0) { state.pollSkip--; return; } // exponential backoff after errors
        state.pollInflight = true;
        state.pollCount = (state.pollCount || 0) + 1;
        // Cost control on shared hosting: the frequent path is ONE cheap status
        // probe (single imap_status). The heavy side-effect jobs + the full
        // per-folder refresh run only every Nth cycle (or when mail is detected),
        // and the jobs are additionally throttled SERVER-side (poll_gate) so their
        // IMAP work runs at most once every ~2 min across ALL open tabs.
        const HEAVY_EVERY = 3;
        const doHeavy = (state.pollCount % HEAVY_EVERY) === 1; // cycles 1, 4, 7, …
        let failed = false;

        try {
            const acct = viewAcct() || state.primaryAccount || '';

            if (doHeavy) {
                // Independent side-effects; parallel to save round-trip latency.
                // Each is a cheap no-op server-side unless its ~2 min window is due.
                await Promise.all([
                    checkSnoozeWake(),
                    processOutbox(),
                    runOutOfOffice(),
                    runRulesOnNew(),
                ]);
            }

            // Keep the Outbox badge (and the open Outbox view) current: after the
            // heavy cycle that may have flushed/failed queued mail, or any cycle
            // while the Outbox is on screen. Cheap local file read, no IMAP.
            if (doHeavy || state.currentFolder === OUTBOX_FOLDER) loadOutbox(acct);

            const prevInboxUnread = inboxUnreadCount();
            // Never probe the virtual Outbox against IMAP — fall back to INBOX so
            // new-mail detection keeps working while the Outbox is on screen.
            const cur   = (state.currentFolder && state.currentFolder !== OUTBOX_FOLDER) ? state.currentFolder : 'INBOX';
            const probe = await apiGet('status', withAcct({ folder: cur }, acct));
            if (probe && probe.error && probe.network) failed = true; // host unreachable → back off
            const prevCurrent = state.folders.find(f => f.name === state.currentFolder);
            const grew = probe && !probe.error && prevCurrent && probe.total > prevCurrent.total;

            // Refresh all sidebar counts (also updates the tab title + app badge via
            // renderFolders) on the heavy cycle or when the viewed folder grew.
            if (doHeavy || grew) {
                const data = await apiGet('folders', withAcct({}, acct));
                if (data && !data.error && Array.isArray(data.folders)) {
                    state.folders = data.folders;
                    if (acct) state.accountFolders[acct] = data.folders;
                    renderFolders();
                    renderMoveDropdown();
                }
            }

            // Desktop notification when inbox unread rose (no-op while focused).
            const newInboxUnread = inboxUnreadCount();
            maybeNotifyNewMail(newInboxUnread - prevInboxUnread, newInboxUnread);

            // New mail in the folder we're viewing → silently refresh the list.
            // Skip if mid-search or on an older page.
            if (grew && !state.searchQuery && state.currentPage === 1) {
                await loadMessages({ keepReading: true, silent: true });
            }
        } catch (e) {
            failed = true; // network/other error — back off
        } finally {
            state.pollInflight = false;
            // Exponential backoff: after consecutive failures skip up to ~16 cycles
            // so we don't hammer a struggling host; reset immediately on success.
            if (failed) {
                state.pollFail = Math.min((state.pollFail || 0) + 1, 4);
                state.pollSkip = Math.pow(2, state.pollFail); // 2,4,8,16 cycles
            } else {
                state.pollFail = 0;
                state.pollSkip = 0;
            }
        }
    }

    function startPolling() {
        if (state.pollTimer) clearInterval(state.pollTimer);
        state.pollTimer = setInterval(pollForUpdates, state.pollIntervalMs);
    }

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) { state.pollSkip = 0; pollForUpdates(); } // returning to the tab → poll now
    });
    window.addEventListener('focus', () => { state.pollSkip = 0; pollForUpdates(); });

    /* ---------- Message context menu ---------- */
    let _msgCtxTarget = null; // { uids, single }
    function openMsgCtxMenu(x, y, uid) {
        const menu = $('msgCtxMenu');
        if (!menu) return;
        // Determine targets: if right-clicked uid is part of selection, use selection; else just that uid.
        const inSelection = state.selectedUids.has(uid);
        const uids = inSelection ? Array.from(state.selectedUids) : [uid];
        const targets = targetMessages(uids);
        const single = uids.length === 1;
        _msgCtxTarget = { uids, single };

        // Toggle visibility of single-only items
        menu.querySelectorAll('[data-msg-action="reply"], [data-msg-action="reply-all"], [data-msg-action="forward"]')
            .forEach(el => el.style.display = single ? '' : 'none');
        const replyDivider = menu.querySelector('.ctx-divider-reply');
        if (replyDivider) replyDivider.style.display = single ? '' : 'none';

        // Update toggle labels
        const anyUnread  = targets.some(m => !m.seen);
        const anyFlagged = targets.some(m => m.flagged);
        const readLabel  = menu.querySelector('.ctx-read-label');
        const flagLabel  = menu.querySelector('.ctx-flag-label');
        if (readLabel) readLabel.textContent = anyUnread ? 'Mark as read' : 'Mark as unread';
        if (flagLabel) flagLabel.textContent = anyFlagged ? 'Unflag' : 'Flag';

        // Show & position
        menu.hidden = false;
        const r = menu.getBoundingClientRect();
        const winW = window.innerWidth, winH = window.innerHeight;
        const left = Math.min(x, winW - r.width  - 8);
        const top  = Math.min(y, winH - r.height - 8);
        menu.style.left = left + 'px';
        menu.style.top  = top  + 'px';
    }
    function closeMsgCtxMenu() {
        const menu = $('msgCtxMenu');
        if (menu) menu.hidden = true;
        _msgCtxTarget = null;
    }
    async function ensureCurrentMessage(uid) {
        if (state.currentUid === uid && state.currentMessage) return;
        await loadMessage(uid);
    }
    async function dispatchMsgCtxAction(action, target) {
        if (!target) return;
        const { uids, single } = target;
        const uid = uids[0];
        if (action === 'reply' || action === 'reply-all' || action === 'forward') {
            if (!single) return;
            await ensureCurrentMessage(uid);
            if (action === 'reply')          startReply(false);
            else if (action === 'reply-all') startReply(true);
            else                             startForward();
            return;
        }
        if (action === 'read-toggle') return actReadToggle(uids);
        if (action === 'flag-toggle') return actFlag(uids);
        if (action === 'archive')     return actArchive(uids);
        if (action === 'delete')      return actDelete(uids);
        if (action === 'block')       return blockSender(uids);
        if (action === 'snooze') {
            const x = window.innerWidth  / 2 - 110;
            const y = window.innerHeight / 2 - 200;
            openSnoozePopover(x, y, uids);
            return;
        }
        if (action === 'filter-like') {
            if (!single) {
                // For multi-select, just use the first message's sender as a hint
                openFilterFromMessage(uids[0]);
                return;
            }
            openFilterFromMessage(uid);
            return;
        }
    }

    /* ---------- Sidebar calendar ---------- */
    const cal = {
        viewYear:   new Date().getFullYear(),
        viewMonth:  new Date().getMonth(),
        selectedDate: dateOnlyKey(new Date()),
        events:     [],   // currently loaded range
        rangeFrom:  null,
        rangeTo:    null,
        editing:    null, // event id being edited (null = new)
        loaded:     false,
    };

    function dateOnlyKey(d) {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const da = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${da}`;
    }
    function startOfMonthUTC(year, month) {
        const d = new Date(year, month, 1);
        return new Date(d.getFullYear(), d.getMonth(), d.getDate(), 0, 0, 0).toISOString().replace(/\.\d{3}Z$/, 'Z');
    }
    function endOfMonthUTC(year, month) {
        const d = new Date(year, month + 1, 0, 23, 59, 59);
        return d.toISOString().replace(/\.\d{3}Z$/, 'Z');
    }
    function isoWindowAroundView() {
        // Load a wider window than the visible month so navigating back/forth feels fast
        const from = new Date(cal.viewYear, cal.viewMonth - 1, 1, 0, 0, 0).toISOString().replace(/\.\d{3}Z$/, 'Z');
        const to   = new Date(cal.viewYear, cal.viewMonth + 2, 0, 23, 59, 59).toISOString().replace(/\.\d{3}Z$/, 'Z');
        return { from, to };
    }
    function eventLocalDateKey(ev) {
        // For all-day events the start is normalized to midnight UTC; using
        // toLocaleDateString in the UTC zone keeps it on the intended date.
        const d = new Date(ev.start);
        if (ev.all_day) return d.toISOString().slice(0, 10);
        return dateOnlyKey(d);
    }

    async function calFetchEvents() {
        const { from, to } = isoWindowAroundView();
        cal.rangeFrom = from;
        cal.rangeTo   = to;
        try {
            const r = await fetch('ajax/calendar.php?action=events&from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to), {
                credentials: 'same-origin',
            });
            const data = await r.json();
            cal.events = Array.isArray(data.events) ? data.events : [];
            cal.loaded = true;
        } catch (e) {
            cal.events = [];
        }
        renderCalendar();
    }

    function renderCalendar() {
        renderMiniGrid();
        renderAgenda();
    }

    function renderMiniGrid() {
        const grid = $('calMiniGrid');
        if (!grid) return;
        const monthLabel = new Date(cal.viewYear, cal.viewMonth, 1).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
        $('calMonthLabel').textContent = monthLabel;

        const eventsByDay = {};
        for (const ev of cal.events) {
            const key = eventLocalDateKey(ev);
            if (!eventsByDay[key]) eventsByDay[key] = [];
            eventsByDay[key].push(ev);
        }

        const first = new Date(cal.viewYear, cal.viewMonth, 1);
        const startWeekday = (first.getDay() + 6) % 7; // 0=Mon
        const daysInMonth  = new Date(cal.viewYear, cal.viewMonth + 1, 0).getDate();
        const todayKey = dateOnlyKey(new Date());

        const headers = ['M','T','W','T','F','S','S']
            .map(d => '<div class="cal-mini-cell cal-mini-head">' + d + '</div>').join('');
        let cells = '';
        // leading blanks
        for (let i = 0; i < startWeekday; i++) cells += '<div class="cal-mini-cell cal-mini-empty"></div>';
        for (let day = 1; day <= daysInMonth; day++) {
            const key = `${cal.viewYear}-${String(cal.viewMonth+1).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
            const cls = ['cal-mini-cell', 'cal-mini-day'];
            if (key === todayKey) cls.push('today');
            if (key === cal.selectedDate) cls.push('selected');
            const evs = eventsByDay[key] || [];
            if (evs.length) cls.push('has-events');
            const dotColors = evs.slice(0, 3).map(e => e.feed_color || (e.source === 'feed' ? '#6b4c93' : 'var(--c-brand)'));
            const dots = dotColors.length
                ? '<span class="cal-mini-dots">' + dotColors.map(c => '<span class="cal-mini-dot" style="background:' + c + '"></span>').join('') + '</span>'
                : '';
            cells += '<button type="button" class="' + cls.join(' ') + '" data-key="' + key + '"><span class="cal-mini-num">' + day + '</span>' + dots + '</button>';
        }
        grid.innerHTML = headers + cells;
    }

    function renderAgenda() {
        const list = $('calAgendaList');
        const header = $('calAgendaHeader');
        if (!list || !header) return;

        const sel = cal.selectedDate;
        const todayKey = dateOnlyKey(new Date());
        const tomorrowKey = (() => {
            const t = new Date(); t.setDate(t.getDate() + 1);
            return dateOnlyKey(t);
        })();
        const dt = new Date(sel + 'T12:00:00');
        let label;
        if (sel === todayKey)         label = 'Today · ' + dt.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
        else if (sel === tomorrowKey) label = 'Tomorrow · ' + dt.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
        else                          label = dt.toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric' });
        header.textContent = label;

        const dayEvents = cal.events.filter(e => eventLocalDateKey(e) === sel);
        dayEvents.sort((a, b) => (a.all_day === b.all_day) ? a.start.localeCompare(b.start) : (a.all_day ? -1 : 1));

        if (dayEvents.length === 0) {
            list.innerHTML = '<div class="cal-agenda-empty">No events</div>';
            return;
        }
        list.innerHTML = dayEvents.map(e => {
            const color = e.feed_color || (e.source === 'feed' ? '#6b4c93' : '#1f5fb8');
            const time = e.all_day
                ? 'All day'
                : new Date(e.start).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
            const isLocal = e.source === 'local';
            const meta = isLocal
                ? (e.location ? '<div class="cal-ev-loc">' + escapeHtml(e.location) + '</div>' : '')
                : '<div class="cal-ev-feed">' + escapeHtml(e.feed_name || 'External') + '</div>';
            return (
                '<div class="cal-ev" data-event-id="' + escapeHtml(e.id) + '" data-source="' + escapeHtml(e.source) + '">' +
                    '<span class="cal-ev-bar" style="background:' + color + '"></span>' +
                    '<div class="cal-ev-body">' +
                        '<div class="cal-ev-time">' + escapeHtml(time) + '</div>' +
                        '<div class="cal-ev-title">' + escapeHtml(e.title) + '</div>' +
                        meta +
                    '</div>' +
                '</div>'
            );
        }).join('');
    }

    /* ----- Calendar event modal ----- */
    function openCalModal(event) {
        cal.editing = event ? event.id : null;
        const isLocal = !event || event.source === 'local';
        $('calModalTitle').textContent = event ? (isLocal ? 'Edit event' : 'Event') : 'New event';
        $('calEvTitle').value      = event ? event.title || '' : '';
        $('calEvLocation').value   = event ? event.location || '' : '';
        $('calEvNotes').value      = event ? event.notes || event.description || '' : '';
        $('calEvAllDay').checked   = event ? !!event.all_day : false;

        const startDt = event ? new Date(event.start) : (() => {
            const d = new Date(cal.selectedDate + 'T09:00:00');
            return d;
        })();
        const endDt   = event ? new Date(event.end) : (() => {
            const d = new Date(startDt); d.setHours(d.getHours() + 1); return d;
        })();
        $('calEvStart').value = toLocalInput(startDt);
        $('calEvEnd').value   = toLocalInput(endDt);

        // Read-only for feed events
        ['calEvTitle','calEvAllDay','calEvStart','calEvEnd','calEvLocation','calEvNotes'].forEach(id => {
            $(id).disabled = !isLocal;
        });
        $('calEvSave').style.display   = isLocal ? '' : 'none';
        $('calEvDelete').style.display = (event && isLocal) ? '' : 'none';

        $('calModal').classList.remove('hidden');
        $('calModal').setAttribute('aria-hidden', 'false');
        modalTrap.activate($('calModal'), { focus: !isLocal });
        if (isLocal) setTimeout(() => $('calEvTitle').focus(), 30);
    }
    function closeCalModal() {
        $('calModal').classList.add('hidden');
        $('calModal').setAttribute('aria-hidden', 'true');
        cal.editing = null;
        modalTrap.deactivate($('calModal'));
    }
    function toLocalInput(d) {
        const pad = n => String(n).padStart(2, '0');
        return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }
    function fromLocalInput(s) {
        // Treat local-input string as local time, return UTC ISO
        if (!s) return null;
        const d = new Date(s);
        if (isNaN(d)) return null;
        return d.toISOString().replace(/\.\d{3}Z$/, 'Z');
    }

    async function saveCalEvent() {
        const title = $('calEvTitle').value.trim();
        if (!title) { $('calEvTitle').focus(); return; }
        const allDay = $('calEvAllDay').checked;
        let start, end;
        if (allDay) {
            const s = $('calEvStart').value || (cal.selectedDate + 'T00:00');
            const e = $('calEvEnd').value || s;
            start = fromLocalInput(s.slice(0,10) + 'T00:00');
            end   = fromLocalInput(e.slice(0,10) + 'T23:59:59');
        } else {
            start = fromLocalInput($('calEvStart').value);
            end   = fromLocalInput($('calEvEnd').value);
            if (!start) { alert('Start time is required'); return; }
            if (!end || end < start) end = start;
        }
        const body = {
            title,
            start, end, all_day: allDay,
            location: $('calEvLocation').value,
            notes:    $('calEvNotes').value,
        };
        let url, method;
        if (cal.editing) { body.id = cal.editing; url = 'ajax/calendar.php?action=event_update'; }
        else             { url = 'ajax/calendar.php?action=event_add'; }

        const r = await fetch(url, {
            method: 'POST',
            headers: csrfHeaders({ 'Content-Type': 'application/json' }),
            credentials: 'same-origin',
            body: JSON.stringify(body),
        }).then(r => r.json());
        if (r.error) { alert(r.error); return; }
        closeCalModal();
        await calFetchEvents();
    }

    async function deleteCalEvent() {
        if (!cal.editing) return;
        if (!confirm('Delete this event?')) return;
        const r = await fetch('ajax/calendar.php?action=event_delete', {
            method: 'POST',
            headers: csrfHeaders({ 'Content-Type': 'application/json' }),
            credentials: 'same-origin',
            body: JSON.stringify({ id: cal.editing }),
        }).then(r => r.json());
        if (r.error) { alert(r.error); return; }
        closeCalModal();
        await calFetchEvents();
    }

    function wireCalendar() {
        const grid = $('calMiniGrid');
        if (!grid) return;

        $('calPrevMonth').addEventListener('click', () => {
            cal.viewMonth--;
            if (cal.viewMonth < 0) { cal.viewMonth = 11; cal.viewYear--; }
            calFetchEvents();
        });
        $('calNextMonth').addEventListener('click', () => {
            cal.viewMonth++;
            if (cal.viewMonth > 11) { cal.viewMonth = 0; cal.viewYear++; }
            calFetchEvents();
        });
        $('calMonthLabel').addEventListener('click', () => {
            const t = new Date();
            cal.viewYear  = t.getFullYear();
            cal.viewMonth = t.getMonth();
            cal.selectedDate = dateOnlyKey(t);
            calFetchEvents();
        });
        grid.addEventListener('click', (e) => {
            const cell = e.target.closest('.cal-mini-day');
            if (!cell) return;
            cal.selectedDate = cell.dataset.key;
            renderCalendar();
        });
        $('calAgendaList').addEventListener('click', (e) => {
            const row = e.target.closest('.cal-ev');
            if (!row) return;
            const id = row.dataset.eventId;
            const ev = cal.events.find(x => String(x.id) === id);
            if (ev) openCalModal(ev);
        });
        $('calAddBtn').addEventListener('click', () => openCalModal(null));
        $('calEvSave').addEventListener('click', saveCalEvent);
        $('calEvDelete').addEventListener('click', deleteCalEvent);
        $('calModal').addEventListener('click', (e) => {
            if (e.target.closest('[data-cal-close="1"]')) closeCalModal();
        });
        $('calCollapseBtn').addEventListener('click', () => {
            $('calPane').classList.toggle('collapsed');
        });

        // Refresh feeds & events every 5 minutes (much lighter than mail polling)
        setInterval(calFetchEvents, 5 * 60 * 1000);
    }

    /* ---------- Density ---------- */
    function applyDensity(density) {
        const valid = ['comfortable', 'cozy', 'compact'];
        const d = valid.includes(density) ? density : 'comfortable';
        document.body.classList.remove('density-comfortable', 'density-cozy', 'density-compact');
        document.body.classList.add('density-' + d);
    }
    applyDensity((window.__PREFS__ && window.__PREFS__.density) || 'compact');

    /* ---------- Theme ----------
       The inline <head> script sets html.theme-dark/theme-light before first
       paint. This keeps it in sync when the pref changes at runtime, and — while
       the pref is "system" — when the OS color scheme flips with the app open. */
    const _themeMq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    // Keep the browser/installed-app chrome (PWA title bar, mobile address bar)
    // in step with the active theme — matches the topbar's --c-brand value.
    function _syncThemeColor(dark) {
        const tc = document.querySelector('meta[name="theme-color"]');
        if (tc) tc.setAttribute('content', dark ? '#2b88d8' : '#0078d4');
    }
    function _onSystemThemeChange(e) {
        document.documentElement.classList.toggle('theme-dark', e.matches);
        document.documentElement.classList.toggle('theme-light', !e.matches);
        _syncThemeColor(e.matches);
    }
    function applyTheme(theme) {
        const valid = ['system', 'light', 'dark'];
        const t = valid.includes(theme) ? theme : 'system';
        if (window.__PREFS__) window.__PREFS__.theme = t;
        const dark = t === 'dark' || (t === 'system' && !!(_themeMq && _themeMq.matches));
        document.documentElement.classList.toggle('theme-dark', dark);
        document.documentElement.classList.toggle('theme-light', !dark);
        _syncThemeColor(dark);
        if (_themeMq) {
            // Re-attach cleanly so we never stack duplicate listeners.
            if (_themeMq.removeEventListener) _themeMq.removeEventListener('change', _onSystemThemeChange);
            else if (_themeMq.removeListener) _themeMq.removeListener(_onSystemThemeChange);
            if (t === 'system') {
                if (_themeMq.addEventListener) _themeMq.addEventListener('change', _onSystemThemeChange);
                else if (_themeMq.addListener) _themeMq.addListener(_onSystemThemeChange); // Safari < 14
            }
        }
    }
    applyTheme((window.__PREFS__ && window.__PREFS__.theme) || 'system');

    const themeToggleBtn = $('themeToggle');
    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', () => {
            const next = document.documentElement.classList.contains('theme-dark') ? 'light' : 'dark';
            applyTheme(next);
            const fd = new FormData();
            fd.append('theme', next);
            fetch('ajax/prefs.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': CSRF_TOKEN },
            }).catch(() => {});
        });
    }

    /* ---------- Service worker (PWA install + offline read) ---------- */
    function registerServiceWorker() {
        if (!('serviceWorker' in navigator)) return;
        // Defer past load so registration never competes with first paint.
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('sw.js').catch(() => {});
        });
    }

    /* ---------- Offline indicator ---------- */
    // A slim, polite status pill that appears only while the browser reports no
    // connectivity, so the stale/cached state of the mailbox is never silent.
    function initOfflineIndicator() {
        const bar = document.createElement('div');
        bar.id = 'offlineBar';
        bar.setAttribute('role', 'status');
        bar.setAttribute('aria-live', 'polite');
        bar.hidden = true;
        bar.innerHTML =
            '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" ' +
            'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
            '<line x1="1" y1="1" x2="23" y2="23"/><path d="M16.72 11.06A10.94 10.94 0 0 1 19 12.55"/>' +
            '<path d="M5 12.55a10.94 10.94 0 0 1 5.17-2.39"/><path d="M10.71 5.05A16 16 0 0 1 22.58 9"/>' +
            '<path d="M1.42 9a15.91 15.91 0 0 1 4.7-2.88"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/>' +
            '<line x1="12" y1="20" x2="12.01" y2="20"/></svg>' +
            '<span>You’re offline — showing saved messages.</span>';
        document.body.appendChild(bar);
        const sync = () => { bar.hidden = navigator.onLine; };
        window.addEventListener('online', sync);
        window.addEventListener('offline', sync);
        sync();
    }

    /* ---------- Boot ---------- */
    registerServiceWorker();
    initOfflineIndicator();
    initAccounts();
    wire();
    wireCalendar();
    wireDragAndDrop();
    // Folders and messages don't depend on each other — fire in parallel.
    Promise.all([loadFolders(), loadMessages()]);
    updateActionBar();
    // Calendar is sidebar-only; defer until the browser is idle so initial
    // paint of the inbox isn't blocked by it.
    if ('requestIdleCallback' in window) {
        requestIdleCallback(() => calFetchEvents(), { timeout: 1500 });
    } else {
        setTimeout(calFetchEvents, 600);
    }
    startPolling();
})();
