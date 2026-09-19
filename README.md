# EventFlow Engine

[![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)](https://laravel.com/)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-4169E1?logo=postgresql&logoColor=white)](https://www.postgresql.org/)
[![Redis](https://img.shields.io/badge/Redis-7-DC382D?logo=redis&logoColor=white)](https://redis.io/)
[![RabbitMQ](https://img.shields.io/badge/RabbitMQ-3.13-FF6600?logo=rabbitmq&logoColor=white)](https://www.rabbitmq.com/)
[![k6](https://img.shields.io/badge/k6-load-7D64FF?logo=k6&logoColor=white)](https://k6.io/)

Multi-tenant **ingest → enrich → dispatch** platform for mass leads/events.

This repository tells a deliberate **Before vs After** story: the same Laravel app, switched by `EVENTFLOW_MODE`, proves why a synchronous path collapses under ~3,000 RPS — and how a transactional outbox + RabbitMQ + Redis architecture stays healthy under the same load.

| Mode | Env | HTTP behavior |
|------|-----|----------------|
| **Phase 1 — Before** | `EVENTFLOW_MODE=phase1` | Validate → enrich → webhook → audit **in one request** |
| **Phase 2 — After** | `EVENTFLOW_MODE=phase2` | Validate → rate limit → **outbox** → **202**; workers do the heavy work |

---

## Architecture

```mermaid
flowchart LR
  subgraph phase1 [Phase 1 Sync Chaos]
    K6a[k6] --> APIs[Laravel API]
    APIs --> Enr1[Enrich sync]
    Enr1 --> Wh1[Webhook]
  end

  subgraph phase2 [Phase 2 Distributed]
    K6b[k6] --> APId[Laravel API]
    APId --> RL[Redis Rate Limit]
    APId --> OB[(outbox_events)]
    Relay[Outbox Relay] --> OB
    Relay --> RQ[RabbitMQ]
    RQ --> W[Workers]
    W --> Cache[Redis Cache]
    W --> Lock[Redis Lock]
    W --> Geo[Redis Geo]
    W --> CB[Circuit Breaker]
    CB --> Ext[BrasilAPI / Webhook]
    W --> AUD[(audit_logs)]
  end

  Obs[OTel / Prom / Grafana / Loki / Jaeger] -.-> APId
  Obs -.-> W
```

### Layering (CSR)

`Controller → Service → Repository → Model` — queries never live in controllers, jobs, or HTTP handlers.

### Stack

| Concern | Choice |
|---------|--------|
| API | Laravel 13 (PHP 8.5) |
| DB | PostgreSQL (UUID + JSONB) |
| Broker | RabbitMQ (main + retry TTL + DLQ) |
| Redis ×4 | Cache Aside · Distributed Lock · Rate Limiter · Geo |
| Enrichment | BrasilAPI CNPJ (config-driven) |
| Resilience | Circuit breaker around outbound HTTP |
| Observability | Prometheus · Grafana · Loki · Jaeger (OTLP) |
| Load | k6 (`k6/phase1-ingest.js`, `k6/phase2-ingest.js`) |

---

## Before — Phase 1 (sync chaos)

**Idea:** One HTTP request does everything. Great for a demo until traffic arrives.

```
POST /api/leads → auth → enrich (BrasilAPI) → webhook → audit_logs → 201
```

Under aggressive k6 (~3k RPS):

- Latency p95 climbs; timeouts and 5xx appear
- PHP workers and DB connections saturate
- Cascading failures when enrichment/webhook slow down

### Evidence (Phase 1)

| Artifact | Location |
|----------|----------|
| k6 script | [`k6/phase1-ingest.js`](k6/phase1-ingest.js) |
| Runbook | [`k6/README.md`](k6/README.md) |
| Notes template | [`k6/results/SAMPLE-phase1-notes.md`](k6/results/SAMPLE-phase1-notes.md) |
| Grafana dashboard | **EventFlow Phase 1 Ingest** (provisioned) |

**Screenshot checklist (paste into notes / this README):**

1. Grafana — ingest latency p95 unstable  
2. Grafana — request rate by status (5xx rising)  
3. Optional — Prometheus query panel for `http_request_errors_total`

> Replace the placeholders below with your local run screenshots after executing k6 Phase 1 (keep PNGs under `k6/results/` or link externally — do not commit huge binaries).

```text
k6/results/phase1-latency.png   ← add after your run
k6/results/phase1-errors.png
```

---

## After — Phase 2 (distributed)

**Idea:** The API only accepts work safely. Workers own enrichment and dispatch.

```
POST /api/leads → auth → Redis rate limit → outbox_events (same DB txn) → 202
                      ↓
            eventflow:relay-outbox → RabbitMQ
                      ↓
            eventflow:consume-leads
                 lock → cache/geo → CB → webhook → audit
```

### Why Outbox (dual-write)

Publishing to RabbitMQ *inside* the HTTP request alone can lose messages if the broker ack succeeds and the DB fails (or the reverse). Phase 2 writes the raw payload to `outbox_events` in the **same SQL transaction** as the accept path; a relay worker publishes asynchronously and marks rows processed/failed with `attempts`.

### Redis — four separate roles

| Role | Class / prefix | Purpose |
|------|----------------|---------|
| Cache Aside | `CacheAsideEnrichmentStore` | CNPJ enrichment TTL |
| Lock | `LeadProcessingLock` | No duplicate concurrent processing |
| Rate limit | `CacheTenantRateLimiter` | Per-tenant plan ceilings |
| Geo | `LeadGeoIndex` | `GEOADD` / radius when `lat`/`lng` present |

### Resilience

- RabbitMQ **retry** queue (TTL → main) and **DLQ** for poison messages  
- **Circuit breaker** (closed / open / half-open) on webhook + enrichment HTTP  
- W3C **traceparent** propagated HTTP → outbox → AMQP → consumer → Jaeger (OTLP)

### Evidence (Phase 2)

| Artifact | Location |
|----------|----------|
| k6 script | [`k6/phase2-ingest.js`](k6/phase2-ingest.js) |
| Comparison notes | [`k6/results/SAMPLE-phase2-notes.md`](k6/results/SAMPLE-phase2-notes.md) |
| Grafana dashboard | **EventFlow Phase 2 Pipeline** |
| Tracing runbook | [`observability/README.md`](observability/README.md) |

**Screenshot checklist:**

1. Ingest status dominated by **202**, low p95  
2. Outbox relay + consumer ack/retry/dlq panels  
3. Jaeger — one Trace ID: `http.request` → `outbox.relay` → `lead.consume`

```text
k6/results/phase2-latency.png
k6/results/phase2-relay-consume.png
k6/results/phase2-jaeger-trace.png
```

---

## Quick start

### 1. Infrastructure

```bash
docker compose up -d
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
```

Demo API keys (after seed) are commented in `.env.example`.

### 2. Observability

| Service | URL |
|---------|-----|
| Grafana | http://localhost:3000 (`admin` / `admin`) |
| Prometheus | http://localhost:9090 |
| Jaeger | http://localhost:16686 |
| RabbitMQ UI | http://localhost:15672 (`eventflow` / `eventflow`) |
| App metrics | http://localhost:8000/api/metrics |

Details: [`observability/README.md`](observability/README.md).

### 3. Phase 1 (Before)

```bash
# .env → EVENTFLOW_MODE=phase1
php artisan serve
k6 run -e BASE_URL=http://127.0.0.1:8000 -e API_KEY=ef_demo_basic_key_change_me k6/phase1-ingest.js
```

### 4. Phase 2 (After)

```bash
# .env → EVENTFLOW_MODE=phase2
php artisan serve
php artisan eventflow:relay-outbox
php artisan eventflow:consume-leads

k6 run -e BASE_URL=http://127.0.0.1:8000 -e API_KEY=ef_demo_pro_key_change_me k6/phase2-ingest.js
```

### 5. Tests

```bash
php artisan test
```

---

## API sketch

```http
POST /api/leads
X-Api-Key: <tenant api_key>
Content-Type: application/json

{ "cnpj": "00000000000191", "name": "Acme", "lat": -23.55, "lng": -46.63 }
```

- Phase 1 → `201` + `audit_id`  
- Phase 2 → `202` + `outbox_id`  
- Invalid key → `401` · validation → `422` · rate limit (phase2) → `429`

---

## Domain tables

- `tenants` — UUID, `api_key` (indexed), plan `basic` \| `pro` \| `enterprise`  
- `outbox_events` — JSONB payload, status `PENDING` \| `PROCESSING` \| `PROCESSED` \| `FAILED`, `attempts`  
- `audit_logs` — enriched JSONB, status `SUCCESS` \| `DISPATCHED` \| `ERROR`

---

## Branching

Work on feature branches. Local git hooks (`.githooks/`) block direct commits/pushes to `main`.

---

## Specs (local only)

Implementation was driven by Kiro-style specs under `.cursor/specs/eventflow-engine/` (requirements, design, tasks). That folder is **gitignored** — clone the repo for the running system; use local Cursor rules/specs if you extend the product.

---

## License

MIT (or project default). Portfolio / learning project — not a production SaaS.
