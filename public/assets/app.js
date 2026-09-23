'use strict';

document.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        const search = document.querySelector('#global-search');
        if (search) {
            event.preventDefault();
            search.focus();
            search.select();
        }
    }
});

const editorRoot = document.querySelector('[data-wiki-editor]');
if (editorRoot) {
    const formatInput = editorRoot.querySelector('[data-content-format]');
    const visualEditor = editorRoot.querySelector('[data-visual-editor]');
    const markdownEditor = editorRoot.querySelector('[data-markdown-editor]');
    const htmlField = editorRoot.querySelector('[data-html-field]');
    const form = editorRoot.querySelector('[data-editor-form]');
    const saveButton = editorRoot.querySelector('[data-save-button]');
    const saveState = editorRoot.querySelector('[data-save-state]');
    const lockWarning = editorRoot.querySelector('[data-edit-lock-warning]');
    const recoveryBanner = editorRoot.querySelector('[data-recovery-banner]');
    const recoveryText = editorRoot.querySelector('[data-recovery-text]');
    const tabs = Array.from(editorRoot.querySelectorAll('[data-editor-mode]'));
    const panes = Array.from(editorRoot.querySelectorAll('[data-pane]'));
    const isEdit = editorRoot.dataset.isEdit === '1';
    const csrfToken = form.querySelector('input[name="_token"]').value;
    const baseVersionField = form.querySelector('input[name="base_version"]');
    const recoveryKey = 'openwiki:draft:' + window.location.pathname;
    let lockToken = '';
    let dirty = false;
    let autosaveTimer = null;
    let localSaveTimer = null;

    const setMode = (mode) => {
        const safeMode = mode === 'markdown' ? 'markdown' : 'visual';
        formatInput.value = safeMode;
        tabs.forEach((tab) => {
            const active = tab.dataset.editorMode === safeMode;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        panes.forEach((pane) => {
            pane.hidden = pane.dataset.pane !== safeMode;
        });
    };

    const snapshot = () => ({
        savedAt: new Date().toISOString(),
        title: form.elements.title.value,
        slug: form.elements.slug.value,
        parentId: form.elements.parent_id.value,
        status: form.elements.status.value,
        format: formatInput.value,
        html: visualEditor.innerHTML,
        markdown: markdownEditor.value
    });

    const persistLocalRecovery = () => {
        try {
            localStorage.setItem(recoveryKey, JSON.stringify(snapshot()));
            saveState.textContent = 'Recovery copy saved locally.';
        } catch {
            saveState.textContent = 'Local recovery storage is unavailable.';
        }
    };

    const markDirty = () => {
        dirty = true;
        window.clearTimeout(localSaveTimer);
        localSaveTimer = window.setTimeout(persistLocalRecovery, 500);
    };

    const restoreSnapshot = (data) => {
        if (!data) return;
        if (typeof data.title === 'string') form.elements.title.value = data.title;
        if (typeof data.slug === 'string') form.elements.slug.value = data.slug;
        if (typeof data.parentId === 'string') form.elements.parent_id.value = data.parentId;
        if (typeof data.status === 'string') form.elements.status.value = data.status;
        if (typeof data.html === 'string') visualEditor.innerHTML = data.html;
        if (typeof data.markdown === 'string') markdownEditor.value = data.markdown;
        setMode(data.format);
        markDirty();
    };

    let recovery = null;
    try {
        const local = localStorage.getItem(recoveryKey);
        if (local) recovery = JSON.parse(local);
    } catch {
        recovery = null;
    }

    if (!recovery) {
        const serverDraft = editorRoot.querySelector('[data-server-draft]');
        if (serverDraft) {
            recovery = {
                savedAt: serverDraft.dataset.savedAt,
                format: serverDraft.dataset.format,
                html: serverDraft.querySelector('[data-server-draft-html]').value,
                markdown: serverDraft.querySelector('[data-server-draft-markdown]').value
            };
        }
    }

    if (recovery) {
        recoveryText.textContent = 'Recovered editor content from ' + (recovery.savedAt || 'an earlier autosave') + ' is available.';
        recoveryBanner.hidden = false;
        editorRoot.querySelector('[data-restore-recovery]').addEventListener('click', () => {
            restoreSnapshot(recovery);
            recoveryBanner.hidden = true;
            saveState.textContent = 'Recovered content restored. Save the page to create a revision.';
        });
        editorRoot.querySelector('[data-hide-recovery]').addEventListener('click', () => {
            recoveryBanner.hidden = true;
        });
    }

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            setMode(tab.dataset.editorMode);
            markDirty();
        });
    });

    editorRoot.querySelectorAll('[data-command]').forEach((button) => {
        button.addEventListener('click', () => {
            visualEditor.focus();
            const command = button.dataset.command;
            const value = button.dataset.commandValue || null;
            document.execCommand(command, false, value);
            markDirty();
        });
    });

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
        }
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
    if (templateSelector) {
        templateSelector.addEventListener('change', () => {
            const option = templateSelector.options[templateSelector.selectedIndex];
            if (!option || !option.value) return;
            visualEditor.innerHTML = option.dataset.html || '<p></p>';
            markdownEditor.value = option.dataset.markdown || '';
            setMode(markdownEditor.value ? 'markdown' : 'visual');
            markDirty();
        });
    }

    form.addEventListener('input', markDirty);
    form.addEventListener('change', markDirty);

    const acquireLock = async () => {
        if (!isEdit || !editorRoot.dataset.lockUrl) return;

        const body = new URLSearchParams({_token: csrfToken});
        try {
            const response = await fetch(editorRoot.dataset.lockUrl, {
                method: 'POST',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body,
                credentials: 'same-origin'
            });
            const payload = await response.json();

            if (!response.ok) {
                throw new Error(payload?.error?.message || 'Unable to verify edit lock.');
            }

            if (!payload.data.acquired) {
                lockWarning.textContent = (payload.data.holder || 'Another user') + ' is currently editing this page. Saving is disabled until the edit lock is released.';
                lockWarning.hidden = false;
                saveButton.disabled = true;
                return;
            }

            lockToken = payload.data.token || lockToken;
            lockWarning.hidden = true;
            saveButton.disabled = false;
        } catch {
            lockWarning.textContent = 'The active editor lock could not be verified. Optimistic locking will still prevent silent overwrites.';
            lockWarning.hidden = false;
            saveButton.disabled = false;
        }
    };

    const autosave = async () => {
        if (!isEdit || !dirty || !editorRoot.dataset.autosaveUrl || !baseVersionField) return;

        htmlField.value = visualEditor.innerHTML;
        const body = new URLSearchParams({
            _token: csrfToken,
            base_version: baseVersionField.value,
            content_format: formatInput.value,
            content_html: visualEditor.innerHTML,
            content_markdown: markdownEditor.value
        });

        try {
            const response = await fetch(editorRoot.dataset.autosaveUrl, {
                method: 'POST',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body,
                credentials: 'same-origin'
            });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload?.error?.message || 'Autosave failed.');

            if (payload.data.stale) {
                saveState.textContent = 'Autosave kept a recovery copy, but the page has a newer revision. Reload before saving.';
            } else {
                saveState.textContent = 'Autosaved at ' + payload.data.saved_at + '.';
            }
            dirty = false;
        } catch {
            saveState.textContent = 'Server autosave failed; local recovery copy remains available.';
        }
    };

    form.addEventListener('submit', () => {
        htmlField.value = visualEditor.innerHTML;
        try {
            localStorage.removeItem(recoveryKey);
        } catch {
            // No action is required when storage is unavailable.
        }
    });

    if (isEdit) {
        acquireLock();
        window.setInterval(acquireLock, 30000);
        autosaveTimer = window.setInterval(autosave, 10000);

        window.addEventListener('pagehide', () => {
            window.clearInterval(autosaveTimer);
            if (!lockToken || !editorRoot.dataset.unlockUrl) return;
            const body = new URLSearchParams({_token: csrfToken, lock_token: lockToken});
            navigator.sendBeacon(editorRoot.dataset.unlockUrl, body);
        });
    }

    setMode(formatInput.value);
}

document.querySelectorAll('[data-document-content] img[data-attachment-id]').forEach((image) => {
    image.classList.add('document-image--attachment');
    image.setAttribute('tabindex', '0');
    image.setAttribute('role', 'link');

    const openPreview = () => {
        const id = image.dataset.attachmentId;
        if (!id) return;
        window.open('/attachments/' + encodeURIComponent(id) + '/preview', '_blank', 'noopener');
    };

    image.addEventListener('click', openPreview);
    image.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openPreview();
        }
    });
});

document.querySelectorAll('[data-document-content] pre').forEach((pre) => {
    const code = pre.querySelector('code');
    if (!code) {
        return;
    }

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'code-copy';
    button.textContent = 'Copy';
    button.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(code.innerText);
            button.textContent = 'Copied';
            window.setTimeout(() => {
                button.textContent = 'Copy';
            }, 1500);
        } catch {
            button.textContent = 'Copy failed';
            window.setTimeout(() => {
                button.textContent = 'Copy';
            }, 1500);
        }
    });

    pre.appendChild(button);
});
