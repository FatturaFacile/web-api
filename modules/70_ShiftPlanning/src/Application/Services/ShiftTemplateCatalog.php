<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Application\Services;

use Illuminate\Support\Collection;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftNotFoundException;
use Modules\ShiftPlanning\Infrastructure\Models\ShiftTemplate;

/**
 * The reusable shift templates of a tenant.
 */
final class ShiftTemplateCatalog
{
    /**
     * @return Collection<int, ShiftTemplate>
     */
    public function list(string $tenantId): Collection
    {
        return ShiftTemplate::query()->where('tenant_id', $tenantId)->orderBy('start_time')->orderBy('code')->get();
    }

    /**
     * @param  array<string, mixed>  $data  validated StoreShiftTemplateRequest input
     *
     * @throws ShiftNotFoundException
     */
    public function save(string $tenantId, ?string $id, array $data): ShiftTemplate
    {
        $template = $id === null ? new ShiftTemplate : (ShiftTemplate::query()->where('tenant_id', $tenantId)->whereKey($id)->first()
            ?? throw new ShiftNotFoundException('Unknown shift template.'));

        $template->fill([
            'tenant_id' => $tenantId,
            'code' => $data['code'],
            'name' => $data['name'],
            'start_time' => $data['start_time'].':00',
            'end_time' => $data['end_time'].':00',
            'break_minutes' => (int) ($data['break_minutes'] ?? 0),
            'color_hex' => $data['color_hex'] ?? '#1890FF',
            'night_surcharge_applicable' => (bool) ($data['night_surcharge_applicable'] ?? true),
            'is_on_call' => (bool) ($data['is_on_call'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ])->save();

        return $template;
    }
}
