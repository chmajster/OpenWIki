const fs = require('node:fs');
const {execFileSync} = require('node:child_process');
for (const file of ['public/assets/app.js', ...fs.readdirSync('resources/js').filter(name => name.endsWith('.js')).map(name => 'resources/js/' + name)]) {
    execFileSync(process.execPath, ['--check', file], {stdio: 'inherit'});
}
