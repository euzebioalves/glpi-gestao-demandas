<?php
declare(strict_types=1);
namespace GlpiPlugin\Demandas;

final class ActionDashboardPanel
{
    private static function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES); }
    private static function url(array $filters, array $changes): string
    {
        return '?' . http_build_query(array_merge($filters, ['metric'=>'','delivery'=>'','attention'=>'','stage'=>''], $changes)) . '#demandas-results';
    }
    public static function show(array $data, array $filters): void
    {
        echo '<section class="demandas-panel my-3" aria-label="Precisam de ação"><h2 class="demandas-panel-title">Precisam de ação</h2><p class="text-muted">'
            .count($data['attention']).' chamado(s) com atenção no escopo. Motivos podem se sobrepor; cada chamado é contado uma única vez por motivo. Nenhuma ação altera chamados ou WPs.</p><div class="row g-2">';
        foreach (ActionDashboard::REASONS as $key=>$label) {
            if (in_array($key,ActionDashboard::REVIEW_REASONS,true) && !DashboardView::canReview()) continue;
            echo '<div class="col-sm-6 col-xl-4"><a class="d-block border rounded p-3 h-100 text-decoration-none" href="'.self::h(self::url($filters,['attention'=>$key])).'"><strong class="fs-2 me-2">'.(int)$data['counts'][$key].'</strong>'.self::h($label).'</a></div>';
        }
        echo '</div><p class="small text-muted mt-3">Prazo de atenção: '.$filters['attention_days'].' dias observados na mesma etapa. Sincronização antiga ou ausente exige conferência antes de decidir. Responsável não informado não comprova ausência no OpenProject.</p>';
        $visible = array_slice($data['visible_attention'],0,10);
        echo '<h3 class="h4">Fila de conferência</h3><p class="small text-muted">Até dez chamados dos filtros atuais, priorizando qualidade dos dados, divergências e impedimentos; depois, maior tempo observado e idade.</p>';
        if (!$visible) echo '<p>Nenhum chamado exige atenção nos filtros atuais.</p>';
        else {
            echo '<div class="table-responsive"><table class="table table-vcenter"><thead><tr><th>Chamado / cliente</th><th>Motivos / próxima ação</th><th>Responsáveis GLPI</th><th>WPs / responsáveis OpenProject</th></tr></thead><tbody>';
            foreach ($visible as $row) {
                echo '<tr><td><a target="_blank" rel="noopener noreferrer" href="/front/ticket.form.php?id='.(int)$row['ticket_id'].'">#'.(int)$row['ticket_id'].'</a><div>'.self::h($row['ticket_name']).'</div><small>'.self::h(ManagementDashboardService::clientLabel($row['entity_name'])).' · '.(int)$row['age_days'].' dias desde a abertura</small></td><td><ul>';
                foreach ($row['attention'] as $reason) echo '<li>'.self::h($reason).'</li>';
                echo '</ul></td><td>'.self::h(implode(', ',$row['glpi_owners']) ?: 'Nenhum técnico atribuído').'</td><td>'.self::workPackages($row['work_packages']).'</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '<a class="btn btn-outline-primary" href="'.self::h(self::url($filters,['attention'=>'any'])).'">Ver todos que precisam de ação</a></section>';

        echo '<section class="demandas-panel my-3" aria-label="Envelhecimento por etapa"><h2 class="demandas-panel-title">Envelhecimento por etapa</h2><p class="text-muted">Chamados abertos únicos em cada etapa. Um chamado pode aparecer em mais de uma etapa. O tempo corresponde à sequência de observações locais da mesma etapa, até a última sincronização; não à data exata de mudança no OpenProject. Mudanças não observadas entre consultas são desconhecidas.</p><div class="row g-3">';
        foreach ($data['stages'] as $key=>$stage) {
            echo '<article class="col-md-6 col-xl-4"><div class="border rounded p-3 h-100"><a class="h4 d-block" href="'.self::h(self::url($filters,['stage'=>$key,'ticket_scope'=>'open'])).'">'.self::h($stage['label']).' · '.(int)$stage['count'].'</a><div>Maior permanência observada: <strong>'.($stage['max_days']===null?'Indisponível':(int)$stage['max_days'].' dias').'</strong></div><small class="text-muted">'.(int)$stage['known'].' com evidência de tempo; '.(int)$stage['unknown'].' com alguma WP sem histórico suficiente.</small><ul class="mt-2 mb-0">';
            foreach ($stage['oldest'] as $ticket) echo '<li><a target="_blank" rel="noopener noreferrer" href="/front/ticket.form.php?id='.(int)$ticket['id'].'">#'.(int)$ticket['id'].'</a> — '.(int)$ticket['days'].' dias observados</li>';
            echo '</ul></div></article>';
        }
        echo '</div><p class="small text-muted mt-3 mb-0">Grupos por nomes de status; situações não reconhecidas permanecem em “Outras situações”. A relação completa mantém os nomes originais. Referências a PR, build ou status Desenvolvido não equivalem a Entregue.</p></section>';
        echo '<section class="demandas-panel my-3"><h2 class="demandas-panel-title">Distribuição por responsável OpenProject</h2><p class="small text-muted">Quantidade de chamados, não medida de produtividade ou capacidade. Cada responsável pode compartilhar um chamado com outros.</p><div class="d-flex flex-wrap gap-2">';
        foreach (array_slice($data['owners'],0,20) as $owner) echo '<a class="btn btn-outline-secondary" href="'.self::h(self::url($filters,['op_owner'=>$owner['value']])).'">'.self::h($owner['label']).' · '.(int)$owner['count'].'</a>';
        echo '</div></section>';
    }
    public static function workPackages(array $wps): string
    {
        if (!$wps) return 'Sem WP vinculada';
        $html = '';
        foreach ($wps as $wp) {
            $html .= '<div class="border-bottom pb-2 mb-2">'.DeliveryReview::linksHtml([$wp]);
            $html .= '<div class="small">Responsável: '.self::h($wp['responsible'] ?: 'Não informado')
                .' · Atribuído: '.self::h($wp['assignee'] ?: 'Não informado').'<br>'
                .($wp['stage_days']===null?'Tempo na etapa: histórico insuficiente':(int)$wp['stage_days'].' dias observados nesta etapa (desde '.self::h($wp['observed_since']).', até a última sincronização)').'</div></div>';
        }
        return $html;
    }
}
