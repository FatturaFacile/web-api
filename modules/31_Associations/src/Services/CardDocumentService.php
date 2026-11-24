<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Contracts\PdfGenerator;
use App\Enums\BarcodeType;
use App\Services\Inventory\BarcodeService;
use App\Services\Tenancy\TenantContext;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\Membership;

/**
 * The digital card as a PDF, with the QR code of the person.
 */
final class CardDocumentService
{
    public function __construct(
        private readonly PdfGenerator $pdf,
        private readonly BarcodeService $barcodes,
        private readonly CredentialService $credentials,
        private readonly TenantContext $tenantContext,
    ) {}

    public function render(Membership $membership): string
    {
        if ($membership->card_number === null) {
            throw new AssociationException(__('associations.card_not_issued'));
        }

        $member = $membership->member;
        $credential = $this->credentials->currentQr($member);

        return $this->pdf->render('associations::pdf.card', [
            'tenant' => $this->tenantContext->getTenant(),
            'membership' => $membership,
            'memberName' => $member->displayName(),
            'qr' => $credential === null ? null : $this->barcodes->pngDataUri(BarcodeType::QrCode, $this->credentials->qrPayload($credential)),
        ]);
    }
}
