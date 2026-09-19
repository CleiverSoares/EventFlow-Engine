# Observability

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

## Phase 2 tracing runbook (Jaeger E2E)

1. Set `EVENTFLOW_MODE=phase2` and ensure `EVENTFLOW_TRACING_ENABLED=true`.
2. Start API + workers:
   ```bash
   php artisan serve
   php artisan eventflow:relay-outbox
   php artisan eventflow:consume-leads
   ```
3. Ingest a lead (`POST /api/leads` with `X-Api-Key`). Response includes `X-Trace-Id`.
4. Open Jaeger → service `eventflow-engine` → find the Trace ID.
5. Expected span chain: `http.request` → `outbox.relay` → `lead.consume` (same `trace_id`).

Trace context uses W3C `traceparent` on HTTP and RabbitMQ message headers. Spans export via OTLP HTTP to Jaeger (`EVENTFLOW_OTLP_ENDPOINT`, default `http://127.0.0.1:4318/v1/traces`).

Logs use Laravel `Log::withContext` with `trace_id`, `span_id`, and `tenant_id` when available (ready for Promtail → Loki).

## Useful PromQL

```promql
sum(rate(http_requests_total{route="api/leads"}[1m])) by (status)
histogram_quantile(0.95, sum(rate(http_request_duration_seconds_bucket{route="api/leads"}[1m])) by (le))
sum(rate(http_request_errors_total{route="api/leads"}[1m]))
sum(rate(eventflow_outbox_relay_total[1m])) by (result)
sum(rate(eventflow_leads_consumed_total[1m])) by (result)
```

## Grafana

Provisioned dashboards under folder **EventFlow**:

- **EventFlow Phase 1 Ingest** — sync chaos baseline
- **EventFlow Phase 2 Pipeline** — ingest + outbox relay + consumer outcomes
- **EventFlow CRM Exports Fairness** — multi-tenant export accept/process + queue wait by plan

```promql
sum(rate(http_requests_total{route="api/exports"}[1m])) by (status)
sum(rate(eventflow_exports_processed_total[1m])) by (plan, result)
histogram_quantile(0.95, sum(rate(eventflow_export_queue_wait_seconds_bucket[1m])) by (le, plan))
```

k6 → Prometheus (exports):

```bash
k6 run -o experimental-prometheus-rw \
  -e K6_PROMETHEUS_RW_SERVER_URL=http://127.0.0.1:9090/api/v1/write \
  -e BASE_URL=http://127.0.0.1:8000 \
  k6/exports-multi-tenant.js
```

## Notes

- App counters/histograms are stored in Redis (shared across Octane workers).
- Disable OTLP in tests via `EVENTFLOW_TRACING_ENABLED=false`.
- `/api/metrics` requires `EVENTFLOW_METRICS_TOKEN` (Prometheus scrape uses the same default as `observability/prometheus/prometheus.yml`).