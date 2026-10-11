const {execFileSync} = require('node:child_process');
const fs = require('node:fs');

module.exports = () => {
    try {
        if (fs.existsSync('storage/temp/editor-browser.json')) {
            execFileSync('php', ['tests/Browser/fixture.php', 'cleanup']);
            fs.unlinkSync('storage/temp/editor-browser.json');
        }
    } finally {
        const path = 'storage/temp/editor-browser-lock.json';
        if (fs.existsSync(path)) {
            const {old} = JSON.parse(fs.readFileSync(path, 'utf8'));
            if (old === null) fs.rmSync('storage/installed.lock', {force: true});
            else fs.writeFileSync('storage/installed.lock', old);
            fs.unlinkSync(path);
        }
    }
};
