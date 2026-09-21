<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use JsonException;
use RuntimeException;

final class WebhookHandler
{
    public function handle(string $rawBody, ?string $signature): array
    {
        $this->assertSignature($rawBody, $signature);

        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('O corpo do webhook não contém JSON válido.', 0, $exception);
        }

        if (!is_array($payload)) {
            throw new RuntimeException('O corpo do webhook não contém um objeto JSON.');
        }

        $action = (string) ($payload['action'] ?? '');
        if (!in_array($action, ['work_package:created', 'work_package:updated'], true)) {
            return ['processed' => false, 'action' => $action, 'reason' => 'Evento ignorado.'];
        }

        $workPackage = $payload['work_package'] ?? null;
        $workPackageId = is_array($workPackage) ? (int) ($workPackage['id'] ?? 0) : 0;
        if ($workPackageId <= 0) {
            throw new RuntimeException('O webhook não informou uma Work Package válida.');
        }

        $result = (new SynchronizationService())->synchronizeByWorkPackage($workPackageId, 'webhook');

        return [
            'processed' => true,
            'action' => $action,
            'work_package_id' => $workPackageId,
            'status' => $result['status'],
            'public_phase' => $result['public_phase'],
        ];
    }

    private function assertSignature(string $rawBody, ?string $signature): void
    {
        $received = trim((string) $signature);
        if ($received === '') {
            throw new WebhookAuthenticationException('Assinatura X-Op-Signature ausente.');
        }

        $secret = Config::webhookSecret();
        $sha1 = hash_hmac('sha1', $rawBody, $secret);
        $sha256 = hash_hmac('sha256', $rawBody, $secret);
        $candidates = [
            $sha1,
            'sha1=' . $sha1,
            base64_encode(hash_hmac('sha1', $rawBody, $secret, true)),
            $sha256,
            'sha256=' . $sha256,
            base64_encode(hash_hmac('sha256', $rawBody, $secret, true)),
        ];

        foreach ($candidates as $candidate) {
            if (hash_equals($candidate, $received)) {
                return;
            }
        }

        throw new WebhookAuthenticationException('Assinatura X-Op-Signature inválida.');
    }
}

final class WebhookAuthenticationException extends RuntimeException
{
}
