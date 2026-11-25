<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CorporateConventions\Models\ConventionBeneficiaryEnrollment;
use Modules\CorporateConventions\Services\WalletPassService;

/**
 * The digital convention pass shown to the beneficiary: QR payload, links to the QR image and the wallets that are configured.
 *
 * @mixin ConventionBeneficiaryEnrollment
 */
final class PassResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $wallets = app(WalletPassService::class);
        $convention = $this->convention;
        $base = '/api/v1/public/conventions/passes/'.$this->wallet_pass_id;
        $members = $this->parent_enrollment_id === null && $this->relationLoaded('familyMembers') ? $this->familyMembers : collect();

        return [
            'wallet_pass_id' => $this->wallet_pass_id,
            'full_name' => $this->full_name,
            'beneficiary_type' => $this->beneficiary_type->value,
            'relationship' => $this->relationship->value,
            'status' => $this->status->value,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'qr_payload' => $this->qr_payload,
            'convention' => [
                'name' => $convention->name,
                'partner_company_name' => $convention->partner_company_name,
                'primary_color' => $convention->primary_color,
                'allow_family_members' => $convention->allow_family_members,
                'max_family_members' => $convention->max_family_members,
            ],
            'qr_svg_url' => $base.'/qr.svg',
            'qr_png_url' => $base.'/qr.png',
            'apple_wallet_url' => $wallets->appleAvailable() ? $base.'/apple.pkpass' : null,
            'google_wallet_url' => $wallets->googleSaveUrl($this->resource, $convention),
            'family_members' => $members->map(static fn (ConventionBeneficiaryEnrollment $member): array => [
                'wallet_pass_id' => $member->wallet_pass_id,
                'full_name' => $member->full_name,
                'relationship' => $member->relationship->value,
                'status' => $member->status->value,
            ])->values()->all(),
            'can_add_family_member' => $this->parent_enrollment_id === null && $convention->allow_family_members && $members->where('status', '!=', 'REVOKED')->count() < $convention->max_family_members,
        ];
    }
}
