const fs = require('node:fs');
const {execFileSync} = require('node:child_process');
for (const file of ['public/assets/app.js', 'public/assets/theme.js', 'public/assets/search.js', 'public/assets/navigation.js', 'scripts/build-assets.cjs', 'scripts/check-local-node.cjs', 'scripts/package-release.cjs', ...fs.readdirSync('resources/js').filter(name => name.endsWith('.js')).map(name => 'resources/js/' + name)]) {
    execFileSync(process.execPath, ['--check', file], {stdio: 'inherit'});
}
