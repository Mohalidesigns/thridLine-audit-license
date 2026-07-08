@php
    $featureCatalog = config('licensing.available_features');
    $planFeatures = collect(config('licensing.plans'))
        ->mapWithKeys(fn ($p, $k) => [$k => $p['features']])
        ->all();
    $planLimits = collect(config('licensing.plans'))
        ->mapWithKeys(fn ($p, $k) => [$k => ['max_users' => $p['max_users'], 'max_activations' => $p['max_activations']]])
        ->all();
@endphp
<div x-data="{
    // Canonical module catalog + per-plan feature maps (server config = source of truth).
    featureCatalog: {{ Illuminate\Support\Js::from($featureCatalog) }},
    planFeatures: {{ Illuminate\Support\Js::from($planFeatures) }},
    planLimits: {{ Illuminate\Support\Js::from($planLimits) }},
    licenses: [],
    pagination: {},
    search: '',
    statusFilter: '',
    planFilter: '',
    loading: true,
    page: 1,

    showIssueModal: false,
    showIssuedModal: false,
    showRevokeModal: false,
    showDetailModal: false,
    showOfflineModal: false,
    selectedLicense: null,
    issuedLicense: null,
    revokeReason: '',
    revokeConfirmKey: '',
    keyCopied: false,

    // Offline activation
    offlineLicense: null,
    offlineFingerprint: null,
    offlineFingerprintData: null,
    offlineActivating: false,
    offlineResult: null,

    form: {
        org_id: '',
        plan: 'professional',
        features: {},   // populated to the full catalog by init() below
        max_users: 15,
        max_activations: 2,
        duration_days: 365,
        notes: '',
    },
    // A complete features object: every catalog key present, seeded from a plan
    // (or all-false). Guarantees the issued license explicitly sets all 18.
    fullFeatures(planKey) {
        const src = this.planFeatures[planKey] ?? {};
        const out = {};
        Object.keys(this.featureCatalog).forEach(k => { out[k] = src[k] === true; });
        return out;
    },
    selectedFeatureCount() { return Object.values(this.form.features).filter(Boolean).length; },
    setAllFeatures(val) { Object.keys(this.form.features).forEach(k => this.form.features[k] = val); },
    orgs: [],
    issuing: false,
    revoking: false,

    async load() {
        this.loading = true;
        try {
            let url = `/licenses?page=${this.page}&per_page=15`;
            if (this.search) url += `&search=${encodeURIComponent(this.search)}`;
            if (this.statusFilter) url += `&status=${this.statusFilter}`;
            if (this.planFilter) url += `&plan=${this.planFilter}`;
            const res = await api.get(url);
            this.licenses = res.data;
            this.pagination = res.meta ?? {};
        } catch (e) {
            $store.notify.error('Failed to load licenses');
        }
        this.loading = false;
    },

    async loadOrgs() {
        try {
            const res = await api.get('/organizations?per_page=100');
            this.orgs = res.data;
        } catch(e) {}
    },

    async issueLicense() {
        this.issuing = true;
        try {
            const res = await api.post('/licenses', this.form);
            this.issuedLicense = res.data;
            this.showIssueModal = false;
            this.showIssuedModal = true;
            this.resetForm();
            this.load();
        } catch (e) {
            $store.notify.error(e.data?.message || 'Failed to issue license');
        }
        this.issuing = false;
    },

    async revokeLicense() {
        this.revoking = true;
        try {
            await api.post('/licenses/revoke', {
                license_id: this.selectedLicense.id,
                reason: this.revokeReason,
                // Server safety check: must match the target key exactly (hash_equals).
                confirm_license_key: this.revokeConfirmKey,
                effective_immediately: true,
            });
            $store.notify.success('License revoked successfully');
            this.showRevokeModal = false;
            this.revokeReason = '';
            this.revokeConfirmKey = '';
            this.selectedLicense = null;
            this.load();
        } catch (e) {
            $store.notify.error(e.data?.message || 'Failed to revoke license');
        }
        this.revoking = false;
    },

    /**
     * Download a .lic file for the given license.
     * Accepts either inline file data (from issuance response) or
     * fetches it from the generate-file endpoint for existing licenses.
     */
    async downloadLicenseFile(licenseId, inlineFile) {
        let content, filename;

        if (inlineFile) {
            content = inlineFile.content;
            filename = inlineFile.filename;
        } else {
            try {
                const res = await api.get(`/licenses/${licenseId}/generate-file`);
                content = res.data.content;
                filename = res.data.filename;
            } catch (e) {
                $store.notify.error(e.data?.message || 'Failed to generate license file');
                return;
            }
        }

        // Trigger browser download
        const blob = new Blob([content], { type: 'application/octet-stream' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        $store.notify.success('License file downloaded: ' + filename);
    },

    openOfflineModal(license) {
        this.offlineLicense = license;
        this.offlineFingerprint = null;
        this.offlineFingerprintData = null;
        this.offlineResult = null;
        this.showOfflineModal = true;
    },

    handleFingerprintFile(event) {
        const file = event.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = (e) => {
            const content = e.target.result.trim();
            this.offlineFingerprint = content;
            // Try to decode and show preview
            try {
                const decoded = JSON.parse(atob(content));
                this.offlineFingerprintData = decoded;
            } catch {
                // May be raw JSON
                try {
                    const decoded = JSON.parse(content);
                    this.offlineFingerprintData = decoded;
                    this.offlineFingerprint = btoa(content);
                } catch {
                    this.offlineFingerprintData = null;
                }
            }
        };
        reader.readAsText(file);
    },

    async processOfflineActivation() {
        if (!this.offlineFingerprint || !this.offlineLicense) return;
        this.offlineActivating = true;
        try {
            const res = await api.post(`/licenses/${this.offlineLicense.id}/offline-activate`, {
                fingerprint_file: this.offlineFingerprint,
            });
            this.offlineResult = res.data;
            $store.notify.success('Device-bound license file generated successfully');
            this.load();
        } catch (e) {
            $store.notify.error(e.data?.message || 'Failed to process offline activation');
        }
        this.offlineActivating = false;
    },

    downloadOfflineFile() {
        if (!this.offlineResult?.file) return;
        const blob = new Blob([this.offlineResult.file.content], { type: 'application/octet-stream' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = this.offlineResult.file.filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        $store.notify.success('License file downloaded: ' + this.offlineResult.file.filename);
    },

    copyToClipboard(text) {
        navigator.clipboard.writeText(text);
        this.keyCopied = true;
        setTimeout(() => this.keyCopied = false, 2000);
    },

    applyPlanDefaults() {
        // Limits come from server config (planLimits), so the form defaults never
        // drift from config('licensing.plans').
        const l = this.planLimits[this.form.plan];
        if (l) { this.form.max_users = l.max_users; this.form.max_activations = l.max_activations; }
        // Feature set follows the plan (full 18-key object).
        this.form.features = this.fullFeatures(this.form.plan);
    },

    resetForm() {
        const l = this.planLimits['professional'] ?? { max_users: 25, max_activations: 2 };
        this.form = { org_id: '', plan: 'professional', features: this.fullFeatures('professional'), max_users: l.max_users, max_activations: l.max_activations, duration_days: 365, notes: '' };
    },

    statusColor(status) {
        return {
            active: 'bg-green-100 text-green-800',
            suspended: 'bg-yellow-100 text-yellow-800',
            revoked: 'bg-red-100 text-red-800',
            expired: 'bg-gray-100 text-gray-600',
        }[status] || 'bg-gray-100 text-gray-600';
    },
}" x-init="load(); loadOrgs(); resetForm()">

    {{-- Header --}}
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
        <div>
            <h2 class="text-xl font-bold text-text-primary">License Management</h2>
            <p class="text-sm text-text-secondary mt-0.5">Issue, manage, and revoke licenses across organizations</p>
        </div>
        <button @click="resetForm(); showIssueModal = true; loadOrgs()"
                class="px-4 py-2 bg-primary hover:bg-primary-light text-white text-sm font-medium rounded-lg transition-colors flex items-center gap-2 shadow-sm">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
            Issue License
        </button>
    </div>

    {{-- Filters --}}
    <div class="bg-white rounded-xl border border-gray-100 p-4 mb-5 shadow-sm flex flex-wrap items-center gap-3">
        <input type="text" x-model.debounce.400ms="search" @input="page = 1; load()" placeholder="Search licenses or organizations..."
               class="flex-1 min-w-[200px] px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none">
        <select x-model="statusFilter" @change="page = 1; load()" class="px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none">
            <option value="">All Statuses</option>
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
            <option value="revoked">Revoked</option>
            <option value="expired">Expired</option>
        </select>
        <select x-model="planFilter" @change="page = 1; load()" class="px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none">
            <option value="">All Plans</option>
            <option value="starter">Starter</option>
            <option value="professional">Professional</option>
            <option value="enterprise">Enterprise</option>
        </select>
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50/80">
                        <th class="text-left px-5 py-3 text-xs font-semibold text-text-secondary uppercase tracking-wider">License Key</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-text-secondary uppercase tracking-wider">Organization</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-text-secondary uppercase tracking-wider">Plan</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-text-secondary uppercase tracking-wider">Status</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-text-secondary uppercase tracking-wider">Expires</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-text-secondary uppercase tracking-wider">Users</th>
                        <th class="text-right px-5 py-3 text-xs font-semibold text-text-secondary uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <template x-for="lic in licenses" :key="lic.id">
                        <tr class="hover:bg-gray-50/50 transition-colors">
                            <td class="px-5 py-3">
                                <span class="font-mono text-xs font-medium text-primary" x-text="lic.license_key"></span>
                            </td>
                            <td class="px-5 py-3 text-sm" x-text="lic.organization?.name ?? '—'"></td>
                            <td class="px-5 py-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium capitalize"
                                      :class="{
                                         'bg-accent/10 text-amber-800': lic.plan === 'enterprise',
                                         'bg-info/10 text-teal-800': lic.plan === 'professional',
                                         'bg-gray-100 text-gray-700': lic.plan === 'starter',
                                      }" x-text="lic.plan"></span>
                            </td>
                            <td class="px-5 py-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium capitalize"
                                      :class="statusColor(lic.status)" x-text="lic.status"></span>
                            </td>
                            <td class="px-5 py-3 text-xs text-text-secondary font-mono whitespace-nowrap" x-text="lic.expires_at ? new Date(lic.expires_at).toLocaleDateString() : '—'"></td>
                            <td class="px-5 py-3 text-xs text-text-secondary" x-text="lic.max_users"></td>
                            <td class="px-5 py-3 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    {{-- View Details --}}
                                    <button @click="selectedLicense = lic; showDetailModal = true"
                                            class="p-1.5 rounded-lg hover:bg-gray-100 text-text-secondary hover:text-primary transition-colors" title="View Details">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                    </button>
                                    {{-- Download File --}}
                                    <button x-show="lic.status === 'active' || lic.status === 'suspended'"
                                            @click="downloadLicenseFile(lic.id)"
                                            class="p-1.5 rounded-lg hover:bg-blue-50 text-text-secondary hover:text-info transition-colors" title="Download License File">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                                    </button>
                                    {{-- Offline Activate --}}
                                    <button x-show="lic.status === 'active'"
                                            @click="openOfflineModal(lic)"
                                            class="p-1.5 rounded-lg hover:bg-green-50 text-text-secondary hover:text-secondary transition-colors" title="Offline Activate (Upload Fingerprint)">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M7.864 4.243A7.5 7.5 0 0119.5 10.5c0 2.92-.556 5.709-1.568 8.268M5.742 6.364A7.465 7.465 0 004.5 10.5a7.464 7.464 0 01-1.15 3.993m1.989 3.559A11.209 11.209 0 008.25 10.5a3.75 3.75 0 117.5 0c0 .527-.021 1.049-.064 1.565M12 10.5a14.94 14.94 0 01-3.6 9.75m6.633-4.596a18.666 18.666 0 01-2.485 5.33"/></svg>
                                    </button>
                                    {{-- Revoke --}}
                                    <button x-show="lic.status === 'active'"
                                            @click="selectedLicense = lic; revokeReason = ''; revokeConfirmKey = ''; showRevokeModal = true"
                                            class="p-1.5 rounded-lg hover:bg-red-50 text-text-secondary hover:text-error transition-colors" title="Revoke">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="licenses.length === 0 && !loading">
                        <td colspan="7" class="px-5 py-12 text-center text-text-secondary">No licenses found</td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        <div x-show="pagination.last_page > 1" class="px-5 py-3 border-t border-gray-100 flex items-center justify-between">
            <span class="text-xs text-text-secondary">Page <span x-text="pagination.current_page"></span> of <span x-text="pagination.last_page"></span></span>
            <div class="flex items-center gap-1">
                <button @click="page = Math.max(1, page - 1); load()" :disabled="page <= 1" class="px-3 py-1 text-xs border border-gray-200 rounded-lg hover:bg-gray-50 disabled:opacity-40">Prev</button>
                <button @click="page = Math.min(pagination.last_page, page + 1); load()" :disabled="page >= pagination.last_page" class="px-3 py-1 text-xs border border-gray-200 rounded-lg hover:bg-gray-50 disabled:opacity-40">Next</button>
            </div>
        </div>
    </div>

    {{-- ================================================================ --}}
    {{-- Issue License Modal                                               --}}
    {{-- ================================================================ --}}
    <div x-show="showIssueModal" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="showIssueModal = false">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto" @click.stop>
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-lg font-semibold text-text-primary">Issue New License</h3>
                <button @click="showIssueModal = false" class="p-1 rounded-lg hover:bg-gray-100"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Organization *</label>
                    <select x-model="form.org_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none">
                        <option value="">Select organization...</option>
                        <template x-for="org in orgs" :key="org.id"><option :value="org.id" x-text="org.name"></option></template>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Plan *</label>
                    <select x-model="form.plan" @change="applyPlanDefaults()" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none">
                        <option value="starter">Starter</option>
                        <option value="professional">Professional</option>
                        <option value="enterprise">Enterprise</option>
                    </select>
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-medium mb-1">Max Users</label>
                        <input type="number" x-model.number="form.max_users" min="1" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Max Activations</label>
                        <input type="number" x-model.number="form.max_activations" min="1" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Duration (days)</label>
                        <input type="number" x-model.number="form.duration_days" min="1" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none">
                    </div>
                </div>
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-sm font-medium">Feature Entitlements
                            <span class="text-text-secondary font-normal" x-text="'(' + selectedFeatureCount() + ' of ' + Object.keys(featureCatalog).length + ')'"></span>
                        </label>
                        <div class="flex items-center gap-3 text-xs">
                            <button type="button" @click="setAllFeatures(true)" class="font-medium text-primary hover:underline">Select all</button>
                            <span class="text-gray-300">|</span>
                            <button type="button" @click="setAllFeatures(false)" class="font-medium text-text-secondary hover:underline">Clear</button>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2 max-h-56 overflow-y-auto pr-1">
                        <template x-for="[key, label] in Object.entries(featureCatalog)" :key="key">
                            <label class="flex items-center gap-2 p-2 rounded-lg border border-gray-200 cursor-pointer hover:bg-gray-50">
                                <input type="checkbox" :checked="form.features[key] === true" @change="form.features[key] = $el.checked" class="rounded border-gray-300 text-primary focus:ring-primary">
                                <span class="text-sm" x-text="label"></span>
                            </label>
                        </template>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Notes</label>
                    <textarea x-model="form.notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none" placeholder="Internal notes..."></textarea>
                </div>
            </div>
            <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-3">
                <button @click="showIssueModal = false" class="px-4 py-2 text-sm font-medium text-text-secondary hover:bg-gray-100 rounded-lg transition-colors">Cancel</button>
                <button @click="issueLicense()" :disabled="issuing || !form.org_id" class="px-4 py-2 bg-primary hover:bg-primary-light text-white text-sm font-medium rounded-lg transition-colors disabled:opacity-50 flex items-center gap-2">
                    <svg x-show="issuing" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                    <span x-text="issuing ? 'Issuing...' : 'Issue License'"></span>
                </button>
            </div>
        </div>
    </div>

    {{-- ================================================================ --}}
    {{-- License Issued — Result Modal (Key + File Download)               --}}
    {{-- ================================================================ --}}
    <div x-show="showIssuedModal" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg" @click.stop>
            <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-secondary/10 flex items-center justify-center">
                    <svg class="w-5 h-5 text-secondary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-secondary">License Issued Successfully</h3>
                    <p class="text-xs text-text-secondary mt-0.5">Use the key or download the file to activate the client instance</p>
                </div>
            </div>
            <div class="p-6 space-y-5">
                {{-- License Key --}}
                <div>
                    <label class="block text-xs font-semibold text-text-secondary uppercase tracking-wider mb-1.5">License Key</label>
                    <div class="flex items-center gap-2">
                        <div class="flex-1 bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 font-mono text-sm font-semibold text-primary tracking-wide select-all"
                             x-text="issuedLicense?.license_key"></div>
                        <button @click="copyToClipboard(issuedLicense?.license_key)"
                                class="shrink-0 p-2.5 rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors" title="Copy key">
                            <svg x-show="!keyCopied" class="w-4 h-4 text-text-secondary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9.75a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184"/></svg>
                            <svg x-show="keyCopied" class="w-4 h-4 text-secondary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                        </button>
                    </div>
                    <p class="text-xs text-text-secondary mt-1.5">Enter this key in the client application at <span class="font-mono">Settings &gt; License &gt; Activate</span></p>
                </div>

                {{-- Divider --}}
                <div class="relative">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center"><span class="bg-white px-3 text-xs font-medium text-text-secondary uppercase tracking-wider">or</span></div>
                </div>

                {{-- License File Download --}}
                <div>
                    <label class="block text-xs font-semibold text-text-secondary uppercase tracking-wider mb-1.5">License File</label>
                    <div class="bg-blue-50 border border-blue-100 rounded-lg p-4">
                        <div class="flex items-start gap-3">
                            <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-blue-900" x-text="issuedLicense?.file?.filename"></p>
                                <p class="text-xs text-blue-700 mt-0.5">Signed license file for offline activation. Import at <span class="font-mono">Settings &gt; License &gt; Import File</span></p>
                            </div>
                        </div>
                        <button @click="downloadLicenseFile(issuedLicense?.id, issuedLicense?.file)"
                                class="mt-3 w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                            Download License File (.lic)
                        </button>
                    </div>
                </div>

                {{-- Details summary --}}
                <div class="bg-gray-50 rounded-lg p-3 grid grid-cols-3 gap-3 text-center">
                    <div>
                        <p class="text-xs text-text-secondary">Plan</p>
                        <p class="text-sm font-semibold capitalize" x-text="issuedLicense?.plan"></p>
                    </div>
                    <div>
                        <p class="text-xs text-text-secondary">Expires</p>
                        <p class="text-sm font-semibold font-mono" x-text="issuedLicense?.expires_at ? new Date(issuedLicense.expires_at).toLocaleDateString() : '—'"></p>
                    </div>
                    <div>
                        <p class="text-xs text-text-secondary">Status</p>
                        <p class="text-sm font-semibold text-secondary capitalize" x-text="issuedLicense?.status"></p>
                    </div>
                </div>
            </div>
            <div class="px-6 py-4 border-t border-gray-100 flex justify-end">
                <button @click="showIssuedModal = false; issuedLicense = null; keyCopied = false"
                        class="px-5 py-2 bg-primary hover:bg-primary-light text-white text-sm font-medium rounded-lg transition-colors">Done</button>
            </div>
        </div>
    </div>

    {{-- ================================================================ --}}
    {{-- Revoke Modal                                                      --}}
    {{-- ================================================================ --}}
    <div x-show="showRevokeModal" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="showRevokeModal = false">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md" @click.stop>
            <div class="px-6 py-4 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-error">Revoke License</h3>
                <p class="text-sm text-text-secondary mt-1">This action cannot be undone. All active device activations will be deactivated.</p>
            </div>
            <div class="p-6 space-y-4">
                <div class="bg-red-50 border border-red-100 rounded-lg p-3">
                    <p class="text-sm font-mono text-red-800" x-text="selectedLicense?.license_key"></p>
                    <p class="text-xs text-red-600 mt-1" x-text="selectedLicense?.organization?.name"></p>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Reason for Revocation *</label>
                    <textarea x-model="revokeReason" rows="3" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-error/20 focus:border-error outline-none" placeholder="Contract terminated, non-payment, etc."></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Type the license key to confirm *</label>
                    <input type="text" x-model="revokeConfirmKey" spellcheck="false" autocomplete="off"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm font-mono focus:ring-2 focus:ring-error/20 focus:border-error outline-none"
                           :placeholder="selectedLicense?.license_key">
                    <p x-show="revokeConfirmKey && revokeConfirmKey !== selectedLicense?.license_key" class="text-xs text-error mt-1">Key does not match.</p>
                </div>
            </div>
            <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-3">
                <button @click="showRevokeModal = false" class="px-4 py-2 text-sm font-medium text-text-secondary hover:bg-gray-100 rounded-lg transition-colors">Cancel</button>
                <button @click="revokeLicense()" :disabled="revoking || !revokeReason || revokeConfirmKey !== selectedLicense?.license_key" class="px-4 py-2 bg-error hover:bg-red-700 text-white text-sm font-medium rounded-lg transition-colors disabled:opacity-50">
                    <span x-text="revoking ? 'Revoking...' : 'Revoke License'"></span>
                </button>
            </div>
        </div>
    </div>

    {{-- ================================================================ --}}
    {{-- License Detail Modal                                              --}}
    {{-- ================================================================ --}}
    <div x-show="showDetailModal" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="showDetailModal = false">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto" @click.stop>
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-text-primary">License Details</h3>
                    <p class="text-sm font-mono text-primary mt-0.5" x-text="selectedLicense?.license_key"></p>
                </div>
                <button @click="showDetailModal = false" class="p-1 rounded-lg hover:bg-gray-100"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <div class="p-6 space-y-5">
                <div class="grid grid-cols-2 gap-4">
                    <div><span class="text-xs font-medium text-text-secondary">Organization</span><p class="text-sm font-medium" x-text="selectedLicense?.organization?.name ?? '—'"></p></div>
                    <div><span class="text-xs font-medium text-text-secondary">Plan</span><p class="text-sm font-medium capitalize" x-text="selectedLicense?.plan"></p></div>
                    <div><span class="text-xs font-medium text-text-secondary">Status</span><p><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium capitalize" :class="statusColor(selectedLicense?.status)" x-text="selectedLicense?.status"></span></p></div>
                    <div><span class="text-xs font-medium text-text-secondary">Max Users</span><p class="text-sm font-medium" x-text="selectedLicense?.max_users"></p></div>
                    <div><span class="text-xs font-medium text-text-secondary">Issued At</span><p class="text-sm font-mono" x-text="selectedLicense?.issued_at ? new Date(selectedLicense.issued_at).toLocaleDateString() : '—'"></p></div>
                    <div><span class="text-xs font-medium text-text-secondary">Expires At</span><p class="text-sm font-mono" x-text="selectedLicense?.expires_at ? new Date(selectedLicense.expires_at).toLocaleDateString() : '—'"></p></div>
                </div>
                <div>
                    <span class="text-xs font-medium text-text-secondary">Features</span>
                    <div class="flex flex-wrap gap-2 mt-1">
                        <template x-for="[feat, val] in Object.entries(selectedLicense?.features ?? {})" :key="feat">
                            <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium capitalize"
                                  :class="val ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-500 line-through'"
                                  x-text="feat.replace('_', ' ')"></span>
                        </template>
                    </div>
                </div>
                <div x-show="selectedLicense?.notes">
                    <span class="text-xs font-medium text-text-secondary">Notes</span>
                    <p class="text-sm mt-1 text-text-primary" x-text="selectedLicense?.notes"></p>
                </div>

                {{-- Activation Options (Key + File) --}}
                <div class="border-t border-gray-100 pt-5">
                    <h4 class="text-sm font-semibold text-text-primary mb-3">Activation Options</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        {{-- Copy Key --}}
                        <button @click="copyToClipboard(selectedLicense?.license_key)"
                                class="flex items-center gap-3 p-3 rounded-lg border border-gray-200 hover:border-primary/30 hover:bg-primary/5 transition-all group">
                            <div class="w-9 h-9 rounded-lg bg-primary/10 flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z"/></svg>
                            </div>
                            <div class="text-left">
                                <p class="text-sm font-medium text-text-primary group-hover:text-primary" x-text="keyCopied ? 'Copied!' : 'Copy License Key'"></p>
                                <p class="text-xs text-text-secondary">For online activation</p>
                            </div>
                        </button>
                        {{-- Download File --}}
                        <button x-show="selectedLicense?.status !== 'revoked'"
                                @click="downloadLicenseFile(selectedLicense?.id)"
                                class="flex items-center gap-3 p-3 rounded-lg border border-gray-200 hover:border-blue-300 hover:bg-blue-50 transition-all group">
                            <div class="w-9 h-9 rounded-lg bg-blue-100 flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                            </div>
                            <div class="text-left">
                                <p class="text-sm font-medium text-text-primary group-hover:text-blue-700">Download .lic File</p>
                                <p class="text-xs text-text-secondary">Generic (no device binding)</p>
                            </div>
                        </button>
                        {{-- Offline Activate with Fingerprint --}}
                        <button x-show="selectedLicense?.status === 'active'"
                                @click="showDetailModal = false; openOfflineModal(selectedLicense)"
                                class="flex items-center gap-3 p-3 rounded-lg border border-gray-200 hover:border-green-300 hover:bg-green-50 transition-all group sm:col-span-2">
                            <div class="w-9 h-9 rounded-lg bg-green-100 flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4 text-green-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M7.864 4.243A7.5 7.5 0 0119.5 10.5c0 2.92-.556 5.709-1.568 8.268M5.742 6.364A7.465 7.465 0 004.5 10.5a7.464 7.464 0 01-1.15 3.993m1.989 3.559A11.209 11.209 0 008.25 10.5a3.75 3.75 0 117.5 0c0 .527-.021 1.049-.064 1.565M12 10.5a14.94 14.94 0 01-3.6 9.75m6.633-4.596a18.666 18.666 0 01-2.485 5.33"/></svg>
                            </div>
                            <div class="text-left">
                                <p class="text-sm font-medium text-text-primary group-hover:text-green-700">Offline Activate (Upload Fingerprint)</p>
                                <p class="text-xs text-text-secondary">Upload client fingerprint file to generate a device-bound .lic</p>
                            </div>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ================================================================ --}}
    {{-- Offline Activation Modal                                          --}}
    {{-- ================================================================ --}}
    <div x-show="showOfflineModal" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="showOfflineModal = false">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto" @click.stop>
            <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-secondary/10 flex items-center justify-center">
                    <svg class="w-5 h-5 text-secondary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M7.864 4.243A7.5 7.5 0 0119.5 10.5c0 2.92-.556 5.709-1.568 8.268M5.742 6.364A7.465 7.465 0 004.5 10.5a7.464 7.464 0 01-1.15 3.993m1.989 3.559A11.209 11.209 0 008.25 10.5a3.75 3.75 0 117.5 0c0 .527-.021 1.049-.064 1.565M12 10.5a14.94 14.94 0 01-3.6 9.75m6.633-4.596a18.666 18.666 0 01-2.485 5.33"/></svg>
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-text-primary">Offline Activation</h3>
                    <p class="text-xs text-text-secondary mt-0.5">Upload a fingerprint file to generate a device-bound license</p>
                </div>
                <button @click="showOfflineModal = false" class="ml-auto p-1 rounded-lg hover:bg-gray-100"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <div class="p-6 space-y-5">
                {{-- License info --}}
                <div class="bg-gray-50 rounded-lg p-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs text-text-secondary">License</p>
                            <p class="text-sm font-mono font-semibold text-primary" x-text="offlineLicense?.license_key"></p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-text-secondary">Organization</p>
                            <p class="text-sm font-medium" x-text="offlineLicense?.organization?.name ?? '—'"></p>
                        </div>
                    </div>
                </div>

                {{-- Step 1: Upload fingerprint (before result) --}}
                <template x-if="!offlineResult">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium mb-2">Step 1: Upload Fingerprint File</label>
                            <p class="text-xs text-text-secondary mb-3">The client generates this file at <span class="font-mono">Settings > License > Offline Activation > Generate Fingerprint</span></p>
                            <label class="flex flex-col items-center justify-center w-full h-32 border-2 border-dashed rounded-xl cursor-pointer transition-colors"
                                   :class="offlineFingerprint ? 'border-secondary bg-secondary/5' : 'border-gray-300 hover:border-primary hover:bg-primary/5'">
                                <div class="flex flex-col items-center justify-center pt-5 pb-6">
                                    <template x-if="!offlineFingerprint">
                                        <div class="text-center">
                                            <svg class="w-8 h-8 mx-auto text-gray-400 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
                                            <p class="text-sm text-text-secondary">Click to upload fingerprint file</p>
                                            <p class="text-xs text-text-secondary mt-0.5">.json or base64-encoded file</p>
                                        </div>
                                    </template>
                                    <template x-if="offlineFingerprint">
                                        <div class="text-center">
                                            <svg class="w-8 h-8 mx-auto text-secondary mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            <p class="text-sm font-medium text-secondary">Fingerprint loaded</p>
                                        </div>
                                    </template>
                                </div>
                                <input type="file" class="hidden" accept=".json,.txt,.dat" @change="handleFingerprintFile($event)" />
                            </label>
                        </div>

                        {{-- Fingerprint preview --}}
                        <div x-show="offlineFingerprintData" class="bg-blue-50 border border-blue-100 rounded-lg p-4 space-y-2">
                            <h4 class="text-xs font-semibold text-blue-800 uppercase tracking-wider">Device Information</h4>
                            <div class="grid grid-cols-2 gap-2 text-sm">
                                <div>
                                    <span class="text-xs text-blue-600">Fingerprint</span>
                                    <p class="font-mono text-xs text-blue-900 truncate" x-text="offlineFingerprintData?.fingerprint"></p>
                                </div>
                                <div>
                                    <span class="text-xs text-blue-600">Hostname</span>
                                    <p class="text-sm text-blue-900" x-text="offlineFingerprintData?.hostname ?? '—'"></p>
                                </div>
                                <div>
                                    <span class="text-xs text-blue-600">OS</span>
                                    <p class="text-sm text-blue-900" x-text="offlineFingerprintData?.os ?? '—'"></p>
                                </div>
                                <div>
                                    <span class="text-xs text-blue-600">Generated At</span>
                                    <p class="text-sm text-blue-900" x-text="offlineFingerprintData?.generated_at ? new Date(offlineFingerprintData.generated_at).toLocaleString() : '—'"></p>
                                </div>
                            </div>
                        </div>

                        <p class="text-sm font-medium">Step 2: Generate Device-Bound License</p>
                        <p class="text-xs text-text-secondary">This will create an activation record and generate a .lic file bound to this specific device.</p>
                    </div>
                </template>

                {{-- Result: Download the file --}}
                <template x-if="offlineResult">
                    <div class="space-y-4">
                        <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                            <div class="flex items-start gap-3">
                                <svg class="w-6 h-6 text-secondary shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <div>
                                    <p class="text-sm font-semibold text-green-900">Device-bound license file generated</p>
                                    <p class="text-xs text-green-700 mt-1">Device: <span class="font-mono" x-text="offlineResult?.hostname ?? offlineResult?.device_fingerprint?.substring(0, 20) + '...'"></span></p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-blue-50 border border-blue-100 rounded-lg p-4">
                            <div class="flex items-start gap-3">
                                <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center shrink-0">
                                    <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-blue-900" x-text="offlineResult?.file?.filename"></p>
                                    <p class="text-xs text-blue-700 mt-0.5">Send this file to the client. They import it at <span class="font-mono">Settings > License > Import File</span></p>
                                </div>
                            </div>
                            <button @click="downloadOfflineFile()"
                                    class="mt-3 w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                                Download License File (.lic)
                            </button>
                        </div>
                    </div>
                </template>
            </div>
            <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-3">
                <template x-if="!offlineResult">
                    <div class="flex gap-3 w-full justify-end">
                        <button @click="showOfflineModal = false" class="px-4 py-2 text-sm font-medium text-text-secondary hover:bg-gray-100 rounded-lg transition-colors">Cancel</button>
                        <button @click="processOfflineActivation()" :disabled="offlineActivating || !offlineFingerprint"
                                class="px-4 py-2 bg-secondary hover:bg-secondary-light text-white text-sm font-medium rounded-lg transition-colors disabled:opacity-50 flex items-center gap-2">
                            <svg x-show="offlineActivating" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            <span x-text="offlineActivating ? 'Generating...' : 'Generate License File'"></span>
                        </button>
                    </div>
                </template>
                <template x-if="offlineResult">
                    <button @click="showOfflineModal = false; offlineResult = null; offlineLicense = null"
                            class="px-5 py-2 bg-primary hover:bg-primary-light text-white text-sm font-medium rounded-lg transition-colors">Done</button>
                </template>
            </div>
        </div>
    </div>
</div>
