const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
    testDir: './tests/frontend',
    fullyParallel: true,
    use: {
        browserName: 'chromium',
        viewport: { width: 1440, height: 1000 },
        deviceScaleFactor: 1,
    },
});
