# LicensingServer — Context for Claude

This is the **issuer** of internal-audit licences consumed by `internalaudit/` (ThirdLine).
Single source of truth for licensing parameters.

## Stack
Laravel 11+, Postgres (`licenseServer` DB), RS256 JWTs, Sanctum for admin auth,
custom `X-Client-Id` / `X-Client-Secret` for client-app auth.

## Key paths
- Contract: `./LICENSING_API_CONTRACT.md` (always read this first when changing the API).
- Routes: `routes/api.php` (`/api/v1/*`).
- Client-app endpoints: `app/Http/Controllers/Api/V1/{ActivationController,HeartbeatController}.php`.
- Admin endpoints (Sanctum): `LicenseController`, `OrganizationController`, `ApiClientController`,
  `AuthController`, `DashboardController`, `AuditLogController`.
- Middleware: `app/Http/Middleware/AuthenticateApiClient.php` (bcrypt-checks the secret,
  merges the `ApiClient` model into the request under the `api_client` key).
- License crypto: `app/Services/Licensing/LicenseEngine.php` (RS256 sign, JWT payload schema).
- Keys: `storage/keys/license_{private,public}.pem`. Generate via
  `php artisan licensing:generate-keys`.
- Provisioning: `php artisan license-server:provision-client --org-slug=... --org-name=...
  --contact-email=... [--scopes=...] [--ips=...] [--server-url=...]`.
  Prints `LICENSE_CLIENT_ID`/`LICENSE_CLIENT_SECRET` ONCE and writes a one-shot
  `.env` snippet to `storage/app/provisioned-clients/<slug>.env`.
- Audit log: `App\Models\AuditLog::record(action, resource_type, resource_id, metadata,
  actor_type='client_app', actor_id=$apiClient->id)` on every client-app interaction.

## Tenancy invariant (non-negotiable)
Every client-app endpoint resolves the licence by `license_key`, then asserts
`license.org_id === api_client.org_id`. Mismatch → `403 tenant_mismatch` + audit log
with `action=tenant_mismatch.detected`. This is the only line of defence against a
leaked licence key being used by another tenant.

## Stable error codes (do not rename without bumping contract version)
`activation_limit_reached`, `activation_not_found`, `device_mismatch`,
`insufficient_scope`, `invalid_credentials`, `license_expired`, `license_not_active`,
`license_not_found`, `license_revoked`, `missing_credentials`, `tenant_mismatch`,
`user_limit_exceeded`, `validation_error`.

## JWT claims
RS256. `iss=thirdline-grc-licensing`, `sub=org_id`, `jti=license_id`, `lk=license_key`,
`plan`, `feat`, `mu` (max_users), `ma` (max_activations), `org{id,name,slug}`, `ver=1.0`,
`chk` (server-side integrity hmac), optional `dvc` (device fingerprint).

## Determinism
No AI inference, no external paid feeds in the licensing path. Stored DB parameters
are the only source of truth.

## What recently shipped (May 2026)
Phase 2 of LicensingServer ↔ ThirdLine integration:
- Added `apiClient()`/`assertSameTenant()` helpers + tenancy guard on
  activate/validate/deactivate/heartbeat.
- Fixed missing `use Illuminate\Http\Request;` in `ActivationController::deactivate`.
- Validate response now echoes `license_key` and casts `days_remaining` to int.
- New `ProvisionClientCommand` artisan command (idempotent on org slug, rotates secrets).
- New `LICENSING_API_CONTRACT.md` (v1.0, the source of truth for the wire format).

## What NOT to do
- Do not move secrets into JWTs (the server's `app.key`/HMAC must never leave the server).
- Do not expose the private key path under `resources/` (currently `storage/keys/` is correct).
- Do not change the JWT `iss` value without re-issuing every active licence — ThirdLine
  rejects mismatched issuers.
- Do not relax the tenancy check for "convenience" debugging — it's the security boundary.
