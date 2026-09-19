# EventFlow Engine

Multi-tenant platform for mass lead/event **ingest → enrich → dispatch**, built with Laravel.

This repo currently has the **foundation**: Docker (Postgres, Redis, RabbitMQ), `config/eventflow.php`, CSR skeleton, and smoke tests.

## Quick start

```bash
docker compose up -d
cp .env.example .env
php artisan key:generate
composer install
php artisan serve
php artisan test
```

## Branching

Work on feature/chore branches. Direct commits and pushes to `main` are blocked by local git hooks (`.githooks/`).
