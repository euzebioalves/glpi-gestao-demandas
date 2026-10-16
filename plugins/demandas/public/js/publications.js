(() => {
    'use strict';
    const endpoint = '/plugins/demandas/front/work-package-publications.php';
    let generation = 0;
    const text = (tag, value, className = '') => {
        const node = document.createElement(tag);
        node.textContent = value;
        node.className = className;
        return node;
    };
    const date = value => {
        const parsed = new Date(value);
        return Number.isNaN(parsed.getTime()) ? 'Não informada' : parsed.toLocaleString('pt-BR');
    };
    const versions = report => [...new Set((report.tasks || []).flatMap(task => (task.releases || [])
        .map(release => `${release.environment}: ${release.version}`)))].join(' | ');
    const render = (modal, report) => {
        const content = modal.querySelector('.demandas-publications-content');
        content.replaceChildren();
        if (!report) { content.append(text('p', 'Ainda não consultado. Use Consultar agora para buscar TASKs e versões.')); return; }
        content.append(text('p', 'Última consulta: ' + date(report.checked_at), 'text-muted'));
        if (!report.tasks.length) { content.append(text('p', 'Nenhuma subtarefa ou relação direta visível ao token nesta consulta.')); return; }
        const wrapper = text('div', '', 'table-responsive');
        const table = text('table', '', 'table table-sm table-vcenter d-block d-sm-table');
        const head = text('thead', '', 'd-none d-sm-table-header-group');
        const header = document.createElement('tr');
        ['TASK / WP', 'Tipo / relação', 'Status', 'Publicações identificadas'].forEach(label => header.append(text('th', label)));
        head.append(header); table.append(head);
        const body = text('tbody', '', 'd-block d-sm-table-row-group');
        for (const task of report.tasks) {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            let link = text('span', `#${task.id} — ${task.subject}`);
            try {
                const base = new URL(modal.dataset.opUrl);
                if (['http:', 'https:'].includes(base.protocol) && !base.username && !base.password) {
                    link = text('a', link.textContent);
                    link.href = modal.dataset.opUrl.replace(/\/$/, '') + '/work_packages/' + Number(task.id);
                    link.target = '_blank'; link.rel = 'noopener noreferrer';
                }
            } catch (_) { /* Invalid configured URL: keep the label as plain text. */ }
            cell.append(link); row.append(cell);
            row.append(text('td', `${task.type || 'Não informado'} / ${task.relationship}`));
            row.append(text('td', task.status || 'Não informado'));
            const releases = document.createElement('td');
            if (!task.releases.length) releases.append(text('span', 'Nenhuma publicação identificada'));
            for (const release of task.releases) {
                releases.append(text('div', `${release.environment}: ${release.version}`, 'fw-semibold'));
                releases.append(text('small', `Comentário #${release.activity_id} · ${date(release.created_at)}`, 'text-muted d-block mb-2'));
            }
            row.append(releases);
            row.className = 'd-block d-sm-table-row mb-4';
            [...row.children].forEach((cell, index) => {
                cell.className = 'd-block d-sm-table-cell';
                cell.style.overflowWrap = 'anywhere';
                cell.prepend(text('small', ['TASK / WP', 'Tipo / relação', 'Status', 'Publicações identificadas'][index], 'd-block d-sm-none text-muted'));
            });
            body.append(row);
        }
        table.append(body); wrapper.append(table); content.append(wrapper);
    };
    const request = async (modal, action, job) => {
        const refresh = modal.querySelector('.demandas-publications-refresh');
        const body = new URLSearchParams({ ticket: modal.dataset.ticket, wp: modal.dataset.wp });
        const options = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
        if (action) {
            body.set('action', action); body.set('job', job || ''); body.set('_glpi_csrf_token', refresh.dataset.csrf);
            options.method = 'POST'; options.body = body;
        }
        const response = await fetch(endpoint + (action ? '' : '?' + body), options);
        let result;
        try { result = await response.json(); } catch (_) { throw new Error('Sessão ou permissão inválida. Reabra o chamado e tente novamente.'); }
        if (result.csrf && refresh) refresh.dataset.csrf = result.csrf;
        if (!response.ok) throw new Error(result.error || 'Acesso negado. Reabra o chamado e confira suas permissões.');
        return result;
    };
    document.addEventListener('click', async event => {
        const open = event.target.closest('.demandas-publications-open');
        if (!open) return;
        const modal = document.getElementById('demandas-publications-modal');
        if (!modal) return;
        const current = ++generation;
        modal.dataset.ticket = open.dataset.ticket; modal.dataset.wp = open.dataset.wp;
        modal.querySelector('.modal-title').textContent = `TASKs e publicações SGE — WP #${open.dataset.wp}`;
        const status = modal.querySelector('.demandas-publications-status');
        const button = modal.querySelector('.demandas-publications-refresh');
        if (button) button.disabled = true;
        status.textContent = 'Carregando última consulta…';
        modal.querySelector('.demandas-publications-content').replaceChildren();
        try {
            const result = await request(modal);
            if (current !== generation) return;
            render(modal, result.report); status.textContent = '';
        } catch (error) { if (current === generation) status.textContent = error.message; }
        finally { if (current === generation && button) button.disabled = false; }
    });
    document.addEventListener('click', async event => {
        const button = event.target.closest('.demandas-publications-refresh');
        if (!button || button.disabled) return;
        const modal = button.closest('.modal');
        const current = generation;
        const status = modal.querySelector('.demandas-publications-status');
        button.disabled = true;
        // Prevent switching WPs while the session's single job is running.
        const opens = [...document.querySelectorAll('.demandas-publications-open')];
        opens.forEach(node => { node.disabled = true; });
        status.replaceChildren(text('span', '', 'spinner-border spinner-border-sm me-2'), text('span', 'Identificando TASKs…'));
        try {
            let result = await request(modal, 'start');
            while (!result.done && current === generation && modal.isConnected) {
                status.replaceChildren(text('span', '', 'spinner-border spinner-border-sm me-2'),
                    text('span', result.total === null ? 'Identificando TASKs…' : `Consultando comentários: ${result.processed} de ${result.total} tarefas concluídas.`));
                result = await request(modal, 'step', result.job);
            }
            if (!result.done || current !== generation) return;
            render(modal, result.report);
            status.textContent = 'Consulta concluída e registrada no histórico. Reabra a aba para ver o novo evento.';
            document.querySelectorAll(`[data-publications-summary="${Number(modal.dataset.wp)}"]`).forEach(cell => {
                cell.replaceChildren(text('span', versions(result.report) || 'Nenhuma publicação identificada'),
                    text('small', 'Consulta: ' + date(result.report.checked_at), 'text-muted d-block'));
            });
        } catch (error) { status.textContent = error.message; }
        finally { button.disabled = false; opens.forEach(node => { node.disabled = false; }); }
    });
})();
