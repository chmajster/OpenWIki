const t = (text) => window.wikiTranslate(text);
import {createRichEditor, selectModelImage} from './rich-editor.js';
import {sanitizeHtml, contentSignature} from './html.js';
import {setupEditorTools} from './editor-tools.js';
import {LocalRecovery} from './local-recovery.js';
import {setupUploads} from './upload-queue.js';
import {setupWorkspace} from './workspace-tools.js';
import {setupDocumentWorkspace} from './document-workspace.js';
'use strict';

const editorRoot = document.querySelector('[data-wiki-editor]');
if (editorRoot) {
    const formatInput = editorRoot.querySelector('[data-content-format]');
    let visualEditor = editorRoot.querySelector('[data-rich-host]');
    let richEditor;
    let sourceOverride = null;
    let modelPending = null;
    let refreshTools = () => {};
    const markdownEditor = editorRoot.querySelector('[data-markdown-editor]');
    const form = editorRoot.querySelector('[data-editor-form]');
    const saveButton = editorRoot.querySelector('[data-save-button]');
    const saveState = editorRoot.querySelector('[data-save-state]');
    const localState = editorRoot.querySelector('[data-local-state]');
    const localRecovery = new LocalRecovery();
    const lockWarning = editorRoot.querySelector('[data-edit-lock-warning]');
    const recoveryBanner = editorRoot.querySelector('[data-recovery-banner]');
    const recoveryText = editorRoot.querySelector('[data-recovery-text]');
    const recoverySelect = editorRoot.querySelector('[data-recovery-select]');
    const tabs = Array.from(editorRoot.querySelectorAll('[data-editor-mode]'));
    const panes = Array.from(editorRoot.querySelectorAll('[data-pane]'));
    let isEdit = editorRoot.dataset.isEdit === '1';
    let csrfToken = form.elements._token.value;
    const baseVersionField = form.elements.base_version;
    const userId = editorRoot.dataset.userId;
    const uuid = () => typeof crypto.randomUUID === 'function' ? crypto.randomUUID()
        : '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (character) =>
            (Number(character) ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> Number(character) / 4).toString(16));
    let editorId = uuid();
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
    const instance = editorRoot.dataset.instanceId;
    recoveryPrefix += instance + ':';
    recoveryKey = recoveryPrefix + editorId;
    let generation = 1;
    let draftGeneration = 0;
    let draftVersion = 0;
    let draftAttempt = null;
    let pageAttempt = null;
    let authStopped = false;
    let maximumSaveTimer = null;
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

    const policy = JSON.parse(editorRoot.querySelector('[data-html-policy]').value);
    const safeHtml = (html) => sanitizeHtml(html, policy);
    const getVisualHtml = () => sourceOverride ?? richEditor.getHTML();
    const setVisualHtml = (html) => {
        const safe = safeHtml(html);
        richEditor.commands.setContent(safe, {emitUpdate: false});
        sourceOverride = null;
        editorRoot.querySelector('[data-retained-html]').hidden = true;
        visualEditor.parentElement.hidden = false;
        const after = richEditor.getHTML();
        modelPending = contentSignature(safe) === contentSignature(after) ? null : {before: safe, after};
        if (modelPending) sourceOverride = safe;
        richEditor.setEditable(!modelPending && !accountChanged, false);
        const banner = editorRoot.querySelector('[data-model-review]');
        banner.hidden = !modelPending;
        if (modelPending) {
            editorRoot.querySelector('[data-model-before]').innerHTML = safeHtml(safe);
            editorRoot.querySelector('[data-model-after]').innerHTML = safeHtml(after);
        }
        updateSaveButton();
    };

    const setMode = (mode) => {
        const selected = mode === 'markdown' ? 'markdown' : 'visual';
        formatInput.value = selected;
        tabs.forEach((tab) => {
            tab.classList.toggle('is-active', tab.dataset.editorMode === selected);
            tab.setAttribute('aria-selected', tab.dataset.editorMode === selected ? 'true' : 'false');
            tab.tabIndex = tab.dataset.editorMode === selected ? 0 : -1;
        });
        panes.forEach((pane) => { pane.hidden = pane.dataset.pane !== selected; });
        refreshTools();
    };
    const updateSaveButton = () => {
        saveButton.disabled = saving || converting || pendingImages > 0 || accountChanged || authStopped || Boolean(conflict) || Boolean(modelPending);
        refreshTools();
        if (saving || converting) editorRoot.querySelectorAll('[data-attachment-picker], [data-image-picker]').forEach(button => { button.disabled = true; });
    };
    const snapshot = () => ({
        userId, documentKey, editorId, sequence: generation, savedAt: new Date().toISOString(),
        baseVersion: Number(baseVersionField.value), title: form.elements.title.value,
        slug: form.elements.slug.value, parentId: form.elements.parent_id.value,
        publication_space_id: form.elements.publication_space_id.value,
        publication_parent_id: form.elements.publication_parent_id.value,
        content_owner_id: form.elements.content_owner_id.value, review_due_at: form.elements.review_due_at.value,
        status: form.elements.status.value, tags: form.elements.tags.value,
        changeSummary: form.elements.change_summary.value, format: formatInput.value,
        html: getVisualHtml(), markdown: markdownEditor.value, conversionBackup,
        draftVersion, document: {version: 1, format: formatInput.value, source: formatInput.value === 'visual' ? getVisualHtml() : markdownEditor.value},
        originalMarkdown
    });
    const persistLocalRecovery = () => {
        const captured = snapshot();
        return localRecovery.save(recoveryKey, captured).then((ok) => {
            localState.textContent = ok ? 'Zapisano lokalnie o ' + new Date().toLocaleTimeString('pl-PL') : 'Lokalny magazyn jest niedostępny lub pełny. Brak lokalnej kopii tej zmiany.';
            localState.dataset.protected = String(ok);
            return ok;
        });
    };
    const markDirty = () => {
        generation++;
        dirty = true;
        lastSaveError = '';
        saveState.textContent = t('Unsaved changes. Recovery copy pending.');
        saveState.dataset.state = 'unsaved';
        window.clearTimeout(localSaveTimer);
        localSaveTimer = window.setTimeout(() => {
            persistLocalRecovery();
        }, 350);
        window.clearTimeout(autosaveTimer);
        autosaveTimer = window.setTimeout(autosave, 2000);
        if (maximumSaveTimer === null) maximumSaveTimer = window.setTimeout(() => { maximumSaveTimer = null; autosave(); }, 10000);
        if (conflict) editorRoot.querySelector('[data-conflict-local]').textContent = describe(snapshot());
        editorRoot.dispatchEvent(new Event('wiki:document-state'));
    };
    const initialHtml = visualEditor.innerHTML;
    visualEditor.replaceChildren();
    richEditor = createRichEditor(visualEditor, safeHtml(initialHtml), safeHtml, markDirty);
    visualEditor = richEditor.view.dom;
    setVisualHtml(initialHtml);
    editorRoot.querySelector('[data-model-accept]').addEventListener('click', () => {
        if (!modelPending) return;
        conversionBackup = {format: 'visual', html: modelPending.before, markdown: ''};
        sourceOverride = null;
        modelPending = null;
        richEditor.setEditable(true, false);
        editorRoot.querySelector('[data-model-review]').hidden = true;
        undoButton.hidden = false;
        markDirty();
        updateSaveButton();
    });
    editorRoot.querySelector('[data-model-keep]').addEventListener('click', () => {
        modelPending = null;
        editorRoot.querySelector('[data-model-review]').hidden = true;
        const retained = editorRoot.querySelector('[data-retained-html]');
        retained.querySelector('[data-retained-content]').innerHTML = safeHtml(sourceOverride);
        retained.hidden = false;
        visualEditor.parentElement.hidden = true;
        saveState.textContent = t('The original HTML is retained. Switch to Markdown with a reviewed conversion to edit unsupported structures.');
        updateSaveButton();
    });
    editorRoot.querySelector('[data-reopen-model-review]').addEventListener('click', () => {
        if (sourceOverride !== null) setVisualHtml(sourceOverride);
    });
    const restoreSnapshot = (data) => {
        for (const key of ['title', 'slug', 'status', 'tags', 'content_owner_id', 'review_due_at', 'publication_space_id', 'publication_parent_id']) {
            if (typeof data[key] === 'string') form.elements[key].value = data[key];
        }
        if (typeof data.parentId === 'string') form.elements.parent_id.value = data.parentId;
        if (typeof data.changeSummary === 'string') form.elements.change_summary.value = data.changeSummary;
        if (Number.isInteger(Number(data.baseVersion)) && Number(data.baseVersion) >= (isEdit ? 1 : 0)) baseVersionField.value = String(data.baseVersion);
        setVisualHtml(data.html || '');
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
    const recoveryTime = data => Date.parse(/T/.test(data.savedAt) ? data.savedAt : data.savedAt?.replace(' ', 'T') + 'Z') || 0;
    const recoveryDate = data => {
        const time = recoveryTime(data);
        return time ? new Intl.DateTimeFormat(document.documentElement.lang || 'pl', {dateStyle: 'short', timeStyle: 'short'}).format(new Date(time)) : (data.savedAt || '');
    };
    const updateRecoveryMeta = () => {
        const data = recoveryCopies.get(recoverySelect.value);
        editorRoot.querySelector('[data-recovery-meta]').textContent = data ? t('Base version') + ': ' + data.baseVersion : '';
    };
    recoverySelect.addEventListener('change', updateRecoveryMeta);
    const showRecovery = () => {
        const selected = recoverySelect.value;
        recoverySelect.replaceChildren();
        for (const [key, data] of [...recoveryCopies].sort((left, right) => recoveryTime(right[1]) - recoveryTime(left[1]))) {
            const option = document.createElement('option');
            option.value = key;
            option.textContent = (data.title || t('Untitled page')) + ' — ' + recoveryDate(data);
            recoverySelect.appendChild(option);
        }
        if (recoveryCopies.has(selected)) recoverySelect.value = selected;
        updateRecoveryMeta();
        recoveryText.textContent = t('Choose a recovery copy. Restoring retains its original base version.');
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
    } catch { saveState.textContent = t('Local recovery storage is unavailable.'); }
    showRecovery();
    localRecovery.list(recoveryPrefix).then((copies) => {
        for (const data of copies) if (String(data.userId) === userId && data.documentKey === documentKey) {
            const existing = recoveryCopies.get(data.editorId);
            if (!existing || data.sequence > existing.sequence) recoveryCopies.set(data.editorId, data);
        }
        showRecovery();
    });
    editorRoot.querySelector('[data-restore-recovery]').addEventListener('click', async () => {
        let data = recoveryCopies.get(recoverySelect.value);
        if (!data) return;
        if (data.checkpoint) {
            try { data = (await requestJson(editorRoot.dataset.draftsUrl + '?document_key=' + encodeURIComponent(documentKey) + '&checkpoint=' + data.checkpoint)).draft; }
            catch (error) { saveState.textContent = error.message; return; }
        }
        recoveredSource = data;
        restoreSnapshot(data);
        recoveryBanner.hidden = true;
        saveState.textContent = t('Recovery restored with base version ') + data.baseVersion + t('. Saving still checks for concurrent changes.');
    });
    editorRoot.querySelector('[data-compare-recovery]').onclick = async () => {
        let data = recoveryCopies.get(recoverySelect.value); if (!data) return;
        try {
            if (data.checkpoint) data = (await requestJson(editorRoot.dataset.draftsUrl + '?document_key=' + encodeURIComponent(documentKey) + '&checkpoint=' + data.checkpoint)).draft;
            editorRoot.querySelector('[data-recovery-comparison-info]').textContent = data.savedAt + ' · wersja bazowa ' + data.baseVersion + ' · wersja szkicu ' + (data.draftVersion || 0);
            editorRoot.querySelector('[data-recovery-current]').textContent = describe(snapshot());
            editorRoot.querySelector('[data-recovery-copy]').textContent = describe(data);
            editorRoot.querySelector('[data-recovery-dialog]').showModal();
        } catch (error) { saveState.textContent = error.message; }
    };
    editorRoot.querySelector('[data-hide-recovery]').addEventListener('click', () => { recoveryBanner.hidden = true; });

    const fields = (data) => ({
        _token: csrfToken, editor_user_id: userId, document_key: documentKey, editor_id: editorId,
        draft_sequence: data.sequence, base_version: data.baseVersion,
        document: data.document,
        content_owner_id: data.content_owner_id, review_due_at: data.review_due_at,
        title: data.title, slug: data.slug, parent_id: data.parentId, status: data.status,
        publication_space_id: data.publication_space_id, publication_parent_id: data.publication_parent_id,
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
            if (response.redirected) throw new Error(t('The server redirected this request. Sign in again if your session expired; your document is retained.'));
            let payload;
            try { payload = await response.json(); } catch { throw new Error(t('Unexpected server response (') + response.status + t('). Your document remains in the editor.')); }
            if (!response.ok) {
                const error = new Error(t(payload?.error?.message) || t('Request failed (') + response.status + ').');
                error.conflict = payload?.error?.conflict;
                error.draftConflict = payload?.error?.draft_conflict;
                error.status = response.status;
                if ([401, 403, 419].includes(response.status)) {
                    authStopped = true;
                    editorRoot.querySelector('[data-resume-save]').hidden = false;
                }
                throw error;
            }
            if (payload.data?.user_id && String(payload.data.user_id) !== userId) throw new Error(t('The signed-in account changed. Reopen the editor.'));
            return payload.data;
        } catch (error) {
            if (error.name === 'AbortError') throw new Error(t('The request timed out before confirmation.'));
            if (error instanceof TypeError) throw new Error(t('A network error prevented confirmation.'));
            throw error;
        } finally { window.clearTimeout(timer); }
    };
    const describe = (data) => {
        if (!data) return t('This base revision is unavailable.');
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
        saveState.textContent = t('Resolved document prepared for saving. Any further server change will cause a new conflict.');
    });
    const initialConflict = editorRoot.querySelector('[data-initial-conflict]');
    if (initialConflict) showConflict(JSON.parse(initialConflict.value));
    editorRoot.querySelector('[data-conflict-fork]').onclick = async () => {
        const local = snapshot(), key = recoveryKey;
        await persistLocalRecovery(); recoveryCopies.set(editorId, {...local, localKey: key});
        editorId = uuid(); recoveryKey = recoveryPrefix + editorId; draftVersion = 0; draftAttempt = null;
        markDirty(); await autosave(); showRecovery();
    };
    editorRoot.querySelector('[data-use-server-version]').onclick = async () => {
        if (!conflict?.server) return;
        const server = conflict.server, local = snapshot(), key = recoveryKey;
        await persistLocalRecovery(); recoveryCopies.set(editorId, {...local, localKey: key});
        editorId = uuid(); recoveryKey = recoveryPrefix + editorId; draftVersion = 0; draftAttempt = null;
        restoreSnapshot({...local, title: server.title, slug: server.slug, parentId: String(server.parent_id ?? ''), status: server.status,
            tags: (server.tags || []).join(', '), changeSummary: '', baseVersion: server.version, format: server.content_format,
            html: server.content_html, markdown: server.content_markdown || '', conversionBackup: null, originalMarkdown: null});
        showRecovery(); await autosave();
    };

    const applyConversion = (data, before) => {
        conversionBackup = {format: before.format, html: before.html, markdown: before.markdown};
        setVisualHtml(data.html || '');
        markdownEditor.value = data.markdown || '';
        originalMarkdown = data.format === 'visual' && before.format === 'markdown'
            ? {html: getVisualHtml(), markdown: before.markdown} : null;
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
        saveState.textContent = t('Converting current document…');
        try {
            let data;
            if (target === 'markdown' && originalMarkdown?.html === getVisualHtml()) {
                data = {format: 'markdown', html: getVisualHtml(), markdown: originalMarkdown.markdown, lossy: false};
            } else data = await requestJson(editorRoot.dataset.convertUrl, {...fields(before), target_format: target});
            if (before.sequence !== generation) { saveState.textContent = t('The document changed during conversion. Select the mode again to convert the latest content.'); return; }
            if (data.lossy) {
                pendingConversion = {data, before};
                editorRoot.querySelector('[data-conversion-before]').innerHTML = safeHtml(data.before_html);
                editorRoot.querySelector('[data-conversion-after]').innerHTML = safeHtml(data.html);
                editorRoot.querySelector('[data-conversion-diff]').textContent = '- ' + data.before_html + '\n+ ' + data.html;
                conversionDialog.showModal();
                saveState.textContent = t('Review the conversion before applying it.');
            } else applyConversion(data, before);
        } catch (error) { saveState.textContent = error.message + t(' Current content is retained.'); }
        finally { converting = false; updateSaveButton(); }
    }));
    editorRoot.querySelector('[data-conversion-cancel]').addEventListener('click', () => { pendingConversion = null; conversionDialog.close(); });
    editorRoot.querySelector('[data-conversion-accept]').addEventListener('click', () => {
        if (pendingConversion?.before.sequence === generation) applyConversion(pendingConversion.data, pendingConversion.before);
        else saveState.textContent = t('The document changed. Preview the latest conversion again.');
        pendingConversion = null;
        conversionDialog.close();
    });
    undoButton.addEventListener('click', () => {
        if (!conversionBackup) return;
        const before = {format: formatInput.value, html: getVisualHtml(), markdown: markdownEditor.value};
        setVisualHtml(conversionBackup.html);
        markdownEditor.value = conversionBackup.markdown || '';
        setMode(conversionBackup.format);
        conversionBackup = before;
        originalMarkdown = null;
        markDirty();
    });
    const imageInspector = editorRoot.querySelector('[data-image-inspector]');
    const imageAltInput = editorRoot.querySelector('[data-image-alt]');
    const imageCaptionInput = editorRoot.querySelector('[data-image-caption]');
    const imageWidthInput = editorRoot.querySelector('[data-image-width]');
    const imageApplyButton = editorRoot.querySelector('[data-image-apply]');
    const imageRemoveButton = editorRoot.querySelector('[data-image-remove]');
    const canUploadImage = editorRoot.dataset.canUploadImage === '1';
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
        editorRoot.querySelector('[data-image-align]').value = figure?.dataset.align || 'left';
        editorRoot.querySelector('[data-image-height]').value = image.getAttribute('height') || '';
        editorRoot.querySelector('[data-image-ratio]').checked = !image.hasAttribute('height');
        editorRoot.querySelector('[data-image-preview]').href = image.dataset.draftFileId ? '/editor-files/' + image.dataset.draftFileId + '/preview' : image.dataset.attachmentId ? '/attachments/' + image.dataset.attachmentId + '/preview' : image.src;
        imageInspector.hidden = false;
    };

    if (canUploadImage) setupUploads(editorRoot, richEditor, localRecovery,
        () => ({documentKey, csrfToken, canSend: !saving && !converting && !accountChanged && !authStopped && !modelPending && sourceOverride === null && formatInput.value === 'visual'}),
        uuid, () => { markDirty(); return persistLocalRecovery(); }, (count) => { pendingImages = count; updateSaveButton(); }, (error) => {
            saveState.textContent = error.message;
            if ([401, 403, 419].includes(error.status)) { authStopped = true; editorRoot.querySelector('[data-resume-save]').hidden = false; }
        });

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
            const values = {width: String(clampImageWidth(imageWidthInput?.value, 640)),
                alt: imageAltInput.value.trim(), caption: imageCaptionInput.value.trim() || null, alignment: editorRoot.querySelector('[data-image-align]').value};
            const pos = selectModelImage(richEditor, selectedImage);
            if (pos === null) return;
            const attrs = richEditor.state.doc.nodeAt(pos).attrs;
            const width = Math.min(Number(values.width), Number(attrs['data-original-width']) || 1600);
            richEditor.view.dispatch(richEditor.state.tr.setNodeMarkup(pos, undefined, {...attrs, ...values, width: String(width), height: editorRoot.querySelector('[data-image-ratio]').checked ? null : String(Math.max(32, Math.min(1600, Number(editorRoot.querySelector('[data-image-height]').value) || 200))),
                src: attrs['data-draft-file-id'] ? '/editor-files/' + attrs['data-draft-file-id'] + '/thumbnail?width=' + width
                    : attrs['data-attachment-id'] ? '/attachments/' + attrs['data-attachment-id'] + '/thumbnail?width=' + width : attrs.src}));
            selectImage(richEditor.view.nodeDOM(pos)?.querySelector('img') || null);
        });
    }

    if (imageRemoveButton) {
        imageRemoveButton.addEventListener('click', () => {
            if (!selectedImage) return;
            const pos = selectModelImage(richEditor, selectedImage);
            if (pos !== null) richEditor.commands.deleteSelection();
            selectedImage = null;
            if (imageInspector) imageInspector.hidden = true;
            markDirty();
        });
    }


    const applyTemplate = (template) => {
        conversionBackup = {format: formatInput.value, html: getVisualHtml(), markdown: markdownEditor.value};
        setVisualHtml(template.content_html || '');
        markdownEditor.value = template.content_markdown || '';
        originalMarkdown = null;
        undoButton.hidden = false;
        setMode(template.content_format || (markdownEditor.value ? 'markdown' : 'visual'));
        markDirty();
    };
    form.addEventListener('input', (event) => { if (!visualEditor.contains(event.target) && event.target.name) markDirty(); });
    form.addEventListener('change', (event) => { if (!visualEditor.contains(event.target) && event.target.name) markDirty(); });

    const acquireLock = async () => {
        if (!isEdit || accountChanged || saving || authStopped || document.hidden || !navigator.onLine) return;
        try {
            const data = await requestJson(editorRoot.dataset.lockUrl, {_token: csrfToken});
            lockToken = data.token || lockToken;
            lockWarning.hidden = data.acquired;
            lockWarning.textContent = (data.holder || t('Another editor')) + t(' is editing this page. Every save still checks your original base version.');
        } catch {
            lockWarning.hidden = false;
            lockWarning.textContent = t('Editor presence could not be verified. Version checks still protect every save.');
        }
    };
    const autosave = async () => {
        if (!dirty || generation === draftGeneration || pendingAutosave || saving || converting || accountChanged || authStopped) return;
        if (!navigator.onLine) { saveState.dataset.state = 'offline'; saveState.textContent = 'Brak połączenia. Pisanie i lokalne odzyskiwanie pozostają dostępne.'; persistLocalRecovery(); return; }
        window.clearTimeout(maximumSaveTimer); maximumSaveTimer = null;
        if (!draftAttempt) { const captured = snapshot(); draftAttempt = {captured, body: {...fields(captured), draft_version: draftVersion, operation_id: uuid()}}; }
        const captured = draftAttempt.captured;
        persistLocalRecovery();
        saveState.textContent = 'Zapisywanie szkicu…';
        saveState.dataset.state = 'saving';
        pendingAutosave = requestJson(editorRoot.dataset.draftsUrl, draftAttempt.body);
        try {
            const data = await pendingAutosave;
            if (data.sequence !== captured.sequence || data.operation_id !== draftAttempt.body.operation_id) throw new Error(t('The server did not acknowledge this draft snapshot.'));
            draftVersion = data.draft_version;
            draftGeneration = captured.sequence;
            draftAttempt = null;
            saveState.dataset.state = saving ? 'saving' : lastSaveError ? 'error' : generation === captured.sequence ? 'server' : 'unsaved';
            if (saving) saveState.textContent = 'Zapisywanie dokumentu…';
            else if (generation === captured.sequence) saveState.textContent = data.stale
                ? t('Recovery copy saved with its original base version. The server page has changed; saving will require conflict resolution.')
                : 'Zapisano na serwerze o ' + data.saved_at + '. Szkic nie zmienia strony widocznej czytelnikom.';
            else saveState.textContent = t('An earlier draft was saved. Newer changes are still pending.');
            if (lastSaveError && !saving) saveState.textContent += t(' The page save failed: ') + lastSaveError + t(' Your document and its original base version are retained.');
        } catch (error) {
            if (error.status && error.status < 500) draftAttempt = null;
            if (error.draftConflict) {
                authStopped = true;
                editorRoot.querySelector('[data-resume-save]').hidden = false;
                editorRoot.querySelector('[data-draft-conflict]').hidden = false;
                editorRoot.querySelector('[data-draft-conflict-current]').textContent = describe(error.draftConflict);
                editorRoot.querySelector('[data-draft-fork]').onclick = () => {
                    // A separate editing context retains the original page base, never rebases it.
                    editorId = uuid(); recoveryKey = recoveryPrefix + editorId; draftVersion = 0; draftAttempt = null;
                    authStopped = false; editorRoot.querySelector('[data-draft-conflict]').hidden = true; markDirty(); autosave();
                };
            }
            saveState.dataset.state = error.status === 409 ? 'conflict' : 'error';
            saveState.textContent = (error.status === 409 ? 'Konflikt szkicu. ' : error.status === 401 || error.status === 419 ? 'Sesja wygasła. ' : error.status === 403 ? 'Utrata uprawnień. ' : 'Błąd zapisu. ') + error.message;
        }
        finally {
            pendingAutosave = null;
            if (generation !== captured.sequence && !saving) autosaveTimer = window.setTimeout(autosave, 250);
            else if (draftAttempt && !authStopped) autosaveTimer = window.setTimeout(autosave, 5000);
        }
    };
    editorRoot.querySelector('[data-save-draft]').addEventListener('click', () => autosave());
    editorRoot.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
            event.preventDefault(); event.stopPropagation();
            if (pendingAutosave) pendingAutosave.finally(() => autosave()).catch(() => {}); else autosave();
        }
    }, true);
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const replayingPage = Boolean(pageAttempt), submissionGeneration = generation;
        let publishLatestAfterReplay = false;
        if (saving || converting || pendingImages > 0 || accountChanged || authStopped || conflict || modelPending) return;
        persistLocalRecovery();
        saving = true;
        saveState.dataset.state = 'saving';
        saveState.textContent = 'Zapisywanie dokumentu…';
        updateSaveButton();
        window.clearTimeout(autosaveTimer);
        try {
            if (pendingAutosave) await pendingAutosave.catch(() => {});
            if (!pageAttempt) { const captured = snapshot(); pageAttempt = {captured, body: {...fields(captured), operation_id: uuid()}}; }
            const captured = pageAttempt.captured;
            const data = await requestJson(form.action, pageAttempt.body);
            const unchangedDuringReplay = generation === submissionGeneration;
            pageAttempt = null;
            baseVersionField.value = String(data.page.version);
            lastSaveError = '';
            if (accountChanged) {
                persistLocalRecovery();
                saveState.textContent = t('The submitted version was saved for the original account. Reopen the editor after signing in to continue.');
                return;
            }
            if (captured.sequence === generation) {
                dirty = false;
                window.clearTimeout(localSaveTimer);
                try {
                    await localRecovery.remove(recoveryKey, captured.sequence);
                    if (recoveredSource?.localKey) await localRecovery.remove(recoveredSource.localKey, recoveredSource.sequence);
                } catch { /* A retained copy is harmless when deletion is unavailable. */ }
                window.location.assign(data.url);
            } else {
                // Later edits remain based on our now-confirmed revision and are saved separately.
                const pageUrl = '/spaces/' + encodeURIComponent(data.page.space_key) + '/pages/' + encodeURIComponent(data.page.slug);
                const laterParent = form.elements.publication_space_id.value === String(data.page.space_id) ? form.elements.publication_parent_id.value : String(data.page.parent_id || '');
                form.action = pageUrl;
                const oldKey = recoveryKey;
                isEdit = true;
                editorRoot.dataset.isEdit = '1';
                editorRoot.dataset.pageId = String(data.page.id);
                editorRoot.dataset.spaceId = String(data.page.space_id);
                for (const [key, suffix] of Object.entries({draftsUrl: 'editor-drafts', filesUrl: 'editor-files', previewUrl: 'editor-preview', workspaceUrl: 'editor-workspace', pagesUrl: 'editor-pages', usersUrl: 'editor-users', convertUrl: 'editor-convert'})) editorRoot.dataset[key] = '/spaces/' + encodeURIComponent(data.page.space_key) + '/' + suffix;
                form.elements.publication_space_id.value = String(data.page.space_id);
                form.elements.publication_parent_id.value = form.elements.parent_id.value = laterParent;
                editorRoot.querySelectorAll('[data-editor-heading]').forEach((element) => { element.textContent = t('Edit page'); });
                editorRoot.querySelector('[data-version-hint]').textContent = t('Based on version ') + data.page.version + '.';
                editorRoot.querySelector('[data-editor-cancel]').href = data.url;
                saveButton.textContent = t('Save page');
                documentKey = 'page:' + data.page.id;
                recoveryPrefix = 'openwiki:draft:v2:' + userId + ':' + editorRoot.dataset.spaceId + ':' + documentKey + ':' + instance + ':';
                recoveryKey = recoveryPrefix + editorId;
                draftVersion = 0; draftGeneration = 0; draftAttempt = null;
                editorRoot.dataset.lockUrl = pageUrl + '/edit-lock';
                editorRoot.dataset.unlockUrl = pageUrl + '/edit-unlock';
                history.replaceState(null, '', pageUrl + '/edit');
                // Server mappings identify each source URL; wiki-link decoration may change anchor order.
                const urls = new Map(Object.entries(data.page.asset_replacements || {}));
                const attachmentId = (url) => /^\/attachments\/([1-9][0-9]*)\//.exec(url)?.[1];
                const replaceHtml = (source) => {
                    const dom = new DOMParser().parseFromString(source, 'text/html'); let changed = false;
                    for (const node of dom.querySelectorAll('img,a')) {
                        const attribute = node.tagName === 'IMG' ? 'src' : 'href';
                        const url = urls.get(node.getAttribute(attribute)); if (!url) continue;
                        node.setAttribute(attribute, url); node.setAttribute('data-attachment-id', attachmentId(url));
                        node.removeAttribute('data-draft-file-id'); changed = true;
                    }
                    return changed ? dom.body.innerHTML : source;
                };
                let tr = richEditor.state.tr;
                richEditor.state.doc.descendants((node, pos) => {
                    const url = node.type.name === 'imageFigure' && urls.get(node.attrs.src);
                    if (url) tr = tr.setNodeMarkup(pos, undefined, {...node.attrs, src: url, 'data-attachment-id': attachmentId(url), 'data-draft-file-id': null});
                    if (node.isText) for (const mark of node.marks) {
                        const href = mark.type.name === 'link' && urls.get(mark.attrs.href);
                        if (href) tr = tr.removeMark(pos, pos + node.nodeSize, mark).addMark(pos, pos + node.nodeSize,
                            mark.type.create({...mark.attrs, href, 'data-attachment-id': attachmentId(href), 'data-draft-file-id': null}));
                    }
                });
                // Pending Markdown keeps exact source bytes. The next conditional write rewrites
                // actual destinations server-side; owned adopted URLs remain valid for this page.
                if (originalMarkdown !== null) originalMarkdown = {...originalMarkdown,
                    html: replaceHtml(originalMarkdown.html)};
                if (sourceOverride !== null) sourceOverride = replaceHtml(sourceOverride);
                if (conversionBackup !== null) conversionBackup = {...conversionBackup, html: replaceHtml(conversionBackup.html)};
                if (tr.docChanged) richEditor.view.dispatch(tr.setMeta('addToHistory', false));
                markDirty();
                const afterTransitionGeneration = generation;
                if (await persistLocalRecovery() && oldKey !== recoveryKey) {
                    await localRecovery.remove(oldKey, captured.sequence);
                }
                saveState.textContent = t('The submitted version was saved. Your later changes are still in this editor and need another save.');
                publishLatestAfterReplay = replayingPage && captured.sequence !== submissionGeneration && unchangedDuringReplay && generation === afterTransitionGeneration;
            }
        } catch (error) {
            if (error.status && error.status < 500) pageAttempt = null;
            dirty = true;
            lastSaveError = error.message;
            persistLocalRecovery();
            if (error.conflict) showConflict(error.conflict);
            saveState.dataset.state = error.conflict ? 'conflict' : 'error';
            saveState.textContent = error.message + t(' Your document and its original base version are retained.');
        } finally { saving = false; updateSaveButton(); }
        if (publishLatestAfterReplay && !saveButton.disabled) {
            editorRoot.dataset.publicationReplay = '1';
            try { form.requestSubmit(); } finally { delete editorRoot.dataset.publicationReplay; }
        }
    });
    requestJson(editorRoot.dataset.draftsUrl + '?document_key=' + encodeURIComponent(documentKey)).then((data) => {
        for (const draft of data.drafts) {
            const local = recoveryCopies.get(draft.editorId);
            if (!local || Number(local.sequence) < draft.sequence) recoveryCopies.set(draft.editorId, {...draft, localKey: local?.localKey});
        }
        for (const point of data.checkpoints || []) recoveryCopies.set('checkpoint:' + point.id, {...point, checkpoint: point.id, title: t('Recovery point')});
        showRecovery();
    }).catch(() => { /* Local recovery remains usable without the server. */ });
    const resume = async () => {
        if (accountChanged || !navigator.onLine) return;
        try {
            // Check account and current document before any queued content is sent again.
            const checked = await requestJson(editorRoot.dataset.draftsUrl + '?document_key=' + encodeURIComponent(documentKey));
            csrfToken = checked.csrf_token;
            if (draftAttempt) draftAttempt.body._token = csrfToken;
            if (pageAttempt) pageAttempt.body._token = csrfToken;
            authStopped = false; editorRoot.querySelector('[data-resume-save]').hidden = true;
            autosave();
        } catch (error) { saveState.textContent = error.message; }
    };
    editorRoot.querySelector('[data-resume-save]').addEventListener('click', resume);
    window.addEventListener('online', resume);
    window.addEventListener('offline', () => { persistLocalRecovery(); saveState.textContent = 'Brak połączenia. Dokument pozostaje w edytorze.'; });
    window.addEventListener('storage', (event) => {
        if (event.key === 'openwiki:active-user' && event.newValue !== userId) {
            accountChanged = true;
            richEditor.setEditable(false, false);
            richEditor.view.dispatch(richEditor.state.tr);
            persistLocalRecovery();
            updateSaveButton();
            saveState.textContent = t('The signed-in account changed in another tab. Your document is retained for the original account; reopen the editor after signing in.');
        }
    });
    window.addEventListener('beforeunload', (event) => {
        if (!dirty || generation === draftGeneration || Number(editorRoot.dataset.recoveryClose) === generation) return;
        persistLocalRecovery();
        event.preventDefault();
        event.returnValue = '';
    });
    window.addEventListener('pagehide', () => {
        window.clearTimeout(autosaveTimer);
        window.clearTimeout(localSaveTimer);
        window.clearTimeout(maximumSaveTimer);
        if (dirty) persistLocalRecovery();
        if (lockToken && editorRoot.dataset.unlockUrl) navigator.sendBeacon(editorRoot.dataset.unlockUrl,
            new URLSearchParams({_token: csrfToken, lock_token: lockToken}));
    });
    if (isEdit) { acquireLock(); window.setInterval(acquireLock, 30000); }
    setMode(formatInput.value);
    refreshTools = setupEditorTools(editorRoot, richEditor, requestJson, snapshot, fields,
        () => formatInput.value === 'visual' && !accountChanged && !modelPending && sourceOverride === null);
    const refreshBasic = refreshTools;
    const refreshWorkspace = setupWorkspace(editorRoot, richEditor,
        () => formatInput.value === 'visual' && !accountChanged && !modelPending && sourceOverride === null, requestJson, snapshot);
    const refreshDocument = setupDocumentWorkspace({root: editorRoot, editor: richEditor, form, requestJson, snapshot, fields, safeHtml,
        applyTemplate, preserve: persistLocalRecovery, saveDraft: autosave});
    refreshTools = () => { refreshBasic(); refreshWorkspace(); refreshDocument(); };
    refreshTools();
}
