import hljs from './syntax.js';
import {diagramPreview} from './diagram-preview.js';

document.querySelectorAll('[data-document-content] pre, [data-revision-content] pre').forEach((pre) => {
    const code = pre.querySelector('code'); if (!code) return;
    if (pre.dataset.diagram === 'mermaid') {
        const container = document.createElement('div'), status = document.createElement('p');
        status.className = 'diagram-status'; status.role = 'status';
        const source = code.textContent; pre.after(container, status); pre.hidden = true;
        const details = document.createElement('details'), summary = document.createElement('summary'); summary.textContent = 'Źródło diagramu'; details.append(summary, pre); container.before(details);
        pre.hidden = false; diagramPreview(container, status)(source); return;
    }
    const language = code.dataset.language;
    if (language && hljs.getLanguage(language)) { code.innerHTML = hljs.highlight(code.textContent, {language}).value; code.classList.add('hljs'); }
    if (pre.dataset.lineNumbers === 'true') {
        const numbers = document.createElement('span'); numbers.className = 'code-line-numbers'; numbers.setAttribute('aria-hidden', 'true');
        numbers.textContent = code.textContent.split('\n').map((_, index) => index + 1).join('\n'); pre.prepend(numbers);
    }
});
document.querySelectorAll('[data-document-content] table, [data-revision-content] table').forEach((table) => {
    const wrapper = document.createElement('div'); wrapper.className = 'table-scroll'; table.before(wrapper); wrapper.append(table);
    let minimumWidth = 0;
    for (const row of table.rows) for (const cell of row.cells) {
        const widths = cell.getAttribute('colwidth')?.split(',').map(Number);
        if (widths?.length && widths.every(width => width >= 0 && width <= 1600)) cell.style.width = widths.reduce((sum, width) => sum + (width || 80), 0) + 'px';
    }
    for (const row of table.rows) minimumWidth = Math.max(minimumWidth, [...row.cells].reduce((sum, cell) => sum + (cell.getAttribute('colwidth')?.split(',').reduce((total, width) => total + (Number(width) || 80), 0) || 80 * cell.colSpan), 0));
    table.style.minWidth = minimumWidth + 'px';
});
