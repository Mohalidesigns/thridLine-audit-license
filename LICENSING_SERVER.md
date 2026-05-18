# Licensing Server — Full Implementation Plan

**Project:** AuditPro GRC — Licensing Microservice (Central Authority)
**Version:** 1.0
**Date:** March 25, 2026
**Stack:** Laravel 11 + PostgreSQL + Redis + JWT/RSA + Tailwind CSS + shadcn/ui
**Design System:** AuditPro GRC Design System v1.0

---

## 1. Executive Summary

This document describes the full implementation plan for the **AuditPro GRC Licensing Server** — a secure, auditable, scalable microservice responsible for license issuance, validation, revocation, audit logging, and feature entitlement control. It serves as the central authority for all licensing operations across deployed instances of the Internal Audit application.

---

## 2. Objectives

The Licensing Server must deliver:

- **License Issuance** — Generate cryptographically signed licenses with embedded entitlements.
- **Activation & Device Binding** — Tie licenses to specific hardware fingerprints.
- **Online Validation** — Verify license status, features, and revocation in real time.
- **Revocation** — Instantly invalidate compromised or terminated licenses.
- **Heartbeat Monitoring** — Track deployed instances and detect anomalies.
- **Audit Logging** — Maintain an immutable, exportable trail of every licensing action.
- **Feature Entitlement Control** — Granularly control which modules each licensee can access.

---

## 3. High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        ADMIN PORTAL                             │
│          (React + Inertia.js + Tailwind + shadcn/ui)            │
└───────────────────────────┬─────────────────────────────────────┘
                            │ HTTPS (TLS 1.3)
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│                    LICENSING API (Laravel 11)                    │
│  ┌──────────┐  ┌──────────────┐  ┌────────────┐  ┌──────────┐  │
│  │ Auth      │  │ License      │  │ Activation │  │ Heartbeat│  │
│  │ Guard     │  │ Controller   │  │ Controller │  │ Controller│  │
│  │ (OAuth2)  │  │              │  │            │  │          │  │
│  └──────────┘  └──────────────┘  └────────────┘  └──────────┘  │
│        │              │                │               │        │
│        ▼              ▼                ▼               ▼        │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │            LICENSE ENGINE (JWT + RSA-4096)               │    │
│  │  • Token Generation    • Signature Verification         │    │
│  │  • Claim Embedding     • Revocation Check               │    │
│  └─────────────────────────────────────────────────────────┘    │
└───────────────────────────┬─────────────────────────────────────┘
                            │
              ┌─────────────┼─────────────┐
              ▼             ▼             ▼
┌──────────────┐  ┌──────────────┐  ┌──────────────┐
│  PostgreSQL  │  │    Redis     │  │  Queue       │
│  (Primary)   │  │  (Cache +    │  │  (Laravel    │
│              │  │   Rate Limit)│  │   Horizon)   │
└──────────────┘  └──────────────┘  └──────────────┘
                                          │
                                    ┌─────┴──────┐
                                    │ Audit Log  │
                                    │ Service    │
                                    │ (Immutable)│
                                    └────────────┘
```

---

## 4. Technology Stack

| Layer | Technology | Justification |
|---|---|---|
| API Framework | Laravel 11 | Consistency with AuditPro GRC stack, mature ecosystem |
| Database | PostgreSQL 16 | JSONB support for features, robust indexing, audit-grade reliability |
| Cache / Rate Limit | Redis 7 | Sub-millisecond validation caching, sliding-window rate limiting |
| Queue | Laravel Horizon + Redis | Async audit logging, email notifications, webhook dispatch |
| Auth | Laravel Passport (OAuth2) | Machine-to-machine client credentials + admin user tokens |
| Signing | RSA-4096 via `firebase/php-jwt` | Industry-standard asymmetric signing for offline verification |
| Admin UI | React + Inertia.js + Tailwind + shadcn/ui | Aligned with AuditPro GRC Design System |
| Monitoring | Laravel Telescope + custom metrics | Development debugging + production observability |

---

## 5. Database Design

### 5.1 Entity Relationship Diagram

```
┌─────────────┐       ┌──────────────────┐       ┌─────────────────┐
│ organizations│──1:N──│    licenses       │──1:N──│ license_        │
│             │       │                  │       │ activations     │
├─────────────┤       ├──────────────────┤       ├─────────────────┤
│ id (uuid)   │       │ id (uuid)        │       │ id (uuid)       │
│ name        │       │ org_id (fk)      │       │ license_id (fk) │
│ slug        │       │ license_key      │       │ device_fp       │
│ contact_email│      │ plan             │       │ hostname        │
│ industry    │       │ features (jsonb) │       │ ip_address      │
│ country     │       │ max_users        │       │ os_info         │
│ metadata    │       │ max_activations  │       │ activated_at    │
│ created_at  │       │ issued_at        │       │ last_seen_at    │
│ updated_at  │       │ expires_at       │       │ status          │
└─────────────┘       │ status           │       │ deactivated_at  │
                      │ issued_by        │       └─────────────────┘
                      │ notes            │
                      └──────┬───────────┘
                             │
               ┌─────────────┼──────────────┐
               ▼                            ▼
┌──────────────────┐            ┌──────────────────┐
│ revocation_list  │            │   audit_logs     │
├──────────────────┤            ├──────────────────┤
│ id (uuid)        │            │ id (uuid)        │
│ license_id (fk)  │            │ action           │
│ reason           │            │ actor_type       │
│ revoked_by       │            │ actor_id         │
│ revoked_at       │            │ resource_type    │
│ effective_at     │            │ resource_id      │
└──────────────────┘            │ metadata (jsonb) │
                                │ ip_address       │
                                │ user_agent       │
                                │ created_at       │
                                └──────────────────┘
```

### 5.2 Migration Scripts

#### organizations

```php
Schema::create('organizations', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('name');
    $table->string('slug')->unique();
    $table->string('contact_email');
    $table->string('industry')->nullable();
    $table->string('country')->default('NG');
    $table->jsonb('metadata')->nullable();
    $table->timestamps();
    $table->softDeletes();

    $table->index('slug');
    $table->index('country');
});
```

#### licenses

```php
Schema::create('licenses', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('org_id')->constrained('organizations')->cascadeOnDelete();
    $table->string('license_key', 64)->unique();
    $table->string('plan'); // 'starter', 'professional', 'enterprise'
    $table->jsonb('features');
    $table->unsignedInteger('max_users')->default(5);
    $table->unsignedInteger('max_activations')->default(1);
    $table->timestamp('issued_at');
    $table->timestamp('expires_at');
    $table->enum('status', ['active', 'suspended', 'revoked', 'expired'])->default('active');
    $table->uuid('issued_by')->nullable();
    $table->text('notes')->nullable();
    $table->timestamps();
    $table->softDeletes();

    $table->index(['org_id', 'status']);
    $table->index('license_key');
    $table->index('expires_at');
    $table->index('status');
});
```

#### license_activations

```php
Schema::create('license_activations', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('license_id')->constrained('licenses')->cascadeOnDelete();
    $table->string('device_fingerprint', 128);
    $table->string('hostname')->nullable();
    $table->string('ip_address', 45)->nullable();
    $table->string('os_info')->nullable();
    $table->timestamp('activated_at');
    $table->timestamp('last_seen_at')->nullable();
    $table->enum('status', ['active', 'deactivated'])->default('active');
    $table->timestamp('deactivated_at')->nullable();
    $table->timestamps();

    $table->unique(['license_id', 'device_fingerprint']);
    $table->index('device_fingerprint');
    $table->index('last_seen_at');
});
```

#### revocation_list

```php
Schema::create('revocation_list', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('license_id')->constrained('licenses')->cascadeOnDelete();
    $table->string('reason');
    $table->uuid('revoked_by');
    $table->timestamp('revoked_at');
    $table->timestamp('effective_at')->nullable(); // allows scheduled revocations
    $table->timestamps();

    $table->index('license_id');
    $table->index('revoked_at');
});
```

#### audit_logs (append-only, no updates/deletes)

```php
Schema::create('audit_logs', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('action'); // 'license.issued', 'license.activated', 'validation.failed', etc.
    $table->string('actor_type'); // 'admin', 'system', 'client_app'
    $table->uuid('actor_id')->nullable();
    $table->string('resource_type'); // 'license', 'activation', 'organization'
    $table->uuid('resource_id')->nullable();
    $table->jsonb('metadata')->nullable();
    $table->string('ip_address', 45)->nullable();
    $table->string('user_agent')->nullable();
    $table->timestamp('created_at');

    $table->index(['action', 'created_at']);
    $table->index(['resource_type', 'resource_id']);
    $table->index('actor_id');
    $table->index('created_at');
});
```

### 5.3 Additional Tables (Best Practice Enhancements)

#### license_usage_metrics

```php
Schema::create('license_usage_metrics', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('license_id')->constrained('licenses');
    $table->foreignUuid('activation_id')->constrained('license_activations');
    $table->unsignedInteger('active_users_count');
    $table->jsonb('feature_usage'); // { "audit": 142, "risk": 87, "compliance": 0 }
    $table->timestamp('reported_at');
    $table->timestamps();

    $table->index(['license_id', 'reported_at']);
});
```

#### api_clients (OAuth2 machine credentials)

```php
Schema::create('api_clients', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('org_id')->constrained('organizations');
    $table->string('client_id', 80)->unique();
    $table->string('client_secret_hash');
    $table->jsonb('allowed_scopes'); // ['license:validate', 'license:heartbeat']
    $table->jsonb('allowed_ips')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

---

## 6. License Engine (Core Signing Service)

### 6.1 RSA Key Management

```php
// config/licensing.php
return [
    'keys' => [
        'private' => storage_path('keys/license_private.pem'),
        'public'  => storage_path('keys/license_public.pem'),
    ],
    'algorithm' => 'RS256',
    'issuer'    => 'auditpro-grc-licensing',
    'ttl'       => 365, // default license TTL in days
    'grace_period_days' => 7,
    'heartbeat_interval_hours' => 48,
    'max_clock_drift_seconds' => 300,
];
```

### 6.2 Key Generation (One-Time Setup)

```bash
# Generate RSA-4096 key pair
openssl genrsa -out storage/keys/license_private.pem 4096
openssl rsa -in storage/keys/license_private.pem -pubout -out storage/keys/license_public.pem

# Set strict permissions
chmod 600 storage/keys/license_private.pem
chmod 644 storage/keys/license_public.pem
```

### 6.3 License Token Generator

```php
<?php

namespace App\Services\Licensing;

use Firebase\JWT\JWT;
use Illuminate\Support\Str;
use App\Models\License;

class LicenseEngine
{
    private string $privateKey;
    private string $publicKey;
    private string $algorithm;

    public function __construct()
    {
        $this->privateKey = file_get_contents(config('licensing.keys.private'));
        $this->publicKey  = file_get_contents(config('licensing.keys.public'));
        $this->algorithm  = config('licensing.algorithm');
    }

    /**
     * Generate a signed JWT license token.
     */
    public function generateToken(License $license): string
    {
        $payload = [
            'iss'  => config('licensing.issuer'),
            'sub'  => $license->org_id,
            'jti'  => $license->id,
            'iat'  => now()->timestamp,
            'nbf'  => now()->timestamp,
            'exp'  => $license->expires_at->timestamp,
            'lk'   => $license->license_key,
            'plan' => $license->plan,
            'feat' => $license->features,
            'mu'   => $license->max_users,
            'ma'   => $license->max_activations,
            'org'  => [
                'id'   => $license->organization->id,
                'name' => $license->organization->name,
                'slug' => $license->organization->slug,
            ],
            'ver'  => '1.0',
            'chk'  => $this->generateIntegrityHash($license),
        ];

        return JWT::encode($payload, $this->privateKey, $this->algorithm);
    }

    /**
     * Validate a license token and return decoded claims.
     */
    public function validateToken(string $token): object
    {
        return JWT::decode($token, new \Firebase\JWT\Key($this->publicKey, $this->algorithm));
    }

    /**
     * Generate a unique, collision-resistant license key.
     * Format: APGRC-XXXX-XXXX-XXXX-XXXX
     */
    public function generateLicenseKey(): string
    {
        do {
            $key = 'APGRC-' . implode('-', [
                strtoupper(Str::random(4)),
                strtoupper(Str::random(4)),
                strtoupper(Str::random(4)),
                strtoupper(Str::random(4)),
            ]);
        } while (License::where('license_key', $key)->exists());

        return $key;
    }

    /**
     * Integrity hash to detect payload tampering at rest.
     */
    private function generateIntegrityHash(License $license): string
    {
        $data = $license->id . $license->org_id . $license->plan
              . json_encode($license->features) . $license->expires_at->timestamp;

        return hash_hmac('sha256', $data, config('app.key'));
    }
}
```

---

## 7. API Layer — Full Endpoint Specification

### 7.1 Authentication

All API endpoints require OAuth2 Bearer tokens. Two token types are supported:

| Token Type | Grant | Scope | Use Case |
|---|---|---|---|
| Admin Token | `authorization_code` | `license:*` | Admin portal operations |
| Client Token | `client_credentials` | `license:validate`, `license:heartbeat` | Application-to-server calls |

### 7.2 Endpoint Reference

#### POST /api/v1/licenses — Issue License

```
Authorization: Bearer {admin_token}
Content-Type: application/json

Request:
{
    "org_id": "uuid",
    "plan": "enterprise",
    "features": {
        "audit": true,
        "risk": true,
        "compliance": true,
        "swift_cscf": true,
        "ai_assistant": false
    },
    "max_users": 25,
    "max_activations": 3,
    "duration_days": 365,
    "notes": "Annual enterprise license for First Bank"
}

Response (201):
{
    "data": {
        "id": "uuid",
        "license_key": "APGRC-A1B2-C3D4-E5F6-G7H8",
        "token": "eyJhbGciOiJSUzI1NiIs...",
        "plan": "enterprise",
        "features": { ... },
        "issued_at": "2026-03-25T10:00:00Z",
        "expires_at": "2027-03-25T10:00:00Z",
        "status": "active"
    }
}
```

#### POST /api/v1/licenses/activate — Activate License

```
Authorization: Bearer {client_token}
Content-Type: application/json

Request:
{
    "license_key": "APGRC-A1B2-C3D4-E5F6-G7H8",
    "device_fingerprint": "sha256:cpu+disk+hostname+mac",
    "hostname": "audit-server-01.bank.local",
    "ip_address": "10.0.1.50",
    "os_info": "Ubuntu 22.04 LTS"
}

Response (200):
{
    "data": {
        "activation_id": "uuid",
        "license_token": "eyJhbGciOiJSUzI1NiIs...",
        "activated_at": "2026-03-25T10:05:00Z",
        "entitlements": {
            "features": { "audit": true, "risk": true, ... },
            "max_users": 25,
            "expires_at": "2027-03-25T10:00:00Z"
        },
        "heartbeat_interval_hours": 48,
        "grace_period_days": 7
    }
}

Error (409 - Max Activations):
{
    "error": "activation_limit_reached",
    "message": "Maximum activations (3) reached for this license.",
    "current_activations": 3
}
```

#### POST /api/v1/licenses/validate — Online Validation

```
Authorization: Bearer {client_token}
Content-Type: application/json

Request:
{
    "license_key": "APGRC-A1B2-C3D4-E5F6-G7H8",
    "device_fingerprint": "sha256:...",
    "current_users": 12,
    "app_version": "2.1.0"
}

Response (200):
{
    "data": {
        "valid": true,
        "status": "active",
        "entitlements": { ... },
        "revoked": false,
        "expires_at": "2027-03-25T10:00:00Z",
        "days_remaining": 365,
        "server_time": "2026-03-25T10:10:00Z"
    }
}
```

#### POST /api/v1/licenses/revoke — Revoke License

```
Authorization: Bearer {admin_token}
Content-Type: application/json

Request:
{
    "license_id": "uuid",
    "reason": "Contract terminated - non-payment",
    "effective_immediately": true
}

Response (200):
{
    "data": {
        "license_id": "uuid",
        "status": "revoked",
        "revoked_at": "2026-03-25T11:00:00Z",
        "affected_activations": 2
    }
}
```

#### POST /api/v1/licenses/heartbeat — Heartbeat Check-In

```
Authorization: Bearer {client_token}
Content-Type: application/json

Request:
{
    "license_key": "APGRC-A1B2-C3D4-E5F6-G7H8",
    "device_fingerprint": "sha256:...",
    "active_users": 12,
    "feature_usage": {
        "audit": 142,
        "risk": 87,
        "compliance": 0
    },
    "app_version": "2.1.0",
    "local_audit_logs": [ ... ]  // synced from client
}

Response (200):
{
    "data": {
        "status": "active",
        "revoked": false,
        "server_time": "2026-03-25T12:00:00Z",
        "next_heartbeat_before": "2026-03-27T12:00:00Z",
        "updated_entitlements": null,
        "commands": []  // e.g., ["refresh_token", "force_logout_user:uuid"]
    }
}
```

#### POST /api/v1/licenses/offline-activate — Offline Activation

```
Authorization: Bearer {admin_token}
Content-Type: application/json

Request:
{
    "license_key": "APGRC-A1B2-C3D4-E5F6-G7H8",
    "device_fingerprint_file": "<base64-encoded fingerprint payload>"
}

Response (200):
{
    "data": {
        "license_file": "<base64-encoded signed license file>",
        "activation_id": "uuid",
        "instructions": "Upload this file to the application at Settings > License > Import"
    }
}
```

---

## 8. Licensing Logic Rules

### 8.1 Validation Decision Tree

```
VALIDATE LICENSE REQUEST
│
├── Token signature valid?
│   ├── NO → REJECT (401: invalid_signature)
│   └── YES ↓
│
├── Token expired?
│   ├── YES → REJECT (403: license_expired)
│   └── NO ↓
│
├── License revoked? (check revocation_list + cache)
│   ├── YES → REJECT (403: license_revoked)
│   └── NO ↓
│
├── Device fingerprint matches activation?
│   ├── NO → ALERT + REJECT (403: device_mismatch)
│   └── YES ↓
│
├── Current users ≤ max_users?
│   ├── NO → REJECT (403: user_limit_exceeded)
│   └── YES ↓
│
└── ✅ VALID — return entitlements
```

### 8.2 Business Rules

| Rule | Implementation | Enforcement |
|---|---|---|
| Max device activations | Count active entries in `license_activations` | Block new activations when limit reached |
| License expiry | Compare `expires_at` with server UTC time | Reject expired tokens; cron job to update status |
| Revoked = always invalid | Check `revocation_list` on every validation | Redis cache for sub-ms lookups |
| Device mismatch | Compare submitted fingerprint with stored | Trigger alert + audit log entry |
| Grace period | `last_seen_at` + grace_period vs. now | Allow limited access during grace |
| Clock drift tolerance | Compare client time with server time | Reject if drift > 300 seconds |

---

## 9. Security Controls

### 9.1 Transport Security

```nginx
# nginx.conf — enforce TLS 1.3
server {
    listen 443 ssl http2;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers on;
    ssl_ciphers 'TLS_AES_256_GCM_SHA384:TLS_CHACHA20_POLY1305_SHA256';
    ssl_session_timeout 1d;
    ssl_session_cache shared:SSL:10m;
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains" always;
    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
}
```

### 9.2 Rate Limiting

```php
// app/Http/Kernel.php — throttle groups
RateLimiter::for('license-api', function (Request $request) {
    return [
        Limit::perMinute(60)->by($request->bearerToken()),
        Limit::perMinute(10)->by($request->ip())->response(function () {
            return response()->json([
                'error' => 'rate_limit_exceeded',
                'retry_after' => 60,
            ], 429);
        }),
    ];
});

// Stricter limit for activation attempts (anti-brute-force)
RateLimiter::for('activation', function (Request $request) {
    return Limit::perHour(5)->by($request->input('license_key') . '|' . $request->ip());
});
```

### 9.3 IP Allowlisting (Bank Environments)

```php
// app/Http/Middleware/IpAllowlist.php
class IpAllowlist
{
    public function handle(Request $request, Closure $next)
    {
        $client = ApiClient::where('client_id', $request->oauth_client_id)->first();

        if ($client->allowed_ips && !in_array($request->ip(), $client->allowed_ips)) {
            AuditLog::record('ip_blocked', $client->id, [
                'attempted_ip' => $request->ip(),
                'allowed_ips'  => $client->allowed_ips,
            ]);
            abort(403, 'IP address not in allowlist.');
        }

        return $next($request);
    }
}
```

### 9.4 Additional Security Best Practices

| Control | Implementation |
|---|---|
| **Key rotation** | Scheduled RSA key rotation every 12 months; old public keys kept for validation of existing tokens |
| **Secret management** | Private keys stored in HashiCorp Vault or AWS Secrets Manager — never in Git |
| **Request signing** | HMAC-SHA256 signature on all client requests (prevents replay attacks) |
| **Audit log immutability** | `audit_logs` table has no UPDATE/DELETE grants; append-only with DB-level triggers |
| **Encryption at rest** | PostgreSQL TDE or filesystem-level encryption (LUKS) for database volumes |
| **CORS hardening** | Strict origin allowlist; no wildcards |
| **CSP headers** | Content-Security-Policy with nonce-based script loading |

---

## 10. Admin Dashboard — UI Specification

### 10.1 Design System Integration

The Admin Dashboard follows the **AuditPro GRC Design System v1.0** built with **Tailwind CSS + shadcn/ui** components:

| Design Token | Value | Usage |
|---|---|---|
| `--color-primary` | `#1A365D` | Sidebar, primary buttons, headers |
| `--color-secondary` | `#2D7D46` | Active license badges, success states |
| `--color-accent` | `#D4AF37` | Active nav indicator, important badges |
| `--color-error` | `#C53030` | Revoked licenses, critical alerts |
| `--color-warning` | `#DD6B20` | Expiring soon indicators |
| `--color-info` | `#319795` | Informational callouts |
| Font (UI) | Inter | All interface text |
| Font (Data) | Roboto Mono | License keys, IDs, timestamps |

### 10.2 shadcn/ui Components Used

```
- Card, CardHeader, CardContent, CardFooter     → License detail panels
- Table, TableHeader, TableBody, TableRow        → License lists, activation logs
- Badge                                          → Status indicators (active/revoked/expired)
- Button                                         → Primary/secondary/destructive actions
- Dialog, DialogContent, DialogTrigger           → Issue license, confirm revocation
- Input, Select, Textarea                        → License forms
- Tabs, TabsList, TabsTrigger, TabsContent       → License details sections
- Alert, AlertDescription                        → Warnings, compliance notices
- DropdownMenu                                   → Actions menus on license rows
- Command (CommandPalette)                       → Quick search for licenses/orgs
- Toast                                          → Success/error notifications
- Tooltip                                        → Help text on form fields
- Calendar + DatePicker                          → Expiry date selection
- Progress                                       → Usage meters (users, activations)
- Switch                                         → Feature toggles
- Separator                                      → Section dividers
```

### 10.3 Dashboard Pages

**10.3.1 Overview Dashboard**

```
┌──────────────────────────────────────────────────────────────┐
│  LICENSING DASHBOARD                                         │
├──────────────────────────────────────────────────────────────┤
│                                                              │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐       │
│  │ Active   │ │ Expiring │ │ Revoked  │ │ Total    │       │
│  │ Licenses │ │ Soon     │ │ This     │ │ Orgs     │       │
│  │   47     │ │    5     │ │ Month: 2 │ │   23     │       │
│  │ ▲ 12%    │ │ ⚠ Alert  │ │ ● Red    │ │ ▲ 3 new  │       │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘       │
│                                                              │
│  ┌─────────────────────────────────┐ ┌────────────────────┐ │
│  │ License Activity (30 days)      │ │ Feature Adoption   │ │
│  │ [Area Chart: issues/revocations]│ │ [Bar Chart]        │ │
│  │                                 │ │ Audit ████████ 95% │ │
│  │                                 │ │ Risk  ██████── 72% │ │
│  │                                 │ │ Compl █████─── 61% │ │
│  └─────────────────────────────────┘ └────────────────────┘ │
│                                                              │
│  Recent Activity                                             │
│  ┌──────────────────────────────────────────────────────────┐│
│  │ Time     │ Action           │ Org          │ Actor       ││
│  │ 10:05    │ License Issued   │ First Bank   │ Admin       ││
│  │ 09:42    │ Heartbeat OK     │ GTBank       │ System      ││
│  │ 09:30    │ Validation Fail  │ Unknown      │ 10.0.1.99   ││
│  └──────────────────────────────────────────────────────────┘│
└──────────────────────────────────────────────────────────────┘
```

**10.3.2 License Management Page**

Features: Issue new license (dialog form), search/filter/sort table, bulk actions, export CSV.

**10.3.3 License Detail Page**

Tabs: Overview, Activations, Heartbeat History, Audit Trail, Usage Metrics.

**10.3.4 Audit Logs Page**

Filterable, searchable, exportable log viewer with JSON metadata expansion.

---

## 11. Background Jobs & Queue Architecture

```php
// Scheduled tasks (app/Console/Kernel.php)
$schedule->command('licenses:expire-check')
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/license-cron.log'));

$schedule->command('licenses:heartbeat-alerts')
    ->everyFourHours()
    ->description('Alert on missed heartbeats');

$schedule->command('audit-logs:archive')
    ->daily()
    ->description('Archive logs older than 90 days to cold storage');

$schedule->command('licenses:usage-report')
    ->weeklyOn(1, '08:00')
    ->description('Generate weekly usage summary');
```

---

## 12. Compliance Alignment

| Standard | Control | Implementation |
|---|---|---|
| **NDPA 2023** | Data minimization | Collect only necessary device info; no personal data in fingerprints |
| **NDPA 2023** | Encryption | AES-256 at rest; TLS 1.2+ in transit; RSA-4096 signing |
| **NDPA 2023** | Data subject rights | Org admins can export/delete their licensing data via API |
| **ISO 27001 A.5.15** | Access control | Role-based access; OAuth2 scopes; IP allowlisting |
| **ISO 27001 A.8.16** | Monitoring & logging | Immutable audit logs; real-time alerting; log retention policy |
| **ISO 27001 A.8.24** | Cryptography | RSA-4096 asymmetric signing; HMAC integrity checks |
| **ISO 27001 A.8.9** | Configuration management | Version-controlled config; environment-based settings |
| **CBN Guidelines** | Regulatory reporting | Exportable audit logs in CSV/JSON for regulator submissions |

---

## 13. Enhanced Best Practices & Recommendations

### 13.1 Recommended Additions (Not in Original Spec)

| Enhancement | Description | Priority |
|---|---|---|
| **License Templates** | Pre-defined plans (Starter, Professional, Enterprise) with default feature sets | High |
| **Webhook Notifications** | Push events to client apps on revocation, expiry warning, feature change | High |
| **Multi-Tenant Key Isolation** | Per-organization API credentials with scoped permissions | High |
| **Geo-Fencing** | Restrict license activation to specific countries/regions | Medium |
| **License Transfer** | Allow transferring a license from one device to another (with admin approval) | Medium |
| **Tiered Grace Periods** | Different grace periods per plan (Enterprise: 14 days, Starter: 3 days) | Medium |
| **Auto-Renewal Hooks** | Integration points for payment gateways to auto-renew licenses | Low |
| **Usage Analytics Dashboard** | Visualize feature adoption, peak usage times, trend analysis | Medium |
| **Canary Tokens** | Embed unique identifiers in each license to trace leaks | High |
| **Certificate Pinning** | Pin the server's TLS certificate in client apps | High |
| **SBOM Tracking** | Track which app version each license is running for vulnerability management | Medium |

### 13.2 Disaster Recovery

| Scenario | Recovery Strategy |
|---|---|
| Database failure | Automated PostgreSQL streaming replication with 15-second RPO |
| Key compromise | Immediate key rotation; re-issue all active licenses; notify all clients |
| Server outage | Client apps operate in grace period; failover to standby instance |
| DDoS attack | Cloudflare/WAF protection; Redis-backed rate limiting; IP blocking |

---

## 14. Implementation Timeline

| Phase | Duration | Deliverables |
|---|---|---|
| **Phase 1: Foundation** | 2 weeks | Database schema, License Engine, RSA key management |
| **Phase 2: API Layer** | 2 weeks | All endpoints, OAuth2 auth, rate limiting, validation |
| **Phase 3: Admin Dashboard** | 2 weeks | Dashboard UI (Tailwind + shadcn/ui), license management |
| **Phase 4: Security Hardening** | 1 week | TLS config, IP allowlisting, penetration testing prep |
| **Phase 5: Testing & QA** | 1 week | Unit tests, integration tests, load testing |
| **Phase 6: Documentation & Deployment** | 1 week | API docs, deployment runbook, monitoring setup |
| **Total** | **9 weeks** | Production-ready licensing server |

---

## 15. Testing Strategy

```php
// Example: License Issuance Test
public function test_license_issuance_generates_valid_jwt()
{
    $admin = User::factory()->admin()->create();
    $org = Organization::factory()->create();

    $response = $this->actingAs($admin, 'api')
        ->postJson('/api/v1/licenses', [
            'org_id'          => $org->id,
            'plan'            => 'enterprise',
            'features'        => ['audit' => true, 'risk' => true],
            'max_users'       => 25,
            'max_activations' => 3,
            'duration_days'   => 365,
        ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'data' => ['id', 'license_key', 'token', 'plan', 'features', 'status'],
        ]);

    // Verify JWT signature
    $engine = app(LicenseEngine::class);
    $decoded = $engine->validateToken($response->json('data.token'));
    $this->assertEquals('enterprise', $decoded->plan);
    $this->assertTrue($decoded->feat->audit);
}

// Example: Revocation enforcement test
public function test_revoked_license_fails_validation()
{
    $license = License::factory()->active()->create();
    RevocationList::create([
        'license_id' => $license->id,
        'reason'     => 'Contract terminated',
        'revoked_by' => $this->admin->id,
        'revoked_at' => now(),
    ]);

    $response = $this->postJson('/api/v1/licenses/validate', [
        'license_key'       => $license->license_key,
        'device_fingerprint' => 'sha256:valid-device',
    ]);

    $response->assertStatus(403)
        ->assertJson(['error' => 'license_revoked']);
}
```

---

**End of Part 1 — Licensing Server Implementation Plan**
