export const sanitizeHtml = (html, policy) => {
    const doc = new DOMParser().parseFromString(String(html), 'text/html');
    const clean = (parent) => {
        for (const node of Array.from(parent.children)) {
            const tag = node.tagName.toLowerCase();
            if (['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math'].includes(tag)) { node.remove(); continue; }
            clean(node);
            if (!policy.tags.includes(tag)) { node.replaceWith(...node.childNodes); continue; }
            for (const attr of Array.from(node.attributes)) {
                const allowed = policy.global.concat(policy.attributes[tag] || []).includes(attr.name);
                const url = attr.value.trim();
                const safe = !['src', 'href'].includes(attr.name) || url === '' || /^[/#]/.test(url)
                    || /^(https?:|mailto:)/i.test(url) || (tag === 'img' && /^data:image\/(png|jpeg|gif|webp);base64,/i.test(url));
                if (!allowed || !safe) node.removeAttribute(attr.name);
            }
            if (tag === 'a' && node.target === '_blank') node.rel = 'noopener noreferrer';
        }
    };
    clean(doc.body);
    return doc.body.innerHTML;
};

// Serialization-only differences are harmless; structural/attribute changes need review.
export const contentSignature = (html) => {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const signature = (node, literal = false) => {
        if (node.nodeType === Node.TEXT_NODE) {
            if (!literal && !node.textContent.trim() && ['BODY', 'UL', 'OL', 'TABLE', 'THEAD', 'TBODY', 'TR'].includes(node.parentElement?.tagName)) return '';
            return literal ? node.textContent : node.textContent.replace(/\s+/g, ' ');
        }
        if (node.nodeType !== Node.ELEMENT_NODE) return '';
        let tag = node.tagName.toLowerCase();
        tag = ({b: 'strong', i: 'em', s: 'del', tbody: '', thead: ''})[tag] ?? tag;
        if (tag === 'p' && ['LI', 'TD', 'TH'].includes(node.parentElement?.tagName)
            && [...node.parentElement.children].filter((child) => child.tagName === 'P').length === 1) tag = '';
        if (tag === 'p' && !node.textContent.trim() && !node.querySelector('img')) return '';
        const attrs = [...node.attributes].filter((a) => !['style', 'contenteditable'].includes(a.name)
            && !(['rowspan', 'colspan'].includes(a.name) && a.value === '1')
            && !(a.name === 'class' && /^(wiki-link|language-|ProseMirror)/.test(a.value)));
        const attr = attrs.map((a) => [a.name, a.value]).sort((a, b) => a[0].localeCompare(b[0]));
        let children = [...node.childNodes].map((child) => signature(child, literal || ['pre', 'code'].includes(tag))).join('');
        if (['body', 'ul', 'ol', 'table', 'tr', 'li', 'td', 'th', 'p'].includes(tag)) children = children.trim();
        return tag ? tag + JSON.stringify(attr) + '(' + children + ')' : children;
    };
    return signature(doc.body);
};
