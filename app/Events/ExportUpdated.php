<?php

namespace App\Events;

use App\Models\Export;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExportUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Export $export) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('exports.lab')];
    }

    public function broadcastAs(): string
    {
        return 'export.updated';
    }

    public function broadcastQueue(): string
    {
        return (string) config('eventflow.exports.broadcast_queue', 'exports.broadcast');
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $this->export->loadMissing('tenant:id,name,plan,api_key');

        $waitMs = null;
        if ($this->export->started_at !== null && $this->export->created_at !== null) {
            $waitMs = (int) $this->export->created_at->diffInMilliseconds($this->export->started_at);
        }

        return [
            'export' => [
                'id' => $this->export->id,
                'tenant_id' => $this->export->tenant_id,
                'tenant_name' => $this->export->tenant?->name,
                'plan' => $this->export->tenant?->plan?->value ?? $this->export->tenant?->plan,
                'api_key' => $this->export->tenant?->api_key,
                'report' => $this->export->report->value,
                'status' => strtolower($this->export->status->value),
                'row_count' => $this->export->row_count,
                'wait_ms' => $waitMs,
                'created_at' => $this->export->created_at?->toIso8601String(),
                'started_at' => $this->export->started_at?->toIso8601String(),
                'finished_at' => $this->export->finished_at?->toIso8601String(),
                'error_message' => $this->export->error_message,
            ],
        ];
    }
}
