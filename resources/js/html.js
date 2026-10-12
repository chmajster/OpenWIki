export const sanitizeHtml = (html, policy) => {
    const doc = new DOMParser().parseFromString(String(html), 'text/html');
        for (const node of Array.from(doc.body.querySelectorAll('*')).reverse()) {
            const tag = node.tagName.toLowerCase();
            if (['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'noscript', 'xmp', 'plaintext'].includes(tag)
                || node.namespaceURI !== 'http://www.w3.org/1999/xhtml') { node.remove(); continue; }
            if (!policy.tags.includes(tag)) { node.replaceWith(...node.childNodes); continue; }
            for (const attr of Array.from(node.attributes)) {
                const allowed = policy.global.concat(policy.attributes[tag] || []).includes(attr.name);
                const url = attr.value.trim();
                const safe = !['src', 'href'].includes(attr.name) || (!/[\x00-\x20\x7f\\]/.test(url) && (url === '' || /^\/(?!\/)|^#/.test(url)
                    || /^https?:/i.test(url) || (tag === 'a' && /^mailto:/i.test(url))
                    || (tag === 'img' && /^data:image\/(png|jpeg|gif|webp);base64,[A-Za-z0-9+/]+={0,2}$/i.test(url))));
                const controlled = !policy.values?.[attr.name] || policy.values[attr.name].includes(attr.value);
                const pattern = !policy.patterns?.[attr.name] || new RegExp(policy.patterns[attr.name] + '(?![\\s\\S])', 'u').test(attr.value);
                const range = !policy.ranges?.[attr.name] || (/^-?[0-9]{1,19}$/.test(attr.value)
                    && Number(attr.value) >= policy.ranges[attr.name][0] && Number(attr.value) <= policy.ranges[attr.name][1]);
                const widths = attr.name !== 'colwidth' || (/^(0|[1-9][0-9]{0,3})(,(0|[1-9][0-9]{0,3})){0,29}$/.test(attr.value) && attr.value.split(',').every(value => Number(value) <= 1600));
                const spans = !['colspan', 'rowspan'].includes(attr.name) || (/^[0-9]+$/.test(attr.value) && Number(attr.value) >= 1 && Number(attr.value) <= 100);
                const dimensions = !['width', 'height', 'data-original-width', 'data-original-height'].includes(attr.name) || (/^[0-9]+$/.test(attr.value) && Number(attr.value) >= 1 && Number(attr.value) <= 20000);
                if (!allowed || !safe || !controlled || !pattern || !range || !widths || !spans || !dimensions) node.removeAttribute(attr.name);
            }
            if (tag === 'a' && node.target === '_blank') node.rel = 'noopener noreferrer';
        }
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
