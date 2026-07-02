<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>AuditPro GRC — Licensing Server</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:300,400,500,600,700|roboto-mono:400,500" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-surface text-text-primary font-sans antialiased"
      x-data="{ currentPage: 'dashboard', sidebarOpen: true }"
      x-init="
        const route = () => {
            const hash = window.location.hash.replace('#/', '') || 'dashboard';
            if (!$store.auth.isAuthenticated) {
                if (hash !== 'login') window.location.hash = '#/login';
                currentPage = 'login';
            } else {
                currentPage = hash === 'login' ? 'dashboard' : hash;
            }
        };
        window.addEventListener('hashchange', route);
        route();
        // If we booted with a persisted token, confirm it's still valid before
        // trusting it — an expired/revoked token sends us back to login instead
        // of flashing the authenticated shell.
        if ($store.auth.isAuthenticated) {
            $store.auth.verify().then((ok) => { route(); });
        }
      ">

    {{-- Toast Notifications --}}
    <div class="fixed top-4 right-4 z-[100] space-y-2" x-show="$store.notify.items.length > 0">
        <template x-for="item in $store.notify.items" :key="item.id">
            <div x-show="true"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0 translate-x-8"
                 x-transition:enter-end="opacity-100 translate-x-0"
                 x-transition:leave="transition ease-in duration-200"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="flex items-center gap-3 px-4 py-3 rounded-lg shadow-lg min-w-[320px] cursor-pointer"
                 :class="{
                    'bg-green-50 border border-green-200 text-green-800': item.type === 'success',
                    'bg-red-50 border border-red-200 text-red-800': item.type === 'error',
                    'bg-yellow-50 border border-yellow-200 text-yellow-800': item.type === 'warning',
                    'bg-blue-50 border border-blue-200 text-blue-800': item.type === 'info',
                 }"
                 @click="$store.notify.remove(item.id)">
                <template x-if="item.type === 'success'">
                    <svg class="w-5 h-5 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                </template>
                <template x-if="item.type === 'error'">
                    <svg class="w-5 h-5 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
                </template>
                <span x-text="item.message" class="text-sm font-medium"></span>
            </div>
        </template>
    </div>

    {{-- Login Page --}}
    <template x-if="currentPage === 'login'">
        @include('pages.login')
    </template>

    {{-- Authenticated Layout --}}
    <template x-if="currentPage !== 'login' && $store.auth.isAuthenticated">
        <div class="flex h-full">
            {{-- Sidebar --}}
            @include('components.sidebar')

            {{-- Main Content --}}
            <main class="flex-1 overflow-y-auto transition-all duration-300" :class="sidebarOpen ? 'ml-64' : 'ml-16'">
                {{-- Top Bar --}}
                @include('components.topbar')

                {{-- Page Content --}}
                <div class="p-6">
                    <template x-if="currentPage === 'dashboard'">
                        @include('pages.dashboard')
                    </template>
                    <template x-if="currentPage === 'licenses'">
                        @include('pages.licenses')
                    </template>
                    <template x-if="currentPage === 'organizations'">
                        @include('pages.organizations')
                    </template>
                    <template x-if="currentPage === 'api-clients'">
                        @include('pages.api-clients')
                    </template>
                    <template x-if="currentPage === 'deployments'">
                        @include('pages.deployments')
                    </template>
                    <template x-if="currentPage === 'audit-logs'">
                        @include('pages.audit-logs')
                    </template>
                </div>
            </main>
        </div>
    </template>
</body>
</html>
