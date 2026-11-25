<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Modules\CustomerSatisfaction\Domain\Enums\ResponseBand;
use Modules\CustomerSatisfaction\Domain\Enums\SubmittedVia;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyType;

/**
 * The answer to a survey: the score captured by the first click (even when the customer never writes a comment), its band, the
 * optional text or voice comment with its sentiment, and the dimensions used by the ISO 9001 breakdowns.
 *
 * @property string $survey_dispatch_id
 * @property string $survey_template_id
 * @property string $customer_id
 * @property string|null $contact_id
 * @property SurveyType $survey_type
 * @property int $score
 * @property ResponseBand $normalized_band
 * @property bool $is_detractor
 * @property string|null $comment_text
 * @property string|null $voice_attachment_path
 * @property int|null $voice_duration_seconds
 * @property string|null $voice_transcript
 * @property string|null $sentiment_score
 * @property string|null $sentiment_label
 * @property list<string>|null $sentiment_topics
 * @property string|null $department_code
 * @property string|null $technician_id
 * @property string|null $product_line_code
 * @property string|null $branch_code
 * @property SubmittedVia $submitted_via
 * @property string|null $locale
 * @property array<string, mixed>|null $metadata
 * @property Carbon $initial_clicked_at
 * @property Carbon|null $comment_updated_at
 */
final class SurveyResponse extends BaseEnterpriseModel
{
    protected $table = 'survey_responses';

    protected function casts(): array
    {
        return [
            'survey_type' => SurveyType::class,
            'normalized_band' => ResponseBand::class,
            'submitted_via' => SubmittedVia::class,
            'score' => 'integer',
            'is_detractor' => 'boolean',
            'voice_duration_seconds' => 'integer',
            'sentiment_score' => 'decimal:2',
            'sentiment_topics' => 'array',
            'metadata' => 'array',
            'initial_clicked_at' => 'datetime',
            'comment_updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SurveyDispatch, $this>
     */
    public function surveyDispatch(): BelongsTo
    {
        return $this->belongsTo(SurveyDispatch::class, 'survey_dispatch_id');
    }

    /**
     * @return BelongsTo<SurveyTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(SurveyTemplate::class, 'survey_template_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    /**
     * @return HasOne<DetractorRecoveryCase, $this>
     */
    public function recoveryCase(): HasOne
    {
        return $this->hasOne(DetractorRecoveryCase::class, 'survey_response_id');
    }
}
