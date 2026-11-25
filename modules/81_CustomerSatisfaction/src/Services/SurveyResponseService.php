<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Services;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\CustomerSatisfaction\Contracts\SentimentAnalyzerInterface;
use Modules\CustomerSatisfaction\Domain\Enums\DispatchStatus;
use Modules\CustomerSatisfaction\Domain\Enums\SubmittedVia;
use Modules\CustomerSatisfaction\Domain\Support\SurveyLocale;
use Modules\CustomerSatisfaction\Exceptions\SatisfactionException;
use Modules\CustomerSatisfaction\Models\SurveyDispatch;
use Modules\CustomerSatisfaction\Models\SurveyOptOut;
use Modules\CustomerSatisfaction\Models\SurveyResponse;

/**
 * The public side of a survey, addressed by the token alone (no login): the first click records the score at once, the landing page
 * then enriches the same answer with a comment (text or voice) without ever creating a second one.
 */
final class SurveyResponseService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DetractorRecoveryService $recovery,
        private readonly SentimentAnalyzerInterface $sentiment,
    ) {}

    /**
     * Finds the dispatch behind a token, puts its company in the tenant context and checks the link is still usable.
     */
    public function resolve(string $token): SurveyDispatch
    {
        $dispatch = SurveyDispatch::query()->withoutGlobalScopes()->where('dispatch_token', $token)->first();

        if ($dispatch === null || $dispatch->tenant_id === null) {
            throw new SatisfactionException('survey_not_found', 404);
        }

        $tenant = Tenant::query()->find($dispatch->tenant_id);
        if ($tenant === null) {
            throw new SatisfactionException('survey_not_found', 404);
        }

        $this->tenantContext->setTenant($tenant);
        $dispatch = SurveyDispatch::query()->with('template')->findOrFail($dispatch->id);

        // The template was deleted after the invitation went out: the survey is no longer open.
        if ($dispatch->template === null) {
            throw new SatisfactionException('survey_not_available', 410);
        }

        if (! in_array($dispatch->status, [DispatchStatus::Scheduled, DispatchStatus::Sent, DispatchStatus::Delivered, DispatchStatus::Responded], true)) {
            throw new SatisfactionException($dispatch->status === DispatchStatus::Expired ? 'survey_expired' : 'survey_not_available', 410);
        }

        $answered = $dispatch->status === DispatchStatus::Responded;
        $reference = $dispatch->sent_at ?? $dispatch->scheduled_for ?? $dispatch->created_at;

        if (! $answered && $reference !== null && $reference->copy()->addDays((int) config('customer_satisfaction.link_expiry_days'))->isPast()) {
            $dispatch->update(['status' => DispatchStatus::Expired->value]);

            throw new SatisfactionException('survey_expired', 410);
        }

        return $dispatch;
    }

    /**
     * Records the score of the first click. A second click on the same survey returns the answer already stored.
     *
     * @param  array<string, mixed>  $metadata  IP address, user agent
     * @return array{0: SurveyResponse, 1: bool} the response and whether this call created it
     */
    public function capture(string $token, int $score, SubmittedVia $via, array $metadata = []): array
    {
        $dispatch = $this->resolve($token);
        $template = $dispatch->template;

        if (! $template->survey_type->isValidScore($score)) {
            throw new SatisfactionException('invalid_score', 422, ['min' => $template->survey_type->min(), 'max' => $template->survey_type->max()]);
        }

        return DB::transaction(function () use ($dispatch, $template, $score, $via, $metadata): array {
            $locked = SurveyDispatch::query()->whereKey($dispatch->id)->lockForUpdate()->firstOrFail();
            $existing = SurveyResponse::query()->where('survey_dispatch_id', $locked->id)->first();

            if ($existing !== null) {
                return [$existing, false];
            }

            $dimensions = (array) ($locked->metadata['dimensions'] ?? []);
            $type = $template->survey_type;

            $response = SurveyResponse::query()->create([
                'survey_dispatch_id' => $locked->id,
                'survey_template_id' => $template->id,
                'customer_id' => $locked->customer_id,
                'contact_id' => $locked->contact_id,
                'survey_type' => $type->value,
                'score' => $score,
                'normalized_band' => $type->band($score)->value,
                'is_detractor' => $type->isDetractor($score, $template->effectiveDetractorThreshold()),
                'department_code' => $dimensions['department_code'] ?? null,
                'technician_id' => $dimensions['technician_id'] ?? null,
                'product_line_code' => $dimensions['product_line_code'] ?? null,
                'branch_code' => $dimensions['branch_code'] ?? null,
                'submitted_via' => $via->value,
                'locale' => $locked->locale,
                'metadata' => $metadata,
                'initial_clicked_at' => now(),
            ]);

            $locked->update(['status' => DispatchStatus::Responded->value, 'responded_at' => now()]);

            if ($response->is_detractor) {
                $this->recovery->open($response, $template);
            }

            return [$response, true];
        });
    }

    /**
     * Adds the text and/or voice comment to the answer already recorded by the click.
     */
    public function enrich(string $token, ?string $comment, ?UploadedFile $voice = null, ?int $voiceDurationSeconds = null, ?string $voiceTranscript = null): SurveyResponse
    {
        $dispatch = $this->resolve($token);
        $response = SurveyResponse::query()->where('survey_dispatch_id', $dispatch->id)->first();

        if ($response === null) {
            throw new SatisfactionException('score_required', 409);
        }

        if (blank($comment) && $voice === null && blank($voiceTranscript)) {
            throw new SatisfactionException('comment_required', 422);
        }

        if ($voice !== null && ! $dispatch->template->allow_voice_comment) {
            throw new SatisfactionException('voice_not_allowed', 422);
        }

        $attributes = ['comment_updated_at' => now()];

        if (filled($comment)) {
            $attributes['comment_text'] = $comment;
        }

        if ($voice !== null) {
            $attributes['voice_attachment_path'] = Storage::disk((string) config('customer_satisfaction.voice_disk'))
                ->putFile('customer-satisfaction/'.$response->tenant_id.'/voice', $voice);
            $attributes['voice_duration_seconds'] = $voiceDurationSeconds;
        }

        if (filled($voiceTranscript)) {
            $attributes['voice_transcript'] = $voiceTranscript;
        }

        $response->fill($attributes);
        $text = trim(($response->comment_text ?? '').' '.($response->voice_transcript ?? ''));

        if ($text !== '') {
            $analysis = $this->sentiment->analyze($text, SurveyLocale::language((string) ($response->locale ?? 'en')));
            $response->fill([
                'sentiment_score' => $analysis['score'],
                'sentiment_label' => $analysis['label'],
                'sentiment_topics' => $analysis['topics'],
            ]);
        }

        $response->save();

        return $response;
    }

    public function optOut(string $token): void
    {
        $dispatch = SurveyDispatch::query()->withoutGlobalScopes()->where('dispatch_token', $token)->first();

        if ($dispatch === null || $dispatch->tenant_id === null) {
            throw new SatisfactionException('survey_not_found', 404);
        }

        $tenant = Tenant::query()->find($dispatch->tenant_id);
        if ($tenant === null) {
            throw new SatisfactionException('survey_not_found', 404);
        }

        $this->tenantContext->runWithTenant($tenant, function () use ($dispatch): void {
            SurveyOptOut::query()->firstOrCreate(['customer_id' => $dispatch->customer_id, 'channel' => null], ['reason' => 'link']);
        });
    }
}
