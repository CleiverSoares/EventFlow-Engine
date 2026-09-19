<?php

namespace App\Models;

use App\Enums\OutboxStatus;
use Database\Factories\OutboxEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['aggregate_type', 'payload', 'status', 'attempts'])]
class OutboxEvent extends Model
{
    /** @use HasFactory<OutboxEventFactory> */
    use HasFactory, HasUuids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'PENDING',
        'attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => OutboxStatus::class,
            'attempts' => 'integer',
        ];
    }
}
