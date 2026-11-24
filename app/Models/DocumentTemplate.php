<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\DocumentTemplateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Branding of the PDF documents of a tenant. `default` applies to every type, and a row per type overrides it field by
 * field. Values are data: the views escape them and no user text is compiled as a template.
 */
class DocumentTemplate extends Model
{
    /** @use HasFactory<DocumentTemplateFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    public const string DefaultType = 'default';

    /**
     * @var list<string>
     */
    public const array Types = [self::DefaultType, 'invoice', 'credit_note', 'proforma', 'quote', 'order', 'delivery_note', 'work_order', 'service_report'];

    protected $fillable = ['tenant_id', 'document_type', 'logo_data_uri', 'primary_color', 'secondary_color', 'footer_text'];

    /**
     * What a document of the type uses, for the tenant.
     *
     * @return array{logo_data_uri: ?string, primary_color: ?string, secondary_color: ?string, footer_text: ?string}
     */
    public static function brandingFor(?string $tenantId, string $type): array
    {
        $rows = $tenantId === null ? collect() : self::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('document_type', [self::DefaultType, $type])->get()->keyBy('document_type');
        $value = static fn (string $field): ?string => $rows->get($type)?->{$field} ?? $rows->get(self::DefaultType)?->{$field};

        return [
            'logo_data_uri' => $value('logo_data_uri'),
            'primary_color' => $value('primary_color'),
            'secondary_color' => $value('secondary_color'),
            'footer_text' => $value('footer_text'),
        ];
    }
}
