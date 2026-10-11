const {defineConfig} = require('@playwright/test');
const {phpArguments} = require('./tests/Browser/php-command.cjs');

module.exports = defineConfig({
    testDir: './tests/Browser',
    timeout: 45000,
    workers: 1,
    fullyParallel: false,
    globalSetup: './tests/Browser/setup.cjs',
    globalTeardown: './tests/Browser/teardown.cjs',
    use: {baseURL: 'http://127.0.0.1:8765', headless: true, trace: 'retain-on-failure'},
    webServer: {
        command: ['php', ...phpArguments, '-S', '127.0.0.1:8765', '-t', 'public', 'tests/Browser/router.php'].join(' '),
        url: 'http://127.0.0.1:8765/assets/app.js',
        reuseExistingServer: false,
        stdout: 'ignore',
        stderr: 'ignore',
        env: {
            DB_HOST: process.env.OPENWIKI_TEST_DB_HOST || '',
            DB_PORT: process.env.OPENWIKI_TEST_DB_PORT || '3306',
            DB_DATABASE: process.env.OPENWIKI_TEST_DB_DATABASE || '',
            DB_USERNAME: process.env.OPENWIKI_TEST_DB_USERNAME || '',
            DB_PASSWORD: process.env.OPENWIKI_TEST_DB_PASSWORD || '',
            APP_KEY: 'browser-test-key-'.repeat(4), APP_DEBUG: 'false', APP_URL: 'http://127.0.0.1:8765', APP_TIMEZONE: 'UTC'
        }
    }
});
