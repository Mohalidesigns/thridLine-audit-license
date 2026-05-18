<div x-data="{
    stats: null,
    activity: [],
    loading: true,
    async load() {
        this.loading = true;
        try {
            const [statsRes, activityRes] = await Promise.all([
                api.get('/dashboard/stats'),
                api.get('/dashboard/recent-activity'),
            ]);
            this.stats = statsRes.data;
            this.activity = activityRes.data;
        } catch (e) {
            $store.notify.error('Failed to load dashboard data');
        }
        this.loading = false;
    }
}" x-init="load()">

    {{-- Stats Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
        {{-- Active Licenses --}}
        <div class="bg-white rounded-xl border border-gray-100 p-5 shadow-sm hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between mb-3">
                <span class="text-sm font-medium text-text-secondary">Active Licenses</span>
                <div class="w-10 h-10 rounded-lg bg-secondary/10 flex items-center justify-center">
                    <svg class="w-5 h-5 text-secondary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z"/></svg>
                </div>
            </div>
            <p class="text-3xl font-bold text-text-primary" x-text="stats?.active_licenses ?? '—'"></p>
            <p class="text-xs text-secondary mt-1 font-medium">Currently valid</p>
        </div>

        {{-- Expiring Soon --}}
        <div class="bg-white rounded-xl border border-gray-100 p-5 shadow-sm hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between mb-3">
                <span class="text-sm font-medium text-text-secondary">Expiring Soon</span>
                <div class="w-10 h-10 rounded-lg bg-warning/10 flex items-center justify-center">
                    <svg class="w-5 h-5 text-warning" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                </div>
            </div>
            <p class="text-3xl font-bold text-text-primary" x-text="stats?.expiring_soon ?? '—'"></p>
            <p class="text-xs text-warning mt-1 font-medium">Within 30 days</p>
        </div>

        {{-- Revoked This Month --}}
        <div class="bg-white rounded-xl border border-gray-100 p-5 shadow-sm hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between mb-3">
                <span class="text-sm font-medium text-text-secondary">Revoked (Month)</span>
                <div class="w-10 h-10 rounded-lg bg-error/10 flex items-center justify-center">
                    <svg class="w-5 h-5 text-error" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                </div>
            </div>
            <p class="text-3xl font-bold text-text-primary" x-text="stats?.revoked_this_month ?? '—'"></p>
            <p class="text-xs text-error mt-1 font-medium">This month</p>
        </div>

        {{-- Total Organizations --}}
        <div class="bg-white rounded-xl border border-gray-100 p-5 shadow-sm hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between mb-3">
                <span class="text-sm font-medium text-text-secondary">Organizations</span>
                <div class="w-10 h-10 rounded-lg bg-info/10 flex items-center justify-center">
                    <svg class="w-5 h-5 text-info" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/></svg>
                </div>
            </div>
            <p class="text-3xl font-bold text-text-primary" x-text="stats?.total_organizations ?? '—'"></p>
            <p class="text-xs text-info mt-1 font-medium">Registered clients</p>
        </div>
    </div>

    {{-- Plans Distribution + Active Activations --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-8">
        {{-- By Plan --}}
        <div class="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
            <h3 class="text-sm font-semibold text-text-primary mb-4">Licenses by Plan</h3>
            <div class="space-y-3" x-show="stats?.licenses_by_plan">
                <template x-for="[plan, count] in Object.entries(stats?.licenses_by_plan ?? {})" :key="plan">
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-sm font-medium capitalize" x-text="plan"></span>
                            <span class="text-sm font-mono text-text-secondary" x-text="count"></span>
                        </div>
                        <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                            <div class="h-full rounded-full transition-all duration-500"
                                 :class="{
                                    'bg-secondary': plan === 'enterprise',
                                    'bg-info': plan === 'professional',
                                    'bg-accent': plan === 'starter',
                                 }"
                                 :style="`width: ${Math.max(10, (count / (stats?.active_licenses || 1)) * 100)}%`">
                            </div>
                        </div>
                    </div>
                </template>
            </div>
            <div x-show="!stats?.licenses_by_plan || Object.keys(stats?.licenses_by_plan ?? {}).length === 0" class="text-sm text-text-secondary text-center py-6">No licenses issued yet</div>
        </div>

        {{-- Quick Stats --}}
        <div class="bg-white rounded-xl border border-gray-100 p-5 shadow-sm lg:col-span-2">
            <h3 class="text-sm font-semibold text-text-primary mb-4">Active Activations</h3>
            <div class="flex items-center gap-4">
                <div class="w-20 h-20 rounded-full border-4 border-primary flex items-center justify-center">
                    <span class="text-2xl font-bold text-primary" x-text="stats?.total_active_activations ?? 0"></span>
                </div>
                <div>
                    <p class="text-sm text-text-secondary">Total devices currently activated across all licenses</p>
                    <p class="text-xs text-text-secondary mt-1">Heartbeat interval: 48 hours</p>
                </div>
            </div>
        </div>
    </div>

    {{-- Recent Activity --}}
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-text-primary">Recent Activity</h3>
            <a href="#/audit-logs" @click="currentPage = 'audit-logs'" class="text-xs text-primary font-medium hover:underline">View All</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50/80">
                        <th class="text-left px-5 py-3 text-xs font-semibold text-text-secondary uppercase tracking-wider">Time</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-text-secondary uppercase tracking-wider">Action</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-text-secondary uppercase tracking-wider">Resource</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-text-secondary uppercase tracking-wider">Actor</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <template x-for="log in activity" :key="log.id">
                        <tr class="hover:bg-gray-50/50 transition-colors">
                            <td class="px-5 py-3 text-xs text-text-secondary font-mono whitespace-nowrap"
                                x-text="new Date(log.created_at).toLocaleString()"></td>
                            <td class="px-5 py-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                                      :class="{
                                         'bg-green-100 text-green-800': log.action.includes('success') || log.action.includes('issued') || log.action.includes('activated'),
                                         'bg-red-100 text-red-800': log.action.includes('failed') || log.action.includes('revoked') || log.action.includes('blocked'),
                                         'bg-blue-100 text-blue-800': log.action.includes('heartbeat') || log.action.includes('login'),
                                         'bg-yellow-100 text-yellow-800': log.action.includes('warning') || log.action.includes('updated'),
                                         'bg-gray-100 text-gray-800': !log.action.match(/success|issued|activated|failed|revoked|blocked|heartbeat|login|warning|updated/),
                                      }"
                                      x-text="log.action"></span>
                            </td>
                            <td class="px-5 py-3 text-xs text-text-secondary">
                                <span x-text="log.resource_type" class="capitalize"></span>
                            </td>
                            <td class="px-5 py-3 text-xs text-text-secondary capitalize" x-text="log.actor_type"></td>
                        </tr>
                    </template>
                    <tr x-show="activity.length === 0 && !loading">
                        <td colspan="4" class="px-5 py-8 text-center text-text-secondary">No activity yet</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
