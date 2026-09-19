# Phase 2 k6 — sample notes (template)

Date:
BASE_URL:
API_KEY used: (plan — prefer pro/enterprise)
TARGET_RPS:
DURATION:
Machine:
Workers running: relay [ ] consume [ ]

## Summary

- Peak RPS attempted:
- Approx accept rate (202):
- Rate-limit share (429), if any:
- p95 latency (k6 summary) — expect low vs Phase 1:
- Grafana Phase 2 pipeline observations (relay/consume):

## Comparison vs Phase 1 (`SAMPLE-phase1-notes.md`)

| Signal | Phase 1 (sync chaos) | Phase 2 (outbox) |
|--------|----------------------|------------------|
| HTTP success path | Mostly 201 or 5xx/timeouts | Mostly **202** |
| p95 latency under ~3k RPS | Spikes / multi-second | Stays low (API only writes outbox) |
| Error / timeout rate | High under peak | Low at ingest edge |
| Downstream work | Same request (enrich+webhook) | Workers (relay + consume) |

## Evidence checklist (Grafana screenshots for README After)

- [ ] **Ingest request rate by status** — dominated by 202
- [ ] **Ingest latency p95** — stable/low vs Phase 1 panel
- [ ] **Outbox relay rate** (`eventflow_outbox_relay_total`)
- [ ] **Consumer ack / retry / dlq** (`eventflow_leads_consumed_total`)
- [ ] Optional: RabbitMQ UI queue depth not exploding unboundedly
- [ ] Optional: Jaeger trace for one lead (ingest → relay → consume)

## k6 summary (paste)

```
(paste k6 end-of-run summary here)
```

## Notes for README After narrative

(1–3 sentences: same TARGET_RPS as Phase 1, API stays responsive because heavy work is async behind outbox + workers)
