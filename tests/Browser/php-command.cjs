const {execFileSync} = require('node:child_process');
const extensions = ['gd', 'ldap', 'zip'];
const loaded = JSON.parse(execFileSync('php', ['-r', 'echo json_encode(get_loaded_extensions());'], {encoding: 'utf8'}));
const phpArguments = extensions.filter((extension) => !loaded.includes(extension)).flatMap((extension) => ['-d', 'extension=' + extension]);
module.exports = {phpArguments};
