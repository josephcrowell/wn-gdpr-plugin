const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const skin = path.resolve(root, '../../../plugins/winter/tailwindui');
const hasSkin = fs.existsSync(path.join(skin, 'assets/src/js/darkmode.js'));
const skinVariables = hasSkin
    ? fs.readFileSync(path.join(skin, 'assets/src/css/darkmode.css'), 'utf8').match(/:root\s*\{[^}]+\}/)[0] : '';
const styles = ['styles.css', 'prism.css', 'info.css'].map((file) =>
    fs.readFileSync(path.join(root, 'assets/backend', file), 'utf8')).join('\n');
const samples = `
    <section class="info-dashboard" id="samples">
        <h2>Sample heading</h2><a href="#">Example link</a>
        <blockquote><p>Example quotation</p></blockquote><mark>Example highlight</mark>
        <table class="dpo"><tbody><tr><th>Contact</th><th>Link</th></tr>
            <tr><td>Provider</td><td class="gdpr-info-warning">Warning</td></tr></tbody></table>
        <table class="container"><tbody><tr><th><h1>Heading</h1></th><th>Information</th></tr>
            <tr><td>Policy</td><td>Details</td></tr></tbody></table>
        <pre class="language-php"><code class="language-php"><span class="token comment">// Code example</span></code></pre>
    </section>`;

async function load(page, { view = 'index', mode = 'light', classOnly = false, withSkin = true } = {}) {
    // Documentation embeds third-party images and Google Translate; no network is needed here.
    await page.route('**/*', (route) => route.abort());
    const html = execFileSync('php', [path.join(__dirname, 'render-info.php'), view], { encoding: 'utf8' });
    const preference = classOnly ? 'class="dark"' : mode === null ? '' : `data-color-scheme="${mode}"`;
    await page.setContent(`<!doctype html><html ${preference}><head><style>
        ${skinVariables}
        body { color: #333; } .dark body { color: var(--drk-color); background: var(--drk-bg-color-a); }
        ${styles}
    </style></head><body>${html}${samples}
        <div id="outside" class="container"><span class="blue">Outside text</span>
            <pre class="language-php"><code class="language-php"><span class="token comment">Outside code</span></code></pre>
        </div></body></html>`);
    if (withSkin && hasSkin) await page.addScriptTag({ path: path.join(skin, 'assets/src/js/darkmode.js') });
}

test('explicit backend light mode wins over a dark system preference', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await load(page);
    await expect(page.locator('.info-dashboard').first()).toHaveCSS('background-color', 'rgb(255, 255, 255)');
    await expect(page.locator('#samples mark')).toHaveCSS('background-color', 'rgb(222, 235, 255)');
    await expect(page.locator('.info-dashboard').first()).toHaveCSS('color-scheme', 'light');
    expect(await page.locator('html').getAttribute('data-color-scheme')).toBe('light');
});

test('explicit backend dark mode wins over a light system preference', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' });
    await load(page, { mode: 'dark' });
    await expect(page.locator('.info-dashboard').first()).toHaveCSS('background-color', 'rgb(22, 27, 34)');
    await expect(page.locator('.info-dashboard').first()).toHaveCSS('color', 'rgb(201, 209, 217)');
    await expect(page.locator('#samples h2')).toHaveCSS('color', 'rgb(201, 209, 217)');
    await expect(page.locator('#samples blockquote')).toHaveCSS('background-color', 'rgb(27, 34, 44)');
    await expect(page.locator('#samples mark')).toHaveCSS('background-color', 'rgb(44, 67, 78)');
    await expect(page.locator('#samples .dpo th').first()).toHaveCSS('background-color', 'rgb(33, 38, 45)');
    await expect(page.locator('#samples .gdpr-info-warning')).toHaveCSS('color', 'rgb(248, 81, 73)');
    await expect(page.locator('#samples pre')).toHaveCSS('background-color', 'rgb(1, 4, 9)');
    await expect(page.locator('#samples .token.comment')).toHaveCSS('color', 'rgb(139, 148, 158)');
});

test('the existing TailwindUI switcher changes the info page immediately', async ({ page }) => {
    test.skip(!hasSkin, 'Optional TailwindUI skin is not installed.');
    await load(page);
    await page.evaluate(() => window.updateColorScheme('dark'));
    await expect(page.locator('.info-dashboard').first()).toHaveCSS('color-scheme', 'dark');
    await page.evaluate(() => window.updateColorScheme('light'));
    await expect(page.locator('.info-dashboard').first()).toHaveCSS('color-scheme', 'light');
    await expect(page.locator('.info-dashboard').first()).toHaveCSS('background-color', 'rgb(255, 255, 255)');
});

test('auto mode follows live system changes even if the backend dark class is stale', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await load(page, { mode: 'auto' });
    await page.locator('html').evaluate((element) => element.classList.add('dark'));
    await expect(page.locator('.info-dashboard').first()).toHaveCSS('color-scheme', 'dark');
    await page.emulateMedia({ colorScheme: 'light' });
    await expect(page.locator('.info-dashboard').first()).toHaveCSS('color-scheme', 'light');
    await expect(page.locator('.info-dashboard').first()).toHaveCSS('color', 'rgb(51, 51, 51)');
    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('.info-dashboard').first()).toHaveCSS('color-scheme', 'dark');
    expect(await page.locator('html').getAttribute('data-color-scheme')).toBe('auto');
    // The plugin follows the preference; it does not mutate the skin's state.
    expect(await page.locator('html').evaluate((element) => element.classList.contains('dark'))).toBe(true);
});

test('legacy dark classes and skins without a preference marker also work', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' });
    await load(page, { classOnly: true, withSkin: false });
    await expect(page.locator('.info-dashboard').first()).toHaveCSS('color-scheme', 'dark');
    await page.emulateMedia({ colorScheme: 'dark' });
    await load(page, { mode: null, withSkin: false });
    await expect(page.locator('.info-dashboard').first()).toHaveCSS('color-scheme', 'dark');
    await page.emulateMedia({ colorScheme: 'light' });
    await expect(page.locator('.info-dashboard').first()).toHaveCSS('color-scheme', 'light');
});

test('backend theme colours are inherited and documentation styles do not leak', async ({ page }) => {
    await load(page, { mode: 'dark' });
    await page.locator('html').evaluate((element) => element.style.setProperty('--drk-bg-color-b', '#123456'));
    await expect(page.locator('.info-dashboard').first()).toHaveCSS('background-color', 'rgb(18, 52, 86)');
    await expect(page.locator('#outside')).toHaveCSS('display', 'block');
    await expect(page.locator('#outside pre')).toHaveCSS('background-color', 'rgba(0, 0, 0, 0)');
    const bodyColor = await page.locator('body').evaluate((element) => getComputedStyle(element).color);
    await expect(page.locator('#outside .token.comment')).toHaveCSS('color', bodyColor);
});

for (const mode of ['light', 'dark']) {
    test(`Google Translate logos keep their supplied dimensions in ${mode} mode`, async ({ page }) => {
        await load(page, { mode });
        const logo = `data:image/svg+xml;base64,${Buffer.from('<svg xmlns="http://www.w3.org/2000/svg" width="84" height="32"><rect width="84" height="32" fill="blue"/></svg>').toString('base64')}`;
        const icon = `data:image/svg+xml;base64,${Buffer.from('<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"><rect width="16" height="16" fill="blue"/></svg>').toString('base64')}`;
        await page.locator('#google_translate_element').evaluate((element, { logo, icon }) => {
            element.innerHTML = `<div class="goog-te-gadget">
                <a class="goog-logo-link"><img id="legacy-logo" src="${logo}" width="42" height="16"></a>
                <a class="VIpgJd-ZVi9od-l4eHX"><img id="modern-logo" src="${logo}" width="42" height="16"></a>
                <img id="translate-icon" src="${icon}">
            </div>`;
        }, { logo, icon });
        await page.locator('#google_translate_element').evaluate((element) =>
            Promise.all(Array.from(element.querySelectorAll('img'), (image) => image.decode())));
        for (const id of ['legacy-logo', 'modern-logo']) {
            await expect(page.locator(`#${id}`)).toHaveCSS('width', '42px');
            await expect(page.locator(`#${id}`)).toHaveCSS('height', '16px');
            await expect(page.locator(`#${id}`)).toHaveCSS('display', 'inline');
        }
        await expect(page.locator('#translate-icon')).toHaveCSS('width', '16px');
        await expect(page.locator('#translate-icon')).toHaveCSS('height', '16px');

        // The documentation's responsive illustrations must retain their existing styling.
        await expect(page.locator('.info-dashboard figure img').first()).toHaveCSS('display', 'block');
        await expect(page.locator('.info-dashboard figure img').first()).toHaveCSS('max-width', '940px');
    });
}

for (const view of ['index', 'overallinfo', 'dataretention', 'cookieconsent', 'cookiesmodulesuserguide',
    'cookiesmodulessetup', 'dpolinks', 'bannerlaws']) {
    test(`${view} backend view renders with dark documentation colours`, async ({ page }) => {
        await load(page, { view, mode: 'dark' });
        await expect(page.locator('.info-dashboard').first()).toHaveCSS('background-color', 'rgb(22, 27, 34)');
        await expect(page.locator('.info-dashboard').first()).toHaveCSS('color', 'rgb(201, 209, 217)');
        const headings = page.locator('.info-dashboard').first().locator('h1').first();
        await expect(headings).toHaveCSS('color', 'rgb(201, 209, 217)');
        if (view === 'dpolinks') {
            expect(await page.locator('.gdpr-info-warning').count()).toBeGreaterThan(10);
            await expect(page.locator('.gdpr-info-warning').first()).toHaveCSS('color', 'rgb(248, 81, 73)');
        }
    });
}
