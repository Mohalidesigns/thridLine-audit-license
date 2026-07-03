<div x-data="{
    orgs: [],
    pagination: {},
    search: '',
    loading: true,
    page: 1,
    showCreateModal: false,
    showEditModal: false,
    creating: false,
    editing: false,
    selectedOrg: null,
    form: { name: '', contact_email: '', industry: '', country: 'NG' },

    // --- Onboard Deployment (org + client + license in one call) ---
    showOnboardModal: false,
    onboarding: false,
    onboardResult: null,
    onboardForm: {
        org_name: '', contact_email: '', country: 'NG',
        issue_license: true, plan: 'professional', type: 'full',
        allowed_ips: '', server_url: '',
    },

    resetOnboard() {
        this.onboardResult = null;
        this.onboardForm = { org_name: '', contact_email: '', country: 'NG',
            issue_license: true, plan: 'professional', type: 'full', allowed_ips: '', server_url: '' };
        this.showOnboardModal = true;
    },

    async onboard() {
        this.onboarding = true;
        try {
            const payload = {
                org_name: this.onboardForm.org_name,
                contact_email: this.onboardForm.contact_email,
                country: this.onboardForm.country || 'NG',
                issue_license: this.onboardForm.issue_license,
            };
            if (this.onboardForm.issue_license) { payload.plan = this.onboardForm.plan; payload.type = this.onboardForm.type; }
            if (this.onboardForm.server_url) payload.server_url = this.onboardForm.server_url;
            const ips = this.onboardForm.allowed_ips.split(',').map(s => s.trim()).filter(Boolean);
            if (ips.length) payload.allowed_ips = ips;

            const res = await api.post('/onboarding', payload);
            this.onboardResult = res.data;
            $store.notify.success('Deployment onboarded — copy the credentials now');
            this.load();
        } catch (e) {
            $store.notify.error(e.data?.message || (e.data?.errors ? Object.values(e.data.errors)[0][0] : 'Onboarding failed'));
        }
        this.onboarding = false;
    },

    copy(text) {
        navigator.clipboard.writeText(text).then(() => $store.notify.success('Copied to clipboard'));
    },

    async load() {
        this.loading = true;
        try {
            let url = `/organizations?page=${this.page}&per_page=15`;
            if (this.search) url += `&search=${encodeURIComponent(this.search)}`;
            const res = await api.get(url);
            this.orgs = res.data;
            this.pagination = res.meta ?? {};
        } catch (e) { $store.notify.error('Failed to load organizations'); }
        this.loading = false;
    },

    async createOrg() {
        this.creating = true;
        try {
            await api.post('/organizations', this.form);
            $store.notify.success('Organization created');
            this.showCreateModal = false;
            this.form = { name: '', contact_email: '', industry: '', country: 'NG' };
            this.load();
        } catch (e) { $store.notify.error(e.data?.message || 'Failed to create'); }
        this.creating = false;
    },

    async updateOrg() {
        this.editing = true;
        try {
            await api.put(`/organizations/${this.selectedOrg.id}`, this.form);
            $store.notify.success('Organization updated');
            this.showEditModal = false;
            this.load();
        } catch (e) { $store.notify.error(e.data?.message || 'Failed to update'); }
        this.editing = false;
    },

    editOrg(org) {
        this.selectedOrg = org;
        this.form = { name: org.name, contact_email: org.contact_email, industry: org.industry || '', country: org.country || 'NG' };
        this.showEditModal = true;
    },
}" x-init="load()">

    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
        <div>
            <h2 class="text-xl font-bold text-text-primary">Organizations</h2>
            <p class="text-sm text-text-secondary mt-0.5">Manage client organizations and their licensing</p>
        </div>
        <div class="flex items-center gap-2">
            <button @click="showCreateModal = true; form = { name: '', contact_email: '', industry: '', country: 'NG' }"
                    class="px-4 py-2 bg-white border border-gray-200 hover:bg-gray-50 text-text-primary text-sm font-medium rounded-lg transition-colors flex items-center gap-2 shadow-sm">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Add Organization
            </button>
            <button @click="resetOnboard()"
                    class="px-4 py-2 bg-primary hover:bg-primary-light text-white text-sm font-medium rounded-lg transition-colors flex items-center gap-2 shadow-sm">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                Onboard Deployment
            </button>
        </div>
    </div>

    {{-- Onboard Deployment Modal --}}
    <div x-show="showOnboardModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40"
         @click.self="if (!onboardResult) showOnboardModal = false">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">

            {{-- FORM state --}}
            <template x-if="!onboardResult">
                <form @submit.prevent="onboard()" class="p-6 space-y-4">
                    <div>
                        <h3 class="text-lg font-bold text-text-primary">Onboard Deployment</h3>
                        <p class="text-sm text-text-secondary mt-0.5">Creates the organization, an API client, and (optionally) a license in one step.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Organization name</label>
                        <input x-model="onboardForm.org_name" required placeholder="ACME Bank Internal Audit"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Contact email</label>
                            <input type="email" x-model="onboardForm.contact_email" required placeholder="audit@acme.ng"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Country</label>
                            <input x-model="onboardForm.country" maxlength="2" placeholder="NG"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm uppercase">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Allowed IPs <span class="text-text-secondary font-normal">(comma-separated, optional)</span></label>
                        <input x-model="onboardForm.allowed_ips" placeholder="203.0.113.10, 198.51.100.4"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm font-mono">
                        <p class="text-xs text-text-secondary mt-1">Also feeds the nginx allowlist automation on the server.</p>
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" x-model="onboardForm.issue_license" class="rounded">
                        <span class="font-medium text-gray-700">Issue a license now</span>
                    </label>
                    <div x-show="onboardForm.issue_license" class="grid grid-cols-2 gap-3 pl-6 border-l-2 border-gray-100">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Plan</label>
                            <select x-model="onboardForm.plan" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                                <option value="starter">Starter</option>
                                <option value="professional">Professional</option>
                                <option value="enterprise">Enterprise</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                            <select x-model="onboardForm.type" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                                <option value="full">Full</option>
                                <option value="trial">Trial</option>
                                <option value="demo">Demo</option>
                                <option value="poc">POC</option>
                                <option value="grace">Grace</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Consumer LICENSE_SERVER_URL <span class="text-text-secondary font-normal">(for the .env snippet)</span></label>
                        <input x-model="onboardForm.server_url" placeholder="https://license.atherislimited.com"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="showOnboardModal = false" class="px-4 py-2 text-sm text-text-secondary hover:text-text-primary">Cancel</button>
                        <button type="submit" :disabled="onboarding"
                                class="px-5 py-2 bg-primary hover:bg-primary-light text-white text-sm font-semibold rounded-lg disabled:opacity-50"
                                x-text="onboarding ? 'Onboarding…' : 'Onboard'"></button>
                    </div>
                </form>
            </template>

            {{-- RESULT state (credentials shown once) --}}
            <template x-if="onboardResult">
                <div class="p-6 space-y-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center">
                            <svg class="w-5 h-5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-text-primary">Deployment onboarded</h3>
                            <p class="text-sm text-red-600 font-medium">Copy the client secret now — it will not be shown again.</p>
                        </div>
                    </div>

                    <div class="rounded-lg border border-gray-200 divide-y divide-gray-100 text-sm">
                        <div class="flex items-center justify-between px-3 py-2">
                            <span class="text-text-secondary">Organization</span>
                            <span class="font-medium" x-text="onboardResult.organization.name + ' (' + onboardResult.organization.slug + ')'"></span>
                        </div>
                        <template x-if="onboardResult.license">
                            <div class="flex items-center justify-between px-3 py-2">
                                <span class="text-text-secondary">License key</span>
                                <span class="font-mono text-xs" x-text="onboardResult.license.license_key"></span>
                            </div>
                        </template>
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label class="text-sm font-medium text-gray-700">Consumer .env snippet</label>
                            <button @click="copy(onboardResult.env_snippet)" class="text-xs font-medium text-primary hover:underline">Copy</button>
                        </div>
                        <pre class="bg-gray-900 text-gray-100 text-xs rounded-lg p-3 overflow-x-auto whitespace-pre-wrap" x-text="onboardResult.env_snippet"></pre>
                    </div>

                    <div class="text-xs text-text-secondary bg-amber-50 border border-amber-200 rounded-lg p-3">
                        Paste the <code>LICENSE_*</code> lines into the deployment's <code>.env</code>, run <code>php artisan config:clear</code>, then activate the license key in <b>Settings → License</b>. If you set allowed IPs, run the allowlist sync on the server (or wait for its cron) so the endpoints admit this deployment.
                    </div>

                    <div class="flex justify-end">
                        <button @click="showOnboardModal = false; onboardResult = null"
                                class="px-5 py-2 bg-primary hover:bg-primary-light text-white text-sm font-semibold rounded-lg">Done</button>
                    </div>
                </div>
            </template>
        </div>
    </div>

    {{-- Search --}}
    <div class="bg-white rounded-xl border border-gray-100 p-4 mb-5 shadow-sm">
        <input type="text" x-model.debounce.400ms="search" @input="page = 1; load()" placeholder="Search organizations..."
               class="w-full max-w-md px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none">
    </div>

    {{-- Grid --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        <template x-for="org in orgs" :key="org.id">
            <div class="bg-white rounded-xl border border-gray-100 p-5 shadow-sm hover:shadow-md transition-shadow">
                <div class="flex items-start justify-between mb-3">
                    <div>
                        <h3 class="font-semibold text-text-primary" x-text="org.name"></h3>
                        <p class="text-xs text-text-secondary font-mono mt-0.5" x-text="org.slug"></p>
                    </div>
                    <button @click="editOrg(org)" class="p-1.5 rounded-lg hover:bg-gray-100 text-text-secondary hover:text-primary transition-colors">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/></svg>
                    </button>
                </div>
                <div class="space-y-2 text-sm">
                    <div class="flex items-center gap-2 text-text-secondary">
                        <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
                        <span x-text="org.contact_email" class="truncate"></span>
                    </div>
                    <div class="flex items-center gap-2 text-text-secondary" x-show="org.industry">
                        <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/></svg>
                        <span x-text="org.industry"></span>
                    </div>
                </div>
                <div class="mt-4 pt-3 border-t border-gray-100 flex items-center justify-between">
                    <span class="text-xs text-text-secondary">
                        <span class="font-semibold text-primary" x-text="org.licenses_count ?? 0"></span> licenses
                    </span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-text-secondary" x-text="org.country"></span>
                </div>
            </div>
        </template>
        <div x-show="orgs.length === 0 && !loading" class="col-span-full py-12 text-center text-text-secondary bg-white rounded-xl border border-gray-100">
            No organizations found
        </div>
    </div>

    {{-- Create/Edit Modal --}}
    <template x-for="mode in [showCreateModal ? 'create' : (showEditModal ? 'edit' : '')]" :key="mode">
        <div x-show="mode" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
             @click.self="showCreateModal = false; showEditModal = false">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md" @click.stop>
                <div class="px-6 py-4 border-b border-gray-100">
                    <h3 class="text-lg font-semibold text-text-primary" x-text="mode === 'create' ? 'Add Organization' : 'Edit Organization'"></h3>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Name *</label>
                        <input type="text" x-model="form.name" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none" placeholder="Organization name">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Contact Email *</label>
                        <input type="email" x-model="form.contact_email" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none" placeholder="it@example.com">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium mb-1">Industry</label>
                            <input type="text" x-model="form.industry" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none" placeholder="Banking">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Country</label>
                            <input type="text" x-model="form.country" maxlength="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none" placeholder="NG">
                        </div>
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-3">
                    <button @click="showCreateModal = false; showEditModal = false" class="px-4 py-2 text-sm font-medium text-text-secondary hover:bg-gray-100 rounded-lg transition-colors">Cancel</button>
                    <button @click="mode === 'create' ? createOrg() : updateOrg()" :disabled="creating || editing || !form.name || !form.contact_email"
                            class="px-4 py-2 bg-primary hover:bg-primary-light text-white text-sm font-medium rounded-lg transition-colors disabled:opacity-50">
                        <span x-text="mode === 'create' ? (creating ? 'Creating...' : 'Create') : (editing ? 'Saving...' : 'Save Changes')"></span>
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>
