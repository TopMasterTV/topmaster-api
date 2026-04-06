<?php
header("Content-Type: application/json");

$admin_id      = $_POST['admin_id'] ?? '';
$tipo          = $_POST['tipo'] ?? '';
$revendedor_id = $_POST['revendedor_id'] ?? null;

if ($admin_id === '' || $tipo === '') {
    echo json_encode([
        "success" => false,
        "message" => "admin_id e tipo são obrigatórios"
    ]);
    exit;
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
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao conectar ao banco"
    ]);
    exit;
}

/* =========================
   🔥 ATUALIZA SISTEMAS AUTOMATICAMENTE
   ========================= */
function atualizarSistemas($pdo) {

    $stmt = $pdo->query("SELECT * FROM sistemas");
    $sistemas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($sistemas as $s) {

        $url = rtrim($s['url'], '/');
        $user = $s['usuario'];
        $pass = $s['senha'];

        if (!$url || !$user || !$pass) continue;

        $status = null;
        $exp_date = null;

        // 🔥 TENTA PLAYER API
        try {
            $apiUrl = "$url/player_api.php?username=$user&password=$pass";

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);

            $response = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($response, true);

            if (isset($data['user_info'])) {
                $status = $data['user_info']['status'] ?? null;
                $exp_date = $data['user_info']['exp_date'] ?? null;
            }

        } catch (Exception $e) {}

        // 🔥 FALLBACK M3U
        if (!$status) {
            try {
                $m3u = $s['m3u_url'];

                if ($m3u) {
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $m3u);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

                    $response = curl_exec($ch);
                    curl_close($ch);

                    if (strpos($response, "#EXTM3U") !== false) {
                        $status = 'Active';
                    }
                }
            } catch (Exception $e) {}
        }

        // 🔥 CONVERTE DATA
        $vencimento = null;

        if ($exp_date) {
            $vencimento = date('Y-m-d', $exp_date);
        }

        // 🔥 ATUALIZA NO BANCO
        $update = $pdo->prepare("
            UPDATE sistemas
            SET
                status = :status,
                exp_date = :exp_date,
                vencimento = COALESCE(:vencimento, vencimento)
            WHERE id = :id
        ");

        $update->execute([
            ':status' => $status,
            ':exp_date' => $exp_date,
            ':vencimento' => $vencimento,
            ':id' => $s['id']
        ]);
    }
}

// 🔥 EXECUTA ATUALIZAÇÃO AUTOMÁTICA
atualizarSistemas($pdo);

/* =========================
   LISTAGEM DE CLIENTES
   ========================= */
try {

    if ($tipo === 'master') {

        $sql = "
            SELECT
                c.id,
                c.nome,
                c.usuario,
                c.m3u_url,
                c.whatsapp,
                c.link_pagamento,
                c.plano,
                c.admin_id,
                c.revendedor_id,
                c.revendedor_nome,
                c.criado_em,

                (
                    SELECT COUNT(*)
                    FROM public.sistemas s
                    WHERE s.cliente_id = c.id
                ) AS total_sistemas,

                (
                    SELECT MAX(s.vencimento)
                    FROM public.sistemas s
                    WHERE s.cliente_id = c.id
                ) AS vencimento_principal

            FROM public.clientes c
            ORDER BY c.id DESC
        ";

        $stmt = $pdo->query($sql);

    } else {

        if ($revendedor_id === null || $revendedor_id === '') {
            echo json_encode([
                "success" => false,
                "message" => "revendedor_id é obrigatório"
            ]);
            exit;
        }

        $sql = "
            SELECT
                c.id,
                c.nome,
                c.usuario,
                c.m3u_url,
                c.whatsapp,
                c.link_pagamento,
                c.plano,
                c.admin_id,
                c.revendedor_id,
                c.revendedor_nome,
                c.criado_em,

                (
                    SELECT COUNT(*)
                    FROM public.sistemas s
                    WHERE s.cliente_id = c.id
                ) AS total_sistemas,

                (
                    SELECT MAX(s.vencimento)
                    FROM public.sistemas s
                    WHERE s.cliente_id = c.id
                ) AS vencimento_principal

            FROM public.clientes c
            WHERE c.revendedor_id = :revendedor_id
            ORDER BY c.id DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':revendedor_id' => $revendedor_id
        ]);
    }

    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "total"   => count($clientes),
        "clientes"=> $clientes
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao listar clientes",
        "error"   => $e->getMessage()
    ]);
}
