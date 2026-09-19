/**
 * EventFlow Engine — Phase 2 outbox ingest load test (stable path).
 *
 * Expects HTTP 202 (outbox accept). Workers process asynchronously.
 *
 * Required env:
 *   BASE_URL   e.g. http://127.0.0.1:8000
 *   API_KEY    tenant api key (X-Api-Key) — prefer pro/enterprise for higher rate limit
 *
 * Optional env:
 *   TARGET_RPS   default 3000
 *   DURATION     default 60s
 *   PRE_VUS      default 100
 *   MAX_VUS      default 4000
 *   CNPJ         default 00000000000191
 *
 * Prerequisites (must be running alongside the API):
 *   EVENTFLOW_MODE=phase2
 *   php artisan eventflow:relay-outbox
 *   php artisan eventflow:consume-leads
 *
 * Example:
 *   k6 run -e BASE_URL=http://127.0.0.1:8000 -e API_KEY=ef_demo_pro_key_change_me k6/phase2-ingest.js
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:8000';
const API_KEY = __ENV.API_KEY || '';
const TARGET_RPS = Number(__ENV.TARGET_RPS || 3000);
const DURATION = __ENV.DURATION || '60s';
const PRE_VUS = Number(__ENV.PRE_VUS || 100);
const MAX_VUS = Number(__ENV.MAX_VUS || 4000);
const CNPJ = __ENV.CNPJ || '00000000000191';

const errorRate = new Rate('eventflow_errors');
const ingestDuration = new Trend('eventflow_ingest_duration', true);
const acceptedRate = new Rate('eventflow_accepted_202');

export const options = {
  scenarios: {
    phase2_ingest_stable: {
      executor: 'constant-arrival-rate',
      rate: TARGET_RPS,
      timeUnit: '1s',
      duration: DURATION,
      preAllocatedVUs: PRE_VUS,
      maxVUs: MAX_VUS,
    },
  },
  thresholds: {
    // Phase 2 ingest should stay healthy at the API edge (202).
    http_req_failed: ['rate<0.1'],
    eventflow_errors: ['rate<0.1'],
    eventflow_accepted_202: ['rate>0.9'],
  },
};

export function setup() {
  if (!API_KEY) {
    throw new Error('API_KEY env is required (tenant X-Api-Key).');
  }

  const ping = http.get(`${BASE_URL}/api/ping`, {
    headers: { 'X-Api-Key': API_KEY },
  });

  if (ping.status !== 200) {
    throw new Error(`Auth ping failed with status ${ping.status}. Seed tenants and check API_KEY.`);
  }

  return { baseUrl: BASE_URL, apiKey: API_KEY, cnpj: CNPJ };
}

export default function (data) {
  const payload = JSON.stringify({
    cnpj: data.cnpj,
    name: `k6-phase2-${__VU}-${__ITER}`,
  });

  const response = http.post(`${data.baseUrl}/api/leads`, payload, {
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-Api-Key': data.apiKey,
    },
    timeout: '10s',
  });

  ingestDuration.add(response.timings.duration);

  const accepted = response.status === 202;
  acceptedRate.add(accepted);
  errorRate.add(!accepted && response.status !== 429);

  check(response, {
    'status is 202 (outbox accepted) or 429 (plan ceiling)': (r) =>
      r.status === 202 || r.status === 429,
  });

  if (!accepted) {
    sleep(0.01);
  }
}
