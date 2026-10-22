<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\CreateReferralRequest;
use App\Http\Resources\ReferralResource;
use App\Models\Referral;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The signed-in customer's referrals: people they suggested to the salon, and the GCash rewards they earned. */
class ReferralController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $referrals = $request->user()->referrals()->with('services')->latest()->orderByDesc('id')->limit(200)->get();

        return $this->ok(ReferralResource::collection($referrals));
    }

    public function store(CreateReferralRequest $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validated();

        $referral = DB::transaction(function () use ($request, $data) {
            $referral = $request->user()->referrals()->create([
                'name' => $data['name'],
                'phone' => $data['phone'],
                'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
            ]);
            $referral->services()->attach($data['service_ids']);

            return $referral;
        });
        $audit->log('referral.created', $referral, null, ['name' => $referral->name, 'phone' => $referral->phone]);

        return $this->created(new ReferralResource($referral->load('services')), 'Referral sent. Thank you!');
    }

    public function destroy(Referral $referral, AuditLogger $audit): JsonResponse
    {
        $this->authorize('delete', $referral);
        $audit->log('referral.deleted', $referral, ['name' => $referral->name, 'phone' => $referral->phone]);
        $referral->delete();

        return $this->ok(null, 'Referral removed.');
    }
}
