<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\Profile as DemandasProfile;

Session::checkLoginUser();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

// Only capabilities of the current session, never ticket or WP data.
// Resource endpoints retain their independent authorization checks.
echo json_encode([
    'view_public' => DemandasProfile::has(DemandasProfile::VIEW_PUBLIC),
    'view_technical' => DemandasProfile::has(DemandasProfile::VIEW_TECHNICAL),
], JSON_THROW_ON_ERROR);
