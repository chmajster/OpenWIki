const esbuild = require('esbuild');
esbuild.buildSync({entryPoints: ['resources/js/wiki-editor.js'], outfile: 'public/assets/wiki-editor.js',
    bundle: true, minify: true, target: ['es2020'], format: 'iife', legalComments: 'eof'});
for (const [source, file] of [['diagram-frame', 'diagram'], ['document-reader', 'document-reader']]) esbuild.buildSync({entryPoints: ['resources/js/' + source + '.js'], outfile: 'public/assets/' + file + '.js',
    bundle: true, minify: true, target: ['es2020'], format: 'iife', legalComments: 'eof'});
