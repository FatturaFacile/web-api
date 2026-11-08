<?php

declare(strict_types=1);

namespace Modules\CRM\Services\PartyMaster;

use App\Models\Contact;
use DomainException;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\CRM\Events\ContactsMergedEvent;
use Modules\CRM\Models\ContactMerge;

/**
 * Merges a duplicate contact into a surviving one. Every foreign key that references `contacts.id` is discovered from
 * the database catalogue (so references added by any module are covered without a hand-kept list) and repointed in
 * one transaction, together with CRM polymorphic references. Issued fiscal documents are immutable and keep the
 * party they were issued to; they are reported as retained. Any failure (a unique constraint, an immutability
 * trigger) rolls the whole merge back. The merge is recorded in `crm_contact_merges`, in the audit ledger and as a
 * domain event.
 */
final readonly class ContactMergeService
{
    /**
     * Master data copied from the merged contact when the survivor has no value.
     */
    private const array FillableFields = ['company_name', 'first_name', 'last_name', 'email', 'phone', 'vat_number', 'fiscal_code', 'uid_number', 'sdi_code', 'pec', 'iban', 'qr_iban'];

    /**
     * Rows that must not be repointed because they are legally frozen, as table => [column, value that freezes].
     */
    private const array FrozenRows = ['invoices' => ['status', 'posted']];

    /**
     * Polymorphic CRM references to contacts, as table => [type column, id column, type value].
     */
    private const array MorphReferences = [['crm_activities', 'related_type', 'related_id', 'contact']];

    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
    ) {}

    /**
     * @throws DomainException when the contacts cannot be merged (same contact, or a constraint refuses a repoint)
     */
    public function merge(string $tenantId, string $survivorId, string $mergedId, ?string $actorUserId, bool $fillMissing = true): ContactMerge
    {
        if ($survivorId === $mergedId) {
            throw new DomainException('A contact cannot be merged into itself.');
        }

        try {
            return DB::transaction(function () use ($tenantId, $survivorId, $mergedId, $actorUserId, $fillMissing): ContactMerge {
                $survivor = Contact::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($survivorId);
                $merged = Contact::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($mergedId);
                $snapshot = $merged->toArray();

                $repointed = [];
                $retained = [];
                foreach ($this->referencingColumns() as [$table, $column]) {
                    $query = DB::table($table)->where($column, $mergedId);

                    if (isset(self::FrozenRows[$table]) && Schema::hasColumn($table, self::FrozenRows[$table][0])) {
                        [$frozenColumn, $frozenValue] = self::FrozenRows[$table];
                        $kept = (clone $query)->where($frozenColumn, $frozenValue)->count();
                        if ($kept > 0) {
                            $retained["{$table}.{$column}"] = $kept;
                        }
                        $query->where(fn ($inner) => $inner->where($frozenColumn, '<>', $frozenValue)->orWhereNull($frozenColumn));
                    }

                    $count = $query->update([$column => $survivorId]);
                    if ($count > 0) {
                        $repointed["{$table}.{$column}"] = $count;
                    }
                }

                foreach (self::MorphReferences as [$table, $typeColumn, $idColumn, $typeValue]) {
                    if (! Schema::hasTable($table)) {
                        continue;
                    }

                    $count = DB::table($table)->where($typeColumn, $typeValue)->where($idColumn, $mergedId)->update([$idColumn => $survivorId]);
                    if ($count > 0) {
                        $repointed["{$table}.{$idColumn}"] = $count;
                    }
                }

                $filled = [];
                if ($fillMissing) {
                    foreach (self::FillableFields as $field) {
                        if (blank($survivor->getAttribute($field)) && filled($merged->getAttribute($field))) {
                            $filled[$field] = $merged->getAttribute($field);
                        }
                    }

                    // The merged contact is deleted first: values it holds may be covered by unique indexes.
                    $merged->delete();
                    if ($filled !== []) {
                        $survivor->fill($filled)->save();
                    }
                } else {
                    $merged->delete();
                }

                $merge = ContactMerge::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'survivor_contact_id' => $survivorId,
                    'merged_contact_id' => $mergedId,
                    'repointed' => $repointed,
                    'retained' => $retained,
                    'filled_fields' => array_keys($filled),
                    'merged_snapshot' => $snapshot,
                    'merged_by_user_id' => $actorUserId !== null && Str::isUuid($actorUserId) ? $actorUserId : null,
                ]);

                $this->auditLedger->record(
                    action: 'CONTACTS_MERGED',
                    model: $survivor,
                    payloadBefore: ['merged_contact' => $snapshot],
                    payloadAfter: ['survivor_contact_id' => $survivorId, 'repointed' => $repointed, 'retained' => $retained, 'filled_fields' => array_keys($filled)],
                    moduleCode: 'MODULE_02_CRM'
                );

                $this->dispatcher->publishAndDispatch(new ContactsMergedEvent(
                    mergeId: (string) $merge->id,
                    survivorContactId: $survivorId,
                    mergedContactId: $mergedId,
                    repointed: $repointed,
                    actorUserId: $actorUserId,
                    tenantId: $tenantId,
                    companyId: $tenantId,
                ));

                return $merge;
            });
        } catch (QueryException $exception) {
            throw new DomainException('The contacts cannot be merged: a reference cannot be moved ('.Str::limit($exception->getMessage(), 300).').', 0, $exception);
        }
    }

    /**
     * Every (table, column) with a foreign key to contacts.id, except the contacts table itself.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function referencingColumns(): array
    {
        if (DB::getDriverName() === 'pgsql') {
            $rows = DB::select(<<<'SQL'
                SELECT cl.relname AS table_name, att.attname AS column_name
                FROM pg_constraint con
                JOIN pg_class cl ON cl.oid = con.conrelid
                JOIN pg_namespace ns ON ns.oid = cl.relnamespace
                JOIN pg_class ref ON ref.oid = con.confrelid
                JOIN pg_attribute att ON att.attrelid = con.conrelid AND att.attnum = ANY (con.conkey)
                WHERE con.contype = 'f' AND ref.relname = 'contacts' AND ns.nspname = current_schema()
                ORDER BY cl.relname, att.attname
                SQL);

            return array_values(array_filter(
                array_map(fn (object $row): array => [(string) $row->table_name, (string) $row->column_name], $rows),
                fn (array $reference): bool => $reference[0] !== 'contacts',
            ));
        }

        $references = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if ($table === 'contacts') {
                continue;
            }
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                if ($foreignKey['foreign_table'] === 'contacts' && count($foreignKey['columns']) === 1) {
                    $references[] = [$table, $foreignKey['columns'][0]];
                }
            }
        }

        return $references;
    }
}
