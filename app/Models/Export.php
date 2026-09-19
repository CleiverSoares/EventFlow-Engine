<?php

namespace App\Models;

use App\Enums\ExportReport;
use App\Enums\ExportStatus;
use Database\Factories\ExportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id', 'report', 'format', 'status', 'filters', 'row_count',
    'file_path', 'error_message', 'idempotency_key', 'started_at', 'finished_at',
])]
class Export extends Model
{
    /** @use HasFactory<ExportFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'report' => ExportReport::class,
            'status' => ExportStatus::class,
            'filters' => 'array',
            'row_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
