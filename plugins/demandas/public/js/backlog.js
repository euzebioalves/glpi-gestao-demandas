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
    let currentData = null, generation = 0, detailGeneration = 0;
    const search = document.getElementById('backlog-search');
    const person = document.getElementById('backlog-person');
    const peopleButton = document.getElementById('backlog-people');
    const normalize = value => String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
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
    function ticketLinks(ids) {
        const links = element('div', 'backlog-tickets');
        for (const id of ids || []) {
            const link = element('a', '', `Chamado #${id}`);
            link.href = `/front/ticket.form.php?id=${Number(id)}`;
            link.target = '_blank'; link.rel = 'noopener noreferrer';
            link.addEventListener('click', event => event.stopPropagation());
            links.append(link);
        }
        return links;
    }
    async function details(row) {
        const request = ++detailGeneration;
        document.getElementById('backlog-detail-title').textContent = `WP #${row.id}`;
        document.getElementById('backlog-detail-subject').textContent = row.subject;
        const fields = document.getElementById('backlog-detail-fields');
        fields.replaceChildren();
        const labels = {status:'Status', type:'Tipo', project:'Projeto', customer:'Cliente', responsible:'Responsável', assignee:'Atribuído para', priority:'Prioridade', start_date:'Início', due_date:'Prazo', created_at:'Criada em', updated_at:'Atualizada em'};
        for (const [key, label] of Object.entries(labels)) fields.append(element('dt', '', label), element('dd', '', row[key] || 'Não informado'));
        const time = element('dd', '', row.timePending ? 'Consultando…' : hours(row.minutes));
        time.dataset.timeWp = row.id;
        fields.append(element('dt', '', 'Seu tempo registrado'), time);
        fields.append(element('dt', '', 'Chamados GLPI'), ticketLinks(row.ticket_ids));
        const description = document.getElementById('backlog-description');
        description.textContent = 'Carregando descrição…';
        const link = document.getElementById('backlog-openproject');
        link.hidden = !root.dataset.openproject;
        if (root.dataset.openproject) link.href = `${root.dataset.openproject}/work_packages/${row.id}`;
        try {
            const response = await fetch(`${endpoint}?action=detail&wp=${row.id}`, {credentials:'same-origin', cache:'no-store'});
            if (!response.ok) throw new Error();
            const data = await response.json();
            if (request !== detailGeneration) return;
            // HTML is sanitized by BacklogDetails, with authenticated attachment URLs.
            description.innerHTML = data.html || '<p>Sem descrição.</p>';
            for (const image of description.querySelectorAll('img')) image.addEventListener('error', () => {
                image.replaceWith(element('p', 'text-muted', 'Imagem indisponível. Consulte o anexo no OpenProject.'));
            }, {once:true});
            fields.querySelector('.backlog-tickets')?.replaceWith(ticketLinks(data.ticket_ids));
        } catch {
            if (request === detailGeneration) description.textContent = 'Não foi possível carregar a descrição. Tente novamente ou abra no OpenProject.';
        }
    }
    function render(data, updateOptions = true) {
        const statuses = [...data.statuses];
        for (const id of data.selected) if (!statuses.some(s => Number(s.id) === id)) statuses.push({id, name:`Status #${id} (indisponível)`});
        if (options && updateOptions) {
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
            const query = normalize(search.value.trim());
            const rows = data.rows.filter(row => row.status_id === Number(status.id) &&
                normalize([row.id, row.subject, row.project, row.customer, row.responsible, ...(row.ticket_ids || [])].join(' ')).includes(query));
            const column = element('section', 'backlog-column');
            const heading = element('h2', 'backlog-column-title');
            heading.append(element('span', '', status.name), element('span', 'badge bg-primary-lt', String(rows.length)));
            column.append(heading);
            const cards = element('div', 'backlog-column-cards');
            cards.tabIndex = 0; cards.setAttribute('aria-label', `WPs: ${status.name}`);
            column.append(cards);
            for (const row of rows) {
                const card = element('article', 'backlog-card'); card.tabIndex = 0; card.setAttribute('role', 'button');
                card.setAttribute('data-bs-toggle', 'offcanvas'); card.setAttribute('data-bs-target', '#backlog-detail');
                card.setAttribute('aria-controls', 'backlog-detail');
                card.append(element('span', 'backlog-number', `#${row.id}`), element('strong', 'backlog-subject', row.subject));
                card.append(element('span', 'backlog-meta', row.project || 'Projeto não informado'), element('span', 'backlog-meta', row.customer || 'Cliente não informado'));
                card.append(ticketLinks(row.ticket_ids));
                const footer = element('span', 'backlog-card-footer');
                const time = element('span', 'backlog-time');
                time.title = 'Seu total registrado no OpenProject nesta WP';
                const icon = element('i', 'ti ti-clock'); icon.setAttribute('aria-hidden', 'true');
                time.append(icon, document.createTextNode(' ' + (row.timePending ? 'Consultando…' : hours(row.minutes))));
                time.dataset.timeWp = row.id;
                footer.append(element('span', '', row.responsible || 'Sem responsável'), time); card.append(footer);
                card.addEventListener('click', () => details(row));
                card.addEventListener('keydown', event => {
                    if (event.target === card && ['Enter',' '].includes(event.key)) {event.preventDefault(); card.click();}
                });
                cards.append(card);
            }
            if (!rows.length) cards.append(element('p', 'text-muted p-3', 'Nenhuma WP neste status ou busca.'));
            board.append(column);
        }
        if (!data.selected.length) board.append(element('p', 'text-muted p-3', 'Nenhuma coluna selecionada.'));
        if (updateOptions) notice(`${data.rows.length} WP(s) • Quadro carregado. Consultando suas horas em segundo plano…`);
    }
    async function loadTimes(data, request) {
        let failed = false;
        for (let offset = 0; offset < data.rows.length; offset += 25) {
            if (request !== generation) return;
            const rows = data.rows.slice(offset, offset + 25);
            let minutes = {};
            try {
                const response = await fetch(`${endpoint}?action=times&ids=${rows.map(r=>r.id).join(',')}`, {credentials:'same-origin', cache:'no-store'});
                if (!response.ok) throw new Error();
                minutes = (await response.json()).minutes || {};
            } catch { failed = true; }
            if (request !== generation) return;
            for (const row of rows) {
                row.minutes = minutes[row.id] ?? null; row.timePending = false;
                for (const node of document.querySelectorAll(`[data-time-wp="${row.id}"]`)) {
                    node.textContent = hours(row.minutes);
                    if (node.classList.contains('backlog-time')) node.prepend(element('i', 'ti ti-clock'), document.createTextNode(' '));
                }
            }
        }
        if (request === generation) notice(`${data.rows.length} WP(s) • Atualizado às ${new Date().toLocaleTimeString('pt-BR')}.${failed ? ' Algumas horas estão indisponíveis.' : ''}`, failed ? 'warning' : 'info');
    }
    async function load() {
        if (busy) return;
        const request = ++generation;
        busy = true; refresh.disabled = true; if (configure) configure.disabled = true;
        if (person) person.disabled = true;
        board.replaceChildren(); currentData = null; notice('Consultando Work Packages no OpenProject…');
        try {
            const response = await fetch(`${endpoint}?responsible=${Number(person?.value || 0)}`, {credentials:'same-origin', cache:'no-store'});
            if (!response.ok) throw new Error();
            currentData = await response.json();
            for (const row of currentData.rows) row.timePending = true;
            render(currentData);
            void loadTimes(currentData, request);
            if (configure) configure.disabled = false;
        } catch {
            notice('Não foi possível consultar o Backlog. Confira seu token pessoal e as permissões no OpenProject. Tente atualizar novamente.', 'warning');
        } finally {busy = false; refresh.disabled = false; if (person) person.disabled = false;}
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
    search.addEventListener('input', () => {if (currentData) render(currentData, false);});
    person?.addEventListener('change', () => {
        if (settings && !settings.hidden) {
            person.value = String(currentData?.ownerId === currentData?.userId ? 0 : currentData?.ownerId || 0);
            notice('Salve suas colunas ou feche a configuração antes de trocar o responsável.', 'warning'); return;
        }
        load();
    });
    peopleButton?.addEventListener('click', async () => {
        peopleButton.disabled = true;
        try {
            const response = await fetch(`${endpoint}?action=people`, {credentials:'same-origin', cache:'no-store'});
            if (!response.ok) throw new Error();
            const {people} = await response.json();
            const selected = person.value;
            person.replaceChildren(new Option('Meu backlog', '0'));
            for (const colleague of people) if (Number(colleague.id) !== currentData?.userId) person.add(new Option(colleague.name, colleague.id));
            person.value = selected;
        } catch {notice('Não foi possível consultar os colegas visíveis ao seu token. Tente novamente.', 'warning');}
        finally {peopleButton.disabled = false;}
    });
    load();
})();
