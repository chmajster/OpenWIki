const {execFileSync} = require('node:child_process');
const fs = require('node:fs');
const {phpArguments} = require('./php-command.cjs');

module.exports = () => {
    if (!process.env.OPENWIKI_TEST_DB_HOST || !/_test$/.test(process.env.OPENWIKI_TEST_DB_DATABASE || '')) {
        throw new Error('Browser tests require OPENWIKI_TEST_DB_* and a dedicated database ending in _test.');
    }
    const lock = 'storage/installed.lock';
    fs.mkdirSync('storage/temp', {recursive: true});
    fs.writeFileSync('storage/temp/editor-browser-lock.json', JSON.stringify({old: fs.existsSync(lock) ? fs.readFileSync(lock, 'utf8') : null}));
    fs.writeFileSync(lock, '{"browser_test":true}');
    const data = execFileSync('php', [...phpArguments, 'tests/Browser/fixture.php', 'seed'], {encoding: 'utf8'});
    fs.writeFileSync('storage/temp/editor-browser.json', data);
};
