<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmInvoice extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'deal_id', 'number', 'due_date', 'total',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'total' => 'decimal:2',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(CrmDeal::class, 'deal_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CrmInvoiceLine::class, 'invoice_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CrmPayment::class, 'invoice_id');
    }
}
