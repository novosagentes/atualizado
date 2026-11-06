<?php
declare(strict_types=1);
require_once __DIR__ . '/store.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Metodo nao permitido'], 405);
}

$input = request_json();
$amount = (int) ($_GET['price_cents'] ?? $input['price_cents'] ?? 6348);
if ($amount < 100) {
    json_response(['success' => false, 'error' => 'Valor invalido'], 400);
}

$customer = is_array($input['customer'] ?? null) ? $input['customer'] : [];
$name = trim((string) ($customer['name'] ?? $input['nome_pagador'] ?? 'Nao Informado')) ?: 'Nao Informado';
$email = filter_var((string) ($customer['email'] ?? $input['email_pagador'] ?? ''), FILTER_VALIDATE_EMAIL) ?: 'naoinformado@email.com';
$cpf = preg_replace('/\D+/', '', (string) ($customer['cpf'] ?? $input['cpf_pagador'] ?? '11144477735'));
if (strlen($cpf) !== 11) {
    $cpf = '11144477735';
}
$phone = trim((string) ($customer['phone'] ?? $input['telefone_pagador'] ?? ''));
$utm = store_utm_from_input(is_array($input['utm'] ?? null) ? $input['utm'] : []);
$produtoInput = trim((string) ($input['produto'] ?? ''));
$isReceita = $amount === 5790 || stripos($produtoInput, 'receita') !== false;
$produto = $isReceita ? 'Regularizacao CPF - Receita Federal' : ($produtoInput !== '' ? $produtoInput : 'Taxa de Inscricao');
$cep = preg_replace('/\D+/', '', (string) ($input['cep'] ?? ''));
$cidade = trim((string) ($input['cidade'] ?? ''));
$uf = strtoupper(trim((string) ($input['uf'] ?? '')));
$cargo = trim((string) ($input['cargo'] ?? ''));

$gateway = strtolower(env_value('GATEWAY', 'axxon'));

if ($gateway === 'axxon') {
    $amountReais = round($amount / 100, 2);
    $bodyAxxon = [
        'amount' => $amountReais,
        'paymentMethod' => 'pix',
        'description' => $produto,
        'customer' => [
            'name' => $name,
            'email' => $email,
            'phone' => $phone !== '' ? preg_replace('/\D+/', '', $phone) : '11999999999',
            'document' => [
                'number' => $cpf,
                'type' => 'cpf'
            ]
        ],
        'metadata' => [
            'origin' => 'DIRECT_PAYMENT',
            'attribution' => [
                'utm_source' => $utm['utm_source'] ?? '',
                'utm_medium' => $utm['utm_medium'] ?? '',
                'utm_campaign' => $utm['utm_campaign'] ?? '',
                'utm_content' => $utm['utm_content'] ?? '',
                'utm_term' => $utm['utm_term'] ?? ''
            ]
        ]
    ];

    [$status, $data] = axxon_request('POST', '/direct/payment', $bodyAxxon);
    if ($status < 200 || $status >= 300) {
        $msg = (string) ($data['message'] ?? $data['errorMessage'] ?? $data['error'] ?? 'AxxonPay recusou a transacao');
        json_response(['success' => false, 'error' => $msg], 502);
    }

    $payloadData = is_array($data['data'] ?? null) ? $data['data'] : $data;
    $code = (string) ($payloadData['qrCode'] ?? $payloadData['pixCopyPaste'] ?? '');
    if ($code === '') {
        json_response(['success' => false, 'error' => 'AxxonPay nao retornou o codigo PIX'], 502);
    }
    $transacaoId = (string) ($payloadData['id'] ?? '');
    $gatewayStatus = (string) ($payloadData['status'] ?? 'PENDING');
} else {
    $body = [
        'amount_cents' => $amount,
        'method' => 'pix',
        'product_id' => env_value('BRAVOPAY_PRODUCT_ID'),
        'description' => 'E-book Emagrecimento',
        'external_reference' => 'pedido_' . bin2hex(random_bytes(8)),
        'customer' => ['name' => $name, 'email' => $email, 'cpf' => $cpf],
        'utm' => utm_empty(),
    ];

    [$status, $data] = bravo_request('POST', '/transactions', $body);
    if ($status < 200 || $status >= 300) {
        json_response(['success' => false, 'error' => (string) ($data['message'] ?? $data['error'] ?? 'BravoPay recusou a transacao')], 502);
    }

    $pix = is_array($data['pix'] ?? null) ? $data['pix'] : [];
    $code = (string) ($pix['copy_paste'] ?? $pix['qr_code'] ?? $data['pix_code'] ?? '');
    if ($code === '') {
        json_response(['success' => false, 'error' => 'BravoPay nao retornou o codigo PIX'], 502);
    }

    $transacaoId = (string) ($data['id'] ?? '');
    $gatewayStatus = (string) ($data['status'] ?? 'PENDING');
}

$orderStatus = 'waiting_payment';
$existing = $transacaoId !== '' ? orders_find_by_tx($transacaoId) : null;
if ($existing === null && !$isReceita) {
    $existing = orders_find_by_cpf($cpf, false);
}
if ($existing && !$isReceita && (($existing['status'] ?? '') === 'paid' || !empty($existing['marked_paid_by_admin']))) {
    $orderStatus = 'paid';
}

try {
    orders_upsert(array_merge($utm, [
        'transacao_id' => $transacaoId,
        'status' => $orderStatus,
        'nome' => $name,
        'cpf' => $cpf,
        'email' => $email,
        'telefone' => $phone,
        'cep' => $cep,
        'cidade' => $cidade,
        'uf' => $uf,
        'cargo' => $cargo,
        'produto' => $produto,
        'price_cents' => $amount,
        'marked_paid_by_admin' => is_array($existing) && !empty($existing['marked_paid_by_admin']),
        'paid_at' => $orderStatus === 'paid' ? ((is_array($existing) ? ($existing['paid_at'] ?? null) : null) ?: store_now()) : null,
    ]));
} catch (Throwable $e) {
    // Pedido PIX ja foi gerado; o painel pode ficar sem o registro se o storage falhar.
}

json_response([
    'success' => true,
    'pixCode' => $code,
    'qrCode' => $code,
    'transacao_id' => $transacaoId,
    'status' => $gatewayStatus,
]);
