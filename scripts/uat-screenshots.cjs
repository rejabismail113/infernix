// UAT evidence screenshots (docs/UAT_EVIDENCE.md): signs in as the right demo user for each scenario seeded by
// UatDemoSeeder and saves docs/uat/<id>.png. Dev / UAT only.
// Usage: php artisan db:seed --class=UatDemoSeeder && node scripts/uat-screenshots.cjs [baseUrl]
const { chromium } = require('playwright');
const path = require('path');
const { execSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const BASE = process.argv[2] || 'http://127.0.0.1:8010';
const OUT = path.join(ROOT, 'docs', 'uat');
const ids = JSON.parse(execSync('php scripts/uat-ids.php', { cwd: ROOT }).toString());
const OWNER = ['owner@infernix.local', process.env.SEED_OWNER_PASSWORD || 'ChangeMe-Infernix-2026!'];
const demo = (k) => [`demo.${k}@infernix.local`, process.env.SEED_DEMO_PASSWORD || 'Demo-Infernix-2026!'];

const SHOTS = [
    { id: 'UAT-01-staff-opens-other-claim-403', as: demo('stf2'), url: `/finance/claims/${ids.claim}`, expect: 403 },
    { id: 'UAT-02-mileage-claim-paid', as: OWNER, url: `/finance/claims/${ids.claim}` },
    { id: 'UAT-03-laptop-asset-from-purchase', as: OWNER, url: `/assets/${ids.asset}` },
    { id: 'UAT-04-invoice-partially-paid', as: OWNER, url: `/commercial/invoices/${ids.invoice}` },
    { id: 'UAT-05-trial-balance-balanced', as: OWNER, url: '/finance/reports/trial-balance' },
    { id: 'UAT-06-project-from-quotation', as: OWNER, url: `/projects/${ids.project}` },
    { id: 'UAT-07-timesheet-assigned-projects-only', as: demo('stf1'), url: `/hr/timesheets/${ids.timesheet}` },
    { id: 'UAT-08-credential-metadata-only', as: OWNER, url: `/credentials/${ids.credential}` },
    { id: 'UAT-09-locked-period-transaction', as: OWNER, url: `/finance/transactions/${ids.january_txn}` },
    { id: 'UAT-09-fiscal-periods-locked', as: OWNER, url: '/admin/fiscal-periods' },
    { id: 'UAT-10-direct-file-url-403', as: demo('stf2'), url: `/documents/${ids.invoice_pdf_doc}/download`, expect: 403 },
    { id: 'UAT-11-maker-cannot-approve-own-payment', as: demo('fin1'), url: `/finance/payments/${ids.pending_payment}` },
    { id: 'UAT-12-subscription-renewal-no-finance-entry', as: OWNER, url: '/procurement/subscriptions' },
    { id: 'P4-bank-reconciliation-proof', as: OWNER, url: `/finance/bank/${ids.bank}/reconciliation` },
    { id: 'P4-einvoice-banner', as: OWNER, url: '/commercial/einvoice' },
    { id: 'P4-backup-status', as: OWNER, url: '/admin/backups' },
    { id: 'P4-owner-dashboard', as: OWNER, url: '/' },
];

// Wait for the next 30 s TOTP window so a code is never replayed (the app refuses reuse).
const nextWindow = () => new Promise((r) => setTimeout(r, (30 - (Math.floor(Date.now() / 1000) % 30)) * 1000 + 500));
const otp = () => execSync(`php -r 'require "vendor/autoload.php"; echo (new PragmaRX\\Google2FA\\Google2FA)->getCurrentOtp("JBSWY3DPEHPK3PXP");'`, { cwd: ROOT }).toString().trim();

(async () => {
    const browser = await chromium.launch();
    const sessions = new Map();
    const pageFor = async ([email, password]) => {
        if (sessions.has(email)) return sessions.get(email);
        const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 }, deviceScaleFactor: 2 });
        const page = await ctx.newPage();
        await page.goto(`${BASE}/login`);
        await page.fill('input[type=email]', email);
        await page.fill('input[type=password]', password);
        await page.click('button[type=submit]');
        await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15000 });
        if (page.url().includes('/mfa')) {
            await nextWindow();
            await page.fill('input[autocomplete=one-time-code], input[name=code], input#code', otp());
            await page.click('button[type=submit]');
            await page.waitForURL((u) => !u.pathname.startsWith('/mfa'), { timeout: 15000 }); // Inertia navigates client-side
        }
        if (page.url().includes('/login') || page.url().includes('/mfa')) throw new Error(`login failed for ${email}: ${page.url()}`);
        sessions.set(email, page);
        return page;
    };
    let failures = 0;
    for (const s of SHOTS) {
        const page = await pageFor(s.as);
        const res = await page.goto(BASE + s.url);
        await page.waitForLoadState('networkidle');
        const status = res ? res.status() : 0;
        const ok = s.expect ? status === s.expect : status === 200;
        if (!ok) failures++;
        await page.screenshot({ path: path.join(OUT, `${s.id}.png`), fullPage: false });
        console.log(`${ok ? 'ok ' : 'BAD'} ${status} ${s.id}  (${s.as[0]} ${s.url})`);
    }
    await browser.close();
    process.exit(failures ? 1 : 0);
})();
