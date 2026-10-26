<?php
declare(strict_types=1);
namespace GlpiPlugin\Demandas;

final class WorkPackageDocumentationForm
{
    public static function render(int $ticket, int $wp = 0): void
    {
        WorkPackageDocumentation::access($ticket, $wp);
        echo '<section class="demandas-documentation col-12" data-draft="" data-ticket="' . $ticket . '" data-wp="' . $wp . '" data-csrf="' . htmlspecialchars(\Session::getNewCSRFToken(), ENT_QUOTES) . '">';
        echo '<input type="hidden" name="doc_draft" value=""><h3 class="h4">Documentação da Work Package</h3>';
        if (!$wp) echo '<button type="button" class="btn btn-outline-primary doc-template mb-2" hidden>Carregar template do tipo selecionado</button>';
        echo '<div class="doc-workspace" hidden><p class="text-muted">Use o editor visual ou Markdown. Insira código com seleção de linguagem; cole ou envie imagens e ajuste suas dimensões. Anexos: até 20 arquivos de 15 MB por edição, sujeitos também ao limite do OpenProject. Os uploads vão ao OpenProject; serão associados à WP ao salvar. A sessão do editor dura duas horas.</p>';
        echo '<div class="d-flex gap-2 flex-wrap mb-2">';
        echo '<button type="button" class="btn btn-outline-secondary doc-mode">Editar Markdown</button></div>';
        echo '<textarea class="doc-rich form-control" aria-label="Documentação visual" rows="15"></textarea><textarea class="doc-markdown form-control font-monospace" aria-label="Documentação em Markdown" rows="18" hidden></textarea>';
        echo '<div class="my-3"><label class="form-label">Adicionar anexos<input class="form-control doc-files" type="file" multiple></label><ul class="doc-attachments"></ul></div></div><div class="doc-status alert alert-info" role="status" aria-live="polite">'.($wp ? 'Carregando documentação…' : 'Selecione projeto e tipo para carregar os campos e o template da nova WP.').'</div></section>';
    }
}
