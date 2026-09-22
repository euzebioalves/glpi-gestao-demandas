<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\AccessPolicy;
use GlpiPlugin\Demandas\Profile as DemandasProfile;
use GlpiPlugin\Demandas\TimeManagementService;

Session::checkLoginUser();
AccessPolicy::check(DemandasProfile::VIEW_TIME_PORTAL);
AccessPolicy::check(DemandasProfile::VIEW_OWN_ATTENDANCE);

$currentUserId = (int) Session::getLoginUserID();
$targetUserId = (int) ($_GET['users_id'] ?? $currentUserId);
if ($targetUserId !== $currentUserId && !AccessPolicy::has(DemandasProfile::VIEW_TEAM_ATTENDANCE)) {
    throw new Glpi\Exception\Http\AccessDeniedHttpException();
}

$file = (new TimeManagementService())->absenceFile((int) ($_GET['id'] ?? 0), $targetUserId);
$path = GLPI_DOC_DIR . '/_plugins/demandas/absences/' . basename((string) $file['stored_name']);
if (!is_file($path)) {
    throw new RuntimeException('O arquivo solicitado não está mais disponível.');
}
$originalName = str_replace(["\r", "\n", '"'], '', (string) $file['original_name']);
$mime = trim((string) ($file['mime_type'] ?? ''));
if ($mime === '' || !preg_match('/^[a-zA-Z0-9.+-]+\/[a-zA-Z0-9.+-]+$/', $mime)) {
    $mime = 'application/octet-stream';
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($originalName));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
