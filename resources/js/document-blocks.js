import {Node, Mark, Extension, mergeAttributes} from '@tiptap/core';
import {Plugin, PluginKey} from '@tiptap/pm/state';
import {Decoration, DecorationSet} from '@tiptap/pm/view';
import hljs from './syntax.js';

const simpleMark = (name, tag) => Mark.create({name, parseHTML: () => [{tag}], renderHTML: () => [tag, {}, 0],
    addCommands() { return {[name]: () => ({commands}) => commands.toggleMark(name)}; }});
const Color = Mark.create({
    name: 'controlledStyle',
    addAttributes() { return {'data-text-color': {default: null}, 'data-highlight': {default: null}}; },
    parseHTML() { return [{tag: 'span[data-text-color]'}, {tag: 'span[data-highlight]'}]; },
    renderHTML({HTMLAttributes}) { return ['span', HTMLAttributes, 0]; }
});
const Alignment = Extension.create({name: 'controlledAlignment', addGlobalAttributes() {
    return [{types: ['paragraph', 'heading', 'blockquote', 'tableCell', 'tableHeader'], attributes: {'data-align': {default: null}}},
        {types: ['tableCell', 'tableHeader'], attributes: {'data-cell-color': {default: null}}}];
}});
const Disclosure = Node.create({
    name: 'disclosure', group: 'block', content: 'block+', defining: true, priority: 110,
    addAttributes() { return {title: {default: 'Szczegóły', rendered: false}}; },
    parseHTML() { return [{tag: 'details[data-disclosure]', contentElement: '[data-disclosure-content]', getAttrs: (element) => ({title: element.querySelector('summary')?.textContent || 'Szczegóły'})}]; },
    renderHTML({node}) { return ['details', {'data-disclosure': 'true'}, ['summary', {}, node.attrs.title], ['div', {'data-disclosure-content': 'true'}, 0]]; },
    addNodeView() { return ({node}) => {
        const dom = document.createElement('details'), summary = document.createElement('summary'), contentDOM = document.createElement('div');
        dom.open = true; dom.dataset.disclosure = 'true'; summary.textContent = node.attrs.title; dom.append(summary, contentDOM);
        return {dom, contentDOM, update: (next) => { if (next.type !== node.type) return false; summary.textContent = next.attrs.title; return true; }};
    }; }
});
const Columns = Node.create({
    name: 'columns', group: 'block', content: 'column{2,3}', defining: true, priority: 110,
    addAttributes() { return {count: {default: 2, rendered: false, parseHTML: (element) => Number(element.dataset.columns)}}; },
    parseHTML: () => [{tag: 'div[data-columns]'}],
    renderHTML: ({node}) => ['div', {'data-columns': String(node.attrs.count)}, 0]
});
const Column = Node.create({name: 'column', content: 'block+', defining: true, priority: 110,
    parseHTML: () => [{tag: 'div[data-column]'}], renderHTML: () => ['div', {'data-column': 'true'}, 0]});
const Diagram = Node.create({name: 'diagram', group: 'block', content: 'text*', marks: '', code: true, defining: true, priority: 110,
    parseHTML: () => [{tag: 'pre[data-diagram="mermaid"]', preserveWhitespace: 'full'}],
    renderHTML: () => ['pre', {'data-diagram': 'mermaid'}, ['code', {}, 0]]});

// Decorations affect the view only; source whitespace, tabs and history stay in the model.
const Syntax = Extension.create({name: 'codeSyntax', addProseMirrorPlugins() {
    const cache = new Map();
    const build = (doc) => {
        const decorations = [];
        doc.descendants((node, pos) => {
            if (node.type.name !== 'codeBlock' || !node.attrs.language || !hljs.getLanguage(node.attrs.language) || node.textContent.length > 100000) return;
            const key = node.attrs.language + ':' + node.textContent;
            let ranges = cache.get(key);
            if (!ranges) {
                const parsed = new DOMParser().parseFromString(hljs.highlight(node.textContent, {language: node.attrs.language}).value, 'text/html');
                ranges = []; let offset = 0;
                const walk = (parent, classes = []) => {
                    for (const child of parent.childNodes) {
                        if (child.nodeType === 3) { if (classes.length && child.textContent.length) ranges.push([offset, offset + child.textContent.length, classes.join(' ')]); offset += child.textContent.length; }
                        else walk(child, classes.concat([...child.classList]));
                    }
                };
                walk(parsed.body); if (cache.size > 20) cache.clear(); cache.set(key, ranges);
            }
            for (const [from, to, className] of ranges) decorations.push(Decoration.inline(pos + 1 + from, pos + 1 + to, {class: className}));
        });
        return DecorationSet.create(doc, decorations);
    };
    return [new Plugin({key: new PluginKey('wikiSyntax'), state: {init: (_, state) => build(state.doc), apply: (tr, previous) => tr.docChanged ? build(tr.doc) : previous}, props: {decorations(state) { return this.getState(state); }}})];
}});
export const documentBlocks = [simpleMark('superscript', 'sup'), simpleMark('subscript', 'sub'), Color, Alignment, Disclosure, Columns, Column, Diagram, Syntax];
