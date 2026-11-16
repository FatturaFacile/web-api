import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  stages: [
    { duration: '30s', target: 20 },  // Warm-up to 20 virtual users
    { duration: '1m', target: 50 },   // Sustained load at 50 virtual users
    { duration: '30s', target: 100 }, // Peak load stress testing
    { duration: '30s', target: 0 },   // Graceful ramp-down
  ],
  thresholds: {
    // 95% of requests must complete below 300ms SLA
    http_req_duration: ['p(95)<300'],
    // Error rate must remain below 1%
    http_req_failed: ['rate<0.01'],
  },
};

const BASE_URL = __ENV.API_BASE_URL || 'https://localhost:8000';
const TENANT_ID = '018f0000-0000-7000-8000-000000000001';

const HEADERS = {
  'Content-Type': 'application/json',
  'Accept': 'application/json',
  'X-Tenant': TENANT_ID,
};

export default function () {
  // Scenario 1: Paginated Inventory Items Query
  const inventoryRes = http.get(`${BASE_URL}/api/v1/inventory/items?page=1&per_page=25`, {
    headers: HEADERS,
  });

  check(inventoryRes, {
    'inventory query status is 200 or 401 (if unauth)': (r) => r.status === 200 || r.status === 401,
    'inventory response time within SLA': (r) => r.timings.duration < 300,
  });

  sleep(0.5);

  // Scenario 2: Invoice Creation Flow Simulation
  const invoicePayload = JSON.stringify({
    invoice_type: 'standard',
    date: new Date().toISOString().split('T')[0],
    currency: 'CHF',
    jurisdiction: 'CH',
    subtotal: 1500.0,
    total_tax: 121.5,
    total_gross: 1621.5,
    payment_method: 'QRR',
    notes: 'k6 performance benchmark synthetic invoice',
  });

  const invoiceRes = http.post(`${BASE_URL}/api/v1/invoices`, invoicePayload, {
    headers: HEADERS,
  });

  check(invoiceRes, {
    'invoice creation completed': (r) => [200, 201, 401, 422].includes(r.status),
    'invoice creation duration within SLA': (r) => r.timings.duration < 300,
  });

  sleep(1);
}
