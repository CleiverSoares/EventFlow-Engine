# Observability baseline

## Stack

```bash
docker compose up -d
php artisan serve
```

| Service | URL |
|---------|-----|
| Prometheus | http://localhost:9090 |
| Grafana | http://localhost:3000 (`admin` / `admin`) |
| Loki | http://localhost:3100 |
| Jaeger | http://localhost:16686 |
| App metrics | http://localhost:8000/api/metrics |

Prometheus scrapes `host.docker.internal:8000/api/metrics` every 5s.

## Useful PromQL

```promql
sum(rate(http_requests_total{route="api/leads"}[1m])) by (status)
histogram_quantile(0.95, sum(rate(http_request_duration_seconds_bucket{route="api/leads"}[1m])) by (le))
sum(rate(http_request_errors_total{route="api/leads"}[1m]))
```

## Grafana

Datasource Prometheus/Loki and the **EventFlow Phase 1 Ingest** dashboard are provisioned automatically under folder EventFlow.

## Notes

- Metrics are in-process (fine for local Phase 1 demos; not multi-worker durable storage).
- Loki/Jaeger are ready for later slices (log shipping / OTLP traces).
