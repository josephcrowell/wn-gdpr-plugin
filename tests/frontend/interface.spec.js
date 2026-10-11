const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const cssPaths = ['assets/frontend/ui.css', 'assets/cookieBanner/banner.css', 'assets/cookieManager/manager.css'];
const legacyRef = process.env.GDPR_LEGACY_REF;
const styles = cssPaths.filter((file) => !legacyRef || file !== 'assets/frontend/ui.css').map((file) => legacyRef
    ? execFileSync('git', ['show', `${legacyRef}:${file}`], { cwd: root, encoding: 'utf8' })
    : fs.readFileSync(path.join(root, file), 'utf8')).join('\n');
const render = (component) => execFileSync('php', [path.join(__dirname, 'render.php'), component], {
    cwd: root,
    encoding: 'utf8',
});

// Only the screenshot reference supplies the defaults previously inherited from a theme.
// The migrated interface is rendered without them, on an otherwise unstyled page.
const legacyDefaults = `
    html { font-size: 16px; }
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        font-size: 16px; line-height: 1.5; color: #333; }
    *, *::before, *::after { box-sizing: border-box; }
    button, input, select { font-family: inherit; font-size: inherit; line-height: inherit; }
    a { color: #337ab7; text-decoration: none; }
`;

async function load(page, component, { hostCss = '', extra = '' } = {}) {
    await page.setContent(`<!doctype html><html><head><style>
        ${legacyRef ? legacyDefaults : ''}
        ${hostCss}
        ${styles}
    </style></head><body>${extra}${render(component)}</body></html>`);
    await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
    if (!legacyRef && component === 'cookiebanner') {
        await page.addScriptTag({ path: path.join(root, 'assets/cookieBanner/banner.js') });
    }
}

for (const width of [375, 1024, 1440]) {
    test(`banner keeps its original appearance at ${width}px without theme CSS`, async ({ page }) => {
        await page.setViewportSize({ width, height: 1000 });
        await load(page, 'cookiebanner');
        await expect(page.locator('.gdpr-banner')).toHaveScreenshot(`banner-${width}.png`);
    });

    test(`manager keeps its original appearance at ${width}px without theme CSS`, async ({ page }) => {
        await page.setViewportSize({ width, height: 1000 });
        await load(page, 'cookiemanager');
        await expect(page.locator('.gdpr-cookie-manager')).toHaveScreenshot(`manager-${width}.png`);
    });

    test(`analytics controls keep their original appearance at ${width}px without theme CSS`, async ({ page }) => {
        await page.setViewportSize({ width, height: 1000 });
        await load(page, 'cookiemanager');
        await page.locator('[data-handle-group-id="2"]').click();
        await expect(page.locator('.gdpr-cookie-manager')).toHaveScreenshot(`analytics-${width}.png`);
    });
}

test('group tabs, optional toggles, levels, required cookies and form data still work', async ({ page }) => {
    await load(page, 'cookiemanager');
    const required = page.locator('input[name="cookies[session][enabled]"]');
    await expect(required).toBeChecked();
    await expect(required).toBeDisabled();
    await expect(page.locator('[data-group-id="1"]')).toBeVisible();
    await page.locator('[data-handle-group-id="2"]').click();
    await expect(page.locator('[data-group-id="2"]')).toBeVisible();
    await expect(page.locator('[data-group-id="1"]')).toBeHidden();

    const optional = page.locator('input[name="cookies[analytics][enabled]"]');
    await optional.uncheck();
    await expect(page.locator('.gdpr-cookie-manager__cookie-levels')).toBeHidden();
    await optional.check();
    await expect(page.locator('.gdpr-cookie-manager__cookie-levels')).toBeVisible();
    await page.locator('[data-handle-cookie-slider]').fill('1');
    await expect(page.locator('[data-cookie-level="2-1"]')).toBeVisible();
    await expect(page.locator('[data-cookie-level="2-0"]')).toBeHidden();
    await page.locator('[name="consent_expiry"]').selectOption('24');
    const values = await page.locator('form').evaluate((form) => Object.fromEntries(new FormData(form)));
    expect(values).toMatchObject({
        _gdpr_submit: '1',
        consent_expiry: '24',
        'cookies[analytics][enabled]': '1',
        'cookies[analytics][level]': '1',
    });
});

test('optional toggle remains keyboard accessible', async ({ page }) => {
    await load(page, 'cookiemanager');
    await page.locator('[data-handle-group-id="2"]').click();
    const toggle = page.getByRole('checkbox', { name: 'Website analytics' });
    await toggle.focus();
    await page.keyboard.press('Space');
    await expect(toggle).not.toBeChecked();
    await expect(toggle).toBeFocused();
});

test('assets do not change the host page or expose Bootstrap/generic toggle classes', async ({ page }) => {
    const extra = '<h1 id="host-title">Host title</h1><button class="btn" id="host-button">Host button</button>';
    await page.setContent(extra);
    const properties = ['fontFamily', 'fontSize', 'lineHeight', 'color', 'backgroundColor', 'border', 'padding', 'width', 'height'];
    const before = await page.locator('#host-button').evaluate((element, properties) => {
        const style = getComputedStyle(element);
        return Object.fromEntries(properties.map((property) => [property, style[property]]));
    }, properties);
    await load(page, 'cookiemanager', { extra });
    const after = await page.locator('#host-button').evaluate((element, properties) => {
        const style = getComputedStyle(element);
        return Object.fromEntries(properties.map((property) => [property, style[property]]));
    }, properties);
    expect(after).toEqual(before);
    await expect(page.locator('#host-title')).toHaveCSS('font-size', '32px');
    await expect(page.locator('.gdpr-ui .btn, .gdpr-ui .tgl, .gdpr-ui .form-control')).toHaveCount(0);
});

test('all emitted CSS selectors are plugin-specific, without global preflight or theme selectors', async ({ page }) => {
    await load(page, 'cookiemanager');
    const selectors = await page.evaluate(() => {
        const selectors = [];
        function walk(rules) {
            for (const rule of rules) {
                if (rule.selectorText) selectors.push(rule.selectorText);
                if (rule.cssRules) walk(rule.cssRules);
            }
        }
        for (const sheet of document.styleSheets) walk(sheet.cssRules);
        return selectors;
    });
    expect(selectors.length).toBeGreaterThan(30);
    for (const selector of selectors) {
        let depth = 0;
        let start = 0;
        for (let index = 0; index <= selector.length; index++) {
            const char = selector[index];
            if (char === '(' || char === '[') depth++;
            if (char === ')' || char === ']') depth--;
            if (index === selector.length || (char === ',' && depth === 0)) {
                expect(selector.slice(start, index)).toContain('gdpr-');
                start = index + 1;
            }
        }
    }
});

test('the manager is independent of host font size, dark theme variables and generic controls', async ({ page }) => {
    await load(page, 'cookiemanager', {
        hostCss: `html { font-size: 24px; } body { font-family: serif; font-size: 28px; color: purple; }
            :root { --color-base-100: black; --color-base-content: white; --radius-field: 40px; }
            button, input, select { font-family: serif; font-size: 30px; }
            h3, h4 { font-weight: 400; color: purple; }`,
    });
    await expect(page.locator('.gdpr-cookie-manager')).toHaveCSS('font-size', '16px');
    await expect(page.locator('.gdpr-cookie-manager')).toHaveCSS('color', 'rgb(51, 51, 51)');
    await expect(page.locator('.gdpr-cookie-manager__cookie-name').first()).toHaveCSS('font-size', '16px');
    await expect(page.locator('.gdpr-cookie-manager__submit-button')).toHaveCSS('background-color', 'rgb(224, 224, 224)');
    await expect(page.locator('.gdpr-cookie-manager__toggle').first()).toHaveCSS('width', '64px');
});

test('the plugin also coexists with the reference theme Tailwind/daisyUI build', async ({ page }) => {
    const directory = path.resolve(root, '../../../themes/sonicdeorg-tailwind/assets/dist');
    const files = fs.existsSync(directory) ? fs.readdirSync(directory).filter((file) => file.endsWith('.css')) : [];
    test.skip(files.length === 0, 'Optional reference theme is not installed; the plugin does not require it.');
    const hostCss = files.map((file) => fs.readFileSync(path.join(directory, file), 'utf8')).join('\n');
    await load(page, 'cookiemanager', { hostCss });
    await page.evaluate(() => document.documentElement.dataset.theme = 'sonicde-dark');
    await expect(page.locator('.gdpr-cookie-manager')).toHaveCSS('color-scheme', 'dark');
    await expect(page.locator('.gdpr-cookie-manager')).toHaveCSS('color', 'rgb(238, 238, 238)');
    await expect(page.locator('.gdpr-cookie-manager__content')).toHaveCSS('background-color', 'rgb(23, 25, 29)');
    await expect(page.locator('.gdpr-cookie-manager__cookie-name').first()).toHaveCSS('font-size', '16px');
    await expect(page.locator('.gdpr-cookie-manager__toggle').first()).toHaveCSS('width', '64px');
    await expect(page.locator('.gdpr-cookie-manager__submit-button')).not.toHaveCSS('background-color', 'rgb(224, 224, 224)');
    await page.evaluate(() => document.documentElement.dataset.theme = 'sonicde-light');
    await expect(page.locator('.gdpr-cookie-manager')).toHaveCSS('color-scheme', 'light');
    await expect(page.locator('.gdpr-cookie-manager__content')).toHaveCSS('background-color', 'rgb(253, 253, 253)');
});

for (const component of ['cookiebanner', 'cookiemanager']) {
    test(`${component} follows live system preference changes without a theme switch`, async ({ page }) => {
        await page.emulateMedia({ colorScheme: 'light' });
        await load(page, component);
        const widget = page.locator('.gdpr-ui');
        await expect(widget).toHaveCSS('color-scheme', 'light');
        const light = await widget.evaluate((element) => ({
            background: getComputedStyle(element).backgroundColor,
            color: getComputedStyle(element).color,
            height: element.getBoundingClientRect().height,
        }));

        await page.emulateMedia({ colorScheme: 'dark' });
        await expect(widget).toHaveCSS('color-scheme', 'dark');
        await expect(widget).not.toHaveCSS('background-color', light.background);
        await expect(widget).not.toHaveCSS('color', light.color);
        expect(await widget.evaluate((element) => element.getBoundingClientRect().height)).toBe(light.height);

        await page.emulateMedia({ colorScheme: 'light' });
        await expect(widget).toHaveCSS('color-scheme', 'light');
        await expect(widget).toHaveCSS('background-color', light.background);
        await expect(widget).toHaveCSS('color', light.color);
        expect(await widget.getAttribute('data-theme')).toBeNull();
        expect(await page.locator('input[class*="theme-controller"]').count()).toBe(0);
    });
}

test('automatic dark mode works without JavaScript and does not theme the host page', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false, colorScheme: 'dark' });
    const page = await context.newPage();
    try {
        await load(page, 'cookiemanager');
        await expect(page.locator('.gdpr-ui')).toHaveCSS('color-scheme', 'dark');
        await expect(page.locator('.gdpr-cookie-manager__content')).not.toHaveCSS('background-color', 'rgb(255, 255, 255)');
        await expect(page.locator('html')).toHaveCSS('color-scheme', 'normal');
        expect(await page.locator('html').evaluate((element) => getComputedStyle(element).getPropertyValue('--color-base-100'))).toBe('');
    } finally {
        await context.close();
    }
});

test('explicit daisyUI themes override system preferences on a plugin root without affecting other widgets', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await load(page, 'cookiemanager', { extra: render('cookiebanner') });
    const manager = page.locator('.gdpr-cookie-manager');
    const banner = page.locator('.gdpr-banner');
    await manager.evaluate((element) => element.dataset.theme = 'light');
    await expect(manager).toHaveCSS('color-scheme', 'light');
    await expect(manager).toHaveCSS('color', 'rgb(51, 51, 51)');
    await expect(banner).toHaveCSS('color-scheme', 'dark');
    await page.emulateMedia({ colorScheme: 'light' });
    await manager.evaluate((element) => element.dataset.theme = 'dark');
    await expect(manager).toHaveCSS('color-scheme', 'dark');
    await expect(banner).toHaveCSS('color-scheme', 'light');
    await manager.evaluate((element) => element.removeAttribute('data-theme'));
    await expect(manager).toHaveCSS('color-scheme', 'light');
});

async function loadSnowboard(page) {
    const cmsRoot = path.resolve(root, '../../..');
    for (const script of [
        'modules/system/assets/js/build/manifest.js',
        'modules/system/assets/js/snowboard/build/snowboard.vendor.js',
        'modules/system/assets/js/snowboard/build/snowboard.base.js',
        'modules/system/assets/js/snowboard/build/snowboard.request.js',
        'modules/system/assets/js/snowboard/build/snowboard.data-attr.js',
    ]) {
        await page.addScriptTag({ path: path.join(cmsRoot, script) });
    }
    await page.evaluate(() => window.dispatchEvent(new Event('DOMContentLoaded')));
}

for (const [handler, action] of [['onAccept', 'accept'], ['onDecline', 'decline']]) {
    test(`banner ${action} works with the real Snowboard framework and no jQuery`, async ({ page }) => {
        const requests = [];
        await page.route('https://gdpr.test/**', (route) => {
            if (route.request().method() === 'POST') {
                requests.push(route.request().headers()['x-winter-request-handler']);
                return route.fulfill({
                    json: handler === 'onAccept' ? { '#gdpr-reload': '<p>Consent updated</p>' } : { result: 'declined' },
                });
            }
            return route.fulfill({ contentType: 'text/html', body: '<!doctype html>' });
        });
        await page.goto('https://gdpr.test/');
        await load(page, 'cookiebanner', { extra: '<div id="gdpr-reload"></div>' });
        await loadSnowboard(page);
        await page.locator(`button[data-request="${handler}"]`).click();
        await expect(page.locator('.gdpr-banner')).toHaveCount(0);
        expect(requests).toEqual([handler]);
        if (handler === 'onAccept') await expect(page.locator('#gdpr-reload')).toHaveText('Consent updated');
        expect(await page.evaluate(() => typeof window.jQuery)).toBe('undefined');
    });
}

test('failed consent requests leave the banner available to retry', async ({ page }) => {
    page.on('dialog', (dialog) => dialog.dismiss());
    await page.route('https://gdpr.test/**', (route) => route.request().method() === 'POST'
        ? route.fulfill({ status: 500, json: { X_WINTER_ERROR_MESSAGE: 'Please try again' } })
        : route.fulfill({ contentType: 'text/html', body: '<!doctype html>' }));
    await page.goto('https://gdpr.test/');
    await load(page, 'cookiebanner');
    await loadSnowboard(page);
    const failed = page.waitForResponse((response) => response.request().method() === 'POST');
    await page.locator('[data-request="onAccept"]').click();
    await failed;
    await expect(page.locator('.gdpr-banner')).toBeVisible();
});

test('advanced settings saves the decline decision before redirecting', async ({ page }) => {
    const requests = [];
    await page.route('https://gdpr.test/**', (route) => {
        if (route.request().method() === 'POST') {
            requests.push(route.request().headers()['x-winter-request-handler']);
            return route.fulfill({ json: { result: 'declined' } });
        }
        return route.fulfill({ contentType: 'text/html', body: '<!doctype html>' });
    });
    await page.goto('https://gdpr.test/');
    await load(page, 'cookiebanner');
    await loadSnowboard(page);
    await page.locator('.gdpr-banner__settings a').click();
    await expect(page).toHaveURL('https://gdpr.test/cookies');
    expect(requests).toEqual(['onDecline']);
});
