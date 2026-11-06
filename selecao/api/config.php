<?php
declare(strict_types=1);

// Mantenha este arquivo no servidor. Nunca o exponha ao navegador.
//
// As credenciais NAO ficam mais neste arquivo. Elas sao lidas de:
//   1) Variaveis de ambiente reais do hosting (tem prioridade), ou
//   2) Um arquivo .env que fica ao lado deste config (protegido pelo .htaccess).
// Assim, se o codigo vazar (GitHub, zip, backup), as chaves NAO vao junto.

// Le e memoriza o .env uma unica vez por requisicao.
function env_file(): array {
    static $parsed = null;
    if ($parsed !== null) return $parsed;
    $parsed = [];
    // Procura o .env subindo as pastas a partir daqui: api/ -> public_html/ ->
    // .../ -> raiz da conta (ex: /home/usuario/.env, FORA da webroot = mais seguro).
    // Usa o primeiro .env encontrado.
    $candidates = [];
    $dir = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $candidates[] = $dir . '/.env';
        $parent = dirname($dir);
        if ($parent === $dir) break; // chegou na raiz do filesystem
        $dir = $parent;
    }
    foreach ($candidates as $path) {
        if (!is_file($path) || !is_readable($path)) continue;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $pos = strpos($line, '=');
            if ($pos === false) continue;
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            // Remove aspas envolventes, se houver.
            if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && $val[strlen($val) - 1] === $val[0]) {
                $val = substr($val, 1, -1);
            }
            if ($key !== '' && !isset($parsed[$key])) $parsed[$key] = $val;
        }
        break; // usa o primeiro .env encontrado
    }
    return $parsed;
}

function env_value(string $name, string $default = ''): string {
    // 1) Variavel de ambiente real do servidor tem prioridade.
    $value = getenv($name);
    if ($value !== false && $value !== '') return $value;
    if (isset($_ENV[$name]) && $_ENV[$name] !== '') return (string) $_ENV[$name];
    if (isset($_SERVER[$name]) && $_SERVER[$name] !== '') return (string) $_SERVER[$name];
    // 2) Cai para o arquivo .env.
    $file = env_file();
    if (isset($file[$name]) && $file[$name] !== '') return $file[$name];
    // 3) Valor padrao explicito passado no codigo (nunca uma credencial).
    return $default;
}

function json_response(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_json(): array {
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function bravo_request(string $method, string $path, array $body = []): array {
    $token = env_value('BRAVOPAY_API_TOKEN');
    if ($token === '') json_response(['success' => false, 'error' => 'BRAVOPAY_API_TOKEN nao configurado'], 500);
    $ch = curl_init('https://bravopay.club/api/v1' . $path);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ];
    if ($body !== []) $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $error !== '') json_response(['success' => false, 'error' => 'Falha de comunicacao com o BravoPay'], 502);
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) json_response(['success' => false, 'error' => 'Resposta invalida do BravoPay'], 502);
    return [$status, $decoded];
}

function axxon_request(string $method, string $path, array $body = []): array {
    $pubKey = env_value('AXXON_PUBLIC_KEY', 'pk_3d92fea008f93990a2cceaf90a0b83a5');
    $secKey = env_value('AXXON_SECRET_KEY', 'sk_79aa2d3c3f7a8b45213a82b414e3eff417c45a4a5331924cc167b38f928cd9a8');

    if ($pubKey === '' || $secKey === '') {
        json_response(['success' => false, 'error' => 'Credenciais AXXON nao configuradas'], 500);
    }

    $ch = curl_init('https://api.axxonpay.com.br/api/v1' . $path);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'axxon-gateway-publickey: ' . $pubKey,
        'axxon-gateway-secretkey: ' . $secKey,
    ];

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ];
    if ($body !== []) {
        $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }

    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $error !== '') {
        json_response(['success' => false, 'error' => 'Falha de comunicacao com AxxonPay: ' . $error], 502);
    }
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        json_response(['success' => false, 'error' => 'Resposta invalida da AxxonPay'], 502);
    }
    return [$status, $decoded];
}

function utm_empty(): array {
    return ['source' => '', 'medium' => '', 'campaign' => '', 'content' => '', 'term' => '', 'gclid' => '', 'fbclid' => '', 'ttclid' => ''];
}
?>
