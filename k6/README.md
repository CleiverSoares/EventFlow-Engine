# k6 — EventFlow load evidence

## Phase 1 — chaos baseline (`phase1-ingest.js`)

Drive ~3,000 RPS against `POST /api/leads` in **phase1** mode to show collapse
(latency spikes, timeouts, 5xx) for the README Before narrative.

### Prerequisites

1. App running (`php artisan serve`) with `EVENTFLOW_MODE=phase1`
2. DB migrated + seeded (`php artisan migrate --seed`)
3. Observability stack up (`docker compose up -d`)
4. [k6](https://grafana.com/docs/k6/latest/set-up/install-k6/) installed
5. Webhook reachable **or** expect many 502s (still useful collapse evidence)

### Run

PowerShell (sends k6 metrics to Prometheus → Grafana “k6 client fail ratio”):

```powershell
k6 run `
  -o experimental-prometheus-rw `
  -e K6_PROMETHEUS_RW_SERVER_URL=http://127.0.0.1:9090/api/v1/write `
  -e BASE_URL=http://127.0.0.1:8000 `
  -e API_KEY=ef_demo_basic_key_change_me `
  -e TARGET_RPS=1000 `
  -e DURATION=30s `
  -e PRE_VUS=200 `
  -e MAX_VUS=2000 `
  k6/phase1-ingest.js
```

Cmd.exe:

```bash
k6 run ^
  -o experimental-prometheus-rw ^
  -e K6_PROMETHEUS_RW_SERVER_URL=http://127.0.0.1:9090/api/v1/write ^
  -e BASE_URL=http://127.0.0.1:8000 ^
  -e API_KEY=ef_demo_basic_key_change_me ^
  -e TARGET_RPS=3000 ^
  -e DURATION=60s ^
  k6/phase1-ingest.js
```

Prometheus needs `--web.enable-remote-write-receiver` (already in `docker-compose.yml`).

### What to screenshot (Grafana — Phase 1)

1. **k6 client fail ratio** climbing under load (timeouts / client errors)
2. **Ingest latency p95** climbing / unstable
3. **Ingest request rate by status (app)** — few 201s vs intended RPS
4. Optional — terminal k6 summary (~99% `http_req_failed`)

---

## Phase 2 — stable outbox path (`phase2-ingest.js`)

Same arrival-rate profile against **phase2** ingest. Expect **HTTP 202** quickly;
enrichment/dispatch happen in workers (not in the request).

### Prerequisites

1. `EVENTFLOW_MODE=phase2`
2. API + **both workers** running:
   ```bash
   php artisan serve
   php artisan eventflow:relay-outbox
   php artisan eventflow:consume-leads
   ```
3. Prefer a higher plan key (`ef_demo_pro_key_change_me` / enterprise) so Redis rate limit does not dominate the story
4. Observability up — use dashboard **EventFlow Phase 2 Pipeline**

### Run

```bash
k6 run ^
  -e BASE_URL=http://127.0.0.1:8000 ^
  -e API_KEY=ef_demo_pro_key_change_me ^
  -e TARGET_RPS=3000 ^
  -e DURATION=60s ^
  k6/phase2-ingest.js
```

Bash:

```bash
k6 run \
  -e BASE_URL=http://127.0.0.1:8000 \
  -e API_KEY=ef_demo_pro_key_change_me \
  -e TARGET_RPS=3000 \
  -e DURATION=60s \
  k6/phase2-ingest.js
```

### Env vars (both scripts)

| Var | Required | Default | Meaning |
|-----|----------|---------|---------|
| `BASE_URL` | no | `http://127.0.0.1:8000` | App base |
| `API_KEY` | **yes** | — | Tenant `X-Api-Key` |
| `TARGET_RPS` | no | `3000` | Arrival rate |
| `DURATION` | no | `60s` | Scenario length |
| `PRE_VUS` / `MAX_VUS` | no | `100` / `4000` | VU pool |
| `CNPJ` | no | `00000000000191` | Payload CNPJ |

### What to screenshot (Grafana — Phase 2)

1. **Ingest request rate by status** — mostly 202
2. **Ingest latency p95** — stable vs Phase 1
3. **Outbox relay rate** and **Consumer ack/retry/dlq**
4. Optional: RabbitMQ queue depth; Jaeger one-lead trace

---

## Results notes

Copy observations into `k6/results/` (markdown only). Do not commit huge JSON dumps.

- Phase 1 template: `k6/results/SAMPLE-phase1-notes.md`
- Phase 2 template (+ comparison table): `k6/results/SAMPLE-phase2-notes.md`
- Portfolio screenshots: [`phase1/`](../phase1/) · [`phase2/`](../phase2/) (linked from the root README)
