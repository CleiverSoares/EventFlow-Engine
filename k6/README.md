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

## Multi-tenant CRM exports (`exports-multi-tenant.js`)

Load lab for **fair multi-tenant exports**: a **whale** tenant requests a heavy
`commercial_dossier` (multi-table JOIN CSV) while **light** tenants keep
queueing smaller exports. The fair worker (`eventflow:process-exports`) must
start light jobs without waiting for the entire whale export to finish.

### Prerequisites

1. Migrate + seed CRM load:
   ```bash
   php artisan migrate
   php artisan eventflow:seed-crm-load --whale=2000 --light=100
   ```
2. `EVENTFLOW_EXPORT_MODE=async`
3. Keep the fair worker running (RabbitMQ wake + Postgres claim):
   ```bash
   php artisan eventflow:process-exports
   # or: docker compose up -d process-exports
   # poll-only fallback: php artisan eventflow:process-exports --poll
   ```
4. Demo API keys (created by the seed command):
   - whale → `ef_export_whale_key` (enterprise)
   - light A → `ef_export_light_a_key` (basic)
   - light B → `ef_export_light_b_key` (pro)

Wake path: `POST /api/exports` → `PENDING` → RabbitMQ `exports.requested` → worker → **`claimNextFair()`** in Postgres (plan caps). The message is a signal only — not FIFO “process this id”.

### Run (with Grafana)

```bash
k6 run \
  -o experimental-prometheus-rw \
  -e K6_PROMETHEUS_RW_SERVER_URL=http://127.0.0.1:9090/api/v1/write \
  -e BASE_URL=http://127.0.0.1:8000 \
  -e WHALE_RPS=1 \
  -e LIGHT_RPS=5 \
  -e DURATION=30s \
  k6/exports-multi-tenant.js
```

PowerShell:

```powershell
k6 run `
  -o experimental-prometheus-rw `
  -e K6_PROMETHEUS_RW_SERVER_URL=http://127.0.0.1:9090/api/v1/write `
  -e BASE_URL=http://127.0.0.1:8000 `
  -e WHALE_RPS=1 `
  -e LIGHT_RPS=5 `
  -e DURATION=30s `
  k6/exports-multi-tenant.js
```

Grafana dashboard: **EventFlow CRM Exports Fairness** (folder EventFlow).

### What to observe

1. HTTP `202` on `POST /api/exports` for both whale and light (accept is cheap)
2. Panel **Queue wait by plan** — `basic`/`pro` wait stays low while `enterprise` processes heavy JOIN
3. `GET /api/exports/{id}` → light tenants reach `completed` while whale is still `processing`
4. Optional: raise `--whale=10000` for a longer JOIN; keep `LIGHT_RPS` higher than `WHALE_RPS`

| Var | Default | Meaning |
|-----|---------|---------|
| `WHALE_KEY` / `LIGHT_A_KEY` / `LIGHT_B_KEY` | seed keys above | `X-Api-Key` |
| `WHALE_RPS` | `1` | Whale arrival rate |
| `LIGHT_RPS` | `5` | Combined light arrival rate |
| `DURATION` | `30s` | Scenario length |

---

## Results notes

Copy observations into `k6/results/` (markdown only). Do not commit huge JSON dumps.

- Phase 1 template: `k6/results/SAMPLE-phase1-notes.md`
- Phase 2 template (+ comparison table): `k6/results/SAMPLE-phase2-notes.md`
- Portfolio screenshots: [`phase1/`](../phase1/) · [`phase2/`](../phase2/) (linked from the root README)
