/**
 * Live exports lab board — one HTTP snapshot on page load, then Reverb only (no polling).
 */
function bootExportsLab() {
    const root = document.getElementById('exports-lab');
    if (!root || !window.Echo) {
        return;
    }

    const initial = JSON.parse(root.dataset.snapshot || '{}');
    const state = {
        totals: {
            pending: initial.totals?.pending || 0,
            processing: initial.totals?.processing || 0,
            completed: initial.totals?.completed || 0,
            failed: initial.totals?.failed || 0,
        },
        tenants: (initial.tenants || []).map((t) => ({ ...t })),
        recent: (initial.recent || []).map((e) => ({ ...e })),
        connected: false,
        lastEventAt: null,
    };

    const els = {
        totals: root.querySelector('[data-totals]'),
        tenants: root.querySelector('[data-tenants]'),
        recent: root.querySelector('[data-recent]'),
        status: root.querySelector('[data-ws-status]'),
        lastEvent: root.querySelector('[data-last-event]'),
    };

    function ensureTenant(exportRow) {
        let tenant = state.tenants.find((t) => t.tenant_id === exportRow.tenant_id);
        if (!tenant) {
            tenant = {
                tenant_id: exportRow.tenant_id,
                name: exportRow.tenant_name || exportRow.tenant_id,
                plan: exportRow.plan || 'unknown',
                api_key: exportRow.api_key || '',
                pending: 0,
                processing: 0,
                completed: 0,
                failed: 0,
            };
            state.tenants.push(tenant);
        } else {
            tenant.name = exportRow.tenant_name || tenant.name;
            tenant.plan = exportRow.plan || tenant.plan;
            tenant.api_key = exportRow.api_key || tenant.api_key;
        }

        return tenant;
    }

    function bump(counter, status, delta) {
        if (counter[status] === undefined) {
            return;
        }
        counter[status] = Math.max(0, (counter[status] || 0) + delta);
    }

    function applyExport(exportRow) {
        const idx = state.recent.findIndex((r) => r.id === exportRow.id);
        const previousStatus = idx >= 0 ? state.recent[idx].status : null;
        const nextStatus = exportRow.status;
        const tenant = ensureTenant(exportRow);

        if (previousStatus !== nextStatus) {
            if (previousStatus) {
                bump(state.totals, previousStatus, -1);
                bump(tenant, previousStatus, -1);
            }
            bump(state.totals, nextStatus, 1);
            bump(tenant, nextStatus, 1);
        }

        if (idx >= 0) {
            state.recent[idx] = exportRow;
        } else {
            state.recent.unshift(exportRow);
        }
        state.recent = state.recent.slice(0, 50);
        state.lastEventAt = new Date().toLocaleTimeString();
        render();
    }

    function render() {
        if (els.totals) {
            els.totals.innerHTML = ['pending', 'processing', 'completed', 'failed']
                .map((key) => {
                    const n = state.totals[key] ?? 0;
                    return `<div class="stat"><span class="stat-label">${key}</span><span class="stat-value status-${key}">${n}</span></div>`;
                })
                .join('');
        }

        if (els.tenants) {
            els.tenants.innerHTML = state.tenants
                .map(
                    (t) => `<tr>
                        <td>${escapeHtml(t.name)}<div class="muted">${escapeHtml(t.api_key || '')}</div></td>
                        <td><span class="plan">${escapeHtml(t.plan)}</span></td>
                        <td class="num status-pending">${t.pending}</td>
                        <td class="num status-processing">${t.processing}</td>
                        <td class="num status-completed">${t.completed}</td>
                        <td class="num status-failed">${t.failed}</td>
                    </tr>`,
                )
                .join('');
        }

        if (els.recent) {
            els.recent.innerHTML = state.recent
                .slice(0, 40)
                .map(
                    (e) => `<tr>
                        <td class="mono">${escapeHtml(shortId(e.id))}</td>
                        <td>${escapeHtml(e.tenant_name || e.tenant_id)} <span class="plan">${escapeHtml(e.plan || '')}</span></td>
                        <td><span class="badge status-${e.status}">${escapeHtml(e.status)}</span></td>
                        <td>${e.wait_ms != null ? `${e.wait_ms} ms` : '—'}</td>
                        <td>${e.row_count != null ? e.row_count : '—'}</td>
                        <td class="muted">${escapeHtml(formatTime(e.started_at || e.created_at))}</td>
                    </tr>`,
                )
                .join('');
        }

        if (els.status) {
            els.status.textContent = state.connected ? 'WebSocket connected' : 'WebSocket connecting…';
            els.status.className = state.connected ? 'ws ok' : 'ws wait';
        }

        if (els.lastEvent) {
            els.lastEvent.textContent = state.lastEventAt
                ? `last push ${state.lastEventAt}`
                : 'waiting for export.updated';
        }
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;');
    }

    function shortId(id) {
        return String(id || '').slice(0, 8);
    }

    function formatTime(value) {
        if (!value) {
            return '';
        }
        try {
            return new Date(value).toLocaleTimeString();
        } catch {
            return String(value);
        }
    }

    render();

    window.Echo.channel('exports.lab')
        .listen('.export.updated', (payload) => {
            if (payload?.export) {
                applyExport(payload.export);
            }
        });

    const pusher = window.Echo.connector?.pusher;
    if (pusher?.connection) {
        pusher.connection.bind('connected', () => {
            state.connected = true;
            render();
        });
        pusher.connection.bind('disconnected', () => {
            state.connected = false;
            render();
        });
        if (pusher.connection.state === 'connected') {
            state.connected = true;
            render();
        }
    }
}

document.addEventListener('DOMContentLoaded', bootExportsLab);
