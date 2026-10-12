import mermaid from 'mermaid';

mermaid.initialize({startOnLoad: false, securityLevel: 'strict', suppressErrorRendering: true,
    maxTextSize: 20000, maxEdges: 500, flowchart: {htmlLabels: false}, theme: 'default',
    secure: ['securityLevel', 'startOnLoad', 'maxTextSize', 'maxEdges', 'secure', 'htmlLabels']});
let serial = Promise.resolve();
window.addEventListener('message', (event) => {
    if (event.source !== parent || event.data?.type !== 'openwiki-mermaid' || typeof event.data.source !== 'string') return;
    const {source, sequence} = event.data;
    serial = serial.then(async () => {
        const result = document.querySelector('#result');
        try {
            // Configuration, links, HTML labels and remote resources cannot enter a diagram.
            if (source.length > 20000 || /^\s*---/m.test(source) || /%%\s*\{|\bclick\s|https?:|javascript:|data:|\/\/|url\s*\(|@import|<[^>]*>/i.test(source)) throw new Error('Konfiguracja, HTML i zewnętrzne odwołania są niedozwolone w diagramie.');
            await mermaid.parse(source);
            const {svg} = await mermaid.render('diagram-' + sequence + '-' + Math.random().toString(16).slice(2), source);
            const dom = new DOMParser().parseFromString(svg, 'image/svg+xml');
            dom.querySelectorAll('script,foreignObject,image,a,iframe,object,embed').forEach((node) => node.remove());
            dom.querySelectorAll('*').forEach((node) => {
                for (const attr of [...node.attributes]) {
                    if (/^on/i.test(attr.name) || ((attr.name === 'href' || attr.name === 'xlink:href') && !attr.value.startsWith('#')) || /url\(\s*["']?(?!#)/i.test(attr.value)) node.removeAttribute(attr.name);
                }
            });
            const image = new Image(); image.alt = 'Diagram Mermaid';
            image.src = 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(new XMLSerializer().serializeToString(dom.documentElement))));
            image.style.maxWidth = '100%'; result.replaceChildren(image);
            image.onload = () => parent.postMessage({type: 'openwiki-mermaid-result', sequence, height: Math.min(800, image.height + 30)}, '*');
            parent.postMessage({type: 'openwiki-mermaid-result', sequence, valid: true}, '*');
        } catch (error) {
            result.textContent = 'Błąd diagramu: ' + String(error.message || error).slice(0, 500);
            parent.postMessage({type: 'openwiki-mermaid-result', sequence, error: result.textContent, height: 120}, '*');
        }
    }).catch(() => {});
});
