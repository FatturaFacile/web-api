<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceReport extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'service_reports';

    protected $fillable = [
        'tenant_id',
        'report_number',
        'status_id',
        'contact_id',
        'ticket_id',
        'project_id',
        'technician_id',
        'report_date',
        'time_start',
        'time_end',
        'total_hours',
        'travel_distance_km',
        'site_address',
        'customer_notes',
        'internal_notes',
        'total_net',
        'total_tax',
        'total_gross',
        'is_portal_visible',
        'signed_at',
        'invoiced_at',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'total_hours' => 'decimal:2',
            'travel_distance_km' => 'decimal:2',
            'total_net' => 'decimal:4',
            'total_tax' => 'decimal:4',
            'total_gross' => 'decimal:4',
            'is_portal_visible' => 'boolean',
            'signed_at' => 'datetime',
            'invoiced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<LookupItem, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(LookupItem::class, 'status_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return HasMany<ServiceReportLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ServiceReportLine::class, 'service_report_id');
    }

    /**
     * @return HasMany<ServiceReportSignature, $this>
     */
    public function signatures(): HasMany
    {
        return $this->hasMany(ServiceReportSignature::class, 'service_report_id');
    }

    /**
     * @return HasOne<InvoiceServiceReport, $this>
     */
    public function invoiceLink(): HasOne
    {
        return $this->hasOne(InvoiceServiceReport::class, 'service_report_id');
    }

    /**
     * @return HasOneThrough<Invoice, InvoiceServiceReport, $this>
     */
    public function invoice(): HasOneThrough
    {
        return $this->hasOneThrough(
            Invoice::class,
            InvoiceServiceReport::class,
            'service_report_id',
            'id',
            'id',
            'invoice_id'
        );
    }

    /**
     * Morph attachments (photos, receipts, files).
     *
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Scope only portal-visible reports.
     *
     * @param  Builder<ServiceReport>  $query
     */
    public function scopePortalVisible(Builder $query): Builder
    {
        return $query->where('is_portal_visible', true);
    }

    /**
     * Scope reports that are signed.
     *
     * @param  Builder<ServiceReport>  $query
     */
    public function scopeSigned(Builder $query): Builder
    {
        return $query->whereNotNull('signed_at');
    }

    /**
     * Scope reports that have not yet been invoiced.
     *
     * @param  Builder<ServiceReport>  $query
     */
    public function scopeUninvoiced(Builder $query): Builder
    {
        return $query->whereNull('invoiced_at')
            ->whereDoesntHave('invoiceLink');
    }
}
