const esbuild = require('esbuild');
esbuild.buildSync({entryPoints: ['./resources/js/wiki-editor.js'], outfile: 'public/assets/wiki-editor.js',
    bundle: true, minify: true, target: ['es2020'], format: 'iife', legalComments: 'eof'});
for (const [source, file] of [['diagram-frame', 'diagram'], ['document-reader', 'document-reader']]) esbuild.buildSync({entryPoints: ['./resources/js/' + source + '.js'], outfile: 'public/assets/' + file + '.js',
    bundle: true, minify: true, target: ['es2020'], format: 'iife', legalComments: 'eof'});
esbuild.buildSync({entryPoints: ['./resources/js/mfa-setup.js'], outfile: 'public/assets/mfa-setup.js',
    bundle: true, minify: true, target: ['es2020'], format: 'iife', legalComments: 'eof'});
const fs = require('node:fs');
const {createHash} = require('node:crypto');
const files = ['package.json', 'package-lock.json', 'scripts/build-assets.cjs', 'public/assets/diagram.html',
    ...fs.readdirSync('resources/js').filter(name => name.endsWith('.js')).map(name => 'resources/js/' + name)].sort();
const hash = createHash('sha256');
for (const file of files) hash.update(file + '\0').update(fs.readFileSync(file)).update('\0');
const outputs = Object.fromEntries(['wiki-editor.js', 'diagram.js', 'document-reader.js', 'mfa-setup.js', 'diagram.html'].map(name =>
    [name, createHash('sha256').update(fs.readFileSync('public/assets/' + name)).digest('hex')]));
const temporary = 'public/assets/.editor-manifest-' + process.pid + '.tmp';
fs.writeFileSync(temporary, JSON.stringify({version: 1, input: hash.digest('hex'), outputs}) + '\n');
fs.renameSync(temporary, 'public/assets/editor-manifest.json');
