<?php

declare(strict_types=1);

namespace Modules\Intercompany\Services;

use App\Models\PassiveInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Actions\PostPassiveInvoiceAction;
use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\GeneralLedger\Services\Consolidation\ConsolidationMath;
use Modules\Intercompany\Enums\DocumentKind;
use Modules\Intercompany\Enums\LinkStatus;
use Modules\Intercompany\Enums\MirrorMode;
use Modules\Intercompany\Enums\SyncEventType;
use Modules\Intercompany\Exceptions\IntercompanyConflictException;
use Modules\Intercompany\Exceptions\IntercompanyException;
use Modules\Intercompany\Models\PartyLink;
use Modules\Intercompany\Models\TransactionLink;
use Modules\Intercompany\Support\GroupScope;
use Modules\Invoicing\Models\SalesInvoice;
use Throwable;

/**
 * The mirror posting engine (Scritture a specchio). An invoice, debit note or credit note that one entity of a group issues
 * to another becomes a supplier document in the books of the counterparty:
 *  - idempotent: the link of a source document is found by a key built from entity, document and kind, so processing a
 *    document twice (event replay, scan, manual action) never creates a second mirror;
 *  - `draft` mirrors are supplier documents that are registered but not posted to the ledger; `confirmed` mirrors are
 *    posted at once. The mode comes from the settings of the group and can be given per call;
 *  - source and mirror point at each other (the link holds both ids, the supplier document carries the pair id in its
 *    notes-free `document_hash`), and the pair id groups the two sides;
 *  - a reversal removes a draft mirror and offsets a confirmed one with a credit note, and a credit note issued by the
 *    source is mirrored as a supplier credit note.
 * Every step is logged in the append-only sync log. The writes in the books of another entity run in that entity's
 * tenant context, never through the context of the caller.
 */
final readonly class MirrorPostingService
{
    public function __construct(
        private GroupScope $scope,
        private SettingsService $settings,
        private SyncLog $log,
        private PostPassiveInvoiceAction $post,
    ) {}

    /**
     * Finds the group and the counterparty of a document issued to a contact, through the party links.
     */
    public function linkFor(string $sourceTenantId, string $contactId): ?PartyLink
    {
        return PartyLink::withoutGlobalScopes()
            ->where('entity_tenant_id', $sourceTenantId)
            ->where('contact_id', $contactId)
            ->where('role', 'customer')
            ->first();
    }

    public static function kindOf(SalesInvoice $invoice): DocumentKind
    {
        return match (true) {
            $invoice->invoice_type === 'credit_note' => DocumentKind::CreditNote,
            (string) $invoice->document_type === 'TD05' => DocumentKind::DebitNote,
            default => DocumentKind::Invoice,
        };
    }

    public static function idempotencyKey(string $sourceTenantId, string $documentId, DocumentKind $kind): string
    {
        return 'ic:'.substr(hash('sha256', "mirror|{$kind->value}|{$sourceTenantId}|{$documentId}"), 0, 40);
    }

    /**
     * Picks up the posted documents the entities of the group issued to each other and mirrors those without a link.
     *
     * @return array{created: int, skipped: int, failed: int, transactions: list<TransactionLink>}
     */
    public function scan(User $user, ConsolidationGroup $group, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $this->scope->assertReadable($user, $group);
        $result = ['created' => 0, 'skipped' => 0, 'failed' => 0, 'transactions' => []];

        $links = PartyLink::withoutGlobalScopes()->where('tenant_id', $group->tenant_id)->where('group_id', $group->id)->where('role', 'customer')->get();

        foreach ($links as $party) {
            $invoices = SalesInvoice::withoutGlobalScopes()
                ->where('tenant_id', $party->entity_tenant_id)
                ->where('contact_id', $party->contact_id)
                ->where('status', 'posted')
                ->where('invoice_type', '!=', 'proforma')
                ->when($from, fn ($query) => $query->whereDate('date', '>=', $from->toDateString()))
                ->when($to, fn ($query) => $query->whereDate('date', '<=', $to->toDateString()))
                ->orderBy('date')
                ->get();

            foreach ($invoices as $invoice) {
                $key = self::idempotencyKey((string) $party->entity_tenant_id, (string) $invoice->id, self::kindOf($invoice));

                if (TransactionLink::withoutGlobalScopes()->where('tenant_id', $group->tenant_id)->where('idempotency_key', $key)->exists()) {
                    $result['skipped']++;

                    continue;
                }

                $link = $this->mirrorInvoice($group, $party, $invoice, $user);
                $result[$link->status === LinkStatus::Failed->value ? 'failed' : 'created']++;
                $result['transactions'][] = $link;
            }
        }

        return $result;
    }

    /**
     * Creates (or finds) the link of a source document and mirrors it. Never throws for a business failure: the link is
     * stored as `failed` with the reason, so the controller can retry it after the party link is fixed.
     */
    public function mirrorInvoice(ConsolidationGroup $group, PartyLink $party, SalesInvoice $invoice, ?User $user, ?MirrorMode $mode = null): TransactionLink
    {
        return $this->scope->runAs((string) $group->tenant_id, function () use ($group, $party, $invoice, $user, $mode): TransactionLink {
            $kind = self::kindOf($invoice);
            $key = self::idempotencyKey((string) $party->entity_tenant_id, (string) $invoice->id, $kind);

            $link = TransactionLink::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $group->tenant_id, 'idempotency_key' => $key],
                [
                    'group_id' => $group->id,
                    'pair_id' => (string) Str::uuid7(),
                    'document_type' => $kind->value,
                    'source_tenant_id' => $party->entity_tenant_id,
                    'source_document_id' => $invoice->id,
                    'source_document_number' => $invoice->invoice_number,
                    'mirror_tenant_id' => $party->counterparty_tenant_id,
                    'status' => LinkStatus::Pending->value,
                    'currency' => $invoice->currency,
                    'net_amount' => self::money($invoice->total_net),
                    'tax_amount' => self::money($invoice->total_tax),
                    'gross_amount' => self::money($invoice->total_gross),
                    'document_date' => CarbonImmutable::parse($invoice->date)->toDateString(),
                    'payment_status' => 'unpaid',
                ],
            );

            return $this->apply($group, $link, $user, $mode);
        });
    }

    /**
     * (Re)applies the mirror of a link: nothing happens when the mirror exists and nothing changed.
     */
    public function mirror(ConsolidationGroup $group, TransactionLink $link, ?User $user, ?MirrorMode $mode = null, ?int $expectedVersion = null): TransactionLink
    {
        $this->scope->assertReadable($user, $group);

        return $this->scope->runAs((string) $group->tenant_id, function () use ($group, $link, $user, $mode, $expectedVersion): TransactionLink {
            $this->assertVersion($link, $expectedVersion);

            return $this->apply($group, $link, $user, $mode);
        });
    }

    public function confirm(ConsolidationGroup $group, TransactionLink $link, ?User $user, ?int $expectedVersion = null): TransactionLink
    {
        $this->scope->assertReadable($user, $group);

        return $this->scope->runAs((string) $group->tenant_id, function () use ($group, $link, $user, $expectedVersion): TransactionLink {
            $this->assertVersion($link, $expectedVersion);

            if ($link->mirror_document_id === null || $link->status !== LinkStatus::Drafted->value) {
                throw new IntercompanyConflictException('Only a draft mirror can be confirmed.', 'mirror_not_draft');
            }

            return DB::transaction(function () use ($group, $link, $user): TransactionLink {
                $this->postMirror($link);
                $link->forceFill(['status' => LinkStatus::Confirmed->value, 'mirror_status' => MirrorMode::Confirmed->value, 'error_message' => null, 'version' => $link->version + 1])->save();
                $this->log->record((string) $group->tenant_id, (string) $group->id, $link, SyncEventType::MirrorConfirmed, null, ['mirror_document_id' => $link->mirror_document_id], null, $user?->id);

                return $link->refresh();
            });
        });
    }

    public function reverse(ConsolidationGroup $group, TransactionLink $link, string $reason, ?User $user, ?int $expectedVersion = null): TransactionLink
    {
        $this->scope->assertReadable($user, $group);

        return $this->scope->runAs((string) $group->tenant_id, function () use ($group, $link, $reason, $user, $expectedVersion): TransactionLink {
            $this->assertVersion($link, $expectedVersion);

            if ($link->status === LinkStatus::Reversed->value) {
                return $link;
            }

            return DB::transaction(function () use ($group, $link, $reason, $user): TransactionLink {
                $context = ['reason' => $reason];

                if ($link->mirror_document_id !== null) {
                    $context['offset_document_id'] = $this->scope->runAs((string) $link->mirror_tenant_id, fn (): ?string => $this->removeMirror($link));
                }

                $link->forceFill(['status' => LinkStatus::Reversed->value, 'error_message' => null, 'version' => $link->version + 1])->save();
                $this->log->record((string) $group->tenant_id, (string) $group->id, $link, SyncEventType::MirrorReversed, null, $context, null, $user?->id);

                return $link->refresh();
            });
        });
    }

    /**
     * A source document that was voided or credited: the mirror follows it. Called by the listeners.
     */
    public function propagateCredit(ConsolidationGroup $group, TransactionLink $original, SalesInvoice $creditNote, ?User $user): TransactionLink
    {
        $party = PartyLink::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('entity_tenant_id', $original->source_tenant_id)
            ->where('counterparty_tenant_id', $original->mirror_tenant_id)
            ->where('role', 'customer')
            ->firstOrFail();

        return $this->mirrorInvoice($group, $party, $creditNote, $user);
    }

    private function apply(ConsolidationGroup $group, TransactionLink $link, ?User $user, ?MirrorMode $mode): TransactionLink
    {
        $settings = $this->settings->forGroup($group);
        $mode ??= MirrorMode::from((string) $settings->mirror_mode);

        if ($link->mirror_document_id !== null && $link->status !== LinkStatus::Failed->value) {
            return $this->refreshDraft($link);
        }

        $source = SalesInvoice::withoutGlobalScopes()->where('tenant_id', $link->source_tenant_id)->find($link->source_document_id);
        $supplier = PartyLink::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('entity_tenant_id', $link->mirror_tenant_id)
            ->where('counterparty_tenant_id', $link->source_tenant_id)
            ->where('role', 'supplier')
            ->first();

        try {
            if ($source === null) {
                throw new IntercompanyException('The source document no longer exists.', 'source_missing');
            }

            if ($supplier === null) {
                throw new IntercompanyException('The counterparty has no supplier contact for the issuing entity: add the party link.', 'party_link_missing');
            }

            DB::transaction(function () use ($group, $link, $source, $supplier, $mode, $user): void {
                $passive = $this->scope->runAs((string) $link->mirror_tenant_id, fn (): PassiveInvoice => $this->createPassive($link, $source, (string) $supplier->contact_id));
                $link->forceFill([
                    'mirror_document_id' => $passive->id,
                    'mirror_document_number' => $passive->invoice_number,
                    'mirror_status' => MirrorMode::Draft->value,
                    'status' => LinkStatus::Drafted->value,
                    'error_message' => null,
                    'version' => $link->version + 1,
                ])->save();
                $this->log->record((string) $group->tenant_id, (string) $group->id, $link, SyncEventType::MirrorCreated, null, ['mirror_document_id' => $passive->id, 'mode' => $mode->value], (string) $link->gross_amount, $user?->id);

                if ($mode === MirrorMode::Confirmed) {
                    $this->postMirror($link);
                    $link->forceFill(['status' => LinkStatus::Confirmed->value, 'mirror_status' => MirrorMode::Confirmed->value])->save();
                    $this->log->record((string) $group->tenant_id, (string) $group->id, $link, SyncEventType::MirrorConfirmed, null, ['mirror_document_id' => $passive->id], null, $user?->id);
                }
            });
        } catch (IntercompanyException $exception) {
            $this->fail($group, $link, $exception->getMessage(), $exception->errorCode);
        } catch (Throwable $exception) {
            report($exception);
            $this->fail($group, $link, Str::limit($exception->getMessage(), 400), 'mirror_failed');
        }

        return $link->refresh();
    }

    private function fail(ConsolidationGroup $group, TransactionLink $link, string $message, string $code): void
    {
        $link->forceFill(['status' => LinkStatus::Failed->value, 'error_message' => Str::limit($message, 480), 'version' => $link->version + 1])->save();
        $this->log->record((string) $group->tenant_id, (string) $group->id, $link, SyncEventType::Error, 'intercompany.error.'.$code, ['message' => $message]);
    }

    private function refreshDraft(TransactionLink $link): TransactionLink
    {
        return $link->refresh();
    }

    private function createPassive(TransactionLink $link, SalesInvoice $source, string $supplierContactId): PassiveInvoice
    {
        $kind = DocumentKind::from((string) $link->document_type);
        $number = (string) $source->invoice_number;

        $existing = PassiveInvoice::withoutGlobalScopes()
            ->where('tenant_id', $link->mirror_tenant_id)
            ->where('supplier_id', $supplierContactId)
            ->where('invoice_number', $number)
            ->where('document_hash', $link->pair_id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return PassiveInvoice::create([
            'tenant_id' => $link->mirror_tenant_id,
            'supplier_id' => $supplierContactId,
            'invoice_number' => $number,
            'date' => CarbonImmutable::parse($source->date)->toDateString(),
            'due_date' => $source->due_date !== null ? CarbonImmutable::parse($source->due_date)->toDateString() : null,
            'document_type' => match ($kind) {
                DocumentKind::CreditNote => 'TD04',
                DocumentKind::DebitNote => 'TD05',
                DocumentKind::Invoice => 'TD01',
            },
            'total_amount' => ConsolidationMath::abs(self::money($source->total_gross)),
            'total_net' => ConsolidationMath::abs(self::money($source->total_net)),
            'total_tax' => ConsolidationMath::abs(self::money($source->total_tax)),
            'currency' => $source->currency,
            'exchange_rate' => $source->exchange_rate,
            'payment_status' => 'unpaid',
            'total_paid' => 0,
            'document_hash' => $link->pair_id,
        ]);
    }

    private function postMirror(TransactionLink $link): void
    {
        $this->scope->runAs((string) $link->mirror_tenant_id, function () use ($link): void {
            $passive = PassiveInvoice::query()->findOrFail($link->mirror_document_id);

            if ($passive->gl_journal_entry_id === null) {
                $this->post->execute($passive);
            }
        });
    }

    /**
     * Removes a draft mirror, or offsets a posted one with a supplier credit note. Runs in the books of the mirror entity.
     */
    private function removeMirror(TransactionLink $link): ?string
    {
        $passive = PassiveInvoice::query()->find($link->mirror_document_id);

        if ($passive === null) {
            return null;
        }

        if ($passive->gl_journal_entry_id === null) {
            $passive->delete();

            return null;
        }

        $offset = PassiveInvoice::create([
            'tenant_id' => $link->mirror_tenant_id,
            'supplier_id' => $passive->supplier_id,
            'invoice_number' => 'REV-'.$passive->invoice_number,
            'date' => now()->toDateString(),
            'document_type' => 'TD04',
            'total_amount' => $passive->total_amount,
            'total_net' => $passive->total_net,
            'total_tax' => $passive->total_tax,
            'currency' => $passive->currency,
            'exchange_rate' => $passive->exchange_rate,
            'payment_status' => 'unpaid',
            'total_paid' => 0,
            'document_hash' => $link->pair_id.'-rev',
        ]);

        $this->post->execute($offset);

        return (string) $offset->id;
    }

    private function assertVersion(TransactionLink $link, ?int $expected): void
    {
        $current = TransactionLink::withoutGlobalScopes()->whereKey($link->id)->value('version');

        if ($expected !== null && (int) $current !== $expected) {
            throw new IntercompanyConflictException('The transaction was changed by someone else: reload it and try again.', 'stale_version');
        }

        $link->refresh();
    }

    private static function money(mixed $value): string
    {
        return ConsolidationMath::norm((string) ($value ?? '0'));
    }
}
