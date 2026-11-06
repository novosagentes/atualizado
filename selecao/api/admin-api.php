<?php
declare(strict_types=1);
require_once __DIR__ . '/store.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('aventador_admin');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 12,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function admin_logged_in(): bool {
    return !empty($_SESSION['admin_ok']) && (string) ($_SESSION['admin_user'] ?? '') !== '';
}

function admin_require(): void {
    if (!admin_logged_in()) {
        json_response(['success' => false, 'error' => 'Nao autenticado'], 401);
    }
}

function admin_in_range(?string $iso, int $fromTs, int $toTs): bool {
    if (!$iso) {
        return false;
    }
    $ts = strtotime($iso);
    if ($ts === false) {
        return false;
    }
    return $ts >= $fromTs && $ts <= $toTs;
}

function admin_bucket_key(array $order, string $field): string {
    $value = trim((string) ($order[$field] ?? ''));
    if ($field === 'utm_keyword' && $value === '') {
        $value = trim((string) ($order['utm_term'] ?? ''));
    }
    return $value !== '' ? $value : '(sem dado)';
}

function admin_dashboard(array $orders, array $visits, string $range): array {
    $now = time();
    $from = match ($range) {
        'today' => strtotime('today 00:00:00') ?: ($now - 86400),
        '7d' => $now - (7 * 86400),
        '30d' => $now - (30 * 86400),
        default => 0,
    };
    $filtered = array_values(array_filter($orders, static fn(array $o): bool => admin_in_range((string) $o['created_at'], $from, $now + 10)));
    $filteredVisits = array_values(array_filter($visits, static fn(array $v): bool => admin_in_range((string) ($v['created_at'] ?? ''), $from, $now + 10)));

    $paid = array_values(array_filter($filtered, static fn(array $o): bool => ($o['status'] ?? '') === 'paid' || !empty($o['marked_paid_by_admin'])));
    $waiting = array_values(array_filter($filtered, static fn(array $o): bool => ($o['status'] ?? '') !== 'paid' && empty($o['marked_paid_by_admin']) && ($o['status'] ?? '') !== 'canceled'));
    $revenue = array_reduce($paid, static fn(int $sum, array $o): int => $sum + (int) ($o['price_cents'] ?? 0), 0);
    $orderCount = count($filtered);
    $paidCount = count($paid);
    $visitCount = count($filteredVisits);
    $checkoutConversion = $orderCount > 0 ? round(($paidCount / $orderCount) * 100, 1) : 0.0;
    $funnelConversion = $visitCount > 0 ? round(($paidCount / $visitCount) * 100, 1) : 0.0;
    $ticket = $paidCount > 0 ? (int) round($revenue / $paidCount) : 0;

    $byDay = [];
    foreach ($filtered as $order) {
        $day = substr((string) $order['created_at'], 0, 10);
        if ($day === '') {
            continue;
        }
        if (!isset($byDay[$day])) {
            $byDay[$day] = ['day' => $day, 'pedidos' => 0, 'pagos' => 0, 'faturamento_cents' => 0];
        }
        $byDay[$day]['pedidos']++;
        if (($order['status'] ?? '') === 'paid' || !empty($order['marked_paid_by_admin'])) {
            $byDay[$day]['pagos']++;
            $byDay[$day]['faturamento_cents'] += (int) ($order['price_cents'] ?? 0);
        }
    }
    ksort($byDay);

    $group = static function (array $items, string $field) use ($paid): array {
        $map = [];
        foreach ($items as $order) {
            $key = admin_bucket_key($order, $field);
            if (!isset($map[$key])) {
                $map[$key] = [
                    'label' => $key,
                    'pedidos' => 0,
                    'pagos' => 0,
                    'faturamento_cents' => 0,
                    'campaign_name' => (string) ($order['utm_campaign_name'] ?? ''),
                ];
            }
            $map[$key]['pedidos']++;
            if (($order['status'] ?? '') === 'paid' || !empty($order['marked_paid_by_admin'])) {
                $map[$key]['pagos']++;
                $map[$key]['faturamento_cents'] += (int) ($order['price_cents'] ?? 0);
            }
            if ($map[$key]['campaign_name'] === '' && ($order['utm_campaign_name'] ?? '') !== '') {
                $map[$key]['campaign_name'] = (string) $order['utm_campaign_name'];
            }
        }
        $list = array_values($map);
        usort($list, static fn(array $a, array $b): int => $b['faturamento_cents'] <=> $a['faturamento_cents'] ?: $b['pedidos'] <=> $a['pedidos']);
        return $list;
    };

    return [
        'kpis' => [
            'pedidos' => $orderCount,
            'pagos' => $paidCount,
            'aguardando' => count($waiting),
            'visitas' => $visitCount,
            'faturamento_cents' => $revenue,
            'ticket_cents' => $ticket,
            'conversao_checkout' => $checkoutConversion,
            'conversao_funil' => $funnelConversion,
        ],
        'by_day' => array_values($byDay),
        'by_keyword' => $group($filtered, 'utm_keyword'),
        'by_campaign' => $group($filtered, 'utm_campaign'),
        'by_source' => $group($filtered, 'utm_source'),
        'by_device' => $group($filtered, 'utm_device'),
        'orders' => $filtered,
        'paid_sample' => $paid,
    ];
}

$action = trim((string) ($_GET['action'] ?? $_POST['action'] ?? ''));
if ($action === '') {
    $input = request_json();
    $action = trim((string) ($input['action'] ?? ''));
} else {
    $input = request_json();
}

try {
    if ($action === 'login') {
        $user = trim((string) ($input['user'] ?? $_POST['user'] ?? ''));
        $pass = (string) ($input['pass'] ?? $_POST['pass'] ?? '');
        $attempts = (int) ($_SESSION['login_attempts'] ?? 0);
        if ($attempts >= 12) {
            json_response(['success' => false, 'error' => 'Muitas tentativas. Aguarde e tente de novo.'], 429);
        }
        $okUser = hash_equals(env_value('ADMIN_USER'), $user);
        $okPass = hash_equals(env_value('ADMIN_PASS'), $pass);
        if (!$okUser || !$okPass) {
            $_SESSION['login_attempts'] = $attempts + 1;
            json_response(['success' => false, 'error' => 'Usuario ou senha invalidos'], 401);
        }
        $_SESSION['admin_ok'] = true;
        $_SESSION['admin_user'] = $user;
        $_SESSION['login_attempts'] = 0;
        session_regenerate_id(true);
        json_response(['success' => true, 'user' => $user, 'driver' => store_driver()]);
    }

    if ($action === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/');
        }
        session_destroy();
        json_response(['success' => true]);
    }

    if ($action === 'me') {
        if (!admin_logged_in()) {
            json_response(['success' => false, 'authenticated' => false], 401);
        }
        json_response([
            'success' => true,
            'authenticated' => true,
            'user' => (string) $_SESSION['admin_user'],
            'driver' => store_driver(),
            'supabase_ready' => store_using_supabase(),
        ]);
    }

    admin_require();

    if ($action === 'dashboard') {
        $range = trim((string) ($_GET['range'] ?? $input['range'] ?? '30d'));
        if (!in_array($range, ['today', '7d', '30d', 'all'], true)) {
            $range = '30d';
        }
        $dash = admin_dashboard(orders_all(), visits_all(), $range);
        json_response([
            'success' => true,
            'driver' => store_driver(),
            'supabase_ready' => store_using_supabase(),
            'range' => $range,
            ...$dash,
        ]);
    }

    if ($action === 'mark_paid') {
        $id = trim((string) ($input['id'] ?? $input['transacao_id'] ?? $input['cpf'] ?? $_GET['id'] ?? ''));
        if ($id === '' && isset($input['order']) && is_array($input['order'])) {
            $id = trim((string) ($input['order']['id'] ?? $input['order']['transacao_id'] ?? $input['order']['cpf'] ?? ''));
        }
        $order = orders_mark_paid($id, true);
        if ($order === null) {
            json_response(['success' => false, 'error' => 'Pedido nao encontrado'], 404);
        }
        json_response(['success' => true, 'order' => $order]);
    }

    json_response(['success' => false, 'error' => 'Acao invalida'], 400);
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => $e->getMessage()], 500);
}
