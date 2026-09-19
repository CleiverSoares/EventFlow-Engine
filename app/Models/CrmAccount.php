<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmAccount extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'document', 'name'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(CrmDeal::class, 'account_id');
    }
}
