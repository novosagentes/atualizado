<?php
declare(strict_types=1);
require_once __DIR__ . '/store.php';

$id = trim((string) ($_GET['id'] ?? ''));
if ($id === '') {
    json_response(['success' => false, 'error' => 'ID da transacao obrigatorio'], 400);
}

try {
    $stored = orders_find_by_tx($id);
    if ($stored && (($stored['status'] ?? '') === 'paid' || !empty($stored['marked_paid_by_admin']))) {
        json_response([
            'success' => true,
            'status' => 'APPROVED',
            'transacao_id' => $id,
            'source' => !empty($stored['marked_paid_by_admin']) ? 'admin' : 'store',
        ]);
    }
} catch (Throwable $e) {
    // Se o storage falhar, ainda tenta consultar o gateway.
}

$gateway = strtolower(env_value('GATEWAY', 'axxon'));
$raw = 'PENDING';
$txId = $id;

if ($gateway === 'axxon') {
    [$status, $data] = axxon_request('GET', '/payments/' . rawurlencode($id));
    if ($status < 200 || $status >= 300) {
        // Tenta também consultar em /direct/payment/{id} caso seja sandbox
        [$statusDirect, $dataDirect] = axxon_request('GET', '/direct/payment/' . rawurlencode($id));
        if ($statusDirect >= 200 && $statusDirect < 300) {
            $data = is_array($dataDirect['data'] ?? null) ? $dataDirect['data'] : $dataDirect;
        } else {
            json_response(['success' => false, 'error' => 'Transacao nao encontrada'], $status === 404 ? 404 : 502);
        }
    }
    $raw = strtoupper((string) ($data['status'] ?? 'PENDING'));
    $txId = (string) ($data['id'] ?? $id);
} else {
    [$status, $data] = bravo_request('GET', '/transactions/' . rawurlencode($id));
    if ($status < 200 || $status >= 300) {
        json_response(['success' => false, 'error' => 'Transacao nao encontrada'], $status === 404 ? 404 : 502);
    }
    $raw = strtoupper((string) ($data['status'] ?? 'PENDING'));
    $txId = (string) ($data['id'] ?? $id);
}

$mapped = in_array($raw, ['PAID', 'APPROVED', 'COMPLETED', 'SUCCEEDED', 'FINISHED', 'CONFIRMED'], true)
    ? 'APPROVED'
    : (in_array($raw, ['CANCELED', 'CANCELLED', 'EXPIRED', 'FAILED', 'REJECTED'], true) ? 'CANCELED' : 'PENDING');

if ($mapped === 'APPROVED') {
    try {
        orders_mark_paid($id, false);
    } catch (Throwable $e) {
        // Status do PIX ja foi confirmado no gateway.
    }
}

json_response(['success' => true, 'status' => $mapped, 'transacao_id' => $txId]);
