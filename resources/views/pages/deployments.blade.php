<div x-data="{
    deployments: [],
    pagination: {},
    loading: true,
    page: 1,
    statusFilter: '',
    staleOnly: false,
    search: '',
    expanded: null,
    heartbeats: [],
    heartbeatsLoading: false,

    async load() {
        this.loading = true;
        try {
            let url = `/deployments?page=${this.page}&per_page=25`;
            if (this.statusFilter) url += `&status=${encodeURIComponent(this.statusFilter)}`;
            if (this.staleOnly) url += `&stale=1`;
            if (this.search) url += `&search=${encodeURIComponent(this.search)}`;
            const res = await api.get(url);
            this.deployments = res.data;
            this.pagination = res.meta ?? { current_page: res.current_page, last_page: res.last_page, total: res.total };
        } catch (e) { $store.notify.error('Failed to load deployments'); }
        this.loading = false;
    },

    async toggleHeartbeats(dep) {
        if (this.expanded === dep.id) { this.expanded = null; return; }
        this.expanded = dep.id;
        this.heartbeatsLoading = true;
        this.heartbeats = [];
        try {
            const res = await api.get(`/deployments/${dep.id}/heartbeats`);
            this.heartbeats = res.data.heartbeats ?? [];
        } catch (e) { $store.notify.error('Failed to load heartbeat history'); }
        this.heartbeatsLoading = false;
    },

    async resetActivation(dep) {
        const label = dep.domain || dep.hostname || dep.id;
        if (!confirm(`Approve fingerprint reset for ${label}?\n\nThe current machine will lock on its next check-in and the freed slot can be re-activated on new hardware with the same license key.`)) return;
        const reason = prompt('Reason (optional, recorded in the audit log):') ?? '';
        try {
            const res = await api.post(`/deployments/${dep.id}/deactivate`, { reason });
            $store.notify.success(`Slot released — ${res.data.freed_slots} slot(s) now free on this license.`);
            this.load();
        } catch (e) { $store.notify.error(e.message || 'Failed to release the activation slot'); }
    },

    healthBadge(dep) {
        if (dep.status !== 'active') return 'bg-gray-100 text-gray-600';
        if (dep.is_stale) return 'bg-red-100 text-red-800';
        return 'bg-green-100 text-green-800';
    },
    healthLabel(dep) {
        if (dep.status !== 'active') return 'deactivated';
        return dep.is_stale ? 'stale' : 'healthy';
    },
    ago(ts) {
        if (!ts) return 'never';
        const s = Math.floor((Date.now() - new Date(ts).getTime()) / 1000);
        if (s < 3600) return Math.max(1, Math.floor(s / 60)) + 'm ago';
        if (s < 86400) return Math.floor(s / 3600) + 'h ago';
        return Math.floor(s / 86400) + 'd ago';
    },
}" x-init="load()">

    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
        <div>
            <h2 class="text-xl font-bold text-text-primary">Deployments</h2>
            <p class="text-sm text-text-secondary mt-0.5">Every activated instance — where it runs, what it runs, and heartbeat health</p>
        </div>
        <div class="flex items-center gap-2">
            <input type="text" x-model.debounce.400ms="search" @input="page = 1; load()"
                   placeholder="Search domain, host, organization…"
                   class="px-3 py-2 border border-gray-300 rounded-lg text-sm w-64">
            <select x-model="statusFilter" @change="page = 1; load()"
                    class="px-3 py-2 border border-gray-300 rounded-lg text-sm">
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="deactivated">Deactivated</option>
            </select>
            <label class="flex items-center gap-1.5 text-sm text-text-secondary cursor-pointer">
                <input type="checkbox" x-model="staleOnly" @change="page = 1; load()" class="rounded">
                Stale only
            </label>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 text-left text-xs uppercase tracking-wide text-text-secondary">
                        <th class="px-4 py-3">Health</th>
                        <th class="px-4 py-3">Organization</th>
                        <th class="px-4 py-3">Domain / Host</th>
                        <th class="px-4 py-3">Version</th>
                        <th class="px-4 py-3">Env</th>
                        <th class="px-4 py-3">License</th>
                        <th class="px-4 py-3">Last Seen</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody x-show="loading">
                    <tr><td colspan="8" class="px-4 py-10 text-center text-text-secondary">Loading…</td></tr>
                </tbody>
                <tbody x-show="!loading && deployments.length === 0">
                    <tr><td colspan="8" class="px-4 py-10 text-center text-text-secondary">No deployments found</td></tr>
                </tbody>
                {{-- one tbody per deployment: main row + expandable history row --}}
                <template x-for="dep in deployments" :key="dep.id">
                    <tbody class="divide-y divide-gray-100 border-t border-gray-100" x-show="!loading">
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <span class="px-2 py-0.5 rounded-full text-xs font-semibold" :class="healthBadge(dep)" x-text="healthLabel(dep)"></span>
                            </td>
                            <td class="px-4 py-3 font-medium text-text-primary" x-text="dep.organization ?? '—'"></td>
                            <td class="px-4 py-3">
                                <div class="text-text-primary" x-text="dep.domain ?? '—'"></div>
                                <div class="text-xs text-text-secondary font-mono" x-text="dep.hostname ?? ''"></div>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs" x-text="dep.app_version ?? '—'"></td>
                            <td class="px-4 py-3">
                                <span class="text-xs" :class="dep.app_env === 'production' ? 'text-green-700' : 'text-amber-700'" x-text="dep.app_env ?? '—'"></span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-mono text-xs" x-text="dep.license_key"></div>
                                <div class="text-xs text-text-secondary">
                                    <span class="capitalize" x-text="dep.plan"></span> ·
                                    <span class="uppercase" x-text="dep.type"></span> ·
                                    <span x-text="dep.license_status"></span>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div x-text="ago(dep.last_seen_at)"></div>
                                <div class="text-xs text-text-secondary" x-text="dep.last_seen_at ? new Date(dep.last_seen_at).toLocaleString() : ''"></div>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <button @click="toggleHeartbeats(dep)" class="text-xs font-medium text-primary hover:underline"
                                        x-text="expanded === dep.id ? 'Hide history' : 'History'"></button>
                                <button x-show="dep.status === 'active'" @click="resetActivation(dep)"
                                        class="ml-3 text-xs font-medium text-red-600 hover:underline"
                                        title="Approve fingerprint reset — releases this activation slot for new hardware">Reset</button>
                            </td>
                        </tr>
                        <tr x-show="expanded === dep.id" x-cloak>
                            <td colspan="8" class="px-6 py-4 bg-gray-50">
                                <template x-if="heartbeatsLoading"><div class="text-sm text-text-secondary">Loading heartbeat history…</div></template>
                                <template x-if="!heartbeatsLoading && heartbeats.length === 0">
                                    <div class="text-sm text-text-secondary">No telemetry reported yet for this deployment.</div>
                                </template>
                                <template x-if="!heartbeatsLoading && heartbeats.length > 0">
                                    <div>
                                        <div class="text-xs font-semibold uppercase tracking-wide text-text-secondary mb-2">Heartbeat history (latest 100)</div>
                                        <div class="max-h-56 overflow-y-auto border border-gray-200 rounded-lg bg-white">
                                            <table class="w-full text-xs">
                                                <thead><tr class="text-left text-text-secondary bg-gray-50">
                                                    <th class="px-3 py-2">Reported</th><th class="px-3 py-2">Active users</th><th class="px-3 py-2">Feature usage</th>
                                                </tr></thead>
                                                <tbody class="divide-y divide-gray-100">
                                                    <template x-for="(hb, i) in heartbeats" :key="i">
                                                        <tr>
                                                            <td class="px-3 py-1.5 whitespace-nowrap" x-text="new Date(hb.reported_at).toLocaleString()"></td>
                                                            <td class="px-3 py-1.5" x-text="hb.active_users"></td>
                                                            <td class="px-3 py-1.5 font-mono" x-text="JSON.stringify(hb.feature_usage)"></td>
                                                        </tr>
                                                    </template>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </template>
            </table>
        </div>

        <div class="flex items-center justify-between px-4 py-3 border-t border-gray-100 text-sm"
             x-show="(pagination.last_page ?? 1) > 1">
            <span class="text-text-secondary" x-text="`Page ${pagination.current_page ?? 1} of ${pagination.last_page ?? 1} — ${pagination.total ?? 0} deployments`"></span>
            <div class="flex gap-2">
                <button @click="page--; load()" :disabled="(pagination.current_page ?? 1) <= 1"
                        class="px-3 py-1.5 border border-gray-300 rounded-lg disabled:opacity-40">Previous</button>
                <button @click="page++; load()" :disabled="(pagination.current_page ?? 1) >= (pagination.last_page ?? 1)"
                        class="px-3 py-1.5 border border-gray-300 rounded-lg disabled:opacity-40">Next</button>
            </div>
        </div>
    </div>
</div>
