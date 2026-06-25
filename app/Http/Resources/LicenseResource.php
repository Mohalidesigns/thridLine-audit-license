<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LicenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'license_key' => $this->license_key,
            'plan' => $this->plan,
            'features' => $this->features,
            'max_users' => $this->max_users,
            'max_activations' => $this->max_activations,
            'issued_at' => $this->issued_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'status' => $this->status,
            'notes' => $this->notes,
            'organization' => new OrganizationResource($this->whenLoaded('organization')),
            'active_activations_count' => $this->whenCounted('activeActivations'),
            'pending_revocation' => $this->whenLoaded('pendingRevocation', fn () => $this->pendingRevocation ? [
                'id' => $this->pendingRevocation->id,
                'reason' => $this->pendingRevocation->reason,
                'effective_at' => $this->pendingRevocation->effective_at?->toISOString(),
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
