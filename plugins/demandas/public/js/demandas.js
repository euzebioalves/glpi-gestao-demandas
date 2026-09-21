(() => {
    'use strict';

    const ticketId = () => {
        const match = window.location.search.match(/[?&]id=(\d+)/);
        return match ? Number(match[1]) : 0;
    };

    const sourcePhase = () => {
        const source = document.getElementById('demandas-public-phase-source');
        return source?.dataset?.publicPhase || '';
    };

    const sourceLabel = () => document.getElementById('demandas-public-phase-source')?.dataset?.publicPhaseLabel || '';

    const insertField = (phase, configuredLabel, workPackageUrl = '', workPackageId = '', workPackageCount = 0) => {
        // The followup composer also contains a requesttypes_id field. Limit
        // the lookup to the direct fields of the ticket's right-hand panel so
        // the public phase can never be injected into a followup form.
        const sidebar = document.querySelector('.itil-object-fields #item-main .accordion-body');
        if (!sidebar) return false;

        const existing = document.getElementById('demandas-public-phase-field');
        if (existing) {
            if (existing.parentElement === sidebar) {
                const value = existing.querySelector('#demandas-public-phase-value');
                if (value) value.value = phase;
                ensureWorkPackageField(sidebar, existing, workPackageUrl, workPackageId, workPackageCount);
                return phase !== '' || workPackageUrl !== '';
            }
            existing.remove();
        }

        const requestTypeField = Array.from(sidebar.children).find((child) =>
            child.classList?.contains('form-field')
            && child.querySelector('[name="requesttypes_id"]')
        );
        if (!requestTypeField) return false;

        let field = null;
        if (phase) {
            field = document.createElement('div');
            field.id = 'demandas-public-phase-field';
            field.className = requestTypeField.className || 'form-field row align-items-center col-12 glpi-full-width col-sm-6 mb-2';
            field.dataset.testid = 'form-field-demandas-public-phase';

            const label = document.createElement('label');
            const referenceLabel = requestTypeField.querySelector('label');
            label.className = referenceLabel?.className || 'col-form-label col-xxl-5 text-xxl-end';
            label.htmlFor = 'demandas-public-phase-value';
            label.textContent = configuredLabel || 'Fase Pública';

            const container = document.createElement('div');
            const referenceContainer = requestTypeField.querySelector('.field-container');
            container.className = referenceContainer?.className || 'col-xxl-7 field-container';

            const value = document.createElement('input');
            value.id = 'demandas-public-phase-value';
            value.type = 'text';
            value.className = 'form-control';
            value.value = phase;
            value.readOnly = true;
            value.setAttribute('aria-label', configuredLabel || 'Fase Pública');
            value.setAttribute('aria-readonly', 'true');

            container.append(value);
            field.append(label, container);
            sidebar.insertBefore(field, requestTypeField);
        }

        ensureWorkPackageField(sidebar, field || requestTypeField, workPackageUrl, workPackageId, workPackageCount);
        return phase !== '' || workPackageUrl !== '';
    };

    const ensureWorkPackageField = (sidebar, phaseField, workPackageUrl, workPackageId, workPackageCount = 0) => {
        let wpField = document.getElementById('demandas-work-package-field');
        if (!workPackageUrl) {
            wpField?.remove();
            return;
        }
        if (!wpField) {
            const wpField = document.createElement('div');
            wpField.id = 'demandas-work-package-field';
            wpField.className = phaseField.className;
            wpField.dataset.testid = 'form-field-demandas-work-package';
            const wpLabel = document.createElement('label');
            wpLabel.className = phaseField.querySelector('label')?.className || 'col-form-label col-xxl-5 text-xxl-end';
            wpLabel.textContent = workPackageCount > 1 ? 'Work Packages' : 'Work Package';
            const wpContainer = document.createElement('div');
            wpContainer.className = phaseField.querySelector('.field-container')?.className || 'col-xxl-7 field-container';
            const inputGroup = document.createElement('div');
            inputGroup.className = 'input-group';
            const wpValue = document.createElement('input');
            wpValue.type = 'text';
            wpValue.className = 'form-control';
            wpValue.readOnly = true;
            wpValue.value = workPackageId ? `#${workPackageId}${workPackageCount > 1 ? ` (+${workPackageCount - 1})` : ''}` : workPackageUrl;
            const wpLink = document.createElement('a');
            wpLink.id = 'demandas-work-package-link';
            wpLink.className = 'btn btn-outline-primary';
            wpLink.href = workPackageUrl;
            wpLink.target = '_blank';
            wpLink.rel = 'noopener';
            wpLink.title = 'Abrir Work Package no OpenProject';
            wpLink.innerHTML = '<i class="ti ti-external-link"></i>';
            inputGroup.append(wpValue, wpLink);
            wpContainer.append(inputGroup);
            wpField.append(wpLabel, wpContainer);
            if (phaseField.id === 'demandas-public-phase-field') {
                phaseField.after(wpField);
            } else {
                sidebar.insertBefore(wpField, phaseField);
            }
        } else {
            wpField.querySelector('label').textContent = workPackageCount > 1 ? 'Work Packages' : 'Work Package';
            wpField.querySelector('input').value = workPackageId ? `#${workPackageId}${workPackageCount > 1 ? ` (+${workPackageCount - 1})` : ''}` : workPackageUrl;
            wpField.querySelector('a').href = workPackageUrl;
        }
    };

    const loadPhase = async () => {
        let phase = sourcePhase();
        let configuredLabel = sourceLabel();
        let source = document.getElementById('demandas-public-phase-source');
        let workPackageUrl = source?.dataset?.workPackageUrl || '';
        let workPackageId = source?.dataset?.workPackageId || '';
        let workPackageCount = Number(source?.dataset?.workPackageCount || 0);
        if (!phase) {
            const id = ticketId();
            if (!id) return;
            try {
                const response = await fetch(`/plugins/demandas/front/public-phase.php?tickets_id=${id}`, {
                    credentials: 'same-origin',
                    headers: {'Accept': 'application/json'},
                });
                if (!response.ok) return;
                const data = await response.json();
                phase = data.public_phase || '';
                configuredLabel = data.label || '';
                workPackageUrl = data.work_package_url || '';
                workPackageId = data.work_package_id || '';
                workPackageCount = Number(data.work_package_count || 0);
            } catch (_) {
                return;
            }
        }

        if (insertField(phase, configuredLabel, workPackageUrl, workPackageId, workPackageCount)) return;
        const observer = new MutationObserver(() => {
            if (insertField(phase, configuredLabel, workPackageUrl, workPackageId, workPackageCount)) observer.disconnect();
        });
        observer.observe(document.body, {childList: true, subtree: true});
        window.setTimeout(() => observer.disconnect(), 15000);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadPhase, {once: true});
    } else {
        loadPhase();
    }
    window.addEventListener('demandas:phase-ready', loadPhase);
})();
