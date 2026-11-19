<?php
declare(strict_types=1);

namespace GlpiPlugin\Demandas;

/** Presentation only. The balances and authorization remain in their existing services. */
final class TimeclockHelp
{
    private const CARDS = [
        'consolidated' => ['Saldo Consolidado',
            'Soma o saldo histórico informado em Minha jornada aos resultados desde o Início da acumulação até hoje. Exclui os dias com quantidade ímpar de marcações, sinalizados como incompletos. Não se limita ao mês exibido.',
            'Duas marcações já formam um par: esse dia pode participar do consolidado mesmo antes do fim do expediente. Este indicador não representa um fechamento definitivo da jornada.'],
        'general' => ['Saldo Geral',
            'Soma o mesmo saldo histórico e os resultados desde o Início da acumulação até hoje, incluindo dias com marcações incompletas e o dia atual. Não é o saldo do mês selecionado.',
            'Quando não houver dias incompletos nesse período, Saldo Geral e Saldo Consolidado terão o mesmo valor.'],
        'month' => ['Banco de Horas',
            'Soma os resultados diários apenas do mês selecionado, incluindo dias incompletos. Não adiciona o saldo histórico informado em Minha jornada.',
            'Durante o expediente pode aparecer negativo: somente pares de entrada e saída contam como tempo trabalhado, enquanto a jornada esperada já participa do cálculo. Exemplo fictício: +30 minutos ontem e -8 horas provisórias hoje resultam em -7h30 no mês.'],
        'days' => ['Dias Registrados',
            'Conta os dias do mês selecionado que têm pelo menos uma marcação de ponto, completos ou incompletos.',
            'Não é a quantidade de marcações nem de dias úteis. Quatro marcações no mesmo dia contam como um dia; uma ausência sem marcação não incrementa este indicador.'],
    ];

    public static function button(string $key): string
    {
        $title = htmlspecialchars(self::CARDS[$key][0], ENT_QUOTES, 'UTF-8');
        return "<button type='button' class='btn btn-sm btn-ghost-secondary ms-1' data-bs-toggle='modal' data-bs-target='#tm-help-{$key}' aria-label='Ajuda: {$title}'><i class='ti ti-help-circle' aria-hidden='true'></i></button>";
    }

    public static function render(): void
    {
        foreach (self::CARDS as $key => [$title, $meaning, $example]) {
            $title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
            $meaning = htmlspecialchars($meaning, ENT_QUOTES, 'UTF-8');
            $example = htmlspecialchars($example, ENT_QUOTES, 'UTF-8');
            echo "<div class='modal fade' id='tm-help-{$key}' tabindex='-1' aria-labelledby='tm-help-title-{$key}' aria-hidden='true'><div class='modal-dialog modal-dialog-centered modal-dialog-scrollable'><div class='modal-content'><div class='modal-header'><h2 class='modal-title' id='tm-help-title-{$key}'>{$title}</h2><button class='btn-close' type='button' data-bs-dismiss='modal' aria-label='Fechar ajuda'></button></div><div class='modal-body'><p>{$meaning}</p><p>{$example}</p><p>Dias sem marcação e sem ausência registrada são neutros. Feriados, compensações e dias não úteis configurados também são neutros. Ausências seguem as regras de justificativa e período cadastradas.</p><p>Consulte <strong>Minha jornada</strong> para conferir o saldo histórico e a data inicial. Em <strong>Auditoria do banco</strong>, confira a composição do acumulado.</p></div><div class='modal-footer'><button class='btn btn-secondary' type='button' data-bs-dismiss='modal'>Entendi</button></div></div></div></div>";
        }
    }
}
