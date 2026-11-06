// Accessibility pass (NFR-09): logs in, opens the core forms and runs axe-core (WCAG 2.1 A/AA) in Chromium.
// Usage: node scripts/a11y-check.cjs [baseUrl]   (dev: owner with MFA secret JBSWY3DPEHPK3PXP)
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const BASE = process.argv[2] || 'http://127.0.0.1:8010';
const PAGES = ['/login', '/', '/finance/claims/create', '/finance/transactions/create', '/finance/bills/create', '/commercial/invoices/create', '/assets', '/procurement/requests', '/credentials', '/admin/users', '/reports/exports'];
const axeSource = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');

(async () => {
    const browser = await chromium.launch();
    const page = await (await browser.newContext({ bypassCSP: true })).newPage(); // the app's CSP correctly blocks injected scripts
    const results = [];
    const audit = async (url) => {
        await page.addScriptTag({ content: axeSource });
        const r = await page.evaluate(async () => (await window.axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] })).violations
            .map((v) => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.length, target: v.nodes.map((n) => n.target.join(' ') + ' [' + (n.any[0]?.data ? n.any[0].data.fgColor + '/' + n.any[0].data.bgColor + ' ' + n.any[0].data.contrastRatio : '') + ']').join(' | ') })));
        results.push({ url, violations: r });
    };
    await page.goto(`${BASE}/login`);
    await audit('/login');
    await page.fill('input[type=email]', process.env.A11Y_EMAIL || 'owner@infernix.local');
    await page.fill('input[type=password]', process.env.A11Y_PASSWORD || 'ChangeMe-Infernix-2026!');
    await page.click('button[type=submit]');
    await page.waitForLoadState('networkidle');
    if (page.url().includes('/mfa')) {
        const code = execSync(`php -r 'require "vendor/autoload.php"; echo (new PragmaRX\\Google2FA\\Google2FA)->getCurrentOtp("JBSWY3DPEHPK3PXP");'`, { cwd: path.resolve(__dirname, '..') }).toString().trim();
        await page.fill('input[autocomplete=one-time-code], input[name=code], input#code', code);
        await page.click('button[type=submit]');
        await page.waitForTimeout(1500);
    }
    for (const url of PAGES.slice(1)) {
        await page.goto(BASE + url);
        await page.waitForLoadState('networkidle');
        await audit(url);
    }
    await browser.close();
    let serious = 0;
    for (const r of results) {
        for (const v of r.violations) {
            if (['serious', 'critical'].includes(v.impact)) serious++;
            console.log(`${r.url}\t${v.impact}\t${v.id}\t${v.nodes} node(s)\t${v.target}\t${v.help}`);
        }
    }
    console.log(`pages: ${results.length}, serious/critical violations: ${serious}`);
    process.exit(serious > 0 ? 1 : 0);
})();
