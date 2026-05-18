import './bootstrap';
import Alpine from 'alpinejs';
import persist from '@alpinejs/persist';

Alpine.plugin(persist);

// Global auth store
Alpine.store('auth', {
    user: Alpine.$persist(null).as('auth_user'),
    token: Alpine.$persist(null).as('auth_token'),

    get isAuthenticated() {
        return !!this.token;
    },

    get headers() {
        return {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': `Bearer ${this.token}`,
        };
    },

    setAuth(user, token) {
        this.user = user;
        this.token = token;
    },

    clear() {
        this.user = null;
        this.token = null;
    },

    hasRole(role) {
        return this.user?.roles?.includes(role) ?? false;
    },

    hasPermission(permission) {
        return this.user?.permissions?.includes(permission) ?? false;
    },
});

// Global notification store
Alpine.store('notify', {
    items: [],

    success(message) {
        this._add('success', message);
    },

    error(message) {
        this._add('error', message);
    },

    warning(message) {
        this._add('warning', message);
    },

    info(message) {
        this._add('info', message);
    },

    _add(type, message) {
        const id = Date.now();
        this.items.push({ id, type, message });
        setTimeout(() => {
            this.items = this.items.filter(i => i.id !== id);
        }, 5000);
    },

    remove(id) {
        this.items = this.items.filter(i => i.id !== id);
    }
});

// API helper
window.api = {
    baseUrl: '/api/v1',

    async request(method, url, data = null) {
        const auth = Alpine.store('auth');
        const opts = {
            method,
            headers: auth.headers,
        };
        if (data && method !== 'GET') {
            opts.body = JSON.stringify(data);
        }
        const response = await fetch(`${this.baseUrl}${url}`, opts);
        const json = await response.json().catch(() => null);

        if (response.status === 401) {
            auth.clear();
            window.location.hash = '#/login';
            throw new Error('Unauthorized');
        }

        if (!response.ok) {
            throw { status: response.status, data: json };
        }
        return json;
    },

    get(url) { return this.request('GET', url); },
    post(url, data) { return this.request('POST', url, data); },
    put(url, data) { return this.request('PUT', url, data); },
    delete(url) { return this.request('DELETE', url); },
};

window.Alpine = Alpine;
Alpine.start();
