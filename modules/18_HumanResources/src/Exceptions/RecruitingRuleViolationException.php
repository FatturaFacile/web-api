<?php

declare(strict_types=1);

namespace Modules\HumanResources\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Business-rule violation of the recruiting module, rendered as `409 { message, error_code }`.
 */
final class RecruitingRuleViolationException extends RuntimeException
{
    public const string INVALID_STAGE_TRANSITION = 'invalid_stage_transition';

    public const string STAGE_DISABLED = 'stage_disabled';

    public const string OPENING_NOT_OPEN = 'opening_not_open';

    public const string NO_VACANCY = 'no_vacancy';

    public const string OPENING_NOT_PUBLISHABLE = 'opening_not_publishable';

    public const string OFFER_NOT_SIGNED = 'offer_not_signed';

    public const string OFFER_ALREADY_EXISTS = 'offer_already_exists';

    public const string ALREADY_HIRED = 'already_hired';

    public const string CANDIDATE_ANONYMIZED = 'candidate_anonymized';

    public const string CONSENT_EXPIRED = 'consent_expired';

    public const string DUPLICATE_APPLICATION = 'duplicate_application';

    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function invalidStageTransition(string $from, string $to): self
    {
        return new self(self::INVALID_STAGE_TRANSITION, "The application cannot be moved from '{$from}' to '{$to}'.");
    }

    public static function stageDisabled(string $stage): self
    {
        return new self(self::STAGE_DISABLED, "The stage '{$stage}' is disabled in the recruiting pipeline.");
    }

    public static function openingNotOpen(): self
    {
        return new self(self::OPENING_NOT_OPEN, 'The job opening is not open for applications or hires.');
    }

    public static function noVacancy(): self
    {
        return new self(self::NO_VACANCY, 'The position has no vacant headcount for this opening.');
    }

    public static function openingNotPublishable(string $reason): self
    {
        return new self(self::OPENING_NOT_PUBLISHABLE, $reason);
    }

    public static function offerNotSigned(): self
    {
        return new self(self::OFFER_NOT_SIGNED, 'The candidate has no signed job offer.');
    }

    public static function offerAlreadyExists(): self
    {
        return new self(self::OFFER_ALREADY_EXISTS, 'The application already has an active job offer.');
    }

    public static function alreadyHired(): self
    {
        return new self(self::ALREADY_HIRED, 'The candidate has already been hired.');
    }

    public static function candidateAnonymized(): self
    {
        return new self(self::CANDIDATE_ANONYMIZED, 'The candidate data has been anonymized and can no longer be changed.');
    }

    public static function consentExpired(): self
    {
        return new self(self::CONSENT_EXPIRED, 'The privacy consent of the candidate has expired.');
    }

    public static function duplicateApplication(): self
    {
        return new self(self::DUPLICATE_APPLICATION, 'An open application with this email already exists for the job opening.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error_code' => $this->errorCode,
        ], 409);
    }
}
