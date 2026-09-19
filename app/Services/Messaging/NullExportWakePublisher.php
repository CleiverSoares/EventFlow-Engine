<?php

namespace App\Services\Messaging;

use App\Contracts\ExportWakePublisher;

/**
 * No-op wake publisher for unit/feature tests (no broker required).
 */
class NullExportWakePublisher implements ExportWakePublisher
{
    public function declareTopology(): void {}

    public function publishWake(array $body = []): void {}

    public function publishDelayedWake(array $body = []): void {}
}
