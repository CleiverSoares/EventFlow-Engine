# EventFlow Engine

Multi-tenant platform for mass lead/event **ingest → enrich → dispatch**, built with Laravel.

Supports two runtime modes via `EVENTFLOW_MODE`:

- **phase1** — sync ingest/enrich/dispatch in the HTTP request (chaos baseline)
- **phase2** — transactional outbox + RabbitMQ workers (distributed path)

## Quick start

```bash
docker compose up -d
cp .env.example .env
php artisan key:generate
composer install
php artisan migrate --seed
php artisan serve
php artisan test
```

## Phase 2 workers

With `EVENTFLOW_MODE=phase2`, the API only validates, rate-limits, and writes `outbox_events` (HTTP 202). Run these alongside the app:

```bash
# Publish PENDING outbox rows to RabbitMQ
php artisan eventflow:relay-outbox

# Consume leads.incoming → lock → enrich → geo → webhook → audit
php artisan eventflow:consume-leads
```

Poison messages go to the retry queue (TTL then back to main) or DLQ after `EVENTFLOW_OUTBOX_MAX_ATTEMPTS`.

## Branching

Work on feature/chore branches. Direct commits and pushes to `main` are blocked by local git hooks (`.githooks/`).
