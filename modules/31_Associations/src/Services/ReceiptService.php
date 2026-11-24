<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Contracts\PdfGenerator;
use App\Services\Sales\DocumentNumberingService;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Modules\Associations\Enums\ReceiptKind;
use Modules\Associations\Models\AssociationSetting;
use Modules\Associations\Models\FeeAssignment;
use Modules\Associations\Models\Receipt;

/**
 * Numbered receipts, in the simple and in the full form, one sequence per year. The number is taken inside the
 * transaction of the payment: a failed payment gives it back, so the series keeps no gaps.
 */
final class ReceiptService
{
    private const string Sequence = 'association_receipt';

    public function __construct(
        private readonly DocumentNumberingService $numbering,
        private readonly TenantContext $tenantContext,
        private readonly PdfGenerator $pdf,
    ) {}

    public function issue(FeeAssignment $assignment, string $amount, ReceiptKind $kind, CarbonImmutable $issuedOn): Receipt
    {
        $settings = AssociationSetting::current();
        $sequence = $this->numbering->next(self::Sequence, $issuedOn->year, (string) $this->tenantContext->getTenantId());

        return Receipt::create([
            'number' => sprintf('%s%d-%04d', $settings->receipt_prefix, $sequence['fiscal_year'], $sequence['sequential_number']),
            'fiscal_year' => $sequence['fiscal_year'],
            'sequential_number' => $sequence['sequential_number'],
            'kind' => $kind,
            'member_id' => $assignment->member_id,
            'issued_on' => $issuedOn,
            'amount' => $amount,
            'description' => mb_substr((string) $assignment->description, 0, 255),
            'holder' => $this->holder($assignment, $kind),
        ]);
    }

    public function render(Receipt $receipt): string
    {
        return $this->pdf->render('associations::pdf.receipt', [
            'tenant' => $this->tenantContext->getTenant(),
            'receipt' => $receipt,
            'holder' => $receipt->holder,
        ]);
    }

    /**
     * @return array<string, ?string>
     */
    private function holder(FeeAssignment $assignment, ReceiptKind $kind): array
    {
        $member = $assignment->member;
        $payer = $assignment->payer;
        $holder = [
            'name' => $member->displayName(),
            'payer' => $payer->id === $member->contact_id ? null : trim("{$payer->first_name} {$payer->last_name}"),
        ];

        if ($kind === ReceiptKind::Simple) {
            return $holder;
        }

        $address = $member->contact->addresses()->where('type', 'billing')->first();

        return $holder + [
            'tax_code' => $member->tax_code,
            'birth_date' => $member->birth_date,
            'email' => $member->contact->email,
            'address' => $address === null ? null : trim("{$address->street} {$address->house_number}, {$address->postal_code} {$address->city}"),
            'discipline' => $assignment->course?->discipline === null ? null : "{$assignment->course->discipline->code} {$assignment->course->discipline->name}",
        ];
    }
}
