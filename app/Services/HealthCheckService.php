<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Throwable;

class HealthCheckService
{
    /**
     * @return array{ok: bool, checks: array<string, array{ok: bool, detail?: string}>}
     */
    public function check(): array
    {
        $checks = [
            'database' => $this->checkDatabase(),
        ];

        if (config('eventflow.health.check_redis')) {
            $checks['redis'] = $this->checkRedis();
        }

        if (config('eventflow.health.check_rabbitmq')) {
            $checks['rabbitmq'] = $this->checkRabbitMq();
        }

        $ok = collect($checks)->every(fn (array $check): bool => $check['ok'] === true);

        return [
            'ok' => $ok,
            'checks' => $checks,
        ];
    }

    /**
     * @return array{ok: bool, detail?: string}
     */
    private function checkDatabase(): array
    {
        try {
            DB::select('select 1');

            return ['ok' => true];
        } catch (Throwable $exception) {
            return ['ok' => false, 'detail' => $exception->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, detail?: string}
     */
    private function checkRedis(): array
    {
        try {
            $pong = Redis::connection()->ping();

            return ['ok' => $pong === true || $pong === 'PONG' || $pong === '+PONG'];
        } catch (Throwable $exception) {
            return ['ok' => false, 'detail' => $exception->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, detail?: string}
     */
    private function checkRabbitMq(): array
    {
        try {
            $connection = new AMQPStreamConnection(
                (string) config('eventflow.rabbitmq.host'),
                (int) config('eventflow.rabbitmq.port'),
                (string) config('eventflow.rabbitmq.user'),
                (string) config('eventflow.rabbitmq.password'),
                (string) config('eventflow.rabbitmq.vhost'),
                false,
                'AMQPLAIN',
                null,
                'en_US',
                3.0,
                3.0,
            );
            $connection->close();

            return ['ok' => true];
        } catch (Throwable $exception) {
            return ['ok' => false, 'detail' => $exception->getMessage()];
        }
    }
}
