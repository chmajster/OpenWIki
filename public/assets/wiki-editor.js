'use strict';

const editorRoot = document.querySelector('[data-wiki-editor]');
if (editorRoot) {
    const formatInput = editorRoot.querySelector('[data-content-format]');
    const visualEditor = editorRoot.querySelector('[data-visual-editor]');
    const markdownEditor = editorRoot.querySelector('[data-markdown-editor]');
    const form = editorRoot.querySelector('[data-editor-form]');
    const saveButton = editorRoot.querySelector('[data-save-button]');
    const saveState = editorRoot.querySelector('[data-save-state]');
    const lockWarning = editorRoot.querySelector('[data-edit-lock-warning]');
    const recoveryBanner = editorRoot.querySelector('[data-recovery-banner]');
    const recoveryText = editorRoot.querySelector('[data-recovery-text]');
    const recoverySelect = editorRoot.querySelector('[data-recovery-select]');
    const tabs = Array.from(editorRoot.querySelectorAll('[data-editor-mode]'));
    const panes = Array.from(editorRoot.querySelectorAll('[data-pane]'));
    let isEdit = editorRoot.dataset.isEdit === '1';
    const csrfToken = form.elements._token.value;
    const baseVersionField = form.elements.base_version;
    const userId = editorRoot.dataset.userId;
    const uuid = () => typeof crypto.randomUUID === 'function' ? crypto.randomUUID()
        : '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (character) =>
            (Number(character) ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> Number(character) / 4).toString(16));
    const editorId = uuid();
    const url = new URL(window.location.href);
    let documentId = url.searchParams.get('document');
    if (!isEdit && !/^[a-f0-9-]{36}$/.test(documentId || '')) {
        documentId = uuid();
        url.searchParams.set('document', documentId);
        history.replaceState(null, '', url);
    }
    let documentKey = isEdit ? 'page:' + editorRoot.dataset.pageId : 'new:' + documentId;
    let recoveryPrefix = 'openwiki:draft:v2:' + userId + ':' + editorRoot.dataset.spaceId + ':' + documentKey + ':';
    let recoveryKey = recoveryPrefix + editorId;
    let generation = 1;
    let draftGeneration = 0;
    let dirty = false;
    let saving = false;
    let converting = false;
    let accountChanged = false;
    let conflict = null;
    let conversionBackup = null;
    let originalMarkdown = null;
    let recoveredSource = null;
    let lockToken = '';
    let autosaveTimer = null;
    let localSaveTimer = null;
    let pendingAutosave = null;
    let pendingConversion = null;
    let pendingImages = 0;
    let lastSaveError = '';
    const recoveryCopies = new Map();
    const undoButton = editorRoot.querySelector('[data-undo-conversion]');
    const conversionDialog = editorRoot.querySelector('[data-conversion-dialog]');
    const conflictPanel = editorRoot.querySelector('[data-conflict-panel]');

    // Local recovery is untrusted too. Keep the same allowlist as the server.
    const safeHtml = (html) => {
        const doc = new DOMParser().parseFromString(String(html), 'text/html');
        const tags = new Set('p br strong b em i u s del h1 h2 h3 h4 h5 h6 ul ol li blockquote pre code a img figure figcaption table thead tbody tr th td hr div span section aside'.split(' '));
        const global = ['id', 'class', 'title', 'aria-label', 'data-callout'];
        const attributes = {a: ['href', 'target', 'rel'], img: ['src', 'alt', 'width', 'height', 'loading', 'data-attachment-id', 'data-original-width', 'data-original-height'], td: ['colspan', 'rowspan'], th: ['colspan', 'rowspan', 'scope'], code: ['data-language']};
        const clean = (parent) => {
            for (const node of Array.from(parent.children)) {
                const tag = node.tagName.toLowerCase();
                if (['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math'].includes(tag)) { node.remove(); continue; }
                clean(node);
                if (!tags.has(tag)) { node.replaceWith(...node.childNodes); continue; }
                for (const attr of Array.from(node.attributes)) {
                    const allowed = global.concat(attributes[tag] || []).includes(attr.name);
                    let safeUrl = true;
                    if (['src', 'href'].includes(attr.name)) {
                        const value = attr.value.trim();
                        safeUrl = value === '' || /^[/#]/.test(value) || /^(https?:|mailto:)/i.test(value)
                            || (tag === 'img' && /^data:image\/(png|jpeg|gif|webp);base64,/i.test(value));
                    }
                    if (!allowed || !safeUrl) node.removeAttribute(attr.name);
                }
                if (tag === 'a' && node.target === '_blank') node.rel = 'noopener noreferrer';
            }
        };
        clean(doc.body);
        return doc.body.innerHTML;
    };

    const setMode = (mode) => {
        const selected = mode === 'markdown' ? 'markdown' : 'visual';
        formatInput.value = selected;
        tabs.forEach((tab) => {
            tab.classList.toggle('is-active', tab.dataset.editorMode === selected);
            tab.setAttribute('aria-selected', tab.dataset.editorMode === selected ? 'true' : 'false');
        });
        panes.forEach((pane) => { pane.hidden = pane.dataset.pane !== selected; });
    };
    const updateSaveButton = () => { saveButton.disabled = saving || converting || pendingImages > 0 || accountChanged || Boolean(conflict); };
    const snapshot = () => ({
        userId, documentKey, editorId, sequence: generation, savedAt: new Date().toISOString(),
        baseVersion: Number(baseVersionField.value), title: form.elements.title.value,
        slug: form.elements.slug.value, parentId: form.elements.parent_id.value,
        status: form.elements.status.value, tags: form.elements.tags.value,
        changeSummary: form.elements.change_summary.value, format: formatInput.value,
        html: visualEditor.innerHTML, markdown: markdownEditor.value, conversionBackup,
        originalMarkdown
    });
    const persistLocalRecovery = () => {
        try { localStorage.setItem(recoveryKey, JSON.stringify(snapshot())); return true; }
        catch { return false; }
    };
    const markDirty = () => {
        generation++;
        dirty = true;
        lastSaveError = '';
        saveState.textContent = 'Unsaved changes. Recovery copy pending.';
        window.clearTimeout(localSaveTimer);
        localSaveTimer = window.setTimeout(() => {
            if (!persistLocalRecovery()) saveState.textContent = 'Local storage is unavailable. Keep this editor open until saving succeeds.';
        }, 350);
        window.clearTimeout(autosaveTimer);
        autosaveTimer = window.setTimeout(autosave, 1500);
        if (conflict) editorRoot.querySelector('[data-conflict-local]').textContent = describe(snapshot());
    };
    const restoreSnapshot = (data) => {
        for (const key of ['title', 'slug', 'status', 'tags']) {
            if (typeof data[key] === 'string') form.elements[key].value = data[key];
        }
        if (typeof data.parentId === 'string') form.elements.parent_id.value = data.parentId;
        if (typeof data.changeSummary === 'string') form.elements.change_summary.value = data.changeSummary;
        if (Number.isInteger(Number(data.baseVersion)) && Number(data.baseVersion) >= (isEdit ? 1 : 0)) baseVersionField.value = String(data.baseVersion);
        visualEditor.innerHTML = safeHtml(data.html || '');
        markdownEditor.value = typeof data.markdown === 'string' ? data.markdown : '';
        conversionBackup = data.conversionBackup || null;
        originalMarkdown = data.originalMarkdown || null;
        undoButton.hidden = !conversionBackup;
        setMode(data.format);
        conflict = null;
        conflictPanel.hidden = true;
        updateSaveButton();
        markDirty();
    };
    const showRecovery = () => {
        const selected = recoverySelect.value;
        recoverySelect.replaceChildren();
        const time = (data) => Date.parse(/T/.test(data.savedAt) ? data.savedAt : data.savedAt?.replace(' ', 'T') + 'Z') || 0;
        for (const [key, data] of [...recoveryCopies].sort((left, right) => time(right[1]) - time(left[1]))) {
            const option = document.createElement('option');
            option.value = key;
            option.textContent = (data.title || 'Untitled page') + ' — ' + data.savedAt + ' — base ' + data.baseVersion;
            recoverySelect.appendChild(option);
        }
        if (recoveryCopies.has(selected)) recoverySelect.value = selected;
        recoveryText.textContent = 'Choose a recovery copy. Restoring retains its original base version.';
        recoveryBanner.hidden = recoveryCopies.size === 0;
    };
    try {
        for (let index = 0; index < localStorage.length; index++) {
            const key = localStorage.key(index);
            if (!key?.startsWith(recoveryPrefix)) continue;
            try {
                const data = JSON.parse(localStorage.getItem(key));
                if (String(data.userId) === userId && data.documentKey === documentKey
                    && typeof data.editorId === 'string' && /^(?:legacy|[a-f0-9-]{36})$/.test(data.editorId)
                    && Number.isInteger(data.sequence) && data.sequence >= 1) recoveryCopies.set(data.editorId, {...data, localKey: key});
            } catch { /* A corrupt copy must not prevent opening the editor. */ }
        }
    } catch { saveState.textContent = 'Local recovery storage is unavailable.'; }
    showRecovery();
    editorRoot.querySelector('[data-restore-recovery]').addEventListener('click', () => {
        const data = recoveryCopies.get(recoverySelect.value);
        if (!data) return;
        recoveredSource = data;
        restoreSnapshot(data);
        recoveryBanner.hidden = true;
        saveState.textContent = 'Recovery restored with base version ' + data.baseVersion + '. Saving still checks for concurrent changes.';
    });
    editorRoot.querySelector('[data-hide-recovery]').addEventListener('click', () => { recoveryBanner.hidden = true; });

    const fields = (data) => ({
        _token: csrfToken, editor_user_id: userId, document_key: documentKey, editor_id: editorId,
        draft_sequence: data.sequence, base_version: data.baseVersion,
        title: data.title, slug: data.slug, parent_id: data.parentId, status: data.status,
        tags: data.tags, change_summary: data.changeSummary, content_format: data.format,
        content_html: data.html, content_markdown: data.markdown, conversion_backup: data.conversionBackup,
        ...(recoveredSource ? {recovery_editor_id: recoveredSource.editorId, recovery_sequence: recoveredSource.sequence} : {})
    });
    const requestJson = async (target, body = null) => {
        const controller = new AbortController();
        const timer = window.setTimeout(() => controller.abort(), 20000);
        try {
            const response = await fetch(target, {method: body === null ? 'GET' : 'POST',
                headers: {'Accept': 'application/json', ...(body === null ? {} : {'Content-Type': 'application/json'})},
                body: body === null ? null : JSON.stringify(body), credentials: 'same-origin', signal: controller.signal});
            if (response.redirected) throw new Error('The server redirected this request. Sign in again if your session expired; your document is retained.');
            let payload;
            try { payload = await response.json(); } catch { throw new Error('Unexpected server response (' + response.status + '). Your document remains in the editor.'); }
            if (!response.ok) {
                const error = new Error(payload?.error?.message || 'Request failed (' + response.status + ').');
                error.conflict = payload?.error?.conflict;
                throw error;
            }
            if (payload.data?.user_id && String(payload.data.user_id) !== userId) throw new Error('The signed-in account changed. Reopen the editor.');
            return payload.data;
        } catch (error) {
            if (error.name === 'AbortError') throw new Error('The request timed out before confirmation.');
            if (error instanceof TypeError) throw new Error('A network error prevented confirmation.');
            throw error;
        } finally { window.clearTimeout(timer); }
    };
    const describe = (data) => {
        if (!data) return 'This base revision is unavailable.';
        const metadata = {
            title: data.title, slug: data.slug, parent: data.parentId ?? data.parent_id,
            status: data.status, tags: data.tags ?? data.tags_json,
            changeSummary: data.changeSummary ?? data.change_summary,
            version: data.baseVersion ?? data.version ?? data.revision_number
        };
        return JSON.stringify(metadata, null, 2) + '\n\n' + ((data.format ?? data.content_format) === 'markdown'
            ? (data.markdown ?? data.content_markdown ?? '') : (data.html ?? data.content_html ?? ''));
    };
    const showConflict = (data) => {
        conflict = data;
        editorRoot.querySelector('[data-conflict-base]').textContent = describe(data.base);
        editorRoot.querySelector('[data-conflict-local]').textContent = describe(snapshot());
        editorRoot.querySelector('[data-conflict-server]').textContent = describe(data.server);
        conflictPanel.hidden = false;
        updateSaveButton();
    };
    editorRoot.querySelector('[data-resolve-conflict]').addEventListener('click', () => {
        if (!conflict?.server) return;
        baseVersionField.value = String(conflict.server.version);
        conflict = null;
        conflictPanel.hidden = true;
        markDirty();
        updateSaveButton();
        saveState.textContent = 'Resolved document prepared for saving. Any further server change will cause a new conflict.';
    });
    const initialConflict = editorRoot.querySelector('[data-initial-conflict]');
    if (initialConflict) showConflict(JSON.parse(initialConflict.value));

    const applyConversion = (data, before) => {
        conversionBackup = {format: before.format, html: before.html, markdown: before.markdown};
        visualEditor.innerHTML = safeHtml(data.html || '');
        markdownEditor.value = data.markdown || '';
        originalMarkdown = data.format === 'visual' && before.format === 'markdown'
            ? {html: visualEditor.innerHTML, markdown: before.markdown} : null;
        setMode(data.format);
        selectImage(null);
        undoButton.hidden = false;
        markDirty();
    };
    tabs.forEach((tab) => tab.addEventListener('click', async () => {
        const target = tab.dataset.editorMode;
        if (target === formatInput.value || converting || saving || pendingImages > 0 || accountChanged) return;
        const before = snapshot();
        persistLocalRecovery();
        converting = true;
        updateSaveButton();
        saveState.textContent = 'Converting current document…';
        try {
            let data;
            if (target === 'markdown' && originalMarkdown?.html === visualEditor.innerHTML) {
                data = {format: 'markdown', html: visualEditor.innerHTML, markdown: originalMarkdown.markdown, lossy: false};
            } else data = await requestJson(editorRoot.dataset.convertUrl, {...fields(before), target_format: target});
            if (before.sequence !== generation) { saveState.textContent = 'The document changed during conversion. Select the mode again to convert the latest content.'; return; }
            if (data.lossy) {
                pendingConversion = {data, before};
                editorRoot.querySelector('[data-conversion-before]').innerHTML = safeHtml(data.before_html);
                editorRoot.querySelector('[data-conversion-after]').innerHTML = safeHtml(data.html);
                editorRoot.querySelector('[data-conversion-diff]').textContent = '- ' + data.before_html + '\n+ ' + data.html;
                conversionDialog.showModal();
                saveState.textContent = 'Review the conversion before applying it.';
            } else applyConversion(data, before);
        } catch (error) { saveState.textContent = error.message + ' Current content is retained.'; }
        finally { converting = false; updateSaveButton(); }
    }));
    editorRoot.querySelector('[data-conversion-cancel]').addEventListener('click', () => { pendingConversion = null; conversionDialog.close(); });
    editorRoot.querySelector('[data-conversion-accept]').addEventListener('click', () => {
        if (pendingConversion?.before.sequence === generation) applyConversion(pendingConversion.data, pendingConversion.before);
        else saveState.textContent = 'The document changed. Preview the latest conversion again.';
        pendingConversion = null;
        conversionDialog.close();
    });
    undoButton.addEventListener('click', () => {
        if (!conversionBackup) return;
        const before = {format: formatInput.value, html: visualEditor.innerHTML, markdown: markdownEditor.value};
        visualEditor.innerHTML = safeHtml(conversionBackup.html);
        markdownEditor.value = conversionBackup.markdown || '';
        setMode(conversionBackup.format);
        conversionBackup = before;
        originalMarkdown = null;
        markDirty();
    });
    editorRoot.querySelectorAll('[data-command]').forEach((button) => button.addEventListener('click', () => {
        visualEditor.focus();
        document.execCommand(button.dataset.command, false, button.dataset.commandValue || null);
        markDirty();
    }));

    const imagePicker = editorRoot.querySelector('[data-image-picker]');
    const imageFileInput = editorRoot.querySelector('[data-image-file]');
    const imageInspector = editorRoot.querySelector('[data-image-inspector]');
    const imageAltInput = editorRoot.querySelector('[data-image-alt]');
    const imageCaptionInput = editorRoot.querySelector('[data-image-caption]');
    const imageWidthInput = editorRoot.querySelector('[data-image-width]');
    const imageApplyButton = editorRoot.querySelector('[data-image-apply]');
    const imageRemoveButton = editorRoot.querySelector('[data-image-remove]');
    const canUploadImage = editorRoot.dataset.canUploadImage === '1';
    const imageUploadUrl = editorRoot.dataset.imageUploadUrl || '';
    let selectedImage = null;

    const clampImageWidth = (value, fallback = 640) => {
        const parsed = Number.parseInt(String(value || ''), 10);
        if (!Number.isFinite(parsed)) return fallback;
        return Math.max(64, Math.min(1600, parsed));
    };

    const currentFigure = (image) => {
        if (!image) return null;
        const figure = image.closest('figure');
        return figure && visualEditor.contains(figure) ? figure : null;
    };

    const ensureFigure = (image) => {
        const existing = currentFigure(image);
        if (existing) return existing;

        const figure = document.createElement('figure');
        figure.className = 'wiki-image';
        image.replaceWith(figure);
        figure.appendChild(image);
        return figure;
    };

    const selectImage = (image) => {
        if (!imageInspector || !imageAltInput || !imageCaptionInput || !imageWidthInput) return;
        selectedImage = image;
        if (!image) {
            imageInspector.hidden = true;
            return;
        }

        const figure = currentFigure(image);
        const caption = figure ? figure.querySelector('figcaption') : null;
        imageAltInput.value = image.getAttribute('alt') || '';
        imageCaptionInput.value = caption ? caption.textContent || '' : '';
        imageWidthInput.value = image.getAttribute('width')
            || image.dataset.originalWidth
            || image.naturalWidth
            || 640;
        imageInspector.hidden = false;
    };

    const insertAtSelection = (node) => {
        visualEditor.focus();
        const selection = window.getSelection();

        if (
            selection
            && selection.rangeCount > 0
            && visualEditor.contains(selection.anchorNode)
        ) {
            const range = selection.getRangeAt(0);
            range.deleteContents();
            range.insertNode(node);
            range.setStartAfter(node);
            range.collapse(true);
            selection.removeAllRanges();
            selection.addRange(range);
            return;
        }

        visualEditor.appendChild(node);
    };

    const createImageFigure = (data) => {
        const figure = document.createElement('figure');
        figure.className = 'wiki-image';

        const image = document.createElement('img');
        image.src = data.src;
        image.alt = '';
        image.loading = 'lazy';

        if (data.id) image.dataset.attachmentId = String(data.id);
        if (data.width) image.dataset.originalWidth = String(data.width);
        if (data.height) image.dataset.originalHeight = String(data.height);

        const width = clampImageWidth(
            Math.min(Number(data.width || 1280), 1280),
            640
        );
        image.setAttribute('width', String(width));

        figure.appendChild(image);
        insertAtSelection(figure);
        selectImage(image);
        markDirty();
    };

    const readImageFile = (file) => new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onerror = () => reject(new Error('Unable to read image.'));
        reader.onload = () => {
            const src = typeof reader.result === 'string' ? reader.result : '';
            if (!src) {
                reject(new Error('Unable to read image.'));
                return;
            }

            const probe = new Image();
            probe.onload = () => resolve({
                src,
                width: probe.naturalWidth,
                height: probe.naturalHeight
            });
            probe.onerror = () => reject(new Error('Unable to decode image.'));
            probe.src = src;
        };
        reader.readAsDataURL(file);
    });

    const uploadImage = async (file) => {
        if (!imageUploadUrl) {
            return readImageFile(file);
        }

        const body = new FormData();
        body.append('_token', csrfToken);
        body.append('image', file, file.name || 'clipboard-image.png');

        const response = await fetch(imageUploadUrl, {
            method: 'POST',
            headers: {'Accept': 'application/json'},
            body,
            credentials: 'same-origin'
        });
        const payload = await response.json();

        if (!response.ok) {
            throw new Error(payload?.error?.message || 'Image upload failed.');
        }

        return {
            id: payload.data.id,
            src: payload.data.thumbnail_url,
            width: payload.data.width,
            height: payload.data.height
        };
    };

    const handleImageFile = async (file) => {
        if (!canUploadImage || !file) return;

        const allowed = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
        if (!allowed.includes(file.type)) {
            saveState.textContent = 'Only PNG, JPEG, GIF and WebP images are supported.';
            return;
        }

        if (file.size > 10 * 1024 * 1024) {
            saveState.textContent = 'Image exceeds the 10 MiB editor upload limit.';
            return;
        }

        saveState.textContent = imageUploadUrl
            ? 'Uploading image…'
            : 'Preparing pasted image for first save…';

        pendingImages++;
        updateSaveButton();
        try {
            const data = await uploadImage(file);
            createImageFigure(data);
            saveState.textContent = imageUploadUrl
                ? 'Image uploaded. Save the page to reference it in this revision.'
                : 'Image prepared. It will be stored as an attachment when the page is created.';
        } catch (error) {
            saveState.textContent = error instanceof Error
                ? error.message
                : 'Unable to add image.';
        } finally { pendingImages--; updateSaveButton(); }
    };

    if (imagePicker && imageFileInput && canUploadImage) {
        imagePicker.addEventListener('click', () => imageFileInput.click());
        imageFileInput.addEventListener('change', async () => {
            const file = imageFileInput.files && imageFileInput.files[0]
                ? imageFileInput.files[0]
                : null;
            imageFileInput.value = '';
            await handleImageFile(file);
        });

        visualEditor.addEventListener('paste', async (event) => {
            const items = Array.from(event.clipboardData?.items || []);
            const imageItem = items.find((item) => item.kind === 'file' && item.type.startsWith('image/'));
            if (!imageItem) return;

            const file = imageItem.getAsFile();
            if (!file) return;

            event.preventDefault();
            await handleImageFile(file);
        });
    }

    visualEditor.addEventListener('click', (event) => {
        const target = event.target;
        if (target instanceof HTMLImageElement && visualEditor.contains(target)) {
            selectImage(target);
            return;
        }

        if (imageInspector && !imageInspector.contains(target)) {
            selectImage(null);
        }
    });

    if (imageApplyButton) {
        imageApplyButton.addEventListener('click', () => {
            if (!selectedImage) return;

            const width = clampImageWidth(imageWidthInput?.value, selectedImage.naturalWidth || 640);
            selectedImage.setAttribute('width', String(width));
            selectedImage.removeAttribute('height');
            selectedImage.setAttribute('alt', (imageAltInput?.value || '').trim());
            selectedImage.setAttribute('loading', 'lazy');

            if (selectedImage.dataset.attachmentId) {
                selectedImage.src = '/attachments/'
                    + encodeURIComponent(selectedImage.dataset.attachmentId)
                    + '/thumbnail?width=' + width;
            }

            const figure = ensureFigure(selectedImage);
            const captionText = (imageCaptionInput?.value || '').trim();
            let caption = figure.querySelector('figcaption');

            if (captionText !== '') {
                if (!caption) {
                    caption = document.createElement('figcaption');
                    figure.appendChild(caption);
                }
                caption.textContent = captionText;
            } else if (caption) {
                caption.remove();
            }

            markDirty();
            selectImage(selectedImage);
        });
    }

    if (imageRemoveButton) {
        imageRemoveButton.addEventListener('click', () => {
            if (!selectedImage) return;
            const figure = currentFigure(selectedImage);
            (figure || selectedImage).remove();
            selectedImage = null;
            if (imageInspector) imageInspector.hidden = true;
            markDirty();
        });
    }


    const templateSelector = editorRoot.querySelector('[data-template-selector]');
    if (templateSelector) templateSelector.addEventListener('change', () => {
        const option = templateSelector.options[templateSelector.selectedIndex];
        if (!option?.value) return;
        conversionBackup = {format: formatInput.value, html: visualEditor.innerHTML, markdown: markdownEditor.value};
        visualEditor.innerHTML = safeHtml(option.dataset.html || '');
        markdownEditor.value = option.dataset.markdown || '';
        originalMarkdown = null;
        undoButton.hidden = false;
        setMode(markdownEditor.value ? 'markdown' : 'visual');
        markDirty();
    });
    form.addEventListener('input', markDirty);
    form.addEventListener('change', markDirty);

    const acquireLock = async () => {
        if (!isEdit || accountChanged || saving) return;
        try {
            const data = await requestJson(editorRoot.dataset.lockUrl, {_token: csrfToken});
            lockToken = data.token || lockToken;
            lockWarning.hidden = data.acquired;
            lockWarning.textContent = (data.holder || 'Another editor') + ' is editing this page. Every save still checks your original base version.';
        } catch {
            lockWarning.hidden = false;
            lockWarning.textContent = 'Editor presence could not be verified. Version checks still protect every save.';
        }
    };
    const autosave = async () => {
        if (!dirty || generation === draftGeneration || pendingAutosave || saving || converting || accountChanged) return;
        const captured = snapshot();
        persistLocalRecovery();
        pendingAutosave = requestJson(editorRoot.dataset.draftsUrl, fields(captured));
        try {
            const data = await pendingAutosave;
            if (data.sequence !== captured.sequence) throw new Error('The server did not acknowledge this draft snapshot.');
            draftGeneration = captured.sequence;
            if (generation === captured.sequence) saveState.textContent = data.stale
                ? 'Recovery copy saved with its original base version. The server page has changed; saving will require conflict resolution.'
                : 'Draft saved at ' + data.saved_at + '. Save the page to create a revision.';
            else saveState.textContent = 'An earlier draft was saved. Newer changes are still pending.';
            if (lastSaveError) saveState.textContent += ' The page save failed: ' + lastSaveError;
        } catch (error) { saveState.textContent = error.message + ' Server autosave failed; your document remains recoverable locally.'; }
        finally {
            pendingAutosave = null;
            if (generation !== captured.sequence && !saving) autosaveTimer = window.setTimeout(autosave, 250);
        }
    };
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (saving || converting || pendingImages > 0 || accountChanged || conflict) return;
        persistLocalRecovery();
        saving = true;
        updateSaveButton();
        window.clearTimeout(autosaveTimer);
        try {
            if (pendingAutosave) await pendingAutosave.catch(() => {});
            const captured = snapshot();
            const data = await requestJson(form.action, fields(captured));
            baseVersionField.value = String(data.page.version);
            lastSaveError = '';
            if (accountChanged) {
                persistLocalRecovery();
                saveState.textContent = 'The submitted version was saved for the original account. Reopen the editor after signing in to continue.';
                return;
            }
            if (captured.sequence === generation) {
                dirty = false;
                window.clearTimeout(localSaveTimer);
                try {
                    localStorage.removeItem(recoveryKey);
                    if (recoveredSource?.localKey) {
                        const latest = JSON.parse(localStorage.getItem(recoveredSource.localKey) || 'null');
                        if (latest && Number(latest.sequence) <= Number(recoveredSource.sequence)) localStorage.removeItem(recoveredSource.localKey);
                    }
                } catch { /* A retained copy is harmless when deletion is unavailable. */ }
                window.location.assign(data.url);
            } else {
                // Later edits remain based on our now-confirmed revision and are saved separately.
                form.action = data.url;
                const oldKey = recoveryKey;
                isEdit = true;
                editorRoot.dataset.isEdit = '1';
                editorRoot.dataset.pageId = String(data.page.id);
                editorRoot.querySelectorAll('[data-editor-heading]').forEach((element) => { element.textContent = 'Edit page'; });
                editorRoot.querySelector('[data-version-hint]').textContent = 'Based on version ' + data.page.version + '.';
                editorRoot.querySelector('[data-editor-cancel]').href = data.url;
                saveButton.textContent = 'Save page';
                documentKey = 'page:' + data.page.id;
                recoveryPrefix = 'openwiki:draft:v2:' + userId + ':' + editorRoot.dataset.spaceId + ':' + documentKey + ':';
                recoveryKey = recoveryPrefix + editorId;
                editorRoot.dataset.lockUrl = data.url + '/edit-lock';
                editorRoot.dataset.unlockUrl = data.url + '/edit-unlock';
                history.replaceState(null, '', data.url + '/edit');
                const sentHtml = new DOMParser().parseFromString(captured.html, 'text/html');
                const storedHtml = new DOMParser().parseFromString(data.page.content_html, 'text/html');
                const sentImages = Array.from(sentHtml.querySelectorAll('img'));
                const storedImages = Array.from(storedHtml.querySelectorAll('img'));
                sentImages.forEach((image, index) => {
                    if (!/^data:image\//i.test(image.getAttribute('src') || '') || !storedImages[index]) return;
                    visualEditor.querySelectorAll('img').forEach((live) => {
                        if (live.getAttribute('src') !== image.getAttribute('src')) return;
                        live.setAttribute('src', storedImages[index].getAttribute('src'));
                        live.dataset.attachmentId = storedImages[index].dataset.attachmentId;
                    });
                });
                markDirty();
                if (persistLocalRecovery() && oldKey !== recoveryKey) {
                    try { localStorage.removeItem(oldKey); } catch {}
                }
                saveState.textContent = 'The submitted version was saved. Your later changes are still in this editor and need another save.';
            }
        } catch (error) {
            dirty = true;
            lastSaveError = error.message;
            persistLocalRecovery();
            if (error.conflict) showConflict(error.conflict);
            saveState.textContent = error.message + ' Your document and its original base version are retained.';
        } finally { saving = false; updateSaveButton(); }
    });
    requestJson(editorRoot.dataset.draftsUrl + '?document_key=' + encodeURIComponent(documentKey)).then((data) => {
        for (const draft of data.drafts) {
            const local = recoveryCopies.get(draft.editorId);
            if (!local || Number(local.sequence) < draft.sequence) recoveryCopies.set(draft.editorId, {...draft, localKey: local?.localKey});
        }
        showRecovery();
    }).catch(() => { /* Local recovery remains usable without the server. */ });
    window.addEventListener('storage', (event) => {
        if (event.key === 'openwiki:active-user' && event.newValue !== userId) {
            accountChanged = true;
            persistLocalRecovery();
            updateSaveButton();
            saveState.textContent = 'The signed-in account changed in another tab. Your document is retained for the original account; reopen the editor after signing in.';
        }
    });
    window.addEventListener('beforeunload', (event) => {
        if (!dirty) return;
        persistLocalRecovery();
        event.preventDefault();
        event.returnValue = '';
    });
    window.addEventListener('pagehide', () => {
        window.clearTimeout(autosaveTimer);
        window.clearTimeout(localSaveTimer);
        if (dirty) persistLocalRecovery();
        if (lockToken && editorRoot.dataset.unlockUrl) navigator.sendBeacon(editorRoot.dataset.unlockUrl,
            new URLSearchParams({_token: csrfToken, lock_token: lockToken}));
    });
    if (isEdit) { acquireLock(); window.setInterval(acquireLock, 30000); }
    setMode(formatInput.value);
}
