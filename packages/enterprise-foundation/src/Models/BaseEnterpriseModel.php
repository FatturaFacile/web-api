<?php

declare(strict_types=1);

namespace Enterprise\Foundation\Models;

use Enterprise\Foundation\Concerns\AiEnabled;
use Enterprise\Foundation\Concerns\Auditable;
use Enterprise\Foundation\Concerns\EventAware;
use Enterprise\Foundation\Concerns\FeatureAware;
use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\Foundation\Concerns\WorkflowAware;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

abstract class BaseEnterpriseModel extends Model
{
    use AiEnabled,
        Auditable,
        BelongsToTenant,
        EventAware,
        FeatureAware,
        HasFactory,
        HasUuidV7,
        SoftDeletes,
        WorkflowAware;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id', 'created_at', 'updated_at', 'deleted_at'];

    public function __construct(array $attributes = [])
    {
        if (! isset($attributes['id'])) {
            $attributes['id'] = (string) Str::uuid7();
        }

        parent::__construct($attributes);

        $this->attributes['id'] = $attributes['id'];
    }

    public function setAttribute($key, $value)
    {
        if ($key === $this->getKeyName() && $this->exists && ! empty($this->getAttributeFromArray($key))) {
            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    /**
     * Create a new factory instance for the enterprise model.
     *
     * @return Factory<static>|null
     */
    protected static function newFactory()
    {
        $factoryName = 'Database\\Factories\\'.class_basename(static::class).'Factory';
        if (class_exists($factoryName)) {
            /** @var class-string<Factory<static>> $factoryName */
            return $factoryName::new();
        }

        return null;
    }
}
