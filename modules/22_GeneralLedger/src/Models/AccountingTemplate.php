<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * A parametric journal template (causale contabile). `parameters` lists the inputs (`key`, `type` account|amount|rate);
 * each entry of `lines` has an `account` (a `$key` placeholder or a literal account code), a `side` (debit|credit) and
 * an `amount` expression (`amount`, `tax` or `gross`).
 *
 * @property string $code
 * @property string $category
 * @property string $journal_type
 * @property string $ledger_type
 * @property array<string, string> $names
 * @property array<string, string>|null $descriptions
 * @property list<array<string, mixed>> $parameters
 * @property list<array<string, mixed>> $lines
 * @property bool $is_system
 * @property bool $is_active
 */
final class AccountingTemplate extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'accounting_templates';

    protected function casts(): array
    {
        return [
            'names' => 'array',
            'descriptions' => 'array',
            'parameters' => 'array',
            'lines' => 'array',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The name in the locale, falling back to English then to the first available one.
     */
    public function localizedName(string $locale): string
    {
        $names = $this->names;

        return (string) ($names[$locale] ?? $names['en'] ?? (reset($names) ?: $this->code));
    }
}
