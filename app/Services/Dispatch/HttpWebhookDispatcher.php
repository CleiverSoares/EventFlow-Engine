<?php

namespace App\Services\Dispatch;

use App\Contracts\WebhookDispatcher;
use App\Services\Resilience\CircuitBreaker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HttpWebhookDispatcher implements WebhookDispatcher
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(array $payload): void
    {
        (new CircuitBreaker('webhook'))->call(function () use ($payload): void {
            $url = (string) config('eventflow.webhook.url');
            $timeout = (int) config('eventflow.webhook.timeout_seconds', 5);

            try {
                $response = Http::acceptJson()
                    ->connectTimeout(min(3, $timeout))
                    ->timeout($timeout)
                    ->post($url, $payload);
            } catch (ConnectionException $exception) {
                throw new RuntimeException('Webhook destination unreachable.', previous: $exception);
            }

            if ($response->failed()) {
                throw new RuntimeException('Webhook destination returned HTTP '.$response->status());
            }
        });
    }
}
