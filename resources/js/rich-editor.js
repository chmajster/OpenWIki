import {Editor, Extension, Node, Mark, mergeAttributes} from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import {Table, TableRow, TableCell, TableHeader} from '@tiptap/extension-table';
import {TaskList, TaskItem} from '@tiptap/extension-list';
import {NodeSelection} from '@tiptap/pm/state';
import {documentBlocks} from './document-blocks.js';

const globals = ['id', 'class', 'title', 'aria-label'];
const globalAttributes = Extension.create({
    name: 'legacyAttributes',
    addGlobalAttributes() {
        return [{types: ['paragraph', 'heading', 'blockquote', 'bulletList', 'orderedList', 'listItem', 'taskList', 'taskItem', 'callout', 'table', 'tableRow', 'tableCell', 'tableHeader', 'horizontalRule', 'link', 'bold', 'italic', 'underline', 'strike', 'code'],
            attributes: Object.fromEntries(globals.map((name) => [name, {default: null, parseHTML: (element) => element.getAttribute(name)}]))},
            {types: ['link'], attributes: Object.fromEntries(['data-draft-file-id', 'data-attachment-id', 'data-file-size', 'data-file-type'].map((name) => [name, {default: null}]))}];
    }
});

const Callout = Node.create({
    name: 'callout', group: 'block', content: 'block+', defining: true,
    addAttributes() { return {kind: {default: 'info', parseHTML: (e) => e.getAttribute('data-callout'), rendered: false}}; },
    parseHTML() { return ['aside', 'section', 'div'].map((tag) => ({tag: tag + '[data-callout]'})); },
    renderHTML({node, HTMLAttributes}) { return ['aside', mergeAttributes(HTMLAttributes, {'data-callout': node.attrs.kind}), 0]; }
});
const Container = Node.create({
    name: 'legacyContainer', group: 'block', content: 'block+',
    addAttributes() { return {tag: {default: 'div', rendered: false},
        ...Object.fromEntries(globals.map((name) => [name, {default: null}]))}; },
    parseHTML() { return ['div', 'section', 'aside', 'figure'].map((tag) => ({tag: tag + ':not([data-callout])',
        getAttrs: (element) => tag === 'figure' && element.querySelector('img') ? false
            : {tag, ...Object.fromEntries(globals.map((name) => [name, element.getAttribute(name)]))}})); },
    renderHTML({node, HTMLAttributes}) { return [node.attrs.tag, HTMLAttributes, 0]; }
});
const Span = Mark.create({
    name: 'legacySpan', inclusive: false,
    addAttributes() { return Object.fromEntries([...globals, 'data-callout'].map((name) => [name, {default: null}])); },
    parseHTML() { return [{tag: 'span:not([data-text-color]):not([data-highlight])', getAttrs: (element) => Object.fromEntries([...globals, 'data-callout'].map((name) => [name, element.getAttribute(name)]))}]; },
    renderHTML({HTMLAttributes}) { return ['span', HTMLAttributes, 0]; }
});
const ImageFigure = Node.create({
    name: 'imageFigure', group: 'block', atom: true, draggable: true,
    addAttributes() { return Object.fromEntries(['src', 'alt', 'width', 'height', 'loading', 'data-attachment-id', 'data-draft-file-id', 'data-original-width', 'data-original-height', 'caption', 'figureId', 'figureClass', 'alignment'].map((name) => [name, {default: null, rendered: false}])); },
    parseHTML() {
        const attrs = (element) => {
            const image = element.tagName === 'IMG' ? element : element.querySelector('img');
            if (!image) return false;
            return {...Object.fromEntries(['src', 'alt', 'width', 'height', 'loading', 'data-attachment-id', 'data-draft-file-id', 'data-original-width', 'data-original-height'].map((name) => [name, image.getAttribute(name)])),
                caption: element.querySelector('figcaption')?.textContent || null,
                figureId: element.tagName === 'FIGURE' ? element.getAttribute('id') : null,
                figureClass: element.tagName === 'FIGURE' ? element.getAttribute('class') : null,
                alignment: element.tagName === 'FIGURE' ? element.getAttribute('data-align') : null};
        };
        return [{tag: 'figure', priority: 100, getAttrs: attrs}, {tag: 'img[src]', getAttrs: attrs}];
    },
    renderHTML({node}) {
        const attrs = node.attrs;
        const image = Object.fromEntries(['src', 'alt', 'width', 'height', 'loading', 'data-attachment-id', 'data-draft-file-id', 'data-original-width', 'data-original-height'].filter((key) => attrs[key] !== null).map((key) => [key, attrs[key]]));
        return ['figure', mergeAttributes(attrs.figureId ? {id: attrs.figureId} : {}, attrs.figureClass ? {class: attrs.figureClass} : {}, attrs.alignment ? {'data-align': attrs.alignment} : {}),
            ['img', image], ...(attrs.caption ? [['figcaption', {}, attrs.caption]] : [])];
    }
});
const Tasks = TaskItem.extend({
    renderHTML({node, HTMLAttributes}) { return ['li', mergeAttributes(HTMLAttributes, {'data-type': 'taskItem', 'data-checked': String(node.attrs.checked)}), 0]; }
});
const CompatibleTable = Table.extend({
    renderHTML({HTMLAttributes}) { return ['table', HTMLAttributes, ['tbody', 0]]; }
});

export function createRichEditor(element, html, sanitize, onUpdate, onSelection) {
    const editor = new Editor({
        element, content: html, injectCSS: false,
        extensions: [StarterKit.configure({link: {openOnClick: false, autolink: false, defaultProtocol: 'https', HTMLAttributes: {target: null, rel: null}}, codeBlock: false}),
            // Keep code language in the same data-language attribute used by PHP revisions.
            Node.create({name: 'codeBlock', group: 'block', content: 'text*', marks: '', code: true, defining: true,
                addAttributes() { return {language: {default: null, parseHTML: (e) => e.querySelector('code')?.getAttribute('data-language') || e.querySelector('code')?.className.replace(/^language-/, '') || null, rendered: false},
                    'data-line-numbers': {default: null, parseHTML: element => element.getAttribute('data-line-numbers')},
                    'data-code-wrap': {default: null, parseHTML: element => element.getAttribute('data-code-wrap')}}; },
                parseHTML() { return [{tag: 'pre:not([data-diagram])', preserveWhitespace: 'full'}]; },
                renderHTML({node, HTMLAttributes}) { return ['pre', HTMLAttributes, ['code', node.attrs.language ? {'data-language': node.attrs.language} : {}, 0]]; },
                addNodeView() { return ({node}) => {
                    const dom = document.createElement('pre'), gutter = document.createElement('span'), contentDOM = document.createElement('code');
                    gutter.className = 'code-line-numbers'; gutter.contentEditable = 'false'; gutter.setAttribute('aria-hidden', 'true'); dom.append(gutter, contentDOM);
                    const update = (next) => {
                        if (next.type.name !== 'codeBlock') return false;
                        gutter.hidden = next.attrs['data-line-numbers'] !== 'true';
                        gutter.textContent = Array.from({length: next.textContent.split('\n').length}, (_, index) => index + 1).join('\n');
                        for (const name of ['data-line-numbers', 'data-code-wrap']) next.attrs[name] === null ? dom.removeAttribute(name) : dom.setAttribute(name, next.attrs[name]);
                        next.attrs.language ? contentDOM.setAttribute('data-language', next.attrs.language) : contentDOM.removeAttribute('data-language');
                        return true;
                    };
                    update(node); return {dom, contentDOM, update};
                }; },
                addCommands() { return {toggleCodeBlock: (attributes) => ({commands}) => commands.toggleNode('codeBlock', 'paragraph', attributes)}; },
                addKeyboardShortcuts() { return {'Mod-Alt-c': () => this.editor.commands.toggleCodeBlock(), 'Mod-Enter': () => this.editor.commands.exitCode(), Enter: () => this.editor.commands.newlineInCode()}; }
            }), CompatibleTable.configure({resizable: true, allowTableNodeSelection: true}), TableRow, TableCell, TableHeader,
            TaskList, Tasks.configure({nested: true, a11y: {checkboxLabel: (node) => window.wikiTranslate('Task') + ': ' + (node.textContent || window.wikiTranslate('Empty task'))}}), ImageFigure, Callout, Container, Span, globalAttributes,
            ...documentBlocks],
        editorProps: {
            attributes: {'data-visual-editor': '', role: 'textbox', 'aria-label': element.dataset.label, 'aria-multiline': 'true', class: 'visual-editor document-content'},
            transformPastedHTML: sanitize,
            handleKeyDown: (view, event) => {
                if (event.key === 'Tab' && (editor.isActive('listItem') || editor.isActive('taskItem'))) {
                    const type = editor.isActive('taskItem') ? 'taskItem' : 'listItem';
                    event.preventDefault();
                    return event.shiftKey ? editor.commands.liftListItem(type) : editor.commands.sinkListItem(type);
                }
                return false;
            }
        },
        onUpdate: () => onUpdate(),
        onSelectionUpdate: () => onSelection?.()
    });
    editor.view.dom.addEventListener('paste', event => {
        if (event.clipboardData?.files.length) return;
        const html = event.clipboardData?.getData('text/html') || '';
        if (!html) return;
        const parsed = new DOMParser().parseFromString(html, 'text/html'), stack = [[parsed.body, 0]];
        let count = 0, exceeded = html.length > 2000000;
        while (!exceeded && stack.length) {
            const [node, depth] = stack.pop(); exceeded = depth > 100 || ++count > 50000;
            for (const child of node.children) stack.push([child, depth + 1]);
        }
        if (exceeded) {
            event.preventDefault(); event.stopImmediatePropagation();
            element.closest('[data-wiki-editor]').querySelector('[data-block-feedback]').textContent = 'Wklejana struktura przekracza limit dokumentu. Wklej mniejszy fragment. Bieżąca treść pozostaje bez zmian.';
        }
    }, true);
    return editor;
}

export function imagePosition(editor, image) {
    const dom = image.closest('figure') || image;
    const position = editor.view.posAtDOM(dom, 0);
    let found = null;
    editor.state.doc.descendants((node, pos) => {
        if (node.type.name === 'imageFigure' && (pos === position || pos + 1 === position)) found = pos;
    });
    return found;
}
export function selectModelImage(editor, image) {
    const pos = imagePosition(editor, image);
    if (pos !== null) editor.view.dispatch(editor.state.tr.setSelection(NodeSelection.create(editor.state.doc, pos)));
    return pos;
}
