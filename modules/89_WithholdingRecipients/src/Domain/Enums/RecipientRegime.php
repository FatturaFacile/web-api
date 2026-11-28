<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Domain\Enums;

/**
 * Tax regime of a payee of commissions, royalties or special compensations.
 */
enum RecipientRegime: string
{
    case Agent25Bis = 'agent_25bis';
    case AuthorRoyalty = 'author_royalty';
    case OccasionalWorker = 'occasional_worker';
    case AmateurSports = 'amateur_sports';
    case SwissForeignArtist = 'swiss_foreign_artist';
    case SwissDeMinimis = 'swiss_de_minimis';

    public function jurisdiction(): Jurisdiction
    {
        return match ($this) {
            self::Agent25Bis, self::AuthorRoyalty, self::OccasionalWorker, self::AmateurSports => Jurisdiction::Italy,
            self::SwissForeignArtist, self::SwissDeMinimis => Jurisdiction::Switzerland,
        };
    }

    public function currency(): string
    {
        return $this->jurisdiction() === Jurisdiction::Italy ? 'EUR' : 'CHF';
    }

    /**
     * Default CU ministerial causal; the agent causal depends on the Enasarco mandate and Swiss payees have none.
     */
    public function defaultCuCausal(?EnasarcoMandate $mandate = null): ?string
    {
        return match ($this) {
            self::Agent25Bis => $mandate === EnasarcoMandate::Pluri ? 'R' : 'Q',
            self::AuthorRoyalty => (string) config('withholding_recipients.author_royalty.cu_causal', 'B'),
            self::OccasionalWorker => (string) config('withholding_recipients.occasional.cu_causal', 'M'),
            self::AmateurSports => (string) config('withholding_recipients.amateur_sports.cu_causal', 'M'),
            self::SwissForeignArtist, self::SwissDeMinimis => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
