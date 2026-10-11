import {Editor, Extension, Node, Mark, mergeAttributes} from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import {Table, TableRow, TableCell, TableHeader} from '@tiptap/extension-table';
import {TaskList, TaskItem} from '@tiptap/extension-list';
import {NodeSelection} from '@tiptap/pm/state';

const globals = ['id', 'class', 'title', 'aria-label'];
const globalAttributes = Extension.create({
    name: 'legacyAttributes',
    addGlobalAttributes() {
        return [{types: ['paragraph', 'heading', 'blockquote', 'bulletList', 'orderedList', 'listItem', 'taskList', 'taskItem', 'callout', 'table', 'tableRow', 'tableCell', 'tableHeader', 'horizontalRule', 'link', 'bold', 'italic', 'underline', 'strike', 'code'],
            attributes: Object.fromEntries(globals.map((name) => [name, {default: null, parseHTML: (element) => element.getAttribute(name)}]))}];
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
    parseHTML() { return [{tag: 'span', getAttrs: (element) => Object.fromEntries([...globals, 'data-callout'].map((name) => [name, element.getAttribute(name)]))}]; },
    renderHTML({HTMLAttributes}) { return ['span', HTMLAttributes, 0]; }
});
const ImageFigure = Node.create({
    name: 'imageFigure', group: 'block', atom: true, draggable: true,
    addAttributes() { return Object.fromEntries(['src', 'alt', 'width', 'height', 'loading', 'data-attachment-id', 'data-original-width', 'data-original-height', 'caption', 'figureId', 'figureClass'].map((name) => [name, {default: null, rendered: false}])); },
    parseHTML() {
        const attrs = (element) => {
            const image = element.tagName === 'IMG' ? element : element.querySelector('img');
            if (!image) return false;
            return {...Object.fromEntries(['src', 'alt', 'width', 'height', 'loading', 'data-attachment-id', 'data-original-width', 'data-original-height'].map((name) => [name, image.getAttribute(name)])),
                caption: element.querySelector('figcaption')?.textContent || null,
                figureId: element.tagName === 'FIGURE' ? element.getAttribute('id') : null,
                figureClass: element.tagName === 'FIGURE' ? element.getAttribute('class') : null};
        };
        return [{tag: 'figure', priority: 100, getAttrs: attrs}, {tag: 'img[src]', getAttrs: attrs}];
    },
    renderHTML({node}) {
        const attrs = node.attrs;
        const image = Object.fromEntries(['src', 'alt', 'width', 'height', 'loading', 'data-attachment-id', 'data-original-width', 'data-original-height'].filter((key) => attrs[key] !== null).map((key) => [key, attrs[key]]));
        return ['figure', mergeAttributes(attrs.figureId ? {id: attrs.figureId} : {}, attrs.figureClass ? {class: attrs.figureClass} : {}),
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
                addAttributes() { return {language: {default: null, parseHTML: (e) => e.querySelector('code')?.getAttribute('data-language') || e.querySelector('code')?.className.replace(/^language-/, '') || null, rendered: false}}; },
                parseHTML() { return [{tag: 'pre', preserveWhitespace: 'full'}]; },
                renderHTML({node}) { return ['pre', {}, ['code', node.attrs.language ? {'data-language': node.attrs.language} : {}, 0]]; },
                addCommands() { return {toggleCodeBlock: (attributes) => ({commands}) => commands.toggleNode('codeBlock', 'paragraph', attributes)}; },
                addKeyboardShortcuts() { return {'Mod-Alt-c': () => this.editor.commands.toggleCodeBlock(), 'Mod-Enter': () => this.editor.commands.exitCode(), Enter: () => this.editor.commands.newlineInCode()}; }
            }), CompatibleTable.configure({resizable: false}), TableRow, TableCell, TableHeader,
            TaskList, Tasks.configure({nested: true, a11y: {checkboxLabel: (node) => window.wikiTranslate('Task') + ': ' + (node.textContent || window.wikiTranslate('Empty task'))}}), ImageFigure, Callout, Container, Span, globalAttributes],
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
