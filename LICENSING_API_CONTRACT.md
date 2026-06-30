# Licensing API Contract — LicensingServer ↔ ThirdLine (Internal Audit)

**Status:** Implemented (Phase 2 + Phase 3 landed).
**Version:** 1.1
**Owner:** LicensingServer (issuer of the contract); consumed by ThirdLine.
**Transport:** HTTPS, JSON, REST. All endpoints under `/api/v1`.

> **v1.1 changelog (additive, backwards compatible):**
> - Licenses now carry a **`type`** (`full | trial | demo | poc | grace`), orthogonal to `plan`. Surfaced in entitlements as `type` + `is_trial`, and in the JWT as the `ltyp` claim. See §6.1.
> - `activate`/`validate`/`heartbeat` entitlements now include `plan`, `type`, `is_trial`.
> - **Scopes are now enforced per-route** (`license:activate|validate|heartbeat|deactivate`); a client missing the scope gets `403 insufficient_scope`. Admin routes are gated by spatie RBAC permissions.
> - Client now sends `feature_usage` on heartbeat and **persists/honors** server-returned `heartbeat_interval_hours` + `grace_period_days`, and acts on a `revoked`/`valid:false` verdict from `validate`.
> - The RSA keypair on both sides is a verified matched pair; the consumer holds **only** the public key (the private key has been removed from ThirdLine). The dev-only self-signing "demo" generator on the consumer has been removed — demo licenses are now issued by the server as `type=demo`.

---

## 1. Purpose

LicensingServer is the single source of truth for ThirdLine's internal-audit licence. Any change to a licence on the server (plan upgrade, feature flip, renewal, suspension, revocation) MUST be reflected by ThirdLine on its next interaction with the server, without redeployment.

The contract is consumed by `/settings/license` in ThirdLine and by background heartbeat/validate calls.

---

## 2. Authentication

Service-to-service. Every client-facing endpoint requires both headers:

```
X-Client-Id:      <opaque client id, ≤ 80 chars>
X-Client-Secret:  <opaque secret, bcrypt-checked server side>
```

Credentials are issued per consuming application, stored in the `api_clients` table, and bound to exactly one `organizations.id` (`org_id`). The server:

1. Looks up `api_clients` by `client_id`, requires `is_active = true`.
2. `Hash::check(secret, client_secret_hash)`.
3. (Optional) If `allowed_ips` is non-null, enforces source-IP allowlist.
4. (Optional) If the route is scoped, checks `allowed_scopes` contains the route's scope.

Scopes used by this contract: `license:activate`, `license:validate`, `license:heartbeat`, `license:deactivate`. New clients are provisioned with all four by default.

**Tenancy invariant (server-enforced).** Every endpoint that accepts a `license_key` MUST verify that the resolved `licenses.org_id` equals the authenticated `api_clients.org_id`. Mismatch returns `403 tenant_mismatch` and is audit-logged. This is the only line of defence against a stolen `license_key` being used by another tenant's client.

---

## 3. Common response envelopes

**Success:** `200 OK` (or `201 Created` where appropriate), body:

```json
{ "data": { ... } }
```

**Error:** Non-2xx, body:

```json
{
  "error":   "<stable_machine_code>",
  "message": "<human readable>"
}
```

Some errors include extra context fields (e.g. `current_activations`). The `error` code is stable across versions; clients MUST switch on it, not on `message`.

### 3.1 Stable error codes (alphabetical)

| Code | HTTP | Meaning |
|---|---|---|
| `activation_limit_reached` | 409 | `max_activations` reached on the licence. |
| `activation_not_found` | 404 | No active activation for the device fingerprint. |
| `device_mismatch` | 403 | Device fingerprint doesn't match any active activation. |
| `insufficient_scope` | 403 | API client lacks the route's scope. |
| `invalid_credentials` | 401 | `X-Client-Id` / `X-Client-Secret` rejected. |
| `license_expired` | 403 | `expires_at` is in the past. |
| `license_not_active` | 403 | Licence status is `suspended` or otherwise non-active. |
| `license_not_found` | 404 | No licence with the supplied `license_key`. |
| `license_revoked` | 403 | Licence is on the revocation list, or status = `revoked`. |
| `missing_credentials` | 401 | One or both auth headers absent. |
| `tenant_mismatch` | 403 | `license.org_id` ≠ `api_client.org_id`. |
| `user_limit_exceeded` | 403 | Reported `current_users` > `max_users`. |
| `validation_error` | 422 | Request body fails schema validation (Laravel default). |

---

## 4. Date, locale and units

- All timestamps are ISO 8601 with offset, UTC (`Z`), e.g. `2026-12-31T23:59:59Z`.
- Server returns `server_time` on validate/heartbeat so the client can detect clock drift.
- Currency, where it appears, is NGN; locale tag is `en-NG`. (No currency fields are in this contract today; reserved for future billing exposure.)
- `days_remaining` is `floor((expires_at − server_time) / 1 day)`, signed.

---

## 5. Endpoints

### 5.1 `GET /api/v1/health` — unauthenticated

Liveness probe used by ThirdLine's `SyncManager::isServerReachable()`.

**Response 200:**

```json
{
  "status": "ok",
  "service": "thirdline-licensing-server",
  "version": "1.0.0",
  "timestamp": "2026-05-18T10:00:00Z"
}
```

---

### 5.2 `POST /api/v1/licenses/activate` — scope `license:activate`

Exchanges a short licence key for a signed JWT bound to this device. Idempotent on the `(license_key, device_fingerprint)` pair: re-calling returns the existing activation without consuming a new slot.

**Request body:**

```json
{
  "license_key":        "APGRC-XXXX-XXXX-XXXX-XXXX",  // required
  "device_fingerprint": "<sha256 hex, 64 chars>",     // required
  "hostname":           "audit-01.example.ng",         // optional
  "ip_address":         "10.0.0.42",                   // optional, defaults to request IP
  "os_info":            "Ubuntu 22.04 / PHP 8.3"       // optional
}
```

**Response 200:**

```json
{
  "data": {
    "activation_id": "0f1c…",
    "license_token": "<RS256 JWT>",
    "activated_at":  "2026-05-18T10:00:00Z",
    "entitlements": {
      "features":   { "audit": true, "risk": true, "...": true },
      "max_users":  100,
      "expires_at": "2027-05-18T10:00:00Z"
    },
    "heartbeat_interval_hours": 48,
    "grace_period_days": 14
  }
}
```

**Errors:** `license_not_found` (404), `license_not_active` (403), `tenant_mismatch` (403), `activation_limit_reached` (409), `missing_credentials` / `invalid_credentials` (401).

**Audit (server side):** `license.activated` or `activation.failed` with reason; actor_type=`client_app`, actor_id=`api_client.id`.

---

### 5.3 `POST /api/v1/licenses/validate` — scope `license:validate`

Authoritative live check used on `/settings/license` page load (and periodically) to refresh entitlements and status. Does **not** consume an activation slot or create one; the device must already be activated.

**Request body:**

```json
{
  "license_key":        "APGRC-XXXX-XXXX-XXXX-XXXX",  // required
  "device_fingerprint": "<sha256 hex>",                // required
  "current_users":      12                              // optional
}
```

**Response 200:**

```json
{
  "data": {
    "valid":        true,
    "status":       "active",
    "license_key":  "APGRC-XXXX-XXXX-XXXX-XXXX",
    "entitlements": {
      "features":   { "audit": true, "...": true },
      "max_users":  100,
      "plan":       "enterprise"
    },
    "revoked":         false,
    "expires_at":      "2027-05-18T10:00:00Z",
    "days_remaining":  365,
    "server_time":     "2026-05-18T10:00:00Z"
  }
}
```

> **Schema clarification vs. v0 behaviour:** the v0 code does not echo `license_key` or `plan` in the validate response top-level entitlements. We add `entitlements.plan` and a top-level `license_key` echo (already present in `activate`) so the client can confirm what was validated. Backwards compatible.

**Errors:** `license_not_found` (404), `license_revoked` (403), `license_expired` (403), `device_mismatch` (403), `user_limit_exceeded` (403), `tenant_mismatch` (403), `missing_credentials` / `invalid_credentials` (401).

**Audit:** `validation.success` or `validation.failed` with reason.

---

### 5.4 `POST /api/v1/licenses/heartbeat` — scope `license:heartbeat`

Periodic background ping (interval driven by server-returned `heartbeat_interval_hours`). Reports usage telemetry and receives current entitlements plus operational commands. ThirdLine's existing `SyncManager::heartbeat()` already speaks this endpoint — no shape change.

**Request body:**

```json
{
  "license_key":        "APGRC-XXXX-XXXX-XXXX-XXXX",
  "device_fingerprint": "<sha256 hex>",
  "active_users":       12,
  "feature_usage":      { "audit": 4, "risk": 2 },
  "app_version":        "1.4.0"
}
```

**Response 200:**

```json
{
  "data": {
    "status":      "active",
    "revoked":     false,
    "server_time": "2026-05-18T10:00:00Z",
    "next_heartbeat_before": "2026-05-20T10:00:00Z",
    "updated_entitlements": {
      "features":   { "audit": true, "...": true },
      "max_users":  100,
      "plan":       "enterprise",
      "expires_at": "2027-05-18T10:00:00Z"
    },
    "commands":    [],                  // may include "force_deactivate", "license_expired"
    "grace_period_days": 14
  }
}
```

**Client obligation.** ThirdLine MUST persist `updated_entitlements` to the local cache and surface them on the next status read — this is how server-side licence changes propagate without redeploy. Today it doesn't; Phase 3 fixes it.

**Errors:** `license_not_found`, `activation_not_found`.

**Audit:** `heartbeat.received` on success.

---

### 5.5 `POST /api/v1/licenses/deactivate` — scope `license:deactivate`

Releases the activation slot for this device. Called when the user clicks "Deactivate" in `/settings/license`. Idempotent: re-calling for a device that's already deactivated returns `404 activation_not_found` — client treats that as success.

**Request body:**

```json
{
  "license_key":        "APGRC-XXXX-XXXX-XXXX-XXXX",
  "device_fingerprint": "<sha256 hex>"
}
```

**Response 200:**

```json
{
  "data": {
    "deactivated":    true,
    "activation_id":  "0f1c…",
    "deactivated_at": "2026-05-18T10:00:00Z"
  }
}
```

**Errors:** `license_not_found`, `activation_not_found`.

**Audit:** `license.deactivated` with `initiated_by=client`.

---

## 6. JWT format (issued by activate, verified by ThirdLine)

- Algorithm: **RS256**.
- Server private key: `LicensingServer/storage/keys/license_private.pem`.
- Client public key:  `internalaudit/resources/keys/license_public.pem`.
- The two keys MUST be a matched pair. Phase 2 will derive the public key from the server's private key and write it into ThirdLine's `resources/keys/` (current keys do not match — verified during discovery).

**Claims:**

| Claim | Meaning |
|---|---|
| `iss`  | Always `thirdline-grc-licensing`. ThirdLine rejects any other issuer. |
| `sub`  | Organisation UUID. |
| `jti`  | Licence UUID (used as `license_id` client-side). |
| `iat`, `nbf`, `exp` | Standard. `exp` = licence `expires_at`. |
| `lk`   | The licence key string. |
| `plan` | `starter` \| `professional` \| `enterprise`. |
| `ltyp` | License **type**: `full` \| `trial` \| `demo` \| `poc` \| `grace` (see §6.1). |
| `feat` | Object of `{ feature_name: bool }`. |
| `mu`   | `max_users`. |
| `ma`   | `max_activations`. |
| `org`  | `{ id, name, slug }`. |
| `ver`  | `1.0` (this contract version). |
| `chk`  | HMAC-SHA256 integrity hash over key claims, signed with server `app.key`. ThirdLine does not verify `chk` (it can't — it lacks the server's `app.key`); it's a server-side cross-check used during admin operations. |
| `dvc`  | (Optional) Device fingerprint the licence is bound to. |

ThirdLine's `JwtValidator` verifies signature + `iss` + `ver`, then trusts the claims for offline operation between server calls.

### 6.1 License types (`type` / `ltyp`)

`type` is **orthogonal to `plan`**: `plan` is the entitlement tier (which features/limits), `type` is the issuance intent. Defined in `config/licensing.php` → `types`, each with a default duration applied when `duration_days` is omitted at issue time:

| `type` | Meaning | Default duration | `is_trial` |
|---|---|---|---|
| `full`  | Standard paid licence. | 365 days | false |
| `trial` | Time-boxed evaluation. | 14 days | true |
| `demo`  | Showcase / sales demo. | 7 days | true |
| `poc`   | Proof of concept. | 30 days | true |
| `grace` | Explicitly granted grace extension. | 14 days | false |

`is_trial` is a server-derived convenience flag (true for `trial`/`demo`/`poc`) so the consumer can show an evaluation banner without re-deriving config. Issued via `POST /api/v1/licenses` with an optional `"type"` (defaults to `full`) and optional `"duration_days"` (defaults to the type's window).

---

## 7. Audit logging

Both sides MUST append-only log every interaction.

**Server side** — `audit_logs` table, written via `AuditLog::record(action, resource_type, resource_id, metadata, actor_type='client_app', actor_id=api_client.id)`. Actions used:

```
license.activated         activation.failed
validation.success        validation.failed
heartbeat.received        heartbeat.failed
license.deactivated       license.revoked
tenant_mismatch.detected
```

**Client side** — `license_audit_logs` table (already exists), written via `App\Services\Licensing\LicenseAuditLogger`. Actions used (additions in Phase 3 marked *):

```
activation_success                activation_failed
validation_success*               validation_failed*
heartbeat_success                 heartbeat_failed
license_deactivated               license_deactivate_failed*
tamper_detected                   device_mismatch
license_expired                   feature_access_denied
server_command_received*          (e.g. force_deactivate)
```

The local log's `synced` flag is flipped to `true` when the entry has been mirrored to the server via the next heartbeat's `feature_usage`/metadata path; this is the existing behaviour and is preserved.

---

## 8. Tenancy guarantees

1. Every `api_client` row has exactly one `org_id`.
2. Every `license` row has exactly one `org_id`.
3. On every client-app request, the server resolves the licence by `license_key`, then asserts `license.org_id == authenticated_api_client.org_id`. If not, the call returns `403 tenant_mismatch` and is audit-logged with both org ids in metadata.
4. Cross-tenant reads via JWT are impossible: a JWT is RS256-signed by the server, contains `sub = org_id`, and is bound to a `device_fingerprint`. A stolen JWT used on a different device fails ThirdLine's local validator (or the server's validate-call device check).

---

## 9. Determinism

The licensing path contains **no AI inference and no external paid feeds**. The server's stored licence parameters are the only source of truth. The same `(license_key, device_fingerprint, server_state)` tuple yields the same response. This is a non-negotiable property of the contract.

---

## 10. Provisioning (out-of-band, one-off per consuming app)

Phase 2 will ship an artisan command on LicensingServer:

```
php artisan license-server:provision-client \
    --org-slug=thirdline-acme \
    --org-name="ACME Bank Internal Audit" \
    --contact-email=audit@acme.ng \
    --scopes=license:activate,license:validate,license:heartbeat,license:deactivate
```

It will:

1. Find-or-create the `organization` (idempotent on slug).
2. Generate a random `client_id` and `client_secret`, bcrypt-hash the secret, insert `api_clients`.
3. Print the plaintext `client_id` and `client_secret` **once** to stdout, and write them to a one-shot file `storage/app/provisioned-clients/<slug>.env` for the operator to paste into ThirdLine's `.env`:

```
LICENSE_SERVER_URL=https://license.thirdline-grc.com
LICENSE_CLIENT_ID=<generated>
LICENSE_CLIENT_SECRET=<generated, shown once>
```

A licence is issued separately via `POST /api/v1/licenses` (admin Sanctum) — that flow already exists and is out of scope for this contract.

---

## 11. Open items resolved before Phase 2 (assumed defaults)

| # | Item | Default applied |
|---|---|---|
| 1 | Include server-side deactivate fix (gap #4) and `Request` import bug (gap #8) in scope? | **Yes**, both included in Phase 2. |
| 2 | Provisioning approach | Artisan command on LicensingServer; manual `.env` paste on ThirdLine. |
| 3 | RSA keypair | Phase 2 will derive ThirdLine's public key from the server's private key and overwrite `internalaudit/resources/keys/license_public.pem`. |
| 4 | Validate cadence | Called on every `/settings/license` GET, but cached locally for 60 s to absorb refresh bursts. Background heartbeat unchanged. |

If any of these defaults is wrong, raise it now — they're cheap to flip before Phase 2 but expensive after.

---

## 12. Out of scope

- Admin UI on LicensingServer (`auth:sanctum` routes) — already implemented, no integration change needed.
- Offline-activation file (`.lic`) flow — already works end-to-end without server contact.
- Billing, invoicing, payment.
- Multi-region replication / HA for LicensingServer.

---

**End of contract — v1.0 draft. Awaiting confirmation before Phase 2.**
