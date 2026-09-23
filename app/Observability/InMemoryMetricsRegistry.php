<?php

namespace App\Observability;

use Illuminate\Support\Facades\Redis;
use Throwable;

class InMemoryMetricsRegistry
{
    private const COUNTERS_KEY = 'eventflow:metrics:counters';

    private const HISTOGRAMS_KEY = 'eventflow:metrics:histograms';

    /** @var list<float> */
    private array $histogramBuckets = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, 30, 60, 120, 300];

    /** @var array<string, float> */
    private array $counters = [];

    /**
     * @var array<string, array{count: float, sum: float, buckets: array<string, float>}>
     */
    private array $histograms = [];

    /**
     * @param  array<string, string|int|float>  $labels
     */
    public function incrementCounter(string $name, array $labels = [], float $value = 1.0): void
    {
        $field = $this->seriesKey($name, $labels);

        if ($this->usesRedis()) {
            try {
                Redis::hincrbyfloat(self::COUNTERS_KEY, $field, $value);
            } catch (Throwable) {
                // Demo fallback: ignore Redis blips so request path stays healthy.
            }

            return;
        }

        $this->counters[$field] = ($this->counters[$field] ?? 0.0) + $value;
    }

    /**
     * @param  array<string, string|int|float>  $labels
     */
    public function observeHistogram(string $name, float $valueSeconds, array $labels = []): void
    {
        $field = $this->seriesKey($name, $labels);

        if ($this->usesRedis()) {
            try {
                Redis::hincrbyfloat(self::HISTOGRAMS_KEY, $field.'|count', 1.0);
                Redis::hincrbyfloat(self::HISTOGRAMS_KEY, $field.'|sum', $valueSeconds);
                Redis::hincrbyfloat(self::HISTOGRAMS_KEY, $field.'|le:+Inf', 1.0);

                foreach ($this->histogramBuckets as $bound) {
                    if ($valueSeconds <= $bound) {
                        Redis::hincrbyfloat(self::HISTOGRAMS_KEY, $field.'|le:'.$bound, 1.0);
                    }
                }
            } catch (Throwable) {
                // Demo fallback: ignore Redis blips so request path stays healthy.
            }

            return;
        }

        if (! isset($this->histograms[$field])) {
            $buckets = [];
            foreach ($this->histogramBuckets as $bound) {
                $buckets[(string) $bound] = 0.0;
            }
            $buckets['+Inf'] = 0.0;

            $this->histograms[$field] = [
                'count' => 0.0,
                'sum' => 0.0,
                'buckets' => $buckets,
            ];
        }

        $this->histograms[$field]['count']++;
        $this->histograms[$field]['sum'] += $valueSeconds;
        $this->histograms[$field]['buckets']['+Inf']++;

        foreach ($this->histogramBuckets as $bound) {
            if ($valueSeconds <= $bound) {
                $this->histograms[$field]['buckets'][(string) $bound]++;
            }
        }
    }

    public function renderPrometheus(): string
    {
        $lines = [];

        $counters = $this->usesRedis() ? $this->readRedisCounters() : $this->counters;

        foreach ($counters as $key => $value) {
            [$name, $labelString] = $this->parseSeriesKey((string) $key);
            $lines[] = "# TYPE {$name} counter";
            $lines[] = $name.$labelString.' '.$this->formatNumber((float) $value);
        }

        $histograms = $this->usesRedis()
            ? $this->hydrateHistograms($this->readRedisHistogramFields())
            : $this->histograms;

        foreach ($histograms as $key => $histogram) {
            [$name, $labelString] = $this->parseSeriesKey($key);
            $lines[] = "# TYPE {$name} histogram";

            foreach ($histogram['buckets'] as $le => $count) {
                $bucketLabels = $this->mergeLabelString($labelString, 'le="'.$le.'"');
                $lines[] = $name.'_bucket'.$bucketLabels.' '.$this->formatNumber($count);
            }

            $lines[] = $name.'_sum'.$labelString.' '.$this->formatNumber($histogram['sum']);
            $lines[] = $name.'_count'.$labelString.' '.$this->formatNumber($histogram['count']);
        }

        return implode("\n", $lines).(count($lines) > 0 ? "\n" : '');
    }

    public function reset(): void
    {
        $this->counters = [];
        $this->histograms = [];

        if (! $this->usesRedis()) {
            return;
        }

        try {
            Redis::del(self::COUNTERS_KEY, self::HISTOGRAMS_KEY);
        } catch (Throwable) {
            //
        }
    }

    private function usesRedis(): bool
    {
        return ! app()->runningUnitTests();
    }

    /**
     * @return array<string, float>
     */
    private function readRedisCounters(): array
    {
        try {
            /** @var array<string, string> $counters */
            $counters = Redis::hgetall(self::COUNTERS_KEY) ?: [];
        } catch (Throwable) {
            return [];
        }

        $parsed = [];
        foreach ($counters as $key => $value) {
            $parsed[(string) $key] = (float) $value;
        }

        return $parsed;
    }

    /**
     * @return array<string, string>
     */
    private function readRedisHistogramFields(): array
    {
        try {
            /** @var array<string, string> $fields */
            return Redis::hgetall(self::HISTOGRAMS_KEY) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, array{count: float, sum: float, buckets: array<string, float>}>
     */
    private function hydrateHistograms(array $fields): array
    {
        $histograms = [];

        foreach ($fields as $field => $raw) {
            $field = (string) $field;
            $pos = strrpos($field, '|');
            if ($pos === false) {
                continue;
            }

            $series = substr($field, 0, $pos);
            $metric = substr($field, $pos + 1);
            $value = (float) $raw;

            if (! isset($histograms[$series])) {
                $buckets = [];
                foreach ($this->histogramBuckets as $bound) {
                    $buckets[(string) $bound] = 0.0;
                }
                $buckets['+Inf'] = 0.0;

                $histograms[$series] = [
                    'count' => 0.0,
                    'sum' => 0.0,
                    'buckets' => $buckets,
                ];
            }

            if ($metric === 'count') {
                $histograms[$series]['count'] = $value;
            } elseif ($metric === 'sum') {
                $histograms[$series]['sum'] = $value;
            } elseif (str_starts_with($metric, 'le:')) {
                $le = substr($metric, 3);
                $histograms[$series]['buckets'][$le] = $value;
            }
        }

        return $histograms;
    }

    /**
     * @param  array<string, string|int|float>  $labels
     */
    private function seriesKey(string $name, array $labels): string
    {
        ksort($labels);
        $parts = [];
        foreach ($labels as $key => $value) {
            $parts[] = $key.'='.$value;
        }

        return $name.'|'.implode(',', $parts);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseSeriesKey(string $key): array
    {
        [$name, $rawLabels] = array_pad(explode('|', $key, 2), 2, '');

        if ($rawLabels === '') {
            return [$name, ''];
        }

        $pairs = [];
        foreach (explode(',', $rawLabels) as $pair) {
            [$labelKey, $labelValue] = explode('=', $pair, 2);
            $pairs[] = $labelKey.'="'.$this->escapeLabel((string) $labelValue).'"';
        }

        return [$name, '{'.implode(',', $pairs).'}'];
    }

    private function mergeLabelString(string $labelString, string $extra): string
    {
        if ($labelString === '') {
            return '{'.$extra.'}';
        }

        return substr($labelString, 0, -1).','.$extra.'}';
    }

    private function escapeLabel(string $value): string
    {
        return str_replace(['\\', "\n", '"'], ['\\\\', '\\n', '\\"'], $value);
    }

    private function formatNumber(float $value): string
    {
        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') ?: '0';
    }
}
