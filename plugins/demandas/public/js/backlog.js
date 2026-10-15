(() => {
    'use strict';
    const root = document.getElementById('demandas-backlog');
    if (!root) return;
    const board = document.getElementById('backlog-board');
    const message = document.getElementById('backlog-message');
    const refresh = document.getElementById('backlog-refresh');
    const configure = document.getElementById('backlog-configure');
    const settings = document.getElementById('backlog-settings');
    const options = document.getElementById('backlog-status-options');
    const endpoint = '/plugins/demandas/front/backlog-data.php';
    let busy = false;
    const element = (tag, className, text) => {
        const node = document.createElement(tag);
        node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    };
    const hours = minutes => minutes === null ? 'Indisponível' : `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, '0')}min`;
    function notice(text, kind = 'info') {
        message.replaceChildren(element('div', `alert alert-${kind}`, text));
    }
    function details(row) {
        document.getElementById('backlog-detail-title').textContent = `WP #${row.id}`;
        document.getElementById('backlog-detail-subject').textContent = row.subject;
        const fields = document.getElementById('backlog-detail-fields');
        fields.replaceChildren();
        const labels = {status:'Status', type:'Tipo', project:'Projeto', customer:'Cliente', responsible:'Responsável', assignee:'Atribuído para', priority:'Prioridade', start_date:'Início', due_date:'Prazo', created_at:'Criada em', updated_at:'Atualizada em'};
        for (const [key, label] of Object.entries(labels)) fields.append(element('dt', '', label), element('dd', '', row[key] || 'Não informado'));
        fields.append(element('dt', '', 'Seu tempo registrado'), element('dd', '', hours(row.minutes)));
        document.getElementById('backlog-description').textContent = row.description || 'Sem descrição.';
        const link = document.getElementById('backlog-openproject');
        link.hidden = !root.dataset.openproject;
        if (root.dataset.openproject) link.href = `${root.dataset.openproject}/work_packages/${row.id}`;
    }
    function render(data) {
        const statuses = [...data.statuses];
        for (const id of data.selected) if (!statuses.some(s => Number(s.id) === id)) statuses.push({id, name:`Status #${id} (indisponível)`});
        if (options) {
            options.replaceChildren();
            for (const status of statuses) {
                const label = element('label', 'form-check');
                const input = element('input', 'form-check-input');
                input.type = 'checkbox'; input.name = 'statuses[]'; input.value = status.id;
                input.checked = data.selected.includes(Number(status.id));
                label.append(input, element('span', 'form-check-label', status.name)); options.append(label);
            }
        }
        board.replaceChildren();
        for (const status of statuses.filter(s => data.selected.includes(Number(s.id)))) {
            const rows = data.rows.filter(row => row.status_id === Number(status.id));
            const column = element('section', 'backlog-column');
            const heading = element('h2', 'backlog-column-title');
            heading.append(element('span', '', status.name), element('span', 'badge bg-primary-lt', String(rows.length)));
            column.append(heading);
            for (const row of rows) {
                const card = element('button', 'backlog-card'); card.type = 'button';
                card.setAttribute('data-bs-toggle', 'offcanvas'); card.setAttribute('data-bs-target', '#backlog-detail');
                card.setAttribute('aria-controls', 'backlog-detail');
                card.append(element('span', 'backlog-number', `#${row.id}`), element('strong', 'backlog-subject', row.subject));
                card.append(element('span', 'backlog-meta', row.project || 'Projeto não informado'), element('span', 'backlog-meta', row.customer || 'Cliente não informado'));
                const footer = element('span', 'backlog-card-footer');
                const time = element('span', 'backlog-time');
                time.title = 'Seu total registrado no OpenProject nesta WP';
                const icon = element('i', 'ti ti-clock'); icon.setAttribute('aria-hidden', 'true');
                time.append(icon, document.createTextNode(' ' + hours(row.minutes)));
                footer.append(element('span', '', row.responsible || 'Sem responsável'), time); card.append(footer);
                card.addEventListener('click', () => details(row)); column.append(card);
            }
            if (!rows.length) column.append(element('p', 'text-muted p-3', 'Nenhuma WP neste status.'));
            board.append(column);
        }
        if (!data.selected.length) board.append(element('p', 'text-muted p-3', 'Nenhuma coluna selecionada.'));
        notice(`${data.rows.length} WP(s) • Atualizado às ${new Date().toLocaleTimeString('pt-BR')}.${data.timeAvailable ? '' : ' Não foi possível consultar suas horas. O relógio indica indisponibilidade, não zero.'}`, data.timeAvailable ? 'info' : 'warning');
    }
    async function load() {
        if (busy) return;
        busy = true; refresh.disabled = true; if (configure) configure.disabled = true;
        board.replaceChildren(); notice('Consultando suas Work Packages e horas no OpenProject…');
        try {
            const response = await fetch(endpoint, {credentials:'same-origin', cache:'no-store'});
            if (!response.ok) throw new Error();
            render(await response.json());
            if (configure) configure.disabled = false;
        } catch {
            notice('Não foi possível consultar o Backlog. Confira seu token pessoal e as permissões no OpenProject. Tente atualizar novamente.', 'warning');
        } finally {busy = false; refresh.disabled = false;}
    }
    configure?.addEventListener('click', () => {
        settings.hidden = !settings.hidden;
        configure.setAttribute('aria-expanded', String(!settings.hidden));
    });
    settings?.addEventListener('submit', async event => {
        event.preventDefault(); if (busy) return;
        busy = true; const button = settings.querySelector('button'); button.disabled = true; refresh.disabled = true;
        try {
            const response = await fetch(endpoint, {method:'POST', credentials:'same-origin', body:new FormData(settings)});
            if (!response.ok) throw new Error();
            // Refresh the page to renew GLPI's one-use CSRF token.
            window.location.reload();
        } catch {
            notice('Não foi possível salvar. Suas escolhas permanecem no formulário; recarregue a página se sua sessão expirou.', 'warning');
            busy = false; button.disabled = false; refresh.disabled = false;
        }
    });
    refresh.addEventListener('click', () => {
        // Do not overwrite an open, unsaved selection during a data refresh.
        if (settings && !settings.hidden) {notice('Salve suas colunas ou feche a configuração antes de atualizar.', 'warning'); return;}
        load();
    });
    load();
})();
