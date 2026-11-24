<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Services;

use Carbon\CarbonImmutable;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\DeclarationOfIntent;
use Modules\ItalianVat\Exceptions\ItalianVatException;

/**
 * Real-time guard of the plafond of the habitual exporters (art. 8 c. 1 lett. c DPR 633/1972). A sale without VAT to a customer
 * with a declaration of intent is allowed only while the declaration is received, not revoked, valid on the date of the
 * operation and the plafond covers the amount. The issue of the invoice consumes the plafond (DeclarationOfIntentService);
 * this guard answers beforehand, for orders and invoice drafts, and explains why a sale is refused.
 */
final class PlafondGuardService
{
    /** Share of the plafond used from which the monitor raises a warning. */
    private const string WARNING_THRESHOLD = '80';

    /**
     * @return array{covered: bool, error_code: string|null, declaration_id: string|null, protocol_number: string|null, plafond: string|null, consumed: string|null, residual: string|null, requested: string, residual_after: string|null}
     */
    public function assess(string $tenantId, string $contactId, string $date, string $amount, ?string $declarationId = null): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();
        $requested = Decimal::round2($amount);
        $declarations = $this->declarationsOf($tenantId, $contactId, $declarationId);
        $declaration = $this->pick($declarations, $day, $requested);

        if ($declaration === null) {
            return $this->result(false, $this->explain($declarations, $day), null, $requested);
        }

        $residual = $declaration->residualAmount();
        $covered = Decimal::compare($requested, $residual) <= 0;

        return $this->result($covered, $covered ? null : 'plafond_exceeded', $declaration, $requested);
    }

    /**
     * Same check, as an error: the caller (order or invoice flow) refuses the document with the reason.
     *
     * @throws ItalianVatException
     */
    public function enforce(string $tenantId, string $contactId, string $date, string $amount, ?string $declarationId = null): DeclarationOfIntent
    {
        $assessment = $this->assess($tenantId, $contactId, $date, $amount, $declarationId);

        if (! $assessment['covered']) {
            throw ItalianVatException::invalid((string) $assessment['error_code'], [
                'protocol' => $assessment['protocol_number'],
                'residual' => $assessment['residual'],
                'requested' => $assessment['requested'],
                'date' => CarbonImmutable::parse($date)->toDateString(),
            ]);
        }

        return DeclarationOfIntent::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->findOrFail($assessment['declaration_id']);
    }

    /**
     * Revokes a received declaration: from then on it covers no new sale, the sales already issued keep their consumption.
     */
    public function revoke(string $tenantId, string $declarationId, ?string $reason): DeclarationOfIntent
    {
        $declaration = DeclarationOfIntent::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereKey($declarationId)
            ->first() ?? throw ItalianVatException::notFound('declaration_not_found');

        if ($declaration->isRevoked()) {
            throw ItalianVatException::locked('declaration_already_revoked', ['protocol' => $declaration->protocol_number]);
        }

        $declaration->update(['revoked_at' => now(), 'revocation_reason' => $reason]);

        return $declaration;
    }

    /**
     * Erosion of the plafond of the declarations received in a year, with the alert level of each one.
     *
     * @return array{year: int, as_of: string, declarations: list<array<string, mixed>>, totals: array{plafond: string, consumed: string, residual: string}}
     */
    public function monitor(string $tenantId, int $year, ?CarbonImmutable $asOf = null): array
    {
        $today = ($asOf ?? CarbonImmutable::today())->startOfDay();
        $declarations = DeclarationOfIntent::withoutGlobalScope(TenantScope::class)
            ->with('contact')
            ->where('tenant_id', $tenantId)
            ->where('direction', DeclarationOfIntent::RECEIVED)
            ->where('year', $year)
            ->orderBy('valid_from')
            ->get();

        $rows = [];
        $plafond = '0';
        $consumed = '0';

        foreach ($declarations as $declaration) {
            $usage = Decimal::isPositive((string) $declaration->plafond_amount)
                ? Decimal::round2(Decimal::div(Decimal::mul((string) $declaration->consumed_amount, '100'), (string) $declaration->plafond_amount))
                : '0.00';
            $status = $this->statusOf($declaration, $today, $usage);
            $plafond = Decimal::add($plafond, (string) $declaration->plafond_amount);
            $consumed = Decimal::add($consumed, (string) $declaration->consumed_amount);

            $rows[] = [
                'id' => (string) $declaration->id,
                'contact_id' => (string) $declaration->contact_id,
                'contact_name' => data_get($declaration, 'contact.company_name') ?? trim((string) data_get($declaration, 'contact.first_name').' '.(string) data_get($declaration, 'contact.last_name')),
                'protocol_number' => $declaration->protocol_number,
                'valid_from' => $declaration->valid_from->toDateString(),
                'valid_to' => $declaration->valid_to->toDateString(),
                'plafond' => Decimal::round2((string) $declaration->plafond_amount),
                'consumed' => Decimal::round2((string) $declaration->consumed_amount),
                'residual' => $declaration->residualAmount(),
                'usage_percentage' => $usage,
                'status' => $status,
                'alert' => $this->alertOf($status, $usage),
                'days_to_expiry' => (int) $today->diffInDays($declaration->valid_to, false),
            ];
        }

        return [
            'year' => $year,
            'as_of' => $today->toDateString(),
            'declarations' => $rows,
            'totals' => [
                'plafond' => Decimal::round2($plafond),
                'consumed' => Decimal::round2($consumed),
                'residual' => Decimal::round2(Decimal::sub($plafond, $consumed)),
            ],
        ];
    }

    /**
     * The declaration that covers the operation: among the usable ones (not revoked, valid on the day) the first with enough
     * plafond, else the first usable one, which then refuses the sale for lack of plafond.
     *
     * @param  Collection<int, DeclarationOfIntent>  $declarations
     */
    private function pick(Collection $declarations, CarbonImmutable $day, string $requested): ?DeclarationOfIntent
    {
        $usable = $declarations->filter(fn (DeclarationOfIntent $declaration): bool => ! $declaration->isRevoked()
            && $day->gte($declaration->valid_from) && $day->lte($declaration->valid_to))->values();

        return $usable->first(fn (DeclarationOfIntent $declaration): bool => Decimal::compare($requested, $declaration->residualAmount()) <= 0)
            ?? $usable->first();
    }

    /**
     * Why no declaration covers the day.
     *
     * @param  Collection<int, DeclarationOfIntent>  $declarations
     */
    private function explain(Collection $declarations, CarbonImmutable $day): string
    {
        $inForce = $declarations->filter(fn (DeclarationOfIntent $declaration): bool => $day->gte($declaration->valid_from) && $day->lte($declaration->valid_to));

        return match (true) {
            $declarations->isEmpty() => 'plafond_no_declaration',
            $inForce->isNotEmpty() => 'declaration_revoked',
            $declarations->every(fn (DeclarationOfIntent $declaration): bool => $day->gt($declaration->valid_to)) => 'declaration_expired',
            $declarations->every(fn (DeclarationOfIntent $declaration): bool => $day->lt($declaration->valid_from)) => 'declaration_not_yet_valid',
            default => 'plafond_no_declaration',
        };
    }

    private function statusOf(DeclarationOfIntent $declaration, CarbonImmutable $today, string $usage): string
    {
        return match (true) {
            $declaration->isRevoked() => 'revoked',
            $today->gt($declaration->valid_to) => 'expired',
            $today->lt($declaration->valid_from) => 'not_yet_valid',
            Decimal::compare($usage, '100') >= 0 => 'exhausted',
            default => 'active',
        };
    }

    private function alertOf(string $status, string $usage): string
    {
        return match (true) {
            in_array($status, ['revoked', 'expired', 'exhausted'], true) => 'critical',
            Decimal::compare($usage, self::WARNING_THRESHOLD) >= 0 => 'warning',
            default => 'ok',
        };
    }

    /**
     * @return Collection<int, DeclarationOfIntent>
     */
    private function declarationsOf(string $tenantId, string $contactId, ?string $declarationId): Collection
    {
        return DeclarationOfIntent::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $contactId)
            ->where('direction', DeclarationOfIntent::RECEIVED)
            ->when($declarationId !== null, fn (Builder $query) => $query->whereKey($declarationId))
            ->orderBy('valid_from')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function result(bool $covered, ?string $code, ?DeclarationOfIntent $declaration, string $requested): array
    {
        return [
            'covered' => $covered,
            'error_code' => $code,
            'declaration_id' => $declaration !== null ? (string) $declaration->id : null,
            'protocol_number' => $declaration?->protocol_number,
            'plafond' => $declaration !== null ? Decimal::round2((string) $declaration->plafond_amount) : null,
            'consumed' => $declaration !== null ? Decimal::round2((string) $declaration->consumed_amount) : null,
            'residual' => $declaration?->residualAmount(),
            'requested' => $requested,
            'residual_after' => $declaration !== null ? Decimal::round2(Decimal::sub($declaration->residualAmount(), $requested)) : null,
        ];
    }
}
