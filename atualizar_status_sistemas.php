<?php
header("Content-Type: application/json");
date_default_timezone_set("America/Sao_Paulo");

$cliente_id = $_REQUEST['cliente_id'] ?? '';
$limit = intval($_REQUEST['limit'] ?? 500);

if ($limit < 1) {
    $limit = 500;
}

if ($limit > 1000) {
    $limit = 1000;
}

$DATABASE_URL = getenv("DATABASE_URL");

if (!$DATABASE_URL) {
    echo json_encode([
        "success" => false,
        "message" => "DATABASE_URL não definida"
    ]);
    exit;
}

$db = parse_url($DATABASE_URL);

try {
    $pdo = new PDO(
        "pgsql:host={$db['host']};port=" . ($db['port'] ?? 5432) .
        ";dbname=" . ltrim($db['path'], '/') .
        ";sslmode=require",
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    if ($cliente_id !== '') {
        $stmt = $pdo->prepare("
            SELECT *
            FROM public.sistemas
            WHERE cliente_id = :cliente_id
            ORDER BY id ASC
        ");

        $stmt->execute([
            ':cliente_id' => $cliente_id
        ]);
    } else {
        $stmt = $pdo->prepare("
            SELECT *
            FROM public.sistemas
            ORDER BY id ASC
            LIMIT :limit
        ");

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
    }

    $sistemas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = count($sistemas);
    $atualizados = 0;
    $falhas = 0;
    $detalhes = [];

    foreach ($sistemas as $s) {
        $id = $s['id'];
        $url = rtrim(trim($s['url'] ?? ''), '/');
        $user = trim($s['usuario'] ?? '');
        $pass = trim($s['senha'] ?? '');

        $status = null;
        $exp_date = null;
        $vencimento = null;

        if ($url === '' || $user === '' || $pass === '') {
            $falhas++;

            $detalhes[] = [
                "id" => (int)$id,
                "cliente_id" => (int)$s['cliente_id'],
                "nome_sistema" => $s['nome_sistema'] ?? '',
                "status" => "falha",
                "motivo" => "url, usuário ou senha vazio"
            ];

            continue;
        }

        $apiUrl = $url . "/player_api.php?username=" . urlencode($user) . "&password=" . urlencode($pass);

        $response = @file_get_contents($apiUrl);

        if ($response) {
            $data = json_decode($response, true);

            if (isset($data['user_info'])) {
                $status = $data['user_info']['status'] ?? null;
                $exp_date = $data['user_info']['exp_date'] ?? null;

                if ($exp_date) {
                    $vencimento = date("Y-m-d", intval($exp_date));
                }
            }
        }

        if (!$status) {
            if (!empty($s['m3u_url'])) {
                $m3u = @file_get_contents($s['m3u_url']);

                if ($m3u && strpos($m3u, "#EXTM3U") !== false) {
                    $status = "Active";
                }
            }
        }

        if (!$vencimento && !empty($s['vencimento'])) {
            $vencimento = $s['vencimento'];
        }

        $update = $pdo->prepare("
            UPDATE public.sistemas
            SET
                status = :status,
                exp_date = :exp_date,
                vencimento = :vencimento
            WHERE id = :id
        ");

        $update->execute([
            ':status' => $status,
            ':exp_date' => $exp_date,
            ':vencimento' => $vencimento,
            ':id' => $id
        ]);

        $atualizados++;

        $detalhes[] = [
            "id" => (int)$id,
            "cliente_id" => (int)$s['cliente_id'],
            "nome_sistema" => $s['nome_sistema'] ?? '',
            "status" => "atualizado",
            "status_xtream" => $status,
            "exp_date" => $exp_date,
            "vencimento_antigo" => $s['vencimento'] ?? null,
            "vencimento_novo" => $vencimento
        ];
    }

    $pdo->exec("
        UPDATE public.clientes c
        SET ativo = EXISTS (
            SELECT 1
            FROM public.sistemas s
            WHERE s.cliente_id = c.id
            AND (
                s.status = 'Active'
                OR s.vencimento >= CURRENT_DATE
            )
        )
    ");

    echo json_encode([
        "success" => true,
        "message" => "Sistemas atualizados e clientes sincronizados corretamente",
        "cliente_id" => $cliente_id !== '' ? intval($cliente_id) : null,
        "total_sistemas" => $total,
        "atualizados" => $atualizados,
        "falhas" => $falhas,
        "clientes_sincronizados" => true,
        "detalhes" => $detalhes
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao atualizar sistemas",
        "error" => $e->getMessage()
    ]);
}
