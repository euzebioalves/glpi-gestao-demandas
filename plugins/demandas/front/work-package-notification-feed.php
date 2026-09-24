<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\WorkPackageMonitoringService;

Session::checkLoginUser();
header('Content-Type: application/json; charset=utf-8');
try {
    $service = new WorkPackageMonitoringService();
    $userId = (int) Session::getLoginUserID();
    $items = [];
    // O sino é uma prévia compacta; a página de alertas mantém o histórico.
    foreach ($service->notifications($userId, true, 3) as $notification) {
        $items[] = [
            'id' => (int) $notification['id'],
            'title' => (string) $notification['title'],
            'message' => (string) $notification['message'],
            'priority' => (string) $notification['priority'],
            'date' => (string) $notification['date_creation'],
        ];
    }
    echo json_encode(['ok' => true, 'unread' => $service->unreadCount($userId), 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
}
