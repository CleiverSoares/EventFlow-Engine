<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmInvoiceLine extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'invoice_id', 'description', 'quantity', 'unit_price',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CrmInvoice::class, 'invoice_id');
    }
}
