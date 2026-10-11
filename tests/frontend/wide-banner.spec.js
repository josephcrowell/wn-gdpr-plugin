const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const css = ['assets/frontend/ui.css', 'assets/cookieBannerWide/banner.css']
    .map((file) => fs.readFileSync(path.join(root, file), 'utf8')).join('\n')
    .replace(/url\(["']?\.\.\/cookieBannerWide\/cookie\.svg["']?\)/g,
        `url(data:image/svg+xml;base64,${fs.readFileSync(path.join(root, 'assets/cookieBannerWide/cookie.svg')).toString('base64')})`);

async function load(page, { scheme = 'blue', illustration = false, inactive = false, alias = 'cookiebannerwide', hostCss = '' } = {}) {
    const html = execFileSync('php', [path.join(__dirname, 'render.php'), 'cookiebannerwide', scheme,
        illustration ? '1' : '0', inactive ? 'inactive' : '', alias], { cwd: root, encoding: 'utf8' });
    await page.setContent(`<!doctype html><html><head><style>${hostCss}${css}</style></head>
        <body><div id="gdpr-reload"></div>${html}</body></html>`);
    await page.addScriptTag({ path: path.join(root, 'assets/cookieBanner/banner.js') });
    await page.addScriptTag({ path: path.join(root, 'assets/cookieBannerWide/banner.js') });
}

async function snowboard(page) {
    for (const file of [
        'build/manifest.js', 'snowboard/build/snowboard.vendor.js', 'snowboard/build/snowboard.base.js',
        'snowboard/build/snowboard.request.js', 'snowboard/build/snowboard.data-attr.js',
    ]) await page.addScriptTag({ path: path.resolve(root, '../../../modules/system/assets/js', file) });
    await page.evaluate(() => window.dispatchEvent(new Event('DOMContentLoaded')));
}

async function routeRequests(page, status = 200) {
    const requests = [];
    await page.route('https://gdpr.test/**', async (route) => {
        if (route.request().method() === 'POST') {
            const fields = await new Response(route.request().postDataBuffer(), {
                headers: { 'Content-Type': route.request().headers()['content-type'] },
            }).formData();
            requests.push({ handler: route.request().headers()['x-winter-request-handler'], data: new URLSearchParams(fields).toString() });
            return route.fulfill({ status, json: status === 200
                ? { '#gdpr-reload': '<p>Consent saved</p>' }
                : { X_WINTER_ERROR_MESSAGE: 'Please try again' } });
        }
        return route.fulfill({ contentType: 'text/html', body: '<!doctype html>' });
    });
    await page.goto('https://gdpr.test/');
    return requests;
}

for (const [width, size] of [[375, '20px'], [800, '22px'], [1100, '24px'], [1440, '28px']]) {
    test(`wide banner is standalone and responsive at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });
        await load(page);
        const dialog = page.getByRole('dialog', { name: 'This website uses cookies.' });
        await expect(dialog).toBeVisible();
        expect(await dialog.evaluate((element) => element.matches(':modal'))).toBe(true);
        await expect(dialog).toHaveCSS('--toggle-size', size);
        const panel = await page.locator('.gdpr-bannerwide__wrapper').boundingBox();
        expect(panel.x).toBeGreaterThanOrEqual(0);
        expect(panel.x + panel.width).toBeLessThanOrEqual(width);
        expect(panel.height).toBeLessThanOrEqual(900);
        await expect(page.locator('.gdpr-bannerwide__toggle-checkbox').first()).toBeChecked();
        await expect(page.locator('.gdpr-bannerwide__toggle-checkbox').first()).toBeDisabled();
        await expect(page.getByRole('checkbox', { name: 'Personalisation' })).not.toBeChecked();
        expect(await page.evaluate(() => typeof window.jQuery)).toBe('undefined');
    });
}

test('all 17 schemes and custom CSS variables affect the toggles and buttons', async ({ page }) => {
    const accents = new Set();
    for (const scheme of ['blue', 'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald',
        'teal', 'cyan', 'sky', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose']) {
        await load(page, { scheme });
        const button = page.getByRole('button', { name: 'Accept all', exact: true });
        accents.add(await button.evaluate((element) => getComputedStyle(element).backgroundColor));
        await expect(page.locator('.gdpr-bannerwide__wrapper')).toHaveClass(new RegExp(`wrapper--${scheme}`));
    }
    expect(accents.size).toBe(17);
    await page.locator('.gdpr-bannerwide__wrapper').evaluate((element) => {
        element.style.setProperty('--color-accent', '#123456');
    });
    await expect(page.getByRole('button', { name: 'Accept all', exact: true })).toHaveCSS('background-color', 'rgb(18, 52, 86)');
});

test('illustration is optional and available as a bundled asset', async ({ page }) => {
    await load(page, { illustration: true });
    const image = await page.locator('.gdpr-bannerwide__wrapper').evaluate((element) => getComputedStyle(element, '::before').backgroundImage);
    expect(image).toContain('data:image/svg+xml;base64,');
    await load(page);
    await expect(page.locator('.gdpr-bannerwide__wrapper')).not.toHaveClass(/bgCookie/);
});

test('native toggles work by keyboard and optional groups can start unchecked', async ({ page }) => {
    await load(page, { inactive: true });
    const toggle = page.getByRole('checkbox', { name: 'Analytics' });
    await expect(toggle).not.toBeChecked();
    await toggle.focus();
    await page.keyboard.press('Space');
    await expect(toggle).toBeChecked();
    await expect(toggle).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(page.locator('.gdpr-bannerwide')).toHaveCount(0);
});

test('save selection sends only checked optional group IDs through native Snowboard', async ({ page }) => {
    const requests = await routeRequests(page);
    await load(page, { alias: 'privacyNotice' });
    await snowboard(page);
    await page.getByRole('button', { name: 'Save my choice' }).click();
    await expect(page.locator('.gdpr-bannerwide')).toHaveCount(0);
    await expect(page.locator('#gdpr-reload')).toHaveText('Consent saved');
    expect(requests).toHaveLength(1);
    expect(requests[0].handler).toBe('privacyNotice::onSubmit');
    const values = new URLSearchParams(requests[0].data);
    expect(values.getAll('cookies[]')).toEqual(['2']);
    expect(values.get('_token')).toBe('fixture-csrf');
});

test('an empty optional selection can still be saved', async ({ page }) => {
    const requests = await routeRequests(page);
    await load(page);
    await snowboard(page);
    await page.getByRole('checkbox', { name: 'Analytics' }).uncheck();
    await page.getByRole('button', { name: 'Save my choice' }).click();
    await expect(page.locator('.gdpr-bannerwide')).toHaveCount(0);
    expect(new URLSearchParams(requests[0].data).getAll('cookies[]')).toEqual([]);
});

for (const [label, handler] of [['Accept all', 'onAccept'], ['Decline cookies', 'onDecline']]) {
    test(`${label} uses the inherited handler and closes the correct dialog`, async ({ page }) => {
        const requests = await routeRequests(page);
        await load(page);
        await snowboard(page);
        await page.getByRole('button', { name: label, exact: true }).click();
        await expect(page.locator('.gdpr-bannerwide')).toHaveCount(0);
        expect(requests.map((request) => request.handler)).toEqual([`cookiebannerwide::${handler}`]);
    });
}

test('failed selections remain open and can be retried', async ({ page }) => {
    page.on('dialog', (dialog) => dialog.dismiss());
    const requests = await routeRequests(page, 500);
    await load(page);
    await snowboard(page);
    const failed = page.waitForResponse((response) => response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Save my choice' }).click();
    await failed;
    await expect(page.locator('.gdpr-bannerwide')).toBeVisible();
    expect(requests).toHaveLength(1);
});

test('advanced settings records the decline before navigating', async ({ page }) => {
    const requests = await routeRequests(page);
    await load(page);
    await snowboard(page);
    await page.getByRole('link', { name: 'Advanced settings' }).click();
    await expect(page).toHaveURL('https://gdpr.test/cookies');
    expect(requests.map((request) => request.handler)).toEqual(['cookiebannerwide::onDecline']);
});

test('wide banner follows daisyUI automatic dark mode without changing layout', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' });
    await load(page);
    const box = page.locator('.gdpr-bannerwide__wrapper');
    const geometry = await box.boundingBox();
    await expect(box).toHaveCSS('background-color', 'rgb(255, 255, 255)');
    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('.gdpr-bannerwide')).toHaveCSS('color-scheme', 'dark');
    await expect(box).not.toHaveCSS('background-color', 'rgb(255, 255, 255)');
    expect(await box.boundingBox()).toEqual(geometry);
    await expect(page.getByRole('button', { name: 'Decline cookies' })).toHaveCSS('color', 'rgb(191, 219, 254)');
});
