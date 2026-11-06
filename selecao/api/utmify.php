<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['success' => false, 'error' => 'Metodo nao permitido'], 405);
$input = request_json();
$order = trim((string) ($input['orderId'] ?? ''));
$status = trim((string) ($input['status'] ?? 'waiting_payment'));
$price = (int) ($input['priceInCents'] ?? 6348);
if ($order === '') json_response(['success' => false, 'error' => 'orderId obrigatorio'], 400);
$token = env_value('UTMIFY_API_TOKEN');
if ($token === '') json_response(['success' => false, 'error' => 'UTMIFY_API_TOKEN nao configurado'], 500);
$utm = is_array($input['utm'] ?? null) ? $input['utm'] : [];
$payload = ['orderId' => $order, 'platform' => 'BravoPay', 'paymentMethod' => 'pix', 'status' => $status, 'createdAt' => gmdate('Y-m-d H:i:s'), 'approvedDate' => $status === 'paid' ? gmdate('Y-m-d H:i:s') : null, 'refundedAt' => null, 'customer' => ['name' => 'Nao Informado', 'email' => 'naoinformado@email.com', 'phone' => '', 'document' => '', 'country' => 'BR'], 'products' => [['id' => env_value('BRAVOPAY_PRODUCT_ID', 'ebook-emagrecimento'), 'name' => 'E-book Emagrecimento', 'planId' => null, 'planName' => null, 'quantity' => 1, 'priceInCents' => $price]], 'trackingParameters' => ['src' => $utm['src'] ?? null, 'sck' => $utm['sck'] ?? null, 'utm_source' => $utm['utm_source'] ?? null, 'utm_campaign' => $utm['utm_campaign'] ?? null, 'utm_medium' => $utm['utm_medium'] ?? null, 'utm_content' => $utm['utm_content'] ?? null, 'utm_term' => $utm['utm_term'] ?? null, 'gclid' => $utm['gclid'] ?? null, 'fbclid' => $utm['fbclid'] ?? null, 'ttclid' => $utm['ttclid'] ?? null], 'commission' => ['totalPriceInCents' => $price, 'gatewayFeeInCents' => 0, 'userCommissionInCents' => $price]];
$ch = curl_init('https://api.utmify.com.br/api-credentials/orders');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-token: ' . $token, 'Accept: application/json'], CURLOPT_TIMEOUT => 20]);
$res = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
if ($code < 200 || $code >= 300) json_response(['success' => false, 'error' => 'Utmify recusou o pedido'], 502);
json_response(['success' => true]);
?>
