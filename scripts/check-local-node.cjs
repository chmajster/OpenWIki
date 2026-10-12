const fs = require('node:fs');
try {
    const lock = JSON.parse(fs.readFileSync('package-lock.json', 'utf8'));
    const installed = JSON.parse(fs.readFileSync('node_modules/.package-lock.json', 'utf8'));
    for (const [name, expected] of Object.entries(lock.packages)) {
        if (!name || expected.optional) continue;
        const actual = installed.packages[name];
        if (!actual || actual.version !== expected.version || actual.integrity !== expected.integrity || actual.resolved !== expected.resolved) process.exit(1);
        const metadata = JSON.parse(fs.readFileSync(name + '/package.json', 'utf8'));
        if (metadata.version !== expected.version) process.exit(1);
    }
    // A checkout on /mnt/c may have npm dependencies built for Windows.
    require('esbuild').transformSync('', {loader: 'js'});
} catch { process.exit(1); }
