<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Application\Commands;

use Modules\TenderManagement\Domain\Enums\ParticipationStatus;
use Modules\TenderManagement\Exceptions\TenderException;
use Modules\TenderManagement\Models\TenderParticipation;
use Modules\TenderManagement\Services\EnvelopeService;

final class UploadEnvelopeDocumentHandler
{
    public function __construct(private readonly EnvelopeService $envelopes) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(UploadEnvelopeDocumentCommand $command): array
    {
        $participation = TenderParticipation::query()->with('notice')->findOrFail($command->participationId);

        if (in_array($participation->status, [ParticipationStatus::Submitted, ParticipationStatus::AwardedWon, ParticipationStatus::Lost], true)) {
            throw new TenderException('invalid_transition', 422, ['from' => $participation->status->value, 'to' => 'envelope_upload']);
        }

        return $this->envelopes->publicItem($this->envelopes->upload($participation, $command->envelope, $command->docKey, $command->file));
    }
}
