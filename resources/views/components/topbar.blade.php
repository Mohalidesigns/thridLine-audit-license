<header class="sticky top-0 z-40 h-16 bg-white border-b border-gray-200 flex items-center justify-between px-6 shadow-sm">
    <div class="flex items-center gap-4">
        <h1 class="text-lg font-semibold text-primary capitalize" x-text="currentPage.replace('-', ' ')"></h1>
    </div>

    <div class="flex items-center gap-4">
        {{-- User Menu --}}
        <div x-data="{ open: false }" class="relative">
            <button @click="open = !open" class="flex items-center gap-2 px-3 py-1.5 rounded-lg hover:bg-gray-100 transition-colors">
                <div class="w-8 h-8 rounded-full bg-primary flex items-center justify-center text-white text-sm font-semibold"
                     x-text="$store.auth.user?.name?.charAt(0) ?? 'U'"></div>
                <div class="text-left hidden sm:block">
                    <p class="text-sm font-medium text-text-primary" x-text="$store.auth.user?.name"></p>
                    <p class="text-xs text-text-secondary" x-text="$store.auth.user?.roles?.[0] ?? 'User'"></p>
                </div>
                <svg class="w-4 h-4 text-text-secondary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
            </button>

            <div x-show="open" @click.away="open = false" x-transition
                 class="absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border border-gray-200 py-1 z-50">
                <div class="px-4 py-2 border-b border-gray-100">
                    <p class="text-sm font-medium" x-text="$store.auth.user?.email"></p>
                </div>
                <button @click="
                    api.post('/auth/logout').catch(() => {});
                    $store.auth.clear();
                    currentPage = 'login';
                    window.location.hash = '#/login';
                    open = false;
                " class="w-full text-left px-4 py-2 text-sm text-error hover:bg-red-50 transition-colors flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75"/></svg>
                    Sign Out
                </button>
            </div>
        </div>
    </div>
</header>
