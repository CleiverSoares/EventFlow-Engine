# k6 — Phase 1 load (chaos baseline)

## Goal

Drive ~3,000 RPS against `POST /api/leads` in **phase1** mode to show collapse
(latency spikes, timeouts, 5xx) for the README Before narrative.

## Prerequisites

1. App running (`php artisan serve`) with `EVENTFLOW_MODE=phase1`
2. DB migrated + seeded (`php artisan migrate --seed`)
3. Observability stack up (`docker compose up -d`) — Grafana/Prometheus optional but recommended
4. [k6](https://grafana.com/docs/k6/latest/set-up/install-k6/) installed locally
5. Webhook destination reachable **or** expect many 502s (still useful collapse evidence)

## Run

```bash
k6 run ^
  -e BASE_URL=http://127.0.0.1:8000 ^
  -e API_KEY=ef_demo_basic_key_change_me ^
  -e TARGET_RPS=3000 ^
  -e DURATION=60s ^
  k6/phase1-ingest.js
```

Bash:

```bash
k6 run \
  -e BASE_URL=http://127.0.0.1:8000 \
  -e API_KEY=ef_demo_basic_key_change_me \
  -e TARGET_RPS=3000 \
  -e DURATION=60s \
  k6/phase1-ingest.js
```

### Env vars (documented in `phase1-ingest.js`)

| Var | Required | Default | Meaning |
|-----|----------|---------|---------|
| `BASE_URL` | no | `http://127.0.0.1:8000` | App base |
| `API_KEY` | **yes** | — | Tenant `X-Api-Key` |
| `TARGET_RPS` | no | `3000` | Arrival rate |
| `DURATION` | no | `60s` | Scenario length |
| `PRE_VUS` / `MAX_VUS` | no | `100` / `4000` | VU pool |
| `CNPJ` | no | `00000000000191` | Payload CNPJ |

## What to screenshot (Grafana)

1. **Ingest latency p95** climbing / unstable
2. **Ingest request rate by status** showing 5xx / failures
3. **Ingest error rate** rising under the same TARGET_RPS

Prometheus UI: http://localhost:9090 — query `http_request_duration_seconds` / `http_requests_total{route="api/leads"}`.

## Results notes

Copy observations into `k6/results/` (markdown only). Do not commit huge JSON dumps.
See `k6/results/SAMPLE-phase1-notes.md` for the template.
