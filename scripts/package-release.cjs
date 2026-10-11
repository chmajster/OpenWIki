const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const {execFileSync} = require('node:child_process');

const root = path.resolve(__dirname, '..');
const destination = path.resolve(process.argv[2] || path.join(root, 'storage/temp/openwiki.tar.gz'));
execFileSync('git', ['diff', '--quiet', 'HEAD'], {cwd: root});
const bundles = ['wiki-editor', 'diagram', 'document-reader'].map(name => 'public/assets/' + name + '.js');
for (const file of ['vendor/autoload.php', 'public/assets/diagram.html', ...bundles]) {
    const artifact = fs.statSync(path.join(root, file));
    if (!artifact.isFile() || !artifact.size) throw new Error('Missing or empty runtime artifact: ' + file);
}
const stage = fs.mkdtempSync(path.join(os.tmpdir(), 'openwiki-release-'));
try {
    const files = execFileSync('git', ['ls-files', '-z'], {cwd: root}).toString('utf8').split('\0').filter(Boolean);
    for (const file of files) {
        if (/^(tests\/|\.github\/|\.gitignore$|AGENTS\.md$|\.env$|phpunit\.xml$|playwright\.config\.cjs$)/.test(file)) continue;
        const target = path.resolve(stage, file);
        if (!target.startsWith(stage + path.sep)) throw new Error('Invalid package path.');
        fs.mkdirSync(path.dirname(target), {recursive: true});
        fs.copyFileSync(path.join(root, file), target);
    }
    fs.cpSync(path.join(root, 'vendor'), path.join(stage, 'vendor'), {recursive: true});
    for (const file of bundles) fs.copyFileSync(path.join(root, file), path.join(stage, file));
    fs.mkdirSync(path.dirname(destination), {recursive: true});
    execFileSync('tar', ['-czf', destination, '-C', stage, '.'], {stdio: 'inherit'});
    process.stdout.write('PHP runtime package created.\n');
} finally {
    if (!path.resolve(stage).startsWith(path.resolve(os.tmpdir()) + path.sep + 'openwiki-release-')) throw new Error('Unexpected staging directory.');
    fs.rmSync(stage, {recursive: true, force: true});
}
