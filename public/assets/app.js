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

try { localStorage.setItem('openwiki:active-user', document.body.dataset.userId || ''); } catch {}

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


document.querySelectorAll('[data-install-wizard]').forEach((wizard) => {
    const panels = Array.from(wizard.querySelectorAll('[data-wizard-panel]'));
    const indicators = Array.from(wizard.querySelectorAll('[data-wizard-indicator]'));
    const form = wizard.querySelector('[data-wizard-form]');
    const requirementsOk = wizard.dataset.requirementsOk === '1';
    const databaseTest = wizard.querySelector('[data-database-test]');
    const databaseTestButton = databaseTest?.querySelector('[data-database-test-button]') || null;
    const databaseTestStatus = databaseTest?.querySelector('[data-database-test-status]') || null;
    const databaseNext = wizard.querySelector('[data-database-next]');
    let currentStep = 1;
    let databaseTestPassed = databaseNext === null;

    const updateSummary = () => {
        if (!form) return;
        wizard.querySelectorAll('[data-wizard-summary]').forEach((target) => {
            const name = target.dataset.wizardSummary || '';
            const field = form.elements.namedItem(name);
            if (!field || !('value' in field)) return;
            target.textContent = String(field.value || '—');
        });
    };

    const showStep = (step) => {
        currentStep = Math.max(1, Math.min(panels.length, step));

        panels.forEach((panel) => {
            panel.classList.toggle('is-active', Number(panel.dataset.wizardPanel) === currentStep);
        });

        indicators.forEach((indicator) => {
            const indicatorStep = Number(indicator.dataset.wizardIndicator);
            indicator.classList.toggle('is-active', indicatorStep === currentStep);
            indicator.classList.toggle('is-complete', indicatorStep < currentStep);
        });

        if (currentStep === panels.length) {
            updateSummary();
        }

        wizard.scrollIntoView({behavior: 'smooth', block: 'start'});
    };

    const validateCurrentStep = () => {
        const panel = panels.find((item) => Number(item.dataset.wizardPanel) === currentStep);
        if (!panel) return true;

        const fields = Array.from(panel.querySelectorAll('input, select, textarea'));
        for (const field of fields) {
            if (field.disabled) continue;
            if (!field.checkValidity()) {
                field.reportValidity();
                field.focus();
                return false;
            }
        }

        return true;
    };

    const setDatabaseTestStatus = (state, message) => {
        if (!databaseTestStatus) return;
        databaseTestStatus.hidden = false;
        databaseTestStatus.classList.toggle('is-success', state === 'success');
        databaseTestStatus.classList.toggle('is-error', state === 'error');
        databaseTestStatus.classList.toggle('is-pending', state === 'pending');
        databaseTestStatus.textContent = message;
    };

    const resetDatabaseTest = () => {
        if (!databaseNext) return;
        const hadStatus = databaseTestStatus !== null && !databaseTestStatus.hidden;
        databaseTestPassed = false;
        databaseNext.disabled = true;
        if (hadStatus) {
            setDatabaseTestStatus('pending', 'Connection settings changed. Test the connection again.');
        }
    };

    wizard.classList.add('is-enhanced');

    wizard.querySelectorAll('[data-wizard-next]').forEach((button) => {
        button.addEventListener('click', () => {
            if (currentStep === 1 && !requirementsOk) return;
            if (button.matches('[data-database-next]') && !databaseTestPassed) {
                setDatabaseTestStatus('error', 'Test the database connection successfully before continuing.');
                return;
            }
            if (!validateCurrentStep()) return;
            showStep(currentStep + 1);
        });
    });

    wizard.querySelectorAll('[data-wizard-prev]').forEach((button) => {
        button.addEventListener('click', () => showStep(currentStep - 1));
    });

    if (form) {
        form.addEventListener('input', updateSummary);
        form.addEventListener('change', updateSummary);

        ['db_host', 'db_port', 'db_database', 'db_username', 'db_password', 'create_database'].forEach((name) => {
            const field = form.elements.namedItem(name);
            if (!field || !('addEventListener' in field)) return;
            field.addEventListener('input', resetDatabaseTest);
            field.addEventListener('change', resetDatabaseTest);
        });
    }

    databaseTestButton?.addEventListener('click', async () => {
        if (!form || !databaseTest) return;
        if (!validateCurrentStep()) return;

        databaseTestPassed = false;
        if (databaseNext) databaseNext.disabled = true;
        databaseTestButton.disabled = true;
        const originalLabel = databaseTestButton.textContent;
        databaseTestButton.textContent = 'Testing...';
        setDatabaseTestStatus('pending', 'Checking database connection...');

        try {
            const response = await fetch(databaseTest.dataset.testUrl || '/install/test-database', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new FormData(form),
                credentials: 'same-origin',
            });

            const payload = await response.json().catch(() => ({}));
            if (!response.ok || payload?.data?.ok !== true) {
                throw new Error(payload?.error?.message || 'Database connection test failed.');
            }

            databaseTestPassed = true;
            if (databaseNext) databaseNext.disabled = false;
            setDatabaseTestStatus('success', payload.data.message || 'Database connection successful.');
        } catch (error) {
            databaseTestPassed = false;
            if (databaseNext) databaseNext.disabled = true;
            setDatabaseTestStatus(
                'error',
                error instanceof Error ? error.message : 'Database connection test failed.'
            );
        } finally {
            databaseTestButton.disabled = false;
            databaseTestButton.textContent = originalLabel;
        }
    });

    updateSummary();
    showStep(1);
});

document.querySelectorAll('[data-timezone-picker]').forEach((picker) => {
    const input = picker.querySelector('[data-timezone-input]');
    const detectButton = picker.querySelector('[data-timezone-detect]');
    const help = picker.closest('label')?.querySelector('[data-timezone-help]');
    const datalistId = input?.getAttribute('list') || '';
    const datalist = datalistId ? document.getElementById(datalistId) : null;

    if (!input) return;

    const supportedTimezones = new Set(
        datalist
            ? Array.from(datalist.querySelectorAll('option')).map((option) => option.value)
            : []
    );

    const detectTimezone = () => {
        try {
            const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
            if (!timezone) {
                if (help) help.textContent = 'The browser did not return a timezone. Choose one from the list.';
                return false;
            }

            if (supportedTimezones.size > 0 && !supportedTimezones.has(timezone)) {
                if (help) help.textContent = 'Detected ' + timezone + ', but PHP does not support this identifier. Choose one from the list.';
                return false;
            }

            input.value = timezone;
            input.dispatchEvent(new Event('input', {bubbles: true}));
            input.dispatchEvent(new Event('change', {bubbles: true}));
            if (help) help.textContent = 'Detected from this device: ' + timezone + '. You can change it manually.';
            return true;
        } catch {
            if (help) help.textContent = 'Automatic timezone detection is unavailable. Choose one from the list.';
            return false;
        }
    };

    detectButton?.addEventListener('click', detectTimezone);

    if (picker.dataset.timezoneAutodetect === '1') {
        detectTimezone();
    }
});
