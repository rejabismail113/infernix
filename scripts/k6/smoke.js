// k6 load smoke test for staging (NFR-05: p95 < 3 s under agreed load).
// Usage: k6 run -e BASE_URL=https://staging.example -e SESSION_COOKIE='infernix_one_session=...' scripts/k6/smoke.js
// Use a dedicated read-only test account; never point this at production with real users' sessions.
import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
    scenarios: { office: { executor: 'constant-vus', vus: Number(__ENV.VUS || 10), duration: __ENV.DURATION || '2m' } },
    thresholds: { http_req_duration: ['p(95)<3000'], http_req_failed: ['rate<0.01'] },
};

const BASE = __ENV.BASE_URL || 'http://localhost:8010';
const headers = { Cookie: __ENV.SESSION_COOKIE || '', Accept: 'text/html' };
const pages = ['/', '/finance/transactions', '/finance/claims', '/finance/bills', '/commercial/invoices', '/projects/projects', '/reports/projects', '/documents', '/health/ready'];

export default function () {
    for (const p of pages) {
        const res = http.get(`${BASE}${p}`, { headers, redirects: 0 });
        check(res, { [`${p} ok`]: (r) => r.status === 200 });
        sleep(0.5);
    }
}
