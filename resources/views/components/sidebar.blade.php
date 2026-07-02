<aside class="fixed left-0 top-0 h-full bg-primary text-white z-50 transition-all duration-300 flex flex-col shadow-xl"
       :class="sidebarOpen ? 'w-64' : 'w-16'">

    {{-- Logo --}}
    <div class="flex items-center h-16 px-4 border-b border-white/10">
        <a href="#/dashboard" @click="currentPage = 'dashboard'" class="flex items-center gap-3 overflow-hidden">
            <template x-if="sidebarOpen">
                <img src="/thirdline-logo-white.svg" alt="thirdLine" class="h-8 w-auto">
            </template>
            <template x-if="!sidebarOpen">
                <div class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center">
                    <svg class="w-5 h-5 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>
                    </svg>
                </div>
            </template>
        </a>
    </div>

    {{-- Navigation --}}
    <nav class="flex-1 py-4 px-2 space-y-1 overflow-y-auto">
        {{-- Dashboard --}}
        <a href="#/dashboard" @click="currentPage = 'dashboard'"
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors relative"
           :class="currentPage === 'dashboard' ? 'bg-white/15 text-white' : 'text-white/70 hover:bg-white/10 hover:text-white'">
            <div class="absolute left-0 top-1/2 -translate-y-1/2 w-1 h-6 rounded-r-full bg-accent transition-opacity"
                 :class="currentPage === 'dashboard' ? 'opacity-100' : 'opacity-0'"></div>
            <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/>
            </svg>
            <span x-show="sidebarOpen" x-transition class="whitespace-nowrap">Dashboard</span>
        </a>

        {{-- Licenses --}}
        <a href="#/licenses" @click="currentPage = 'licenses'"
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors relative"
           :class="currentPage === 'licenses' ? 'bg-white/15 text-white' : 'text-white/70 hover:bg-white/10 hover:text-white'">
            <div class="absolute left-0 top-1/2 -translate-y-1/2 w-1 h-6 rounded-r-full bg-accent transition-opacity"
                 :class="currentPage === 'licenses' ? 'opacity-100' : 'opacity-0'"></div>
            <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z"/>
            </svg>
            <span x-show="sidebarOpen" x-transition class="whitespace-nowrap">Licenses</span>
        </a>

        {{-- Organizations --}}
        <a href="#/organizations" @click="currentPage = 'organizations'"
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors relative"
           :class="currentPage === 'organizations' ? 'bg-white/15 text-white' : 'text-white/70 hover:bg-white/10 hover:text-white'">
            <div class="absolute left-0 top-1/2 -translate-y-1/2 w-1 h-6 rounded-r-full bg-accent transition-opacity"
                 :class="currentPage === 'organizations' ? 'opacity-100' : 'opacity-0'"></div>
            <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/>
            </svg>
            <span x-show="sidebarOpen" x-transition class="whitespace-nowrap">Organizations</span>
        </a>

        {{-- API Clients --}}
        <a href="#/api-clients" @click="currentPage = 'api-clients'"
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors relative"
           :class="currentPage === 'api-clients' ? 'bg-white/15 text-white' : 'text-white/70 hover:bg-white/10 hover:text-white'">
            <div class="absolute left-0 top-1/2 -translate-y-1/2 w-1 h-6 rounded-r-full bg-accent transition-opacity"
                 :class="currentPage === 'api-clients' ? 'opacity-100' : 'opacity-0'"></div>
            <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17.25 6.75L22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3l-4.5 16.5"/>
            </svg>
            <span x-show="sidebarOpen" x-transition class="whitespace-nowrap">API Clients</span>
        </a>

        {{-- Deployments --}}
        <a href="#/deployments" @click="currentPage = 'deployments'"
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors relative"
           :class="currentPage === 'deployments' ? 'bg-white/15 text-white' : 'text-white/70 hover:bg-white/10 hover:text-white'">
            <div class="absolute left-0 top-1/2 -translate-y-1/2 w-1 h-6 rounded-r-full bg-accent transition-opacity"
                 :class="currentPage === 'deployments' ? 'opacity-100' : 'opacity-0'"></div>
            <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18m9-9H3m14.25-6.75L6.75 17.25m10.5 0L6.75 6.75"/>
            </svg>
            <span x-show="sidebarOpen" x-transition class="whitespace-nowrap">Deployments</span>
        </a>

        {{-- Audit Logs --}}
        <a href="#/audit-logs" @click="currentPage = 'audit-logs'"
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors relative"
           :class="currentPage === 'audit-logs' ? 'bg-white/15 text-white' : 'text-white/70 hover:bg-white/10 hover:text-white'">
            <div class="absolute left-0 top-1/2 -translate-y-1/2 w-1 h-6 rounded-r-full bg-accent transition-opacity"
                 :class="currentPage === 'audit-logs' ? 'opacity-100' : 'opacity-0'"></div>
            <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/>
            </svg>
            <span x-show="sidebarOpen" x-transition class="whitespace-nowrap">Audit Logs</span>
        </a>
    </nav>

    {{-- Sidebar Toggle --}}
    <div class="p-3 border-t border-white/10">
        <button @click="sidebarOpen = !sidebarOpen" class="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-lg text-white/60 hover:text-white hover:bg-white/10 transition-colors">
            <svg class="w-5 h-5 transition-transform" :class="sidebarOpen ? '' : 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M18.75 19.5l-7.5-7.5 7.5-7.5m-6 15L5.25 12l7.5-7.5"/>
            </svg>
            <span x-show="sidebarOpen" x-transition class="text-xs">Collapse</span>
        </button>
    </div>
</aside>
