export function diagramPreview(container, status) {
    const iframe = document.createElement('iframe');
    iframe.src = '/assets/diagram.html'; iframe.sandbox = 'allow-scripts'; iframe.title = 'Bezpieczny podgląd Mermaid'; iframe.className = 'diagram-preview';
    container.replaceChildren(iframe);
    let loaded = false, sequence = 0, source = '';
    const send = () => { if (loaded) iframe.contentWindow.postMessage({type: 'openwiki-mermaid', source, sequence}, '*'); };
    iframe.onload = () => { loaded = true; send(); };
    window.addEventListener('message', (event) => {
        if (event.source !== iframe.contentWindow || event.data?.type !== 'openwiki-mermaid-result' || event.data.sequence !== sequence) return;
        if (status) { status.textContent = event.data.error || 'Diagram poprawny. Źródło pozostaje w dokumencie.'; status.dataset.valid = String(!event.data.error); }
        if (event.data.height) iframe.height = String(Math.max(100, Math.min(800, event.data.height)));
    });
    return (next) => { source = next; sequence++; if (status) status.textContent = 'Renderowanie diagramu…'; send(); };
}
