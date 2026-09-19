/**
 * EventFlow Engine — Phase 1 sync ingest load test (chaos baseline).
 *
 * Required env:
 *   BASE_URL   e.g. http://127.0.0.1:8000
 *   API_KEY    tenant api key (X-Api-Key)
 *
 * Optional env:
 *   TARGET_RPS   default 3000
 *   DURATION     default 60s
 *   PRE_VUS      default 100
 *   MAX_VUS      default 4000
 *   CNPJ         default 00000000000191 (BrasilAPI-friendly sample)
 *
 * Example:
 *   k6 run -e BASE_URL=http://127.0.0.1:8000 -e API_KEY=ef_demo_basic_key_change_me k6/phase1-ingest.js
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

export const options = {
  scenarios: {
    phase1_ingest_spike: {
      executor: 'constant-arrival-rate',
      rate: TARGET_RPS,
      timeUnit: '1s',
      duration: DURATION,
      preAllocatedVUs: PRE_VUS,
      maxVUs: MAX_VUS,
    },
  },
  thresholds: {
    // Phase 1 is expected to degrade — these are observational, not hard gates.
    http_req_failed: ['rate<0.95'],
    eventflow_errors: ['rate<0.95'],
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
    name: `k6-lead-${__VU}-${__ITER}`,
  });

  const response = http.post(`${data.baseUrl}/api/leads`, payload, {
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-Api-Key': data.apiKey,
    },
    timeout: '30s',
  });

  ingestDuration.add(response.timings.duration);

  const ok = check(response, {
    'status is 201 or degraded 5xx/429/0': (r) =>
      r.status === 201 || r.status === 502 || r.status === 429 || r.status === 500 || r.status === 0,
  });

  errorRate.add(!(response.status === 201));

  if (!ok) {
    sleep(0.01);
  }
}
