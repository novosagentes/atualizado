<?php
declare(strict_types=1);
require_once __DIR__ . '/store.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Metodo nao permitido'], 405);
}

$input = request_json();
$utm = is_array($input['utm'] ?? null) ? $input['utm'] : [];
$mapped = store_utm_from_input($utm);

try {
    $visit = visits_insert([
        'session_id' => trim((string) ($input['session_id'] ?? '')),
        'page' => trim((string) ($input['page'] ?? 'landing')) ?: 'landing',
        'utm_source' => $mapped['utm_source'],
        'utm_campaign' => $mapped['utm_campaign'],
        'utm_keyword' => $mapped['utm_keyword'] !== '' ? $mapped['utm_keyword'] : $mapped['utm_term'],
        'utm_campaign_name' => $mapped['utm_campaign_name'],
    ]);
    json_response(['success' => true, 'id' => (string) ($visit['id'] ?? ''), 'driver' => store_driver()]);
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => $e->getMessage()], 500);
}
