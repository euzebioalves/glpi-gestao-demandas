<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

final class OpenProjectClient
{
    private Client $client;
    private array $creationSchemas = [];
    /** @var array<string, array> */
    private array $creationOptions = [];
    private ?array $customFieldsCache = null;
    private bool $allowsWorkPackageCreation;

    private function __construct(string $token, bool $allowsWorkPackageCreation)
    {
        if (trim($token) === '' || trim((string) Config::get('openproject_internal_url', '')) === '') {
            throw new RuntimeException('O token de acesso ao OpenProject ainda não foi configurado.');
        }

        $this->allowsWorkPackageCreation = $allowsWorkPackageCreation;

        $this->client = new Client([
            'base_uri' => rtrim((string) Config::get('openproject_internal_url'), '/') . '/',
            'timeout' => max(2, (int) Config::get('request_timeout', 15)),
            'connect_timeout' => 5,
            'auth' => ['apikey', $token],
            'headers' => [
                'Accept' => 'application/hal+json',
                'Content-Type' => 'application/json',
                'User-Agent' => 'GLPI-Demandas/' . PLUGIN_DEMANDAS_VERSION,
            ],
        ]);
    }

    public static function forCurrentUser(): self
    {
        return new self(Config::personalToken(), true);
    }

    public static function forUser(int $userId): self
    {
        return new self(Config::personalToken($userId), true);
    }

    public static function forAutomation(): self
    {
        return new self(Config::automationToken(), false);
    }

    public function testConnection(): array
    {
        return $this->request('GET', '');
    }

    public function getProjects(): array
    {
        $collection = $this->request('GET', 'projects?pageSize=500');
        $elements = $collection['_embedded']['elements'] ?? [];
        return is_array($elements) ? $elements : [];
    }

    public function getTypesForProject(int $projectId): array
    {
        $collection = $this->request('GET', 'projects/' . $projectId . '/types');
        $elements = $collection['_embedded']['elements'] ?? [];
        return is_array($elements) ? $elements : [];
    }

    public function getTypes(): array
    {
        $collection = $this->request('GET', 'types?pageSize=500');
        $elements = $collection['_embedded']['elements'] ?? [];
        return is_array($elements) ? $elements : [];
    }

    public function getUsers(): array
    {
        $collection = $this->request('GET', 'users?pageSize=500&filters=[{"status":{"operator":"=","values":["active"]}}]');
        $elements = $collection['_embedded']['elements'] ?? [];
        return is_array($elements) ? $elements : [];
    }

    /**
     * Returns the OpenProject identity represented by this client's token.
     * The API accepts the special `me` identifier for the authenticated user.
     */
    public function getCurrentUser(): array
    {
        return $this->request('GET', 'users/me');
    }

    /**
     * Lists all open User Stories, Epics and Bugs whose responsible is the
     * owner of the personal token. Pagination is explicit because a personal
     * backlog can be larger than OpenProject's default page size.
     */
    public function getOpenDemandWorkPackagesForCurrentUser(): array
    {
        $currentUser = $this->getCurrentUser();
        $currentUserId = (int) ($currentUser['id'] ?? 0);
        if ($currentUserId <= 0) {
            throw new RuntimeException('O OpenProject não informou o usuário associado ao token pessoal.');
        }

        $typeIds = [];
        foreach ($this->getTypes() as $type) {
            $name = self::normalizeDemandType((string) ($type['name'] ?? ''));
            if (in_array($name, ['userstory', 'epico', 'epic', 'bug'], true) && (int) ($type['id'] ?? 0) > 0) {
                $typeIds[] = (string) (int) $type['id'];
            }
        }
        if ($typeIds === []) {
            return [];
        }

        $filters = json_encode([
            ['type' => ['operator' => '=', 'values' => array_values(array_unique($typeIds))]],
            ['responsible' => ['operator' => '=', 'values' => [(string) $currentUserId]]],
            ['status' => ['operator' => 'o', 'values' => []]],
        ], JSON_THROW_ON_ERROR);

        $workPackages = [];
        $offset = 1;
        // The official instance can return large embedded WP payloads. A
        // smaller page keeps each HTTP response below the configured timeout
        // while still collecting the complete result set.
        $pageSize = 25;
        $total = null;
        do {
            $query = http_build_query([
                'filters' => $filters,
                'pageSize' => $pageSize,
                'offset' => $offset,
                'sortBy' => json_encode([['updatedAt', 'desc']], JSON_THROW_ON_ERROR),
            ], '', '&', PHP_QUERY_RFC3986);
            $collection = $this->request('GET', 'work_packages?' . $query);
            $elements = $collection['_embedded']['elements'] ?? [];
            if (!is_array($elements)) {
                break;
            }
            foreach ($elements as $element) {
                if (is_array($element)) {
                    $workPackages[] = $element;
                }
            }
            $received = count($elements);
            // OpenProject's `offset` identifies the page (starting at 1),
            // rather than the index of the first item. Advancing it by the
            // number of items would jump from page 1 directly to page 26.
            $offset++;
            $reportedTotal = $collection['total'] ?? $collection['_meta']['total'] ?? null;
            if (is_numeric($reportedTotal)) {
                $total = (int) $reportedTotal;
            }
        } while ($received === $pageSize && ($total === null || count($workPackages) < $total));

        return $workPackages;
    }

    public function getCreationOptions(int $projectId, int $typeId): array
    {
        $cacheKey = $projectId . ':' . $typeId;
        if (isset($this->creationOptions[$cacheKey])) {
            return $this->creationOptions[$cacheKey];
        }
        $base = [
            'subject' => 'Validação dos campos da integração',
            '_links' => [
                'project' => ['href' => '/api/v3/projects/' . $projectId],
                'type' => ['href' => '/api/v3/types/' . $typeId],
            ],
        ];
        $form = $this->request('POST', 'work_packages/form', $base);
        $schema = (array) ($form['_embedded']['schema'] ?? []);
        $this->creationSchemas[$cacheKey] = $schema;
        $formPayload = (array) ($form['_embedded']['payload'] ?? []);
        $fields = [];
        foreach ([
            'assignee' => ['assignee', 'Atribuído para'],
            'responsible' => ['responsible', 'Encarregado'],
            'priority' => ['priority', 'Prioridade'],
        ] as $key => [$schemaKey, $label]) {
            if ($key === 'responsible' && !isset($schema[$schemaKey]) && isset($schema['accountable'])) {
                $schemaKey = 'accountable';
            }
            if (!isset($schema[$schemaKey]) || !is_array($schema[$schemaKey])) continue;
            $fields[$key] = $this->creationField($schemaKey, $label, $schema[$schemaKey], $formPayload);
        }

        foreach ($schema as $schemaKey => $fieldSchema) {
            if (!preg_match('/^customField\d+$/', (string) $schemaKey) || !is_array($fieldSchema)) continue;
            if (mb_strtolower(trim((string) ($fieldSchema['name'] ?? ''))) !== 'cliente') continue;
            $fields['customer'] = $this->creationField((string) $schemaKey, 'Cliente', $fieldSchema, $formPayload);
            break;
        }
        return $this->creationOptions[$cacheKey] = $fields;
    }

    public function createWorkPackage(
        \Ticket $ticket,
        int $projectId,
        int $typeId,
        array $selection = [],
        string $additionalReason = ''
    ): array
    {
        if (!$this->allowsWorkPackageCreation) {
            throw new RuntimeException('O token automático não pode ser utilizado para criar Work Packages.');
        }
        $project = $this->request('GET', 'projects/' . $projectId);
        $types = $this->getTypesForProject($projectId);
        $allowedType = null;
        foreach ($types as $type) {
            if ((int) ($type['id'] ?? 0) === $typeId) {
                $allowedType = $type;
                break;
            }
        }
        if ($allowedType === null) {
            throw new RuntimeException('O tipo selecionado não está disponível no projeto informado.');
        }
        ClassificationPolicy::assertTypeAllowed($ticket, $allowedType);

        $creationOptions = $this->getCreationOptions($projectId, $typeId);
        $templateOverrides = [];
        $selectedCustomer = trim((string) ($selection['customer'] ?? ''));
        if ($selectedCustomer !== '' && isset($creationOptions['customer'])) {
            foreach ((array) ($creationOptions['customer']['options'] ?? []) as $customerOption) {
                if ((string) ($customerOption['href'] ?? '') === $selectedCustomer) {
                    $templateOverrides['cliente'] = (string) ($customerOption['label'] ?? '');
                    break;
                }
            }
        }

        $payload = [
            'subject' => sprintf('[GLPI #%d] %s', $ticket->getID(), $ticket->fields['name']),
            'description' => [
                'format' => 'markdown',
                'raw' => WorkPackageTemplate::render(
                    $ticket,
                    (string) ($allowedType['name'] ?? ('Tipo #' . $typeId)),
                    $additionalReason,
                    $templateOverrides
                ),
            ],
            '_links' => [
                'project' => ['href' => '/api/v3/projects/' . $projectId],
                'type' => ['href' => '/api/v3/types/' . $typeId],
            ],
        ];

        foreach ($creationOptions as $key => $field) {
            $href = trim((string) ($selection[$key] ?? ''));
            if (!empty($field['required']) && !empty($field['writable']) && $href === '') {
                throw new RuntimeException('Informe o campo obrigatório “' . $field['label'] . '”.');
            }
            if ($href === '') continue;
            $allowedHrefs = array_column((array) ($field['options'] ?? []), 'href');
            if (!in_array($href, $allowedHrefs, true)) {
                throw new RuntimeException('O valor selecionado para “' . $field['label'] . '” não é permitido pelo OpenProject.');
            }
            $payload['_links'][(string) $field['schema_key']] = ['href' => $href];
        }

        foreach ($this->discoverOriginFields($projectId, $typeId) as $name => $field) {
            $id = (int) ($field['id'] ?? 0);
            if ($id <= 0) continue;
            if ($name === 'ticket') {
                $payload['customField' . $id] = ($field['format'] ?? '') === 'integer'
                    ? $ticket->getID()
                    : (string) $ticket->getID();
            } elseif ($name === 'url') {
                $payload['customField' . $id] = $this->ticketUrl($ticket->getID());
            }
        }

        $created = $this->request('POST', 'work_packages', $payload);
        $created['_demandas'] = [
            'project_id' => $projectId,
            'project_name' => (string) ($project['name'] ?? ('Projeto #' . $projectId)),
            'project_identifier' => (string) ($project['identifier'] ?? ''),
            'type_id' => $typeId,
            'type_name' => (string) ($allowedType['name'] ?? ('Tipo #' . $typeId)),
            'details' => $this->summarizeWorkPackage($created) + (
                $additionalReason !== '' ? ['creation_reason' => $additionalReason] : []
            ),
        ];
        return $created;
    }

    public function getWorkPackage(int $workPackageId): array
    {
        return $this->request('GET', 'work_packages/' . $workPackageId);
    }

    public function getStatuses(): array
    {
        $collection = $this->request('GET', 'statuses');
        $elements = $collection['_embedded']['elements'] ?? [];
        return is_array($elements) ? $elements : [];
    }

    public function getTimeEntryActivities(array $workPackageIds = []): array
    {
        $activities = [];
        $lastError = null;
        foreach (array_values(array_unique(array_filter(array_map('intval', $workPackageIds)))) as $workPackageId) {
            try {
                // O OpenProject não disponibiliza uma coleção global de
                // atividades. O formulário é a fonte oficial dos valores
                // permitidos para a WP e para seu projeto.
                $form = $this->request('POST', 'time_entries/form', [
                    'spentOn' => date('Y-m-d'),
                    'hours' => 'PT1H',
                    'comment' => ['format' => 'plain', 'raw' => ''],
                    '_links' => [
                        'entity' => ['href' => '/api/v3/work_packages/' . $workPackageId],
                    ],
                ]);
                $schema = (array) ($form['_embedded']['schema'] ?? []);
                $payload = (array) ($form['_embedded']['payload'] ?? []);
                if (!isset($schema['activity']) || !is_array($schema['activity'])) continue;
                $field = $this->creationField('activity', 'Atividade', $schema['activity'], $payload);
                foreach ((array) ($field['options'] ?? []) as $option) {
                    $href = (string) ($option['href'] ?? '');
                    if ($href === '') continue;
                    $activities[$href] = [
                        'name' => (string) ($option['label'] ?? $href),
                        '_links' => ['self' => ['href' => $href, 'title' => (string) ($option['label'] ?? $href)]],
                    ];
                }
            } catch (RuntimeException $exception) {
                $message = $exception->getMessage();
                if (str_contains($message, '403 Forbidden') || str_contains($message, 'MissingPermission')) {
                    $message = 'O usuário da integração não possui permissão no OpenProject para registrar tempo neste projeto. '
                        . 'Execute novamente a automação de homologação ou conceda ao papel do usuário as permissões '
                        . '“Visualizar tempo gasto” e “Registrar as próprias horas”.';
                }
                $lastError = new RuntimeException($message, (int) $exception->getCode(), $exception);
            }
        }
        if ($activities === [] && $lastError !== null) throw $lastError;
        return array_values($activities);
    }

    public function createTimeEntry(
        int $workPackageId,
        string $spentOn,
        int $minutes,
        string $activityHref,
        string $comment,
        string $userHref = ''
    ): array {
        return $this->request('POST', 'time_entries', $this->timeEntryPayload($workPackageId,$spentOn,$minutes,$activityHref,$comment,$userHref));
    }

    public function updateTimeEntry(int $timeEntryId,int $workPackageId,string $spentOn,int $minutes,string $activityHref,string $comment,string $userHref=''):array
    {
        if($timeEntryId<=0)throw new RuntimeException('Entrada de tempo do OpenProject inválida.');
        return $this->request('PATCH','time_entries/'.$timeEntryId,$this->timeEntryPayload($workPackageId,$spentOn,$minutes,$activityHref,$comment,$userHref));
    }

    public function deleteTimeEntry(int $timeEntryId):void
    {
        if($timeEntryId<=0)throw new RuntimeException('Entrada de tempo do OpenProject inválida.');
        $this->request('DELETE','time_entries/'.$timeEntryId);
    }

    private function timeEntryPayload(int $workPackageId,string $spentOn,int $minutes,string $activityHref,string $comment,string $userHref=''):array
    {
        if($workPackageId<=0||$minutes<=0||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$spentOn))throw new RuntimeException('Dados inválidos para a entrada de tempo.');
        if(!str_starts_with($activityHref,'/api/v3/time_entries/activities/'))throw new RuntimeException('Selecione uma atividade de tempo válida.');
        $hours=intdiv($minutes,60);$remaining=$minutes%60;$duration='PT'.($hours>0?$hours.'H':'').($remaining>0?$remaining.'M':'');
        $comment=trim($comment);$comment=$comment===''?'':'- '.ltrim($comment,"-–— \t\n\r\0\x0B");
        $payload=['spentOn'=>$spentOn,'hours'=>$duration,'comment'=>['format'=>'plain','raw'=>mb_substr($comment,0,2000)],'_links'=>['entity'=>['href'=>'/api/v3/work_packages/'.$workPackageId],'activity'=>['href'=>$activityHref]]];
        if($userHref!==''){if(!preg_match('~^/api/v3/users/\d+$~',$userHref))throw new RuntimeException('O usuário vinculado ao OpenProject é inválido.');$payload['_links']['user']=['href'=>$userHref];}
        return $payload;
    }

    public function summarizeWorkPackage(array $workPackage): array
    {
        $details = [
            'id' => (int) ($workPackage['id'] ?? 0),
            'subject' => (string) ($workPackage['subject'] ?? ''),
            'project' => (string) ($workPackage['_links']['project']['title'] ?? ''),
            'type' => (string) ($workPackage['_links']['type']['title'] ?? ''),
            'status' => (string) ($workPackage['_links']['status']['title'] ?? ''),
            'priority' => (string) ($workPackage['_links']['priority']['title'] ?? ''),
            'assignee' => (string) ($workPackage['_links']['assignee']['title'] ?? ''),
            'responsible' => (string) ($workPackage['_links']['responsible']['title'] ?? $workPackage['_links']['accountable']['title'] ?? ''),
            'customer' => '',
            'created_at' => (string) ($workPackage['createdAt'] ?? ''),
            'updated_at' => (string) ($workPackage['updatedAt'] ?? ''),
        ];
        foreach ($this->customFields() as $customField) {
            if (mb_strtolower(trim((string) ($customField['name'] ?? ''))) !== 'cliente') continue;
            $key = 'customField' . (int) ($customField['id'] ?? 0);
            $details['customer'] = $this->customFieldLabel($workPackage, $key);
            break;
        }

        // Tokens de integração sem permissão administrativa podem não acessar
        // /custom_fields. Nesse caso, o schema do formulário da própria WP
        // ainda informa qual customField representa “Cliente”.
        if ($details['customer'] === '') {
            $projectId = $this->linkedResourceId($workPackage, 'project');
            $typeId = $this->linkedResourceId($workPackage, 'type');
            if ($projectId > 0 && $typeId > 0) {
                try {
                    $customer = $this->getCreationOptions($projectId, $typeId)['customer'] ?? null;
                    $key = is_array($customer) ? (string) ($customer['schema_key'] ?? '') : '';
                    if ($key !== '') {
                        $details['customer'] = $this->customFieldLabel($workPackage, $key);
                    }
                } catch (RuntimeException) {
                    // Os demais dados da WP continuam úteis mesmo que o schema
                    // não esteja disponível durante uma sincronização.
                }
            }
        }
        return $details;
    }

    /**
     * OpenProject serializes a list custom field as an array of HAL links,
     * while a single-value field is an individual HAL link. Both forms need
     * to be represented in the monitoring list.
     */
    private function customFieldLabel(array $workPackage, string $key): string
    {
        $value = $workPackage['_links'][$key] ?? $workPackage[$key] ?? null;
        $labels = $this->resourceTitles($value);
        return implode(', ', $labels);
    }

    private function resourceTitles(mixed $value): array
    {
        if (is_scalar($value)) {
            $label = trim((string) $value);
            return $label === '' ? [] : [$label];
        }
        if (!is_array($value)) {
            return [];
        }
        if (isset($value['title']) && is_scalar($value['title'])) {
            $label = trim((string) $value['title']);
            return $label === '' ? [] : [$label];
        }
        $labels = [];
        foreach ($value as $item) {
            foreach ($this->resourceTitles($item) as $label) {
                $labels[$label] = $label;
            }
        }
        return array_values($labels);
    }

    private function linkedResourceId(array $resource, string $link): int
    {
        $href = (string) ($resource['_links'][$link]['href'] ?? '');
        return preg_match('~/([0-9]+)(?:\?.*)?$~', $href, $matches) === 1 ? (int) $matches[1] : 0;
    }

    private function discoverOriginFields(int $projectId, int $typeId): array
    {
        $found = [];
        try {
            $elements = $this->customFields();
            foreach (is_array($elements) ? $elements : [] as $field) {
                $name = mb_strtolower(trim((string) ($field['name'] ?? '')));
                $id = (int) ($field['id'] ?? 0);
                if ($id <= 0) continue;
                $definition = [
                    'id' => $id,
                    'format' => mb_strtolower((string) ($field['fieldFormat'] ?? $field['field_format'] ?? '')),
                ];
                if ($name === 'servicedesk' || ($name === 'ticket glpi' && !isset($found['ticket']))) {
                    $found['ticket'] = $definition;
                }
                if ($name === 'link servicedesk' || (in_array($name, ['url do ticket glpi', 'url do ticket'], true) && !isset($found['url']))) {
                    $found['url'] = $definition;
                }
            }
        } catch (RuntimeException) {
            // Os campos são complementares: a origem também é gravada na descrição.
        }

        // O schema do formulário é acessível ao usuário da integração mesmo
        // quando o catálogo administrativo de campos customizados não é.
        $schema = $this->creationSchemas[$projectId . ':' . $typeId] ?? [];
        foreach ($schema as $schemaKey => $fieldSchema) {
            if (!preg_match('/^customField(\d+)$/', (string) $schemaKey, $matches) || !is_array($fieldSchema)) continue;
            $name = mb_strtolower(trim((string) ($fieldSchema['name'] ?? '')));
            $definition = [
                'id' => (int) $matches[1],
                'format' => str_contains(mb_strtolower((string) ($fieldSchema['type'] ?? '')), 'integer') ? 'integer' : '',
            ];
            // Os nomes corporativos sempre substituem os aliases legados,
            // independentemente da ordem recebida no schema do OpenProject.
            if ($name === 'servicedesk') {
                $found['ticket'] = $definition;
            } elseif (!isset($found['ticket']) && $name === 'ticket glpi') {
                $found['ticket'] = $definition;
            }
            if ($name === 'link servicedesk') {
                $found['url'] = $definition;
            } elseif (!isset($found['url']) && in_array($name, ['url do ticket glpi', 'url do ticket'], true)) {
                $found['url'] = $definition;
            }
        }
        return $found;
    }

    private function customFields(): array
    {
        if ($this->customFieldsCache !== null) {
            return $this->customFieldsCache;
        }
        try {
            $collection = $this->request('GET', 'custom_fields?pageSize=500');
            $elements = $collection['_embedded']['elements'] ?? [];
            return $this->customFieldsCache = is_array($elements) ? $elements : [];
        } catch (RuntimeException) {
            // Some OpenProject instances do not expose this global endpoint
            // to ordinary users. The work package form schema is used below.
            return $this->customFieldsCache = [];
        }
    }

    private function creationField(string $schemaKey, string $fallbackLabel, array $schema, array $formPayload): array
    {
        $options = [];
        $embedded = $schema['_embedded']['allowedValues'] ?? null;
        if (is_array($embedded)) {
            $elements = $embedded['elements'] ?? $embedded;
            foreach (is_array($elements) ? $elements : [] as $option) {
                $normalized = $this->normalizeAllowedValue((array) $option);
                if ($normalized !== null) $options[] = $normalized;
            }
        }
        $href = (string) ($schema['_links']['allowedValues']['href'] ?? '');
        if ($options === [] && $href !== '') {
            try {
                $collection = $this->request('GET', $this->normalizeApiHref($href));
                $elements = $collection['_embedded']['elements'] ?? [];
                foreach (is_array($elements) ? $elements : [] as $option) {
                    $normalized = $this->normalizeAllowedValue((array) $option);
                    if ($normalized !== null) $options[] = $normalized;
                }
            } catch (RuntimeException) {
                // O campo continuará visível, indicando que a API não devolveu opções permitidas.
            }
        }
        return [
            'schema_key' => $schemaKey,
            'label' => trim((string) ($schema['name'] ?? '')) ?: $fallbackLabel,
            'required' => (bool) ($schema['required'] ?? false),
            'writable' => (bool) ($schema['writable'] ?? true),
            'selected_href' => (string) ($formPayload['_links'][$schemaKey]['href'] ?? ''),
            'options' => $options,
        ];
    }

    private function normalizeAllowedValue(array $option): ?array
    {
        $href = trim((string) ($option['_links']['self']['href'] ?? ''));
        if ($href === '') return null;
        $label = trim((string) ($option['name'] ?? $option['_links']['self']['title'] ?? $option['subject'] ?? ''));
        return ['href' => $href, 'label' => $label !== '' ? $label : $href];
    }

    private function normalizeApiHref(string $href): string
    {
        $href = preg_replace('/\{.*$/', '', trim($href)) ?? trim($href);
        $path = parse_url($href, PHP_URL_PATH);
        $query = parse_url($href, PHP_URL_QUERY);
        if (is_string($path) && $path !== '') {
            $href = $path . (is_string($query) && $query !== '' ? '?' . $query : '');
        }
        return str_starts_with($href, '/api/v3/') ? substr($href, strlen('/api/v3/')) : ltrim($href, '/');
    }

    private static function normalizeDemandType(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = strtr($value, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
        return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
    }

    private function request(string $method, string $uri, ?array $payload = null): array
    {
        try {
            $options = [];
            if ($payload !== null) {
                $options['json'] = $payload;
            }
            $response = $this->client->request($method, $uri, $options);
            $body=(string)$response->getBody();if(trim($body)==='')return [];
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (GuzzleException | \JsonException $exception) {
            throw new RuntimeException('Falha na comunicação com o OpenProject: ' . $exception->getMessage(), 0, $exception);
        }
    }

    private function ticketUrl(int $ticketId): string
    {
        return rtrim((string) Config::get('glpi_external_url', 'http://localhost:8180'), '/')
            . '/front/ticket.form.php?id=' . $ticketId;
    }
}
