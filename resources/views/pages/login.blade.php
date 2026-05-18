<div class="min-h-screen flex" x-data="{
    email: '', password: '', loading: false, error: '',
    async login() {
        this.loading = true;
        this.error = '';
        try {
            const res = await fetch('/api/v1/auth/login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ email: this.email, password: this.password }),
            });
            const json = await res.json();
            if (!res.ok) {
                this.error = json.message || json.errors?.email?.[0] || 'Login failed';
                return;
            }
            $store.auth.setAuth(json.data.user, json.data.token);
            currentPage = 'dashboard';
            window.location.hash = '#/dashboard';
            $store.notify.success('Welcome back, ' + json.data.user.name + '!');
        } catch (e) {
            this.error = 'Network error. Please try again.';
        } finally {
            this.loading = false;
        }
    }
}">
    {{-- Left Panel - Branding --}}
    <div class="hidden lg:flex lg:w-1/2 bg-primary relative overflow-hidden">
        {{-- Decorative circles --}}
        <div class="absolute -top-24 -left-24 w-96 h-96 rounded-full bg-white/5"></div>
        <div class="absolute -bottom-32 -right-32 w-[500px] h-[500px] rounded-full bg-white/5"></div>
        <div class="absolute top-1/3 right-10 w-48 h-48 rounded-full bg-accent/10"></div>

        <div class="relative z-10 flex flex-col justify-center px-16 text-white">
            <img src="/thirdline-logo-white.svg" alt="thirdLine" class="h-12 w-auto mb-12">

            <h1 class="text-4xl font-bold leading-tight mb-4">
                Licensing<br>
                <span class="text-accent">Server</span>
            </h1>
            <p class="text-white/70 text-lg leading-relaxed max-w-md">
                Central authority for license issuance, validation, and entitlement control across all AuditPro GRC instances.
            </p>

            <div class="mt-12 space-y-4">
                <div class="flex items-center gap-3 text-white/60">
                    <div class="w-8 h-8 rounded-full bg-secondary/30 flex items-center justify-center">
                        <svg class="w-4 h-4 text-secondary-light" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
                    </div>
                    <span class="text-sm">RSA-4096 Cryptographic Signing</span>
                </div>
                <div class="flex items-center gap-3 text-white/60">
                    <div class="w-8 h-8 rounded-full bg-secondary/30 flex items-center justify-center">
                        <svg class="w-4 h-4 text-secondary-light" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/></svg>
                    </div>
                    <span class="text-sm">Real-time License Validation</span>
                </div>
                <div class="flex items-center gap-3 text-white/60">
                    <div class="w-8 h-8 rounded-full bg-secondary/30 flex items-center justify-center">
                        <svg class="w-4 h-4 text-secondary-light" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
                    </div>
                    <span class="text-sm">Immutable Audit Trail</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Right Panel - Login Form --}}
    <div class="flex-1 flex items-center justify-center p-8 bg-surface">
        <div class="w-full max-w-md">
            {{-- Mobile Logo --}}
            <div class="lg:hidden flex justify-center mb-8">
                <img src="/thirdline-logo.svg" alt="thirdLine" class="h-10 w-auto">
            </div>

            <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-8">
                <div class="text-center mb-8">
                    <h2 class="text-2xl font-bold text-primary">Welcome Back</h2>
                    <p class="text-text-secondary text-sm mt-1">Sign in to the Licensing Admin Portal</p>
                </div>

                {{-- Error Alert --}}
                <div x-show="error" x-transition class="mb-4 p-3 bg-error-light border border-error/20 rounded-lg flex items-center gap-2">
                    <svg class="w-4 h-4 text-error shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
                    <span class="text-sm text-error" x-text="error"></span>
                </div>

                <form @submit.prevent="login" class="space-y-5">
                    <div>
                        <label class="block text-sm font-medium text-text-primary mb-1.5">Email Address</label>
                        <input type="email" x-model="email" required autofocus
                               class="w-full px-4 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary transition-colors outline-none"
                               placeholder="admin@auditpro.com">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-text-primary mb-1.5">Password</label>
                        <input type="password" x-model="password" required
                               class="w-full px-4 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary transition-colors outline-none"
                               placeholder="Enter your password"
                               @keydown.enter="login">
                    </div>
                    <button type="submit" :disabled="loading"
                            class="w-full py-2.5 bg-primary hover:bg-primary-light text-white font-medium rounded-lg transition-colors disabled:opacity-60 flex items-center justify-center gap-2">
                        <svg x-show="loading" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        <span x-text="loading ? 'Signing in...' : 'Sign In'"></span>
                    </button>
                </form>
            </div>

            <p class="text-center text-xs text-text-secondary mt-6">
                AuditPro GRC Licensing Server v1.0 &mdash; thirdLine Internal Audit
            </p>
        </div>
    </div>
</div>
