<div x-data="{
    clients: [],
    loading: true,
    showCreateModal: false,
    showSecretModal: false,
    creating: false,
    orgs: [],
    newSecret: null,
    newClientId: null,
    form: { org_id: '', allowed_scopes: ['license:validate', 'license:activate', 'license:heartbeat'], allowed_ips: '' },

    async load() {
        this.loading = true;
        try {
            const res = await api.get('/api-clients?per_page=50');
            this.clients = res.data ?? [];
        } catch (e) { $store.notify.error('Failed to load API clients'); }
        this.loading = false;
    },

    async loadOrgs() {
        try { const res = await api.get('/organizations?per_page=100'); this.orgs = res.data; } catch(e) {}
    },

    async createClient() {
        this.creating = true;
        try {
            const ips = this.form.allowed_ips ? this.form.allowed_ips.split(',').map(s => s.trim()).filter(Boolean) : null;
            const res = await api.post('/api-clients', {
                org_id: this.form.org_id,
                allowed_scopes: this.form.allowed_scopes,
                allowed_ips: ips,
            });
            this.newClientId = res.data.client_id;
            this.newSecret = res.data.client_secret;
            this.showCreateModal = false;
            this.showSecretModal = true;
            this.load();
        } catch (e) { $store.notify.error(e.data?.message || 'Failed to create client'); }
        this.creating = false;
    },

    async regenerateSecret(client) {
        if (!confirm('Regenerate secret? The old secret will stop working immediately.')) return;
        try {
            const res = await api.post(`/api-clients/${client.id}/regenerate-secret`);
            this.newClientId = res.data.client_id;
            this.newSecret = res.data.client_secret;
            this.showSecretModal = true;
            $store.notify.success('Secret regenerated');
        } catch (e) { $store.notify.error('Failed to regenerate'); }
    },

    toggleScope(scope) {
        const idx = this.form.allowed_scopes.indexOf(scope);
        if (idx >= 0) this.form.allowed_scopes.splice(idx, 1);
        else this.form.allowed_scopes.push(scope);
    },
}" x-init="load()">

    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
        <div>
            <h2 class="text-xl font-bold text-text-primary">API Clients</h2>
            <p class="text-sm text-text-secondary mt-0.5">Machine-to-machine credentials for license validation</p>
        </div>
        <button @click="showCreateModal = true; loadOrgs(); form = { org_id: '', allowed_scopes: ['license:validate', 'license:activate', 'license:heartbeat'], allowed_ips: '' }"
                class="px-4 py-2 bg-primary hover:bg-primary-light text-white text-sm font-medium rounded-lg transition-colors flex items-center gap-2 shadow-sm">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
            Create API Client
        </button>
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50/80">
                        <th class="text-left px-5 py-3 text-xs font-semibold text-text-secondary uppercase tracking-wider">Client ID</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-text-secondary uppercase tracking-wider">Organization</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-text-secondary uppercase tracking-wider">Scopes</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-text-secondary uppercase tracking-wider">Status</th>
                        <th class="text-right px-5 py-3 text-xs font-semibold text-text-secondary uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <template x-for="client in clients" :key="client.id">
                        <tr class="hover:bg-gray-50/50 transition-colors">
                            <td class="px-5 py-3 font-mono text-xs text-primary" x-text="client.client_id"></td>
                            <td class="px-5 py-3 text-sm" x-text="client.organization?.name ?? '—'"></td>
                            <td class="px-5 py-3">
                                <div class="flex flex-wrap gap-1">
                                    <template x-for="scope in (client.allowed_scopes ?? [])" :key="scope">
                                        <span class="inline-flex px-1.5 py-0.5 rounded text-xs bg-primary/10 text-primary font-mono" x-text="scope"></span>
                                    </template>
                                </div>
                            </td>
                            <td class="px-5 py-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                                      :class="client.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'"
                                      x-text="client.is_active ? 'Active' : 'Inactive'"></span>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <button @click="regenerateSecret(client)" class="px-2 py-1 text-xs text-warning hover:bg-warning/10 rounded transition-colors font-medium">Regenerate Secret</button>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="clients.length === 0 && !loading">
                        <td colspan="5" class="px-5 py-12 text-center text-text-secondary">No API clients yet</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Create Modal --}}
    <div x-show="showCreateModal" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="showCreateModal = false">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md" @click.stop>
            <div class="px-6 py-4 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-text-primary">Create API Client</h3>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Organization *</label>
                    <select x-model="form.org_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary">
                        <option value="">Select...</option>
                        <template x-for="org in orgs" :key="org.id"><option :value="org.id" x-text="org.name"></option></template>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-2">Scopes</label>
                    <div class="space-y-2">
                        <template x-for="scope in ['license:validate', 'license:activate', 'license:heartbeat']" :key="scope">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" :checked="form.allowed_scopes.includes(scope)" @change="toggleScope(scope)" class="rounded border-gray-300 text-primary focus:ring-primary">
                                <span class="text-sm font-mono" x-text="scope"></span>
                            </label>
                        </template>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Allowed IPs <span class="text-text-secondary font-normal">(optional, comma-separated)</span></label>
                    <input type="text" x-model="form.allowed_ips" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary" placeholder="10.0.1.50, 192.168.1.100">
                </div>
            </div>
            <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-3">
                <button @click="showCreateModal = false" class="px-4 py-2 text-sm font-medium text-text-secondary hover:bg-gray-100 rounded-lg">Cancel</button>
                <button @click="createClient()" :disabled="creating || !form.org_id" class="px-4 py-2 bg-primary hover:bg-primary-light text-white text-sm font-medium rounded-lg disabled:opacity-50">
                    <span x-text="creating ? 'Creating...' : 'Create Client'"></span>
                </button>
            </div>
        </div>
    </div>

    {{-- Secret Display Modal --}}
    <div x-show="showSecretModal" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg" @click.stop>
            <div class="px-6 py-4 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-secondary">Client Credentials Created</h3>
                <p class="text-sm text-text-secondary mt-1">Save these credentials now. The secret will not be shown again.</p>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-xs font-medium text-text-secondary mb-1">Client ID</label>
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 font-mono text-sm break-all" x-text="newClientId"></div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-text-secondary mb-1">Client Secret</label>
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 font-mono text-sm break-all text-yellow-900" x-text="newSecret"></div>
                </div>
            </div>
            <div class="px-6 py-4 border-t border-gray-100 flex justify-end">
                <button @click="showSecretModal = false; newSecret = null; newClientId = null" class="px-4 py-2 bg-primary text-white text-sm font-medium rounded-lg">Done</button>
            </div>
        </div>
    </div>
</div>
