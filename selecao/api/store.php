<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function store_using_supabase(): bool {
    return env_value('SUPABASE_URL') !== '' && env_value('SUPABASE_SERVICE_KEY') !== '';
}

function store_driver(): string {
    return store_using_supabase() ? 'supabase' : 'local';
}

function store_uuid(): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function store_now(): string {
    return gmdate('c');
}

function store_data_path(): string {
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    return $dir . DIRECTORY_SEPARATOR . 'store.json';
}

function store_local_read(): array {
    $path = store_data_path();
    if (!is_file($path)) {
        return ['orders' => [], 'visits' => []];
    }
    $raw = file_get_contents($path);
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        return ['orders' => [], 'visits' => []];
    }
    $data['orders'] = is_array($data['orders'] ?? null) ? $data['orders'] : [];
    $data['visits'] = is_array($data['visits'] ?? null) ? $data['visits'] : [];
    return $data;
}

function store_local_write(array $data): void {
    $path = store_data_path();
    $fp = fopen($path, 'c+');
    if ($fp === false) {
        throw new RuntimeException('Nao foi possivel gravar o armazenamento local');
    }
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function store_supabase_request(string $method, string $path, ?array $body = null, array $headers = []): array {
    $url = rtrim(env_value('SUPABASE_URL'), '/') . '/rest/v1/' . ltrim($path, '/');
    $key = env_value('SUPABASE_SERVICE_KEY');
    $ch = curl_init($url);
    $reqHeaders = array_merge([
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
        'Content-Type: application/json',
        'Accept: application/json',
        'Prefer: return=representation',
    ], $headers);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $reqHeaders,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if (defined('CURLOPT_SSL_OPTIONS') && defined('CURLSSLOPT_NATIVE_CA')) {
        $options[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
    } elseif (defined('CURLOPT_SSL_OPTIONS')) {
        $options[CURLOPT_SSL_OPTIONS] = 16;
    }
    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $error !== '') {
        throw new RuntimeException('Falha de comunicacao com o Supabase: ' . $error);
    }
    $decoded = json_decode((string) $response, true);
    if ($status < 200 || $status >= 300) {
        $message = is_array($decoded) ? (string) ($decoded['message'] ?? $decoded['error'] ?? $response) : (string) $response;
        throw new RuntimeException('Supabase recusou a operacao: ' . $message);
    }
    if (!is_array($decoded)) {
        return [];
    }
    if ($decoded === []) {
        return [];
    }
    if (array_is_list($decoded)) {
        return $decoded;
    }
    return [$decoded];
}

function store_normalize_order(array $order): array {
    $bool = static function ($value): bool {
        return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 't';
    };
    $status = strtolower(trim((string) ($order['status'] ?? 'waiting_payment')));
    $marked = $bool($order['marked_paid_by_admin'] ?? false);
    if ($marked || in_array($status, ['paid', 'approved', 'completed', 'succeeded'], true)) {
        $status = 'paid';
    } elseif (in_array($status, ['canceled', 'cancelled', 'expired', 'failed'], true)) {
        $status = 'canceled';
    } else {
        $status = 'waiting_payment';
    }
    return [
        'id' => (string) ($order['id'] ?? ''),
        'created_at' => (string) ($order['created_at'] ?? ''),
        'paid_at' => $order['paid_at'] ?? null,
        'status' => $status,
        'transacao_id' => (string) ($order['transacao_id'] ?? ''),
        'nome' => (string) ($order['nome'] ?? ''),
        'cpf' => (string) ($order['cpf'] ?? ''),
        'email' => (string) ($order['email'] ?? ''),
        'telefone' => (string) ($order['telefone'] ?? ''),
        'cep' => (string) ($order['cep'] ?? ''),
        'cidade' => (string) ($order['cidade'] ?? ''),
        'uf' => (string) ($order['uf'] ?? ''),
        'cargo' => (string) ($order['cargo'] ?? ''),
        'produto' => (string) ($order['produto'] ?? 'Taxa de Inscricao'),
        'price_cents' => (int) ($order['price_cents'] ?? 6348),
        'marked_paid_by_admin' => $marked,
        'utm_source' => (string) ($order['utm_source'] ?? ''),
        'utm_medium' => (string) ($order['utm_medium'] ?? ''),
        'utm_campaign' => (string) ($order['utm_campaign'] ?? ''),
        'utm_campaign_name' => (string) ($order['utm_campaign_name'] ?? ''),
        'utm_adgroup' => (string) ($order['utm_adgroup'] ?? ''),
        'utm_ad' => (string) ($order['utm_ad'] ?? ''),
        'utm_keyword' => (string) ($order['utm_keyword'] ?? ''),
        'utm_term' => (string) ($order['utm_term'] ?? ''),
        'utm_matchtype' => (string) ($order['utm_matchtype'] ?? ''),
        'utm_device' => (string) ($order['utm_device'] ?? ''),
        'utm_network' => (string) ($order['utm_network'] ?? ''),
        'utm_content' => (string) ($order['utm_content'] ?? ''),
        'gclid' => (string) ($order['gclid'] ?? ''),
        'fbclid' => (string) ($order['fbclid'] ?? ''),
        'ttclid' => (string) ($order['ttclid'] ?? ''),
    ];
}

function store_utm_from_input(array $utm): array {
    $pick = static function (array $utm, array $keys): string {
        foreach ($keys as $key) {
            $value = trim((string) ($utm[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    };
    return [
        'utm_source' => $pick($utm, ['utm_source', 'source']),
        'utm_medium' => $pick($utm, ['utm_medium', 'medium']),
        'utm_campaign' => $pick($utm, ['utm_campaign', 'campaign']),
        'utm_campaign_name' => $pick($utm, ['utm_campaign_name', 'campaign_name']),
        'utm_adgroup' => $pick($utm, ['utm_adgroup', 'adgroup']),
        'utm_ad' => $pick($utm, ['utm_ad', 'ad', 'creative']),
        'utm_keyword' => $pick($utm, ['utm_keyword', 'keyword']),
        'utm_term' => $pick($utm, ['utm_term', 'term']),
        'utm_matchtype' => $pick($utm, ['utm_matchtype', 'matchtype']),
        'utm_device' => $pick($utm, ['utm_device', 'device']),
        'utm_network' => $pick($utm, ['utm_network', 'network']),
        'utm_content' => $pick($utm, ['utm_content', 'content']),
        'gclid' => $pick($utm, ['gclid']),
        'fbclid' => $pick($utm, ['fbclid']),
        'ttclid' => $pick($utm, ['ttclid']),
    ];
}

function orders_all(): array {
    if (store_using_supabase()) {
        $rows = store_supabase_request('GET', 'orders?select=*&order=created_at.desc&limit=5000');
        return array_map('store_normalize_order', $rows);
    }
    $orders = array_map('store_normalize_order', store_local_read()['orders']);
    usort($orders, static function (array $a, array $b): int {
        return strcmp((string) $b['created_at'], (string) $a['created_at']);
    });
    return $orders;
}

function orders_find_by_cpf(string $cpf, bool $onlyWaiting = false): ?array {
    $cpf = preg_replace('/\D+/', '', $cpf) ?? '';
    if (strlen($cpf) !== 11) {
        return null;
    }
    if (store_using_supabase()) {
        $filter = 'orders?cpf=eq.' . rawurlencode($cpf) . '&order=created_at.desc&limit=1&select=*';
        if ($onlyWaiting) {
            $filter = 'orders?cpf=eq.' . rawurlencode($cpf) . '&status=eq.waiting_payment&order=created_at.desc&limit=1&select=*';
        }
        $rows = store_supabase_request('GET', $filter);
        return isset($rows[0]) ? store_normalize_order($rows[0]) : null;
    }
    $match = null;
    foreach (store_local_read()['orders'] as $order) {
        if ((string) ($order['cpf'] ?? '') !== $cpf) {
            continue;
        }
        if ($onlyWaiting && store_normalize_order($order)['status'] === 'paid') {
            continue;
        }
        $match = store_normalize_order($order);
        break;
    }
    return $match;
}

function orders_find_open_by_cpf(string $cpf): ?array {
    return orders_find_by_cpf($cpf, true);
}

function orders_find_any(string $id): ?array {
    $id = trim($id);
    if ($id === '') {
        return null;
    }
    if (store_using_supabase()) {
        $rows = store_supabase_request('GET', 'orders?id=eq.' . rawurlencode($id) . '&select=*&limit=1');
        if (isset($rows[0])) {
            return store_normalize_order($rows[0]);
        }
        $rows = store_supabase_request('GET', 'orders?transacao_id=eq.' . rawurlencode($id) . '&select=*&limit=1');
        if (isset($rows[0])) {
            return store_normalize_order($rows[0]);
        }
        $digits = preg_replace('/\D+/', '', $id) ?? '';
        if (strlen($digits) === 11) {
            return orders_find_by_cpf($digits, false);
        }
        return null;
    }
    foreach (store_local_read()['orders'] as $order) {
        $normalized = store_normalize_order($order);
        if ($normalized['id'] === $id || $normalized['transacao_id'] === $id || $normalized['cpf'] === preg_replace('/\D+/', '', $id)) {
            return $normalized;
        }
    }
    return null;
}

function orders_find_by_tx(string $transacaoId): ?array {
    $transacaoId = trim($transacaoId);
    if ($transacaoId === '') {
        return null;
    }
    if (store_using_supabase()) {
        $rows = store_supabase_request('GET', 'orders?transacao_id=eq.' . rawurlencode($transacaoId) . '&select=*&limit=1');
        return isset($rows[0]) ? store_normalize_order($rows[0]) : null;
    }
    foreach (store_local_read()['orders'] as $order) {
        if ((string) ($order['transacao_id'] ?? '') === $transacaoId) {
            return store_normalize_order($order);
        }
    }
    return null;
}

function orders_upsert(array $payload): array {
    $now = store_now();
    $order = store_normalize_order(array_merge($payload, [
        'id' => (string) ($payload['id'] ?? store_uuid()),
        'created_at' => (string) ($payload['created_at'] ?? $now),
        'status' => (string) ($payload['status'] ?? 'waiting_payment'),
        'price_cents' => (int) ($payload['price_cents'] ?? 6348),
        'produto' => (string) ($payload['produto'] ?? 'Taxa de Inscricao'),
    ]));
    $isReceita = ((int) $order['price_cents'] === 5790) || (stripos((string) $order['produto'], 'receita') !== false);

    if (store_using_supabase()) {
        $existing = $order['transacao_id'] !== '' ? orders_find_by_tx($order['transacao_id']) : null;
        if ($existing === null && $order['cpf'] !== '' && !$isReceita) {
            $existing = orders_find_by_cpf($order['cpf'], false);
        }
        if ($existing !== null) {
            $order['id'] = $existing['id'];
            $order['created_at'] = $existing['created_at'];
            if (($existing['status'] ?? '') === 'paid' || !empty($existing['marked_paid_by_admin'])) {
                $order['status'] = 'paid';
                $order['marked_paid_by_admin'] = !empty($existing['marked_paid_by_admin']);
                $order['paid_at'] = $existing['paid_at'] ?: ($order['paid_at'] ?? null);
            }
            $rows = store_supabase_request('PATCH', 'orders?id=eq.' . rawurlencode($order['id']), $order);
            return isset($rows[0]) ? store_normalize_order($rows[0]) : $order;
        }
        $rows = store_supabase_request('POST', 'orders', $order);
        return isset($rows[0]) ? store_normalize_order($rows[0]) : $order;
    }

    $data = store_local_read();
    $found = false;
    foreach ($data['orders'] as $index => $current) {
        $sameId = $order['id'] !== '' && (string) ($current['id'] ?? '') === $order['id'];
        $sameTx = $order['transacao_id'] !== '' && (string) ($current['transacao_id'] ?? '') === $order['transacao_id'];
        $sameCpf = !$isReceita && $order['cpf'] !== '' && (string) ($current['cpf'] ?? '') === $order['cpf'] && ($current['status'] ?? '') !== 'paid';
        if (!$sameId && !$sameTx && !$sameCpf) {
            continue;
        }
        $merged = store_normalize_order(array_merge($current, $order, [
            'id' => (string) ($current['id'] ?? $order['id']),
            'created_at' => (string) ($current['created_at'] ?? $order['created_at']),
        ]));
        if (!empty($current['marked_paid_by_admin']) || ($current['status'] ?? '') === 'paid') {
            $merged['status'] = 'paid';
            $merged['marked_paid_by_admin'] = !empty($current['marked_paid_by_admin']);
            $merged['paid_at'] = $current['paid_at'] ?? $merged['paid_at'];
        }
        $data['orders'][$index] = $merged;
        $order = $merged;
        $found = true;
        break;
    }
    if (!$found) {
        $data['orders'][] = $order;
    }
    store_local_write($data);
    return $order;
}

function orders_mark_paid(string $id, bool $byAdmin = true): ?array {
    $id = trim($id);
    if ($id === '') {
        return null;
    }
    $paidAt = store_now();
    $patch = [
        'status' => 'paid',
        'paid_at' => $paidAt,
        'marked_paid_by_admin' => $byAdmin,
    ];

    if (store_using_supabase()) {
        $existing = orders_find_any($id);
        if ($existing && !empty($existing['marked_paid_by_admin'])) {
            $patch['marked_paid_by_admin'] = true;
        }
        $targetId = $existing['id'] ?? $id;
        $rows = store_supabase_request('PATCH', 'orders?id=eq.' . rawurlencode($targetId), $patch);
        if (!isset($rows[0])) {
            $rows = store_supabase_request('PATCH', 'orders?transacao_id=eq.' . rawurlencode($id), $patch);
        }
        if (!isset($rows[0]) && $existing !== null) {
            $rows = [$existing];
            $rows[0]['status'] = 'paid';
            $rows[0]['paid_at'] = $paidAt;
            $rows[0]['marked_paid_by_admin'] = $byAdmin;
        }
        return isset($rows[0]) ? store_normalize_order($rows[0]) : null;
    }

    $data = store_local_read();
    foreach ($data['orders'] as $index => $order) {
        $cpf = preg_replace('/\D+/', '', (string) ($order['cpf'] ?? ''));
        if ((string) ($order['id'] ?? '') !== $id && (string) ($order['transacao_id'] ?? '') !== $id && $cpf !== preg_replace('/\D+/', '', $id)) {
            continue;
        }
        if ($byAdmin === false && !empty($order['marked_paid_by_admin'])) {
            $patch['marked_paid_by_admin'] = true;
        }
        $data['orders'][$index] = store_normalize_order(array_merge($order, $patch));
        store_local_write($data);
        return $data['orders'][$index];
    }
    return null;
}

function visits_insert(array $visit): array {
    $row = [
        'id' => store_uuid(),
        'created_at' => store_now(),
        'session_id' => (string) ($visit['session_id'] ?? ''),
        'page' => (string) ($visit['page'] ?? 'landing'),
        'utm_source' => (string) ($visit['utm_source'] ?? ''),
        'utm_campaign' => (string) ($visit['utm_campaign'] ?? ''),
        'utm_keyword' => (string) ($visit['utm_keyword'] ?? ''),
        'utm_campaign_name' => (string) ($visit['utm_campaign_name'] ?? ''),
    ];

    if (store_using_supabase()) {
        $rows = store_supabase_request('POST', 'visits', $row);
        return isset($rows[0]) ? $rows[0] : $row;
    }

    $data = store_local_read();
    foreach ($data['visits'] as $current) {
        if (
            $row['session_id'] !== ''
            && (string) ($current['session_id'] ?? '') === $row['session_id']
            && substr((string) ($current['created_at'] ?? ''), 0, 10) === substr($row['created_at'], 0, 10)
        ) {
            return $current;
        }
    }
    $data['visits'][] = $row;
    store_local_write($data);
    return $row;
}

function visits_all(): array {
    if (store_using_supabase()) {
        return store_supabase_request('GET', 'visits?select=*&order=created_at.desc&limit=20000');
    }
    return store_local_read()['visits'];
}
