// Runs inside the dev stack's Docker network, see README.
const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
    testDir: '.',
    timeout: 60000,
    workers: 1,
    reporter: [['list']],
    use: {
        baseURL: process.env.BASE_URL || 'http://web',
        screenshot: 'only-on-failure',
        locale: 'de-DE',
    },
    outputDir: '../../dev/screenshots/results',
});
