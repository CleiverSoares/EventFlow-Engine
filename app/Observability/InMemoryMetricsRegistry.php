<?php

namespace App\Observability;

class InMemoryMetricsRegistry
{
    /** @var array<string, float> */
    private array $counters = [];

    /** @var array<string, array{count: float, sum: float, buckets: array<string, float>}> */
    private array $histograms = [];

    /** @var list<float> */
    private array $histogramBuckets = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10];

    /**
     * @param  array<string, string|int|float>  $labels
     */
    public function incrementCounter(string $name, array $labels = [], float $value = 1.0): void
    {
        $key = $this->seriesKey($name, $labels);
        $this->counters[$key] = ($this->counters[$key] ?? 0.0) + $value;
    }

    /**
     * @param  array<string, string|int|float>  $labels
     */
    public function observeHistogram(string $name, float $valueSeconds, array $labels = []): void
    {
        $key = $this->seriesKey($name, $labels);

        if (! isset($this->histograms[$key])) {
            $buckets = [];
            foreach ($this->histogramBuckets as $bound) {
                $buckets[(string) $bound] = 0.0;
            }
            $buckets['+Inf'] = 0.0;

            $this->histograms[$key] = [
                'count' => 0.0,
                'sum' => 0.0,
                'buckets' => $buckets,
            ];
        }

        $this->histograms[$key]['count'] += 1.0;
        $this->histograms[$key]['sum'] += $valueSeconds;

        foreach ($this->histogramBuckets as $bound) {
            if ($valueSeconds <= $bound) {
                $this->histograms[$key]['buckets'][(string) $bound] += 1.0;
            }
        }

        $this->histograms[$key]['buckets']['+Inf'] += 1.0;
    }

    public function renderPrometheus(): string
    {
        $lines = [];

        foreach ($this->counters as $key => $value) {
            [$name, $labelString] = $this->parseSeriesKey($key);
            $lines[] = "# TYPE {$name} counter";
            $lines[] = $name.$labelString.' '.$this->formatNumber($value);
        }

        foreach ($this->histograms as $key => $histogram) {
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
