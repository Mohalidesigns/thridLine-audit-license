<div x-data="{
    logs: [],
    pagination: {},
    loading: true,
    page: 1,
    actionFilter: '',
    actorFilter: '',
    resourceFilter: '',
    expandedLog: null,

    async load() {
        this.loading = true;
        try {
            let url = `/audit-logs?page=${this.page}&per_page=25`;
            if (this.actionFilter) url += `&action=${encodeURIComponent(this.actionFilter)}`;
            if (this.actorFilter) url += `&actor_type=${encodeURIComponent(this.actorFilter)}`;
            if (this.resourceFilter) url += `&resource_type=${encodeURIComponent(this.resourceFilter)}`;
            const res = await api.get(url);
            this.logs = res.data;
            this.pagination = res.meta ?? {};
        } catch (e) { $store.notify.error('Failed to load audit logs'); }
        this.loading = false;
    },

    actionColor(action) {
        if (action.includes('issued') || action.includes('activated') || action.includes('success') || action.includes('created')) return 'bg-green-100 text-green-800';
        if (action.includes('revoked') || action.includes('failed') || action.includes('blocked') || action.includes('deleted') || action.includes('expired')) return 'bg-red-100 text-red-800';
        if (action.includes('heartbeat') || action.includes('login') || action.includes('logout')) return 'bg-blue-100 text-blue-800';
        if (action.includes('updated') || action.includes('warning') || action.includes('missed')) return 'bg-yellow-100 text-yellow-800';
        return 'bg-gray-100 text-gray-700';
    },
}" x-init="load()">

    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
        <div>
            <h2 class="text-xl font-bold text-text-primary">Audit Logs</h2>
            <p class="text-sm text-text-secondary mt-0.5">Immutable record of all licensing actions</p>
        </div>
    </div>

    {{-- Filters --}}
    <div class="bg-white rounded-xl border border-gray-100 p-4 mb-5 shadow-sm flex flex-wrap items-center gap-3">
        <select x-model="actionFilter" @change="page = 1; load()" class="px-3 py-2 border border-gray-200 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary">
            <option value="">All Actions</option>
            <option value="license.issued">license.issued</option>
            <option value="license.activated">license.activated</option>
            <option value="license.revoked">license.revoked</option>
            <option value="license.expired">license.expired</option>
            <option value="license.updated">license.updated</option>
            <option value="validation.success">validation.success</option>
            <option value="validation.failed">validation.failed</option>
            <option value="heartbeat.received">heartbeat.received</option>
            <option value="heartbeat.missed">heartbeat.missed</option>
            <option value="user.login">user.login</option>
            <option value="user.logout">user.logout</option>
            <option value="organization.created">organization.created</option>
            <option value="api_client.created">api_client.created</option>
        </select>
        <select x-model="actorFilter" @change="page = 1; load()" class="px-3 py-2 border border-gray-200 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary">
            <option value="">All Actors</option>
            <option value="admin">Admin</option>
            <option value="system">System</option>
            <option value="client_app">Client App</option>
        </select>
        <select x-model="resourceFilter" @change="page = 1; load()" class="px-3 py-2 border border-gray-200 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary">
            <option value="">All Resources</option>
            <option value="license">License</option>
            <option value="activation">Activation</option>
            <option value="organization">Organization</option>
            <option value="api_client">API Client</option>
            <option value="user">User</option>
        </select>
        <button x-show="actionFilter || actorFilter || resourceFilter" @click="actionFilter = ''; actorFilter = ''; resourceFilter = ''; page = 1; load()"
                class="px-3 py-2 text-xs text-error hover:bg-red-50 rounded-lg transition-colors font-medium">Clear Filters</button>
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50/80">
                        <th class="text-left px-5 py-3 text-xs font-semibold text-text-secondary uppercase tracking-wider">Timestamp</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-text-secondary uppercase tracking-wider">Action</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-text-secondary uppercase tracking-wider">Resource</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-text-secondary uppercase tracking-wider">Actor</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-text-secondary uppercase tracking-wider">IP</th>
                        <th class="text-right px-5 py-3 text-xs font-semibold text-text-secondary uppercase tracking-wider">Details</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <template x-for="log in logs" :key="log.id">
                        <tr class="hover:bg-gray-50/50 transition-colors cursor-pointer" @click="expandedLog = expandedLog?.id === log.id ? null : log">
                            <td class="px-5 py-3 text-xs font-mono text-text-secondary whitespace-nowrap" x-text="new Date(log.created_at).toLocaleString()"></td>
                            <td class="px-5 py-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" :class="actionColor(log.action)" x-text="log.action"></span>
                            </td>
                            <td class="px-5 py-3 text-xs capitalize" x-text="log.resource_type"></td>
                            <td class="px-5 py-3 text-xs capitalize" x-text="log.actor_type"></td>
                            <td class="px-5 py-3 text-xs font-mono text-text-secondary" x-text="log.ip_address ?? '—'"></td>
                            <td class="px-5 py-3 text-right">
                                <svg class="w-4 h-4 text-text-secondary inline-block transition-transform" :class="expandedLog?.id === log.id ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                            </td>
                        </tr>
                    </template>
                    {{-- Expanded metadata row --}}
                    <template x-for="log in logs" :key="'detail-' + log.id">
                        <tr x-show="expandedLog?.id === log.id" x-transition>
                            <td colspan="6" class="px-5 py-3 bg-gray-50">
                                <div class="text-xs">
                                    <div class="flex gap-8 mb-2">
                                        <div><span class="font-medium text-text-secondary">Resource ID:</span> <span class="font-mono" x-text="log.resource_id ?? 'N/A'"></span></div>
                                        <div><span class="font-medium text-text-secondary">Actor ID:</span> <span class="font-mono" x-text="log.actor_id ?? 'N/A'"></span></div>
                                    </div>
                                    <div x-show="log.metadata">
                                        <span class="font-medium text-text-secondary">Metadata:</span>
                                        <pre class="mt-1 p-2 bg-white border border-gray-200 rounded text-xs font-mono overflow-x-auto max-h-40" x-text="JSON.stringify(log.metadata, null, 2)"></pre>
                                    </div>
                                    <div x-show="log.user_agent" class="mt-2">
                                        <span class="font-medium text-text-secondary">User Agent:</span>
                                        <span class="text-text-secondary" x-text="log.user_agent"></span>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="logs.length === 0 && !loading">
                        <td colspan="6" class="px-5 py-12 text-center text-text-secondary">No audit logs found</td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        <div x-show="pagination.last_page > 1" class="px-5 py-3 border-t border-gray-100 flex items-center justify-between">
            <span class="text-xs text-text-secondary">
                Showing <span x-text="pagination.from"></span>-<span x-text="pagination.to"></span> of <span x-text="pagination.total"></span>
            </span>
            <div class="flex items-center gap-1">
                <button @click="page = Math.max(1, page - 1); load()" :disabled="page <= 1" class="px-3 py-1 text-xs border border-gray-200 rounded-lg hover:bg-gray-50 disabled:opacity-40">Prev</button>
                <button @click="page = Math.min(pagination.last_page, page + 1); load()" :disabled="page >= pagination.last_page" class="px-3 py-1 text-xs border border-gray-200 rounded-lg hover:bg-gray-50 disabled:opacity-40">Next</button>
            </div>
        </div>
    </div>
</div>
