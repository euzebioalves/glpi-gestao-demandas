<?php
declare(strict_types=1);
namespace GlpiPlugin\Demandas;

/** Presentation only: counts, ordering and authorization come from the service. */
final class ActionDashboardPanel
{
    private const ACTIONS = [
        'stale' => ['Atualizar dados', 'Sincronize as WPs antes de decidir.', 'amber', 'refresh'],
        'sync_failed' => ['Conferir integração', 'A última tentativa falhou; confira acesso e disponibilidade.', 'amber', 'plug-off'],
        'unknown' => ['Conferir status', 'Verifique o status da WP no OpenProject.', 'amber', 'help-circle'],
        'closed_pending' => ['Revisar divergência', 'Chamado encerrado com WP não entregue.', 'red', 'alert-triangle'],
        'blocked' => ['Destravar execução', 'Confira o impedimento com o responsável.', 'red', 'hand-stop'],
        'all_delivered' => ['Conferir encerramento', 'Todas as WPs estão em Entregue.', 'green', 'circle-check'],
        'partial' => ['Conferir pendências', 'Parte das WPs já foi entregue.', 'blue', 'list-check'],
        'no_wp' => ['Vincular WP', 'Sem WP vinculada ao chamado.', 'amber', 'link'],
        'unassigned' => ['Conferir responsável', 'Nome não informado nos dados locais.', 'amber', 'user-question'],
        'aging' => ['Revisar andamento', 'Etapa acima do prazo de atenção.', 'red', 'clock'],
    ];
    private static function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES); }
    private static function url(array $filters, array $changes): string
    {
        return '?' . http_build_query(array_merge($filters, ['metric'=>'','delivery'=>'','attention'=>'','stage'=>''], $changes)) . '#demandas-results';
    }
    public static function reasons(array $reasons): string
    {
        if (!$reasons) return '<span class="da-muted">Sem alerta identificado</span>';
        $html = '<div class="da-reasons">';
        foreach ($reasons as $key => $label) {
            [$title, $hint, $tone] = self::ACTIONS[$key];
            $html .= '<span class="da-tag da-'.$tone.'" title="'.self::h($label).'">'.self::h($title).'</span>';
        }
        return $html.'</div>';
    }
    public static function show(array $data, array $filters): void
    {
        $count = count($data['attention']);
        echo '<section class="demandas-panel da-action-panel my-3" aria-label="Precisam de ação"><div class="da-section-head"><div><span class="da-eyebrow">Prioridades do escopo</span><h2>Precisam de ação <span class="da-total">'.$count.'</span></h2><p>Escolha uma ação para filtrar os chamados.</p></div><a class="btn btn-primary" href="'.self::h(self::url($filters,['attention'=>'any'])).'">Ver todos que precisam de ação <i class="ti ti-arrow-right" aria-hidden="true"></i></a></div><div class="da-action-grid">';
        $empty = [];
        foreach (ActionDashboard::REASONS as $key=>$label) {
            if (in_array($key,ActionDashboard::REVIEW_REASONS,true) && !DashboardView::canReview()) continue;
            $n = (int)$data['counts'][$key];
            if (!$n) { $empty[$key] = $label; continue; }
            [$title, $hint, $tone, $icon] = self::ACTIONS[$key];
            echo '<a class="da-action da-'.$tone.'" href="'.self::h(self::url($filters,['attention'=>$key])).'"><div class="da-action-top"><i class="ti ti-'.$icon.'" aria-hidden="true"></i><strong>'.$n.'</strong><span>chamado(s)</span></div><h3>'.self::h($title).'</h3><p>'.self::h($hint).'</p><span class="da-action-link">Conferir chamados →</span></a>';
        }
        if (!$count) echo '<p class="da-empty">Nenhuma pendência identificada neste escopo.</p>';
        echo '</div>';
        if ($empty) {
            echo '<details class="da-zero"><summary>Sem ocorrências · '.count($empty).' indicadores</summary><div class="da-zero-list">';
            foreach ($empty as $key=>$label) echo '<a href="'.self::h(self::url($filters,['attention'=>$key])).'">'.self::h(self::ACTIONS[$key][0]).' <strong>0</strong></a>';
            echo '</div></details>';
        }
        echo '<p class="da-footnote">Um chamado pode exigir mais de uma ação. Os cartões não devem ser somados.</p></section>';
        $visible = array_slice($data['visible_attention'],0,10);
        echo '<section class="demandas-panel da-queue my-3" aria-label="Fila de conferência"><div class="da-section-head"><div><h2>Por onde começar</h2><p>Até 10 chamados prioritários dos filtros atuais. Abra as WPs para conferir os detalhes.</p></div><span class="da-count">'.count($visible).' na fila</span></div>';
        if (!$visible) echo '<p class="da-empty">Nenhum chamado exige atenção nos filtros atuais.</p>';
        else {
            echo '<div class="table-responsive"><table class="table da-queue-table"><thead><tr><th>Chamado</th><th>Próximo passo</th><th>Técnico GLPI</th><th>Work Packages</th></tr></thead><tbody>';
            foreach ($visible as $row) {
                echo '<tr><td><a class="da-ticket" target="_blank" rel="noopener noreferrer" href="/front/ticket.form.php?id='.(int)$row['ticket_id'].'">#'.(int)$row['ticket_id'].' · '.self::h($row['ticket_name']).'</a><div class="da-muted">'.self::h(ManagementDashboardService::clientLabel($row['entity_name'])).' · '.(int)$row['age_days'].' dias desde a abertura</div></td><td>'.self::reasons($row['attention']).'</td><td>'.self::h(implode(', ',$row['glpi_owners']) ?: 'Não atribuído').'</td><td>'.self::workPackages($row['work_packages']).'</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</section>';
    }
    public static function stages(array $data, array $filters): void
    {
        $max = max(1, ...array_column($data['stages'], 'count'));
        echo '<section class="demandas-panel my-3" aria-label="Envelhecimento por etapa"><div class="da-section-head"><div><h2>Onde as demandas estão paradas</h2><p>Barras: chamados abertos por etapa. Prazo de atenção: '.(int)$filters['attention_days'].' dias observados.</p></div></div><div class="da-stage-heading"><span>Etapa</span><span>Chamados</span><span>Maior tempo observado</span></div>';
        foreach ($data['stages'] as $key=>$stage) {
            $n = (int)$stage['count'];
            $days = $stage['max_days'];
            $tone = $days !== null && $days >= (int)$filters['attention_days'] ? ' da-overdue' : '';
            echo '<a class="da-stage'.(!$n?' da-stage-empty':'').'" href="'.self::h(self::url($filters,['stage'=>$key,'ticket_scope'=>'open'])).'"><strong>'.self::h($stage['label']).'</strong><span class="da-stage-volume"><span class="da-track"><span style="width:'.round(100*$n/$max,1).'%"></span></span><b>'.$n.'</b></span><span class="da-stage-age'.$tone.'">'.(!$n?'—':($days===null?'Sem histórico':(int)$days.' dias')).'</span></a>';
        }
        echo '<details class="da-method"><summary>Histórico e chamados há mais tempo em cada etapa</summary><p>O tempo vai da primeira observação consecutiva da etapa até a última sincronização. Não é a data exata da mudança no OpenProject. Um chamado pode aparecer em mais de uma etapa.</p>';
        foreach ($data['stages'] as $stage) {
            if (!(int)$stage['count']) continue;
            echo '<p><strong>'.self::h($stage['label']).'</strong> · '.(int)$stage['known'].' com evidência de tempo; '.(int)$stage['unknown'].' com alguma WP sem histórico suficiente.</p><ul>';
            foreach ($stage['oldest'] as $ticket) echo '<li><a target="_blank" rel="noopener noreferrer" href="/front/ticket.form.php?id='.(int)$ticket['id'].'">#'.(int)$ticket['id'].' · '.self::h($ticket['name']).'</a> — '.(int)$ticket['days'].' dias observados</li>';
            echo '</ul>';
        }
        echo '</details></section><details class="demandas-panel my-3 da-owners"><summary>Distribuição por responsável OpenProject</summary><p class="da-muted mt-3">Quantidade de chamados; não mede produtividade. Um chamado pode ter mais de um responsável.</p><div class="d-flex flex-wrap gap-2">';
        foreach (array_slice($data['owners'],0,20) as $owner) echo '<a class="btn btn-outline-secondary" href="'.self::h(self::url($filters,['op_owner'=>$owner['value']])).'">'.self::h($owner['label']).' · '.(int)$owner['count'].'</a>';
        echo '</div></details>';
    }
    public static function workPackages(array $wps): string
    {
        if (!$wps) return '<span class="da-tag da-amber">Sem WP vinculada</span>';
        $statuses = [];
        $stale = false;
        foreach ($wps as $wp) {
            $status = trim((string)$wp['openproject_status']) ?: 'Status não informado';
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
            $stale = $stale || !empty($wp['sync_stale']);
        }
        $html = '<details class="da-wps"><summary><strong>'.count($wps).' WP(s)</strong><span class="da-wp-summary">';
        foreach ($statuses as $status=>$n) $html .= '<span>'.self::h($status).($n>1?' × '.$n:'').'</span>';
        $html .= '</span>'.($stale?'<span class="da-tag da-amber">Dados desatualizados</span>':'').'<span class="da-expand">Ver detalhes</span></summary><ul class="da-wp-list">';
        foreach ($wps as $wp) {
            $html .= '<li><div>'.DeliveryReview::wpLink((int)$wp['openproject_work_package_id']).' <strong>'.self::h($wp['openproject_status'] ?: 'Status não informado').'</strong></div><dl><dt>Responsável</dt><dd>'.self::h($wp['responsible'] ?: 'Não informado').'</dd><dt>Atribuído</dt><dd>'.self::h($wp['assignee'] ?: 'Não informado').'</dd><dt>Qualidade dos dados</dt><dd>'.self::h(IndicatorPolicy::qualityLabel($wp['quality'] ?? 'current')).'</dd><dt>Tempo na etapa</dt><dd>'.($wp['stage_days']===null?'Histórico insuficiente':(int)$wp['stage_days'].' dias observados').'</dd><dt>Observada desde</dt><dd>'.self::h($wp['observed_since'] ?: 'Não informado').'</dd><dt>Última sincronização</dt><dd>'.self::h($wp['last_synced_at'] ?: 'Não informada').'</dd></dl>'.(!empty($wp['sync_stale'])?'<p class="da-warning">Sincronize antes de decidir: dados desatualizados.</p>':'').'</li>';
        }
        return $html.'</ul></details>';
    }
}
