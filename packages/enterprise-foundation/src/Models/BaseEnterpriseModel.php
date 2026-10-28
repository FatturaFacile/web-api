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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

abstract class BaseEnterpriseModel extends Model
{
    use AiEnabled,
        Auditable,
        BelongsToTenant,
        EventAware,
        FeatureAware,
        HasUuidV7,
        SoftDeletes,
        WorkflowAware;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id', 'created_at', 'updated_at', 'deleted_at'];
}
