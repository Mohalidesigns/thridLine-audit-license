<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $logs = AuditLog::query()
            ->when($request->query('action'), fn ($q, $action) => $q->where('action', $action))
            ->when($request->query('actor_type'), fn ($q, $type) => $q->where('actor_type', $type))
            ->when($request->query('resource_type'), fn ($q, $type) => $q->where('resource_type', $type))
            ->when($request->query('resource_id'), fn ($q, $id) => $q->where('resource_id', $id))
            ->when($request->query('from'), fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($request->query('to'), fn ($q, $to) => $q->where('created_at', '<=', $to))
            ->orderBy('created_at', 'desc')
            ->paginate($request->query('per_page', 25));

        return AuditLogResource::collection($logs);
    }

    public function show(AuditLog $auditLog): AuditLogResource
    {
        return new AuditLogResource($auditLog);
    }
}
