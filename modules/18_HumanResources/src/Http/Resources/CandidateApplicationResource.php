<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HumanResources\Models\ApplicationStageHistory;
use Modules\HumanResources\Models\CandidateApplication;
use Modules\HumanResources\Models\CandidateEmail;

/**
 * Detail of a candidate application: card fields plus CV, extracted profile, consent, history, emails and offer.
 *
 * @mixin CandidateApplication
 */
final class CandidateApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CandidateApplication $application */
        $application = $this->resource;
        $offer = $application->currentOffer;

        return [
            ...CandidateApplicationCardResource::cardFields($application),
            'cover_letter' => $application->cover_letter,
            'notes' => $application->notes,
            'locale' => (string) $application->locale,
            'cv' => $application->cv_storage_key !== null ? [
                'file_name' => (string) $application->cv_original_name,
                'mime_type' => (string) $application->cv_mime_type,
                'size_bytes' => (int) $application->cv_size_bytes,
                'download_url' => '/hr/recruiting/applications/'.$application->id.'/cv',
            ] : null,
            'extracted_profile' => $this->extractedProfile($application),
            'consent' => [
                'given_at' => $application->consent_given_at?->toIso8601ZuluString(),
                'text_version' => $application->consent_text_version,
                'expires_at' => $application->privacy_consent_expires_at?->toIso8601ZuluString(),
                'extended_at' => $application->consent_extended_at?->toIso8601ZuluString(),
            ],
            'rejection' => $application->rejection_reason_code !== null ? [
                'reason_code' => $application->rejection_reason_code,
                'note' => $application->rejection_note,
            ] : null,
            'history' => $application->history->map(static fn (ApplicationStageHistory $entry): array => [
                'id' => (string) $entry->id,
                'from_stage' => $entry->from_stage,
                'to_stage' => (string) $entry->to_stage,
                'actor_user_id' => $entry->actor_user_id,
                'note' => $entry->note,
                'created_at' => $entry->created_at?->toIso8601ZuluString(),
            ])->values()->all(),
            'emails' => $application->emails->map(static fn (CandidateEmail $email): array => [
                'id' => (string) $email->id,
                'stage' => (string) $email->stage,
                'subject' => (string) ($email->subject ?? ''),
                'status' => $email->status->value,
                'sent_at' => $email->sent_at?->toIso8601ZuluString(),
            ])->values()->all(),
            'offer' => $offer !== null ? (new JobOfferLetterResource($offer))->resolve($request) : null,
            'hired_employee_id' => $application->hired_employee_id,
        ];
    }

    /**
     * @return array{education: list<mixed>, experience: list<mixed>, languages: list<mixed>, skills: list<string>}|null
     */
    private function extractedProfile(CandidateApplication $application): ?array
    {
        $profile = $application->extracted_profile;
        if (! is_array($profile)) {
            return null;
        }

        return [
            'education' => array_values((array) ($profile['education'] ?? [])),
            'experience' => array_values((array) ($profile['experience'] ?? [])),
            'languages' => array_values((array) ($profile['languages'] ?? [])),
            'skills' => array_values(array_map('strval', (array) ($profile['skills'] ?? []))),
        ];
    }
}
