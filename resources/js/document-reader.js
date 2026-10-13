import {formatDocument} from './reader-format.js';
import './space-settings.js';
import './documentation-tree.js';
import './structure-dialog.js';
document.querySelectorAll('[data-document-content], [data-revision-content]').forEach(formatDocument);
