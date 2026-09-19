/**
 * EventFlow — multi-tenant heavy CRM export load (fairness under contention).
 *
 * Prerequisites:
 *   php artisan migrate
 *   php artisan eventflow:seed-crm-load --whale=2000 --light=100
 *   php artisan eventflow:process-exports   # RabbitMQ wake + fair claim
 *   EVENTFLOW_EXPORT_MODE=async
 *
 * Scenario:
 *   - Whale tenant requests a huge commercial_dossier export (JOIN across CRM tables)
 *   - Light tenants keep requesting smaller exports in parallel
 *   - Fairness: light exports should start without waiting for the entire whale job
 *     (wake via RabbitMQ; who runs is decided in Postgres claim)
 *
 * Env:
 *   BASE_URL
 *   WHALE_KEY   default ef_export_whale_key
 *   LIGHT_A_KEY default ef_export_light_a_key
 *   LIGHT_B_KEY default ef_export_light_b_key
 *   DURATION    default 30s
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:8000';
const WHALE_KEY = __ENV.WHALE_KEY || 'ef_export_whale_key';
const LIGHT_A_KEY = __ENV.LIGHT_A_KEY || 'ef_export_light_a_key';
const LIGHT_B_KEY = __ENV.LIGHT_B_KEY || 'ef_export_light_b_key';
const DURATION = __ENV.DURATION || '30s';

const accepted = new Rate('eventflow_export_accepted');
const errors = new Rate('eventflow_export_errors');
const duration = new Trend('eventflow_export_request_duration', true);

export const options = {
  scenarios: {
    whale_exports: {
      executor: 'constant-arrival-rate',
      rate: Number(__ENV.WHALE_RPS || 1),
      timeUnit: '1s',
      duration: DURATION,
      preAllocatedVUs: 5,
      maxVUs: 20,
      exec: 'whaleExport',
    },
    light_exports: {
      executor: 'constant-arrival-rate',
      rate: Number(__ENV.LIGHT_RPS || 5),
      timeUnit: '1s',
      duration: DURATION,
      preAllocatedVUs: 20,
      maxVUs: 100,
      exec: 'lightExport',
      startTime: '2s',
    },
  },
  thresholds: {
    eventflow_export_accepted: ['rate>0.9'],
    eventflow_export_errors: ['rate<0.1'],
  },
};

function postExport(apiKey, tag) {
  const started = Date.now();
  const res = http.post(
    `${BASE_URL}/api/exports`,
    JSON.stringify({
      report: 'commercial_dossier',
      format: 'csv',
      filters: {},
    }),
    {
      headers: {
        'Content-Type': 'application/json',
        'X-Api-Key': apiKey,
        'Idempotency-Key': `${tag}-${__VU}-${__ITER}-${Date.now()}`,
      },
      tags: { tenant: tag },
    },
  );

  duration.add(Date.now() - started);
  const ok = check(res, {
    'accepted or completed': (r) => r.status === 202 || r.status === 200,
  });
  accepted.add(ok);
  errors.add(!ok);

  return res;
}

export function whaleExport() {
  postExport(WHALE_KEY, 'whale');
  sleep(1);
}

export function lightExport() {
  const key = Math.random() < 0.5 ? LIGHT_A_KEY : LIGHT_B_KEY;
  const tag = key === LIGHT_A_KEY ? 'light_a' : 'light_b';
  postExport(key, tag);
  sleep(0.2);
}
