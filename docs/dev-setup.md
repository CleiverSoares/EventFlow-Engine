# Local development setup

## Prerequisites

- PHP 8.3+ (8.5 OK)
- Composer
- Docker Desktop (Compose)
- Optional: Redis PHP extension (`phpredis`) for cache/queue against Compose Redis

## 1. Start infrastructure

```bash
docker compose up -d
```

Wait until Postgres, Redis, and RabbitMQ are healthy:

```bash
docker compose ps
```

- Postgres: `localhost:5432` (`eventflow` / `eventflow` / db `eventflow`)
- Redis: `localhost:6379`
- RabbitMQ UI: http://localhost:15672 (`eventflow` / `eventflow`)

## 2. Application

```bash
cp .env.example .env
php artisan key:generate
composer install
php artisan migrate
php artisan serve
```

## 3. Tests

```bash
php artisan test
```

Tests use SQLite in-memory (see `phpunit.xml`); they do not require Docker.

## Mode

`EVENTFLOW_MODE=phase1|phase2` in `.env` (see `config/eventflow.php`).

## Specs

Implementation checklist (local, not in git): `.cursor/specs/eventflow-engine/tasks.md`
