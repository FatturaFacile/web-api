<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Dto;

use InvalidArgumentException;
use Modules\SwissEbill\Domain\Support\SwissUid;

/**
 * What the participant directory is asked for: exactly one identifier, by priority UID, participant id, IBAN, email.
 */
final readonly class ParticipantLookupCriteria
{
    public const TYPES = ['uid', 'participant_id', 'iban', 'email'];

    private function __construct(public string $type, public string $value) {}

    public static function uid(string $uid): self
    {
        $normalized = SwissUid::normalize($uid);

        if ($normalized === null) {
            throw new InvalidArgumentException('Invalid Swiss UID.');
        }

        return new self('uid', $normalized);
    }

    public static function participantId(string $id): self
    {
        return new self('participant_id', trim($id));
    }

    public static function iban(string $iban): self
    {
        return new self('iban', strtoupper((string) preg_replace('/\s+/', '', $iban)));
    }

    public static function email(string $email): self
    {
        return new self('email', strtolower(trim($email)));
    }

    public static function of(string $type, string $value): self
    {
        return match ($type) {
            'uid' => self::uid($value),
            'participant_id' => self::participantId($value),
            'iban' => self::iban($value),
            'email' => self::email($value),
            default => throw new InvalidArgumentException("Unknown lookup type [{$type}]."),
        };
    }

    public function cacheKey(): string
    {
        return hash('sha256', $this->type.'|'.$this->value);
    }
}
