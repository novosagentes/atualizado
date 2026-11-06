<?php
declare(strict_types=1);
require_once __DIR__ . '/store.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Metodo nao permitido'], 405);
}

$input = request_json();
$transacaoId = trim((string) ($input['transacao_id'] ?? ''));
$cpf = preg_replace('/\D+/', '', (string) ($input['cpf'] ?? ''));
if ($transacaoId === '' && strlen($cpf) !== 11) {
    json_response(['success' => false, 'error' => 'informe o CPF do cadastro ou o ID da transacao'], 400);
}

$utm = store_utm_from_input(is_array($input['utm'] ?? null) ? $input['utm'] : []);
$priceCents = (int) ($input['price_cents'] ?? 6348);
$produto = trim((string) ($input['produto'] ?? ''));
$isReceita = $priceCents === 5790 || stripos($produto, 'receita') !== false;
$existing = $transacaoId !== '' ? orders_find_by_tx($transacaoId) : null;
if ($existing === null && strlen($cpf) === 11 && !$isReceita) {
    $existing = orders_find_by_cpf($cpf, false);
}
if ($transacaoId === '') {
    $transacaoId = trim((string) (($existing['transacao_id'] ?? '') ?: ('lead_' . $cpf)));
}
$status = (string) ($input['status'] ?? ($existing['status'] ?? 'waiting_payment'));
if ($existing && (($existing['status'] ?? '') === 'paid' || !empty($existing['marked_paid_by_admin']))) {
    $status = 'paid';
}

try {
    $prev = $existing ?? [];
    $order = orders_upsert(array_merge($utm, [
        'id' => (string) ($prev['id'] ?? store_uuid()),
        'created_at' => (string) ($prev['created_at'] ?? store_now()),
        'transacao_id' => $transacaoId,
        'status' => $status,
        'nome' => trim((string) ($input['nome'] ?? $prev['nome'] ?? '')),
        'cpf' => preg_replace('/\D+/', '', (string) ($input['cpf'] ?? $prev['cpf'] ?? '')),
        'email' => trim((string) ($input['email'] ?? $prev['email'] ?? '')),
        'telefone' => trim((string) ($input['telefone'] ?? $prev['telefone'] ?? '')),
        'cep' => preg_replace('/\D+/', '', (string) ($input['cep'] ?? $prev['cep'] ?? '')),
        'cidade' => trim((string) ($input['cidade'] ?? $prev['cidade'] ?? '')),
        'uf' => strtoupper(trim((string) ($input['uf'] ?? $prev['uf'] ?? ''))),
        'cargo' => trim((string) ($input['cargo'] ?? $prev['cargo'] ?? '')),
        'produto' => trim((string) ($input['produto'] ?? $prev['produto'] ?? 'Taxa de Inscricao')),
        'price_cents' => (int) ($input['price_cents'] ?? $prev['price_cents'] ?? 6348),
        'marked_paid_by_admin' => !empty($prev['marked_paid_by_admin']),
        'paid_at' => $status === 'paid' ? ($prev['paid_at'] ?? store_now()) : ($prev['paid_at'] ?? null),
    ]));
    json_response(['success' => true, 'order' => $order, 'driver' => store_driver()]);
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => $e->getMessage()], 500);
}
