<?php
declare(strict_types=1);
namespace GlpiPlugin\Demandas;

final class OperationalHealthPanel
{
    private static function h(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES); }

    public static function evidence(array $evidence): string
    {
        $labels = ['reason'=>'Diagnóstico', 'quality'=>'Qualidade', 'wp_status'=>'Status observado',
            'last_synced_at'=>'Última sincronização', 'observed_since'=>'Etapa observada desde',
            'age_days'=>'Dias observados na etapa', 'limit_days'=>'Limite configurado (dias)', 'recommendation'=>'Próxima ação'];
        $html = '<details><summary>Regra e evidências</summary><dl class="small mt-2 mb-0">';
        foreach ($labels as $key=>$label) if (array_key_exists($key, $evidence)) {
            $value = $key === 'quality' ? IndicatorPolicy::qualityLabel((string)$evidence[$key]) : $evidence[$key];
            $html .= '<dt>'.self::h($label).'</dt><dd>'.self::h($value === null || $value === '' ? 'Não informado' : $value).'</dd>';
        }
        return $html.'</dl></details>';
    }

    public static function treatment(array $row, array $history, bool $canManage): void
    {
        $id = (int)$row['id'];
        echo '<section class="card card-body mb-3" id="treatment"><h2 class="h3">Acompanhamento da pendência #'.$id.'</h2>';
        echo '<p>Responsável pelo tratamento: '.self::h($row['owner_users_id'] ? IntegrationActor::name((int)$row['owner_users_id']) : 'Não assumido')
            .' · Prazo: '.self::h($row['due_date'] ?: 'Não definido').'</p>';
        if (!empty($row['due_date']) && $row['due_date'] < date('Y-m-d') && $row['state'] !== 'resolved') echo '<p class="text-danger">Prazo de tratamento vencido.</p>';
        if ($canManage) {
            echo '<form method="post" action="/plugins/demandas/front/operational-health.form.php"><input type="hidden" name="_glpi_csrf_token" value="'.self::h(\Session::getNewCSRFToken()).'">'
                .'<input type="hidden" name="finding_id" value="'.$id.'"><div class="row g-3"><div class="col-md-4"><label class="form-label" for="finding-due">Prazo de tratamento</label><input class="form-control" id="finding-due" type="date" name="due_date" value="'.self::h($row['due_date']).'"></div>'
                .'<div class="col-md-8 align-self-end"><label class="form-check"><input class="form-check-input" type="checkbox" name="take_ownership" value="1"><span class="form-check-label">Assumir o acompanhamento em meu nome</span></label></div>'
                .'<div class="col-12"><label class="form-label" for="finding-note">Observação / motivo do adiamento</label><textarea class="form-control" id="finding-note" name="note" maxlength="2000" rows="3">'.self::h($row['treatment_note']).'</textarea><p class="form-hint">Adiar exige motivo de 10 a 2.000 caracteres. Não inclua credenciais ou dados sensíveis.</p></div></div>'
                .'<div class="d-flex flex-wrap gap-2 mt-3"><button class="btn btn-primary" name="action" value="followup">Salvar acompanhamento</button><button class="btn btn-outline-primary" name="action" value="acknowledge">Reconhecer</button><button class="btn btn-outline-secondary" name="action" value="ignore">Adiar por sete dias</button><button class="btn btn-outline-warning" name="action" value="reopen">Reabrir</button></div></form>';
        } else echo '<p>'.nl2br(self::h($row['treatment_note'])).'</p>';
        echo '<details class="mt-3"><summary>Histórico de tratativas (até 100 mais recentes)</summary><ol class="mt-2">';
        foreach ($history as $action) {
            $data = json_decode((string)$action['details_json'], true) ?: [];
            echo '<li class="mb-3"><strong>'.self::h($action['date_creation']).'</strong> · Usuário #'.(int)$action['users_id'].' · '.self::h($data['label'] ?? ($action['action'] === 'resolved' ? 'Resolvida pela verificação' : $action['action']));
            foreach (['note'=>'Observação', 'reason'=>'Evidência da resolução', 'run_id'=>'Execução'] as $key=>$label) if (isset($data[$key])) echo '<div>'.self::h($label).': '.self::h($data[$key]).'</div>';
            if (isset($data['after'])) echo '<div>Prazo: '.self::h($data['after']['due_date'] ?? 'Não alterado').' · Responsável: '.self::h(isset($data['after']['owner_users_id']) ? 'Usuário #'.$data['after']['owner_users_id'] : 'Não alterado').'</div>';
            echo '</li>';
        }
        echo '</ol></details></section>';
    }

    public static function runs(array $runs): void
    {
        echo '<details class="card card-body mb-3"><summary>Minhas últimas verificações e critérios</summary><p class="mt-2">Sem vínculo: chamado aberto cuja classificação permite Bug, Épico ou User Story. Etapa inicial: status configurado e tempo observado até a última sincronização. Sincronização: ausência, data inválida ou prazo atingido. Qualidade: status ou histórico insuficiente. Limites configurados permanecem independentes do prazo de atenção do dashboard.</p><p>Uma execução parcial não resolve pendências anteriores. Os dados são locais; esta tela não confirma disponibilidade nem autorização atual no OpenProject. Reconhecer ou adiar não corrige a causa.</p><ul>';
        foreach ($runs as $run) {
            $metrics = json_decode((string)($run['metrics_json'] ?? ''), true) ?: [];
            echo '<li>#'.(int)$run['id'].' · '.self::h($run['started_at']).' · '.self::h(['success'=>'Completa','partial'=>'Parcial — nenhuma resolução automática','error'=>'Falhou — alterações da rodada revertidas','running'=>'Em andamento ou interrompida; execute novamente'][$run['status']] ?? $run['status']);
            if ($metrics) echo ' · '.(int)($metrics['evaluated_tickets'] ?? 0).' chamados · '.(int)($metrics['incomplete_links'] ?? 0).' vínculos com informação insuficiente/desatualizada · '.(int)($metrics['duration_ms'] ?? 0).' ms';
            echo '</li>';
        }
        echo '</ul></details>';
    }
}
