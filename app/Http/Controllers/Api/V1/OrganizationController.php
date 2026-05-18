<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class OrganizationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $organizations = Organization::withCount('licenses')
            ->when($request->query('search'), function ($q, $search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%")
                  ->orWhere('contact_email', 'like', "%{$search}%");
            })
            ->when($request->query('country'), fn ($q, $country) => $q->where('country', $country))
            ->orderBy('name')
            ->paginate($request->query('per_page', 15));

        return OrganizationResource::collection($organizations);
    }

    public function show(Organization $organization): OrganizationResource
    {
        $organization->loadCount('licenses');

        return new OrganizationResource($organization);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_email' => ['required', 'email', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:2'],
            'metadata' => ['nullable', 'array'],
        ]);

        $validated['slug'] = Str::slug($validated['name']);

        // Ensure unique slug
        $baseSlug = $validated['slug'];
        $counter = 1;
        while (Organization::where('slug', $validated['slug'])->exists()) {
            $validated['slug'] = $baseSlug . '-' . $counter++;
        }

        $organization = Organization::create($validated);

        AuditLog::record('organization.created', 'organization', $organization->id, [
            'name' => $organization->name,
        ]);

        return response()->json([
            'data' => new OrganizationResource($organization),
        ], 201);
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'contact_email' => ['sometimes', 'email', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:2'],
            'metadata' => ['nullable', 'array'],
        ]);

        $organization->update($validated);

        AuditLog::record('organization.updated', 'organization', $organization->id, $validated);

        return response()->json([
            'data' => new OrganizationResource($organization->fresh()),
        ]);
    }

    public function destroy(Organization $organization): JsonResponse
    {
        $licensesCount = $organization->licenses()->count();

        if ($licensesCount > 0) {
            return response()->json([
                'error' => 'has_licenses',
                'message' => "Cannot delete organization with {$licensesCount} associated licenses.",
            ], 409);
        }

        AuditLog::record('organization.deleted', 'organization', $organization->id, [
            'name' => $organization->name,
        ]);

        $organization->delete();

        return response()->json(null, 204);
    }
}
