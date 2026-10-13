export function setupUploads(root, editor, local, context, uuid, changed, pending, failed) {
    const rows = new Map();
    const list = root.querySelector('[data-upload-list]');
    const prefix = () => 'upload:' + root.dataset.instanceId + ':' + root.dataset.userId + ':' + root.dataset.spaceId + ':' + context().documentKey + ':';
    const updatePending = () => pending([...rows.values()].filter((row) => !row.done).length);
    const insert = async (data, cover = false) => {
        let exists = false;
        editor.state.doc.descendants(node => { if (String(node.attrs['data-draft-file-id']) === String(data.id) || node.marks.some(mark => String(mark.attrs['data-draft-file-id']) === String(data.id))) exists = true; });
        if (exists) return changed();
        editor.view.focus();
        const image = {type: 'imageFigure', attrs: {
            src: data.thumbnail_url, 'data-draft-file-id': String(data.id), 'data-original-width': String(data.width), 'data-original-height': String(data.height),
            width: String(Math.min(1280, data.width)), alt: '', loading: 'lazy', figureClass: cover ? 'wiki-image page-cover' : 'wiki-image'
        }};
        if (data.width !== null) {
            if (cover) editor.commands.insertContentAt(0, image); else editor.commands.insertContent(image);
        } else editor.commands.insertContent({type: 'paragraph', content: [{type: 'text', text: data.name + ' · ' + data.mime_type + ' · ' + Math.ceil(data.size_bytes / 1024) + ' KiB',
            marks: [{type: 'link', attrs: {href: data.download_url, 'data-draft-file-id': String(data.id), 'data-file-size': String(data.size_bytes), 'data-file-type': data.mime_type, class: 'wiki-file'}}]}]});
        return changed();
    };
    const send = async (row) => {
        if (row.cancelled || row.running || row.done || !context().canSend || row.documentKey !== context().documentKey) return;
        row.running = true; row.retry.hidden = true; row.status.textContent = 'Przesyłanie…';
        try {
            if (!navigator.onLine) throw new Error('Brak połączenia. Plik oczekuje na ponowienie.');
            const data = await new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                row.xhr = xhr; xhr.onabort = () => reject(new Error('Upload anulowany.'));
                xhr.open('POST', root.dataset.filesUrl); xhr.setRequestHeader('Accept', 'application/json'); xhr.timeout = 30000;
                xhr.upload.onprogress = (event) => { if (event.lengthComputable) row.progress.value = event.loaded / event.total * 100; };
                xhr.onerror = () => reject(new Error('Błąd sieci podczas uploadu. Plik pozostaje w kolejce.'));
                xhr.ontimeout = () => reject(new Error('Nie otrzymano potwierdzenia uploadu. Ponowienie użyje tej samej operacji.'));
                xhr.onload = () => {
                    let result;
                    try { result = JSON.parse(xhr.responseText); }
                    catch { reject(new Error('Nieoczekiwana odpowiedź uploadu.')); return; }
                    if (xhr.status < 200 || xhr.status >= 300) {
                        const error = new Error(window.wikiTranslate(result.error?.message || 'Upload nie powiódł się.')); error.status = xhr.status; reject(error); return;
                    }
                    if (String(result.data.user_id) !== root.dataset.userId) { reject(new Error('Konto zmieniło się. Zachowano plik dla pierwotnego konta.')); return; }
                    resolve(result.data);
                };
                const body = new FormData();
                body.append('_token', context().csrfToken); body.append('editor_user_id', root.dataset.userId);
                body.append('document_key', row.documentKey); body.append('operation_id', row.operation);
                body.append('file', row.file, row.file.name || 'clipboard-image.png'); xhr.send(body);
            });
            if (row.cancelled) return;
            if (!context().canSend) throw new Error('Zmieniono konto lub uprawnienia. Plik zachowano dla pierwotnego konta.');
            const protectedCopy = await insert(data, row.cover); row.done = true; row.progress.value = 100;
            row.status.textContent = 'Zapisano jako prywatny plik szkicu.';
            if (protectedCopy) await local.removeUpload(row.key).catch(() => {});
        } catch (error) { if (!row.cancelled) { row.status.textContent = error.message; row.retry.hidden = false; failed(error); } }
        finally { row.running = false; row.xhr = null; updatePending(); }
    };
    const add = async (file, stored = null, cover = false) => {
        if (!file || !context().canSend) return;
        if (file.size > 50 * 1024 * 1024) { failed(new Error('Maksymalny rozmiar pliku: 50 MiB.')); return; }
        const operation = stored?.operation || uuid(), key = stored?.key || prefix() + operation;
        if (rows.has(key)) return;
        const element = document.createElement('li'), name = document.createElement('strong'), status = document.createElement('span'), progress = document.createElement('progress'), retry = document.createElement('button');
        name.textContent = file.name; status.textContent = 'Zapisuję plik lokalnie…'; progress.max = 100; progress.value = 0;
        retry.type = 'button'; retry.className = 'button'; retry.textContent = 'Ponów upload'; retry.hidden = true;
        const cancel = document.createElement('button'); cancel.type = 'button'; cancel.textContent = 'Usuń z kolejki'; cancel.className = 'button button--ghost';
        element.append(name, progress, status, retry, cancel); list.append(element);
        const row = {key, operation, documentKey: context().documentKey, file, cover: stored?.cover || cover, status, progress, retry, done: false, running: false};
        rows.set(key, row); updatePending(); retry.onclick = () => { if (!context().canSend) { status.textContent = 'Wróć do trybu Visual i sprawdź konto oraz uprawnienia przed ponowieniem.'; return; } send(row); };
        cancel.onclick = async () => {
            row.cancelled = true; row.xhr?.abort(); rows.delete(key); updatePending();
            try { await local.removeUpload(key); element.remove(); }
            catch { status.textContent = 'Anulowano w tej karcie. Lokalny magazyn nie pozwolił usunąć pliku z kolejki.'; retry.hidden = true; cancel.disabled = true; }
        };
        try { await local.putUpload(key, {file, operation, documentKey: row.documentKey, cover: row.cover}); }
        catch { status.textContent = 'Plik jest tylko w pamięci tej karty. Lokalny magazyn jest niedostępny.'; failed(new Error(status.textContent)); }
        send(row);
    };
    const input = root.querySelector('[data-attachment-file]');
    root.querySelector('[data-attachment-picker]').onclick = () => input.click();
    input.onchange = () => { for (const file of input.files) add(file); input.value = ''; };
    const imageInput = root.querySelector('[data-image-file]');
    let nextCover = false;
    root.querySelector('[data-image-picker]').onclick = () => { nextCover = false; imageInput.click(); };
    root.querySelector('[data-add-cover]').onclick = () => { if (context().canSend) { nextCover = true; imageInput.click(); } };
    imageInput.onchange = () => { for (const file of imageInput.files) add(file, null, nextCover); nextCover = false; imageInput.value = ''; };
    editor.view.dom.addEventListener('paste', (event) => {
        const files = [...(event.clipboardData?.files || [])];
        if (!files.length) return;
        event.preventDefault(); event.stopPropagation();
        if (!context().canSend) { failed(new Error('Dodawanie plików jest chwilowo niedostępne. Poczekaj na zapis lub konwersję i sprawdź tryb oraz uprawnienia.')); return; }
        files.forEach((file) => add(file));
    }, true);
    root.addEventListener('dragover', (event) => { if ([...(event.dataTransfer?.types || [])].includes('Files')) event.preventDefault(); });
    root.addEventListener('drop', (event) => {
        if (!event.dataTransfer?.files.length) return;
        event.preventDefault(); event.stopPropagation();
        if (!context().canSend) { failed(new Error('Dodawanie plików jest chwilowo niedostępne. Poczekaj na zapis lub konwersję i sprawdź tryb oraz uprawnienia.')); return; }
        [...event.dataTransfer.files].forEach((file) => add(file));
    }, true);
    local.uploads(prefix()).then((stored) => stored.forEach((row) => add(row.file, row)));
    window.addEventListener('online', () => { for (const row of rows.values()) send(row); });
}
