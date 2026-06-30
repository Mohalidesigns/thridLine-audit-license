<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IssueLicenseRequest;
use App\Http\Requests\RevokeLicenseRequest;
use App\Http\Resources\LicenseResource;
use App\Models\AuditLog;
use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\RevocationList;
use App\Services\Licensing\LicenseEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class LicenseController extends Controller
{
    public function __construct(
        private readonly LicenseEngine $engine,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $licenses = License::with('organization')
            ->withCount('activeActivations')
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('org_id'), fn ($q, $orgId) => $q->where('org_id', $orgId))
            ->when($request->query('plan'), fn ($q, $plan) => $q->where('plan', $plan))
            ->when($request->query('search'), function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('license_key', 'like', "%{$search}%")
                      ->orWhereHas('organization', fn ($q) => $q->where('name', 'like', "%{$search}%"));
                });
            })
            ->orderBy('created_at', 'desc')
            ->paginate($request->query('per_page', 15));

        return LicenseResource::collection($licenses);
    }

    public function show(License $license): LicenseResource
    {
        $license->load('organization', 'activations', 'revocations')
            ->loadCount('activeActivations');

        return new LicenseResource($license);
    }

    public function store(IssueLicenseRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $type = $validated['type'] ?? config('licensing.default_type', 'full');
        // When duration is omitted, derive it from the type's default window.
        $durationDays = $validated['duration_days']
            ?? config("licensing.types.{$type}.default_duration_days", config('licensing.ttl', 365));

        $license = License::create([
            'org_id' => $validated['org_id'],
            'license_key' => $this->engine->generateLicenseKey(),
            'plan' => $validated['plan'],
            'type' => $type,
            'features' => $validated['features'],
            'max_users' => $validated['max_users'],
            'max_activations' => $validated['max_activations'],
            'issued_at' => now(),
            'expires_at' => now()->addDays($durationDays),
            'status' => 'active',
            'issued_by' => auth()->id(),
            'notes' => $validated['notes'] ?? null,
        ]);

        $license->load('organization');
        $fileData = $this->engine->generateLicenseFile($license);

        AuditLog::record('license.issued', 'license', $license->id, [
            'org_id' => $license->org_id,
            'plan' => $license->plan,
            'expires_at' => $license->expires_at->toISOString(),
        ]);

        return response()->json([
            'data' => [
                'id' => $license->id,
                'license_key' => $license->license_key,
                'token' => $fileData['envelope']['token'],
                'plan' => $license->plan,
                'type' => $license->type,
                'features' => $license->features,
                'issued_at' => $license->issued_at->toISOString(),
                'expires_at' => $license->expires_at->toISOString(),
                'status' => $license->status,
                'file' => [
                    'content'  => $fileData['encoded'],
                    'filename' => $fileData['filename'],
                ],
            ],
        ], 201);
    }

    public function update(Request $request, License $license): JsonResponse
    {
        $validated = $request->validate([
            'plan' => ['sometimes', 'string', 'in:starter,professional,enterprise'],
            'type' => ['sometimes', 'string', 'in:' . implode(',', array_keys(config('licensing.types', ['full' => []])))],
            'features' => ['sometimes', 'array'],
            'max_users' => ['sometimes', 'integer', 'min:1'],
            'max_activations' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'in:active,suspended'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $oldValues = $license->only(array_keys($validated));
        $license->update($validated);

        AuditLog::record('license.updated', 'license', $license->id, [
            'old' => $oldValues,
            'new' => $validated,
        ]);

        return response()->json([
            'data' => new LicenseResource($license->fresh('organization')),
        ]);
    }

    public function revoke(RevokeLicenseRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $license = License::findOrFail($validated['license_id']);

        if ($license->status === 'revoked') {
            return response()->json([
                'error' => 'already_revoked',
                'message' => 'This license has already been revoked.',
            ], 409);
        }

        RevocationList::create([
            'license_id' => $license->id,
            'reason' => $validated['reason'],
            'revoked_by' => auth()->id(),
            'revoked_at' => now(),
            'effective_at' => $validated['effective_immediately'] ? now() : null,
        ]);

        $license->update(['status' => 'revoked']);

        $affectedActivations = $license->activeActivations()->count();
        $license->activeActivations()->update([
            'status' => 'deactivated',
            'deactivated_at' => now(),
        ]);

        AuditLog::record('license.revoked', 'license', $license->id, [
            'reason' => $validated['reason'],
            'affected_activations' => $affectedActivations,
        ]);

        return response()->json([
            'data' => [
                'license_id' => $license->id,
                'status' => 'revoked',
                'revoked_at' => now()->toISOString(),
                'affected_activations' => $affectedActivations,
            ],
        ]);
    }

    /**
     * Renew an existing license by extending its expiry.
     */
    public function renew(Request $request, License $license): JsonResponse
    {
        $validated = $request->validate([
            'duration_days' => ['required', 'integer', 'min:1', 'max:3650'],
        ]);

        if ($license->status === 'revoked') {
            return response()->json([
                'error' => 'license_revoked',
                'message' => 'Cannot renew a revoked license.',
            ], 403);
        }

        $oldExpiresAt = $license->expires_at;

        // Extend from current expiry or from now if already expired
        $baseDate = $license->isExpired() ? now() : $license->expires_at;
        $license->update([
            'expires_at' => $baseDate->addDays($validated['duration_days']),
            'status' => 'active',
        ]);

        AuditLog::record('license.renewed', 'license', $license->id, [
            'old_expires_at' => $oldExpiresAt->toISOString(),
            'new_expires_at' => $license->fresh()->expires_at->toISOString(),
            'duration_days' => $validated['duration_days'],
        ]);

        return response()->json([
            'data' => new LicenseResource($license->fresh('organization')),
        ]);
    }

    /**
     * Transfer a license to a different organization (re-issuance).
     */
    public function transfer(Request $request, License $license): JsonResponse
    {
        $validated = $request->validate([
            'new_org_id' => ['required', 'uuid', 'exists:organizations,id'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if ($license->status === 'revoked') {
            return response()->json([
                'error' => 'license_revoked',
                'message' => 'Cannot transfer a revoked license.',
            ], 403);
        }

        $oldOrgId = $license->org_id;

        // Deactivate all existing activations
        $deactivatedCount = $license->activeActivations()->count();
        $license->activeActivations()->update([
            'status' => 'deactivated',
            'deactivated_at' => now(),
        ]);

        // Generate new license key for the new org
        $license->update([
            'org_id' => $validated['new_org_id'],
            'license_key' => $this->engine->generateLicenseKey(),
        ]);

        AuditLog::record('license.transferred', 'license', $license->id, [
            'from_org_id' => $oldOrgId,
            'to_org_id' => $validated['new_org_id'],
            'reason' => $validated['reason'],
            'deactivated_activations' => $deactivatedCount,
        ]);

        $license->load('organization');
        $fileData = $this->engine->generateLicenseFile($license);

        return response()->json([
            'data' => [
                'license' => new LicenseResource($license->fresh('organization')),
                'new_license_key' => $license->license_key,
                'file' => [
                    'content' => $fileData['encoded'],
                    'filename' => $fileData['filename'],
                ],
                'deactivated_activations' => $deactivatedCount,
            ],
        ]);
    }

    /**
     * Download a .lic license file (base64-encoded signed envelope).
     * Functionally identical to the license key — can be imported offline.
     */
    public function downloadFile(License $license): Response
    {
        if ($license->status === 'revoked') {
            return response()->json([
                'error' => 'license_revoked',
                'message' => 'Cannot generate a file for a revoked license.',
            ], 403);
        }

        $fileData = $this->engine->generateLicenseFile($license);

        AuditLog::record('license.file_downloaded', 'license', $license->id, [
            'filename' => $fileData['filename'],
        ]);

        return response($fileData['encoded'])
            ->header('Content-Type', 'application/octet-stream')
            ->header('Content-Disposition', 'attachment; filename="' . $fileData['filename'] . '"');
    }

    /**
     * Accept a fingerprint file, create a device-bound activation,
     * and return a .lic file that the client can import offline.
     */
    public function offlineActivate(Request $request, License $license): JsonResponse
    {
        $validated = $request->validate([
            'fingerprint_file' => ['required', 'string'],
        ]);

        if ($license->status === 'revoked') {
            return response()->json([
                'error' => 'license_revoked',
                'message' => 'Cannot activate a revoked license.',
            ], 403);
        }

        if ($license->isExpired()) {
            return response()->json([
                'error' => 'license_expired',
                'message' => 'Cannot activate an expired license.',
            ], 403);
        }

        // Decode the fingerprint file (base64-encoded JSON from ThirdLine client)
        $decoded = base64_decode($validated['fingerprint_file'], true);
        if (!$decoded) {
            return response()->json([
                'error' => 'invalid_fingerprint',
                'message' => 'Could not decode fingerprint file. Expected base64-encoded JSON.',
            ], 422);
        }

        $fingerprintData = json_decode($decoded, true);
        if (!$fingerprintData || empty($fingerprintData['fingerprint'])) {
            return response()->json([
                'error' => 'invalid_fingerprint',
                'message' => 'Fingerprint file is missing required "fingerprint" field.',
            ], 422);
        }

        $deviceFingerprint = $fingerprintData['fingerprint'];
        $hostname = $fingerprintData['hostname'] ?? null;
        $osInfo = $fingerprintData['os'] ?? null;

        // Check if device already activated for this license
        $existing = LicenseActivation::where('license_id', $license->id)
            ->where('device_fingerprint', $deviceFingerprint)
            ->where('status', 'active')
            ->first();

        if (!$existing) {
            // Check max activations
            $activeCount = $license->activeActivations()->count();
            if ($activeCount >= $license->max_activations) {
                return response()->json([
                    'error' => 'activation_limit_reached',
                    'message' => "Maximum activations ({$license->max_activations}) reached for this license.",
                    'current_activations' => $activeCount,
                ], 409);
            }

            // Create the activation record
            $existing = LicenseActivation::create([
                'license_id' => $license->id,
                'device_fingerprint' => $deviceFingerprint,
                'hostname' => $hostname,
                'ip_address' => $request->ip(),
                'os_info' => $osInfo,
                'activated_at' => now(),
                'last_seen_at' => now(),
                'status' => 'active',
            ]);
        } else {
            $existing->update(['last_seen_at' => now()]);
        }

        // Generate device-bound license file
        $fileData = $this->engine->generateLicenseFile($license, $deviceFingerprint);

        AuditLog::record('license.offline_activated', 'activation', $existing->id, [
            'license_id' => $license->id,
            'device_fingerprint' => $deviceFingerprint,
            'hostname' => $hostname,
            'initiated_by' => 'admin',
        ]);

        return response()->json([
            'data' => [
                'activation_id' => $existing->id,
                'device_fingerprint' => $deviceFingerprint,
                'hostname' => $hostname,
                'file' => [
                    'content' => $fileData['encoded'],
                    'filename' => $fileData['filename'],
                ],
            ],
        ]);
    }

    /**
     * Return file data as JSON (for in-browser download via JS).
     */
    public function generateFile(License $license): JsonResponse
    {
        if ($license->status === 'revoked') {
            return response()->json([
                'error' => 'license_revoked',
                'message' => 'Cannot generate a file for a revoked license.',
            ], 403);
        }

        $fileData = $this->engine->generateLicenseFile($license);

        AuditLog::record('license.file_generated', 'license', $license->id, [
            'filename' => $fileData['filename'],
        ]);

        return response()->json([
            'data' => [
                'content'  => $fileData['encoded'],
                'filename' => $fileData['filename'],
            ],
        ]);
    }
}
