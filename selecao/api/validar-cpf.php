<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['success' => false, 'error' => 'Metodo nao permitido'], 405);
$cpf = preg_replace('/\D+/', '', (string) (request_json()['cpf'] ?? ''));
if (strlen($cpf) !== 11) json_response(['success' => false, 'error' => 'CPF invalido'], 400);
$token = env_value('APELA_USER_TOKEN');
if ($token === '') json_response(['success' => false, 'error' => 'APELA_USER_TOKEN nao configurado'], 500);
$url = 'https://api-apela.online/?user=' . rawurlencode($token) . '&cpf=' . rawurlencode($cpf);
$ch = curl_init($url);
if ($ch === false) {
    error_log('[validar-cpf] Nao foi possivel inicializar o cURL');
    json_response(['success' => false, 'error' => 'Falha ao iniciar comunicacao com a API Apela'], 502);
}

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 20,
]);

$res = curl_exec($ch);
$curlError = curl_error($ch);
$curlErrno = curl_errno($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($res === false) {
    error_log('[validar-cpf] Falha cURL ' . $curlErrno . ': ' . $curlError);
    json_response(['success' => false, 'error' => 'Falha de comunicacao com a API Apela'], 502);
}

$data = json_decode($res, true);
if (!is_array($data)) {
    error_log('[validar-cpf] Resposta JSON invalida da Apela; HTTP ' . $httpCode);
    json_response(['success' => false, 'error' => 'Resposta invalida da API Apela'], 502);
}

$apiStatus = (int) ($data['status'] ?? $httpCode);
if ($httpCode === 401 || $apiStatus === 401) {
    json_response(['success' => false, 'error' => 'Token da Apela invalido; confira APELA_USER_TOKEN'], 502);
}
if ($httpCode === 429 || $apiStatus === 429) {
    json_response(['success' => false, 'error' => 'Limite de consultas da Apela atingido'], 429);
}
if ($httpCode === 400 || $apiStatus === 400) {
    $apiError = trim((string) ($data['erro'] ?? ''));
    json_response([
        'success' => false,
        'error' => $apiError !== '' ? $apiError : 'A Apela rejeitou o CPF ou os parametros enviados',
    ], 400);
}
if ($httpCode < 200 || $httpCode >= 300 || $apiStatus !== 200) {
    error_log('[validar-cpf] Resposta inesperada da Apela; HTTP ' . $httpCode . ', status API ' . $apiStatus);
    json_response(['success' => false, 'error' => 'A API Apela retornou uma resposta inesperada'], 502);
}
if (empty($data['nome'])) {
    error_log('[validar-cpf] Resposta de sucesso sem campo nome; HTTP ' . $httpCode);
    json_response(['success' => false, 'error' => 'Resposta da API Apela sem os dados esperados'], 502);
}

json_response(['success' => true, 'dados_originais' => [
    'cpf' => $cpf,
    'nome' => (string) ($data['nome'] ?? ''),
    'nome_mae' => (string) ($data['mae'] ?? ''),
    'data_nascimento' => (string) ($data['nascimento'] ?? ''),
    'sexo' => (string) ($data['sexo'] ?? ''),
]]);
?>
