<?php
header("Content-Type: application/json");
date_default_timezone_set("America/Sao_Paulo");

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

    $stmt = $pdo->query("
        SELECT
            id,
            cliente_id,
            nome_sistema,
            url,
            usuario,
            senha,
            vencimento
        FROM public.sistemas
        ORDER BY id ASC
        LIMIT 500
    ");

    $sistemas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = count($sistemas);
    $atualizados = 0;
    $sem_exp_date = 0;
    $falhas = 0;
    $detalhes = [];

    foreach ($sistemas as $sistema) {

        $id = $sistema['id'];
        $url = rtrim(trim($sistema['url'] ?? ''), '/');
        $usuario = trim($sistema['usuario'] ?? '');
        $senha = trim($sistema['senha'] ?? '');

        if ($url === '' || $usuario === '' || $senha === '') {
            $falhas++;

            $detalhes[] = [
                "id" => (int)$id,
                "cliente_id" => (int)$sistema['cliente_id'],
                "nome_sistema" => $sistema['nome_sistema'],
                "status" => "falha",
                "motivo" => "url, usuário ou senha vazio"
            ];

            continue;
        }

        $apiUrl = $url . "/player_api.php?username=" . urlencode($usuario) . "&password=" . urlencode($senha);

        $context = stream_context_create([
            "http" => [
                "timeout" => 3,
                "ignore_errors" => true,
                "header" => "User-Agent: TOPMASTER-TV\r\n"
            ]
        ]);

        $resposta = @file_get_contents($apiUrl, false, $context);

        if ($resposta === false || trim($resposta) === '') {
            $falhas++;

            $detalhes[] = [
                "id" => (int)$id,
                "cliente_id" => (int)$sistema['cliente_id'],
                "nome_sistema" => $sistema['nome_sistema'],
                "status" => "falha",
                "motivo" => "sem resposta da Xtream"
            ];

            continue;
        }

        $json = json_decode($resposta, true);

        if (!is_array($json) || !isset($json['user_info'])) {
            $falhas++;

            $detalhes[] = [
                "id" => (int)$id,
                "cliente_id" => (int)$sistema['cliente_id'],
                "nome_sistema" => $sistema['nome_sistema'],
                "status" => "falha",
                "motivo" => "resposta inválida da Xtream"
            ];

            continue;
        }

        $userInfo = $json['user_info'];
        $statusXtream = $userInfo['status'] ?? '';
        $expDate = $userInfo['exp_date'] ?? '';

        if ($expDate === '' || $expDate === null || $expDate === '0') {
            $sem_exp_date++;

            $detalhes[] = [
                "id" => (int)$id,
                "cliente_id" => (int)$sistema['cliente_id'],
                "nome_sistema" => $sistema['nome_sistema'],
                "status" => $statusXtream,
                "motivo" => "sem exp_date"
            ];

            continue;
        }

        $novoVencimento = date("Y-m-d", intval($expDate));

        $stmtUpdate = $pdo->prepare("
            UPDATE public.sistemas
            SET vencimento = :vencimento
            WHERE id = :id
        ");

        $stmtUpdate->execute([
            ':vencimento' => $novoVencimento,
            ':id' => $id
        ]);

        $atualizados++;

        $detalhes[] = [
            "id" => (int)$id,
            "cliente_id" => (int)$sistema['cliente_id'],
            "nome_sistema" => $sistema['nome_sistema'],
            "status" => "atualizado",
            "status_xtream" => $statusXtream,
            "vencimento_antigo" => $sistema['vencimento'],
            "vencimento_novo" => $novoVencimento
        ];
    }

    $pdo->exec("
        UPDATE public.clientes c
        SET ativo = EXISTS (
            SELECT 1
            FROM public.sistemas s
            WHERE s.cliente_id = c.id
            AND s.vencimento >= CURRENT_DATE
        )
    ");

    echo json_encode([
        "success" => true,
        "message" => "Atualização concluída",
        "total_sistemas" => $total,
        "atualizados" => $atualizados,
        "sem_exp_date" => $sem_exp_date,
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
