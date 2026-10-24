<?php

namespace App\Http\Controllers;

use App\Models\RdapStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class DomainStatusController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253', 'regex:/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
            'status' => ['required', 'string', Rule::in(RdapStatus::SAVEABLE)],
            'status_label' => ['required', 'string', 'max:120'],
            'expiry' => ['nullable', 'date'],
            'checked_at' => ['nullable', 'date'],
        ]);

        $domain = strtolower(trim((string) $data['domain']));
        $checkedAt = isset($data['checked_at']) ? Carbon::parse($data['checked_at']) : now();

        $status = RdapStatus::updateOrCreate(
            ['domain' => $domain],
            [
                'status' => $data['status'],
                'status_label' => $data['status_label'],
                'expiry' => $data['expiry'] ?? null,
                'last_checked' => $checkedAt,
            ],
        );

        return response()->json(['status' => [
            'domain' => $status->domain,
            'status' => $status->status,
            'status_label' => $status->status_label,
            'expiry' => $status->expiry?->format('Y-m-d'),
            'checked_at' => $status->last_checked?->toIso8601String(),
        ]], 201);
    }
}
