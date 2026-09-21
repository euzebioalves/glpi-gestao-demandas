<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

final class WorkPackageTemplate
{
    private const BLOCKED_VARIABLES = ['descricao', 'description', 'conteudo', 'content'];

    public static function templates(): array
    {
        try {
            $decoded = json_decode((string) Config::get('work_package_templates_json', '{}'), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $templates = [];
        foreach ($decoded as $typeName => $template) {
            if (is_string($typeName) && is_string($template) && trim($typeName) !== '') {
                $templates[self::normalize($typeName)] = $template;
            }
        }
        return $templates;
    }

    public static function forType(string $typeName): string
    {
        return (string) (self::templates()[self::normalize($typeName)] ?? '');
    }

    public static function render(
        \Ticket $ticket,
        string $typeName,
        string $additionalReason = '',
        array $overrides = []
    ): string
    {
        $template = self::forType($typeName);
        if (trim($template) === '') {
            throw new \RuntimeException('Cadastre um template Markdown para o tipo de Work Package “' . $typeName . '”.');
        }

        $variables = array_replace(self::ticketVariables($ticket, $additionalReason), $overrides);
        $rendered = preg_replace_callback(
            '/\{\{?\s*([a-zA-Z0-9_.-]+)\s*\}\}?/',
            static function (array $matches) use ($variables): string {
                $name = self::normalize((string) $matches[1]);
                if (in_array($name, self::BLOCKED_VARIABLES, true)) {
                    return '';
                }
                return array_key_exists($name, $variables) ? (string) $variables[$name] : (string) $matches[0];
            },
            $template
        );

        return trim((string) $rendered);
    }

    public static function defaultUserStory(): string
    {
        return <<<'MARKDOWN'
### CARTÃO

| Campo | Detalhes |
| :--- | :--- |
| **Tipo de Solicitação** | {tipo_solicitacao} |
| **Servicedesk** | {glpi_id} |
| **Data** | {data_abertura} |
| **Cliente** | {cliente} |
| **Sistema** | {sistema} |
| **Solicitante** | {requerente} |
| **Contato** | {contato} |
| **E-mail** | {email} |

### DESCRIÇÃO

_Documentação a ser elaborada pelo analista de requisitos ou de suporte._

### CRITÉRIOS DE ACEITAÇÃO

_A definir durante a documentação da Work Package._
MARKDOWN;
    }

    public static function availableVariables(): array
    {
        return [
            'glpi_id', 'glpi_url', 'titulo', 'data_abertura', 'cliente', 'sistema',
            'tipo_solicitacao', 'requerente', 'contato', 'email', 'motivo_nova_wp',
        ];
    }

    private static function ticketVariables(\Ticket $ticket, string $additionalReason): array
    {
        global $DB;

        $requesterName = '';
        $requesterEmail = '';
        $requesterContact = '';
        $iterator = $DB->request([
            'SELECT' => ['users_id', 'alternative_email'],
            'FROM' => 'glpi_tickets_users',
            'WHERE' => ['tickets_id' => $ticket->getID(), 'type' => 1],
            'LIMIT' => 1,
        ]);
        foreach ($iterator as $actor) {
            $user = new \User();
            if ($user->getFromDB((int) $actor['users_id'])) {
                $requesterName = $user->getFriendlyName();
                $emailIterator = $DB->request([
                    'SELECT' => ['email'],
                    'FROM' => 'glpi_useremails',
                    'WHERE' => ['users_id' => (int) $actor['users_id']],
                    'ORDER' => ['is_default DESC', 'id ASC'],
                    'LIMIT' => 1,
                ]);
                foreach ($emailIterator as $emailRow) {
                    $requesterEmail = trim((string) ($emailRow['email'] ?? ''));
                    break;
                }
                $requesterContact = trim((string) ($user->fields['phone'] ?? $user->fields['mobile'] ?? ''));
            }
            if ($requesterEmail === '') {
                $requesterEmail = trim((string) ($actor['alternative_email'] ?? ''));
            }
            break;
        }

        $entityName = '';
        $entity = new \Entity();
        if ($entity->getFromDB((int) ($ticket->fields['entities_id'] ?? 0))) {
            $entityName = (string) ($entity->fields['completename'] ?? $entity->fields['name'] ?? '');
        }

        $system = '';
        $category = new \ITILCategory();
        if ($category->getFromDB((int) ($ticket->fields['itilcategories_id'] ?? 0))) {
            $system = (string) ($category->fields['completename'] ?? $category->fields['name'] ?? '');
        }

        $classification = ClassificationPolicy::resolve($ticket);
        $opening = trim((string) ($ticket->fields['date'] ?? ''));
        if ($opening !== '') {
            try {
                $opening = (new \DateTimeImmutable($opening))->format('d/m/Y H:i:s');
            } catch (\Throwable) {
                // Mantém o valor original se a base contiver uma data inválida.
            }
        }

        return [
            'glpi_id' => (string) $ticket->getID(),
            'glpi_url' => rtrim((string) Config::get('glpi_external_url', 'http://localhost:8180'), '/') . '/front/ticket.form.php?id=' . $ticket->getID(),
            'titulo' => trim((string) ($ticket->fields['name'] ?? '')),
            'data_abertura' => $opening,
            'cliente' => $entityName,
            'sistema' => $system,
            'tipo_solicitacao' => (string) ($classification['label'] ?? $classification['value'] ?? ''),
            'requerente' => $requesterName,
            'contato' => $requesterContact,
            'email' => $requesterEmail,
            'motivo_nova_wp' => $additionalReason,
        ];
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return $ascii !== false ? $ascii : $value;
    }
}
