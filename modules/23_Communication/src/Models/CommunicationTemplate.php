<?php

declare(strict_types=1);

namespace Modules\Communication\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CommunicationTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A message template of the company for one code, channel and language. `{{ name }}` placeholders are replaced with the
 * variables of the message when it is rendered; nothing else in the text is interpreted.
 *
 * @property string $id
 * @property string $code
 * @property string $channel
 * @property string $locale
 * @property ?string $subject
 * @property string $body
 */
#[UseFactory(CommunicationTemplateFactory::class)]
class CommunicationTemplate extends Model
{
    /** @use HasFactory<CommunicationTemplateFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'code', 'channel', 'locale', 'subject', 'body', 'is_active', 'updated_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
