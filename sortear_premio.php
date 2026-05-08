<?php
header("Content-Type: application/json");
date_default_timezone_set("America/Sao_Paulo");

$premio_id = $_REQUEST['premio_id'] ?? '';
$minimo_acertos = $_REQUEST['minimo_acertos'] ?? 6;

if ($premio_id === '') {
    echo json_encode([
        "success" => false,
        "message" => "premio_id obrigatório"
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

    $stmtPremio = $pdo->prepare("
        SELECT *
        FROM public.enquete_premios
        WHERE id = :premio_id
        LIMIT 1
    ");

    $stmtPremio->execute([
        ':premio_id' => $premio_id
    ]);

    $premio = $stmtPremio->fetch(PDO::FETCH_ASSOC);

    if (!$premio) {
        echo json_encode([
            "success" => false,
            "message" => "Prêmio não encontrado"
        ]);
        exit;
    }

    if (!empty($premio['vencedor_cliente_id'])) {
        echo json_encode([
            "success" => false,
            "message" => "Este prêmio já possui vencedor",
            "premio" => $premio
        ]);
        exit;
    }

    $campanha_id = $premio['campanha_id'];

    $stmtClassificados = $pdo->prepare("
        SELECT
            r.cliente_id,
            c.nome,
            c.usuario,
            COUNT(*) AS total_respostas,
            SUM(
                CASE
                    WHEN r.opcao_id = e.opcao_correta_id THEN 1
                    ELSE 0
                END
            ) AS acertos
        FROM public.enquete_respostas r
        INNER JOIN public.enquetes e
            ON e.id = r.enquete_id
        LEFT JOIN public.clientes c
            ON c.id = r.cliente_id
        WHERE e.campanha_id = :campanha_id
        AND e.opcao_correta_id IS NOT NULL
        GROUP BY r.cliente_id, c.nome, c.usuario
        HAVING SUM(
            CASE
                WHEN r.opcao_id = e.opcao_correta_id THEN 1
                ELSE 0
            END
        ) >= :minimo_acertos
        ORDER BY RANDOM()
    ");

    $stmtClassificados->execute([
        ':campanha_id' => $campanha_id,
        ':minimo_acertos' => intval($minimo_acertos)
    ]);

    $classificados = $stmtClassificados->fetchAll(PDO::FETCH_ASSOC);

    if (count($classificados) === 0) {
        echo json_encode([
            "success" => false,
            "message" => "Nenhum classificado disponível para este prêmio"
        ]);
        exit;
    }

    $disponiveis = [];

    foreach ($classificados as $classificado) {
        $stmtJaGanhou = $pdo->prepare("
            SELECT id
            FROM public.enquete_premios
            WHERE campanha_id = :campanha_id
            AND vencedor_cliente_id = :cliente_id
            LIMIT 1
        ");

        $stmtJaGanhou->execute([
            ':campanha_id' => $campanha_id,
            ':cliente_id' => $classificado['cliente_id']
        ]);

        $jaGanhou = $stmtJaGanhou->fetch(PDO::FETCH_ASSOC);

        if (!$jaGanhou) {
            $disponiveis[] = $classificado;
        }
    }

    if (count($disponiveis) === 0) {
        echo json_encode([
            "success" => false,
            "message" => "Todos os classificados já ganharam algum prêmio"
        ]);
        exit;
    }

    shuffle($disponiveis);
    $vencedor = $disponiveis[0];

    $stmtUpdate = $pdo->prepare("
        UPDATE public.enquete_premios
        SET
            vencedor_cliente_id = :vencedor_cliente_id,
            vencedor_nome = :vencedor_nome
        WHERE id = :premio_id
        RETURNING *
    ");

    $stmtUpdate->execute([
        ':vencedor_cliente_id' => $vencedor['cliente_id'],
        ':vencedor_nome' => $vencedor['nome'],
        ':premio_id' => $premio_id
    ]);

    $premioAtualizado = $stmtUpdate->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "message" => "Prêmio sorteado com sucesso",
        "premio" => $premioAtualizado,
        "vencedor" => [
            "cliente_id" => intval($vencedor['cliente_id']),
            "nome" => $vencedor['nome'],
            "usuario" => $vencedor['usuario'],
            "acertos" => intval($vencedor['acertos']),
            "total_respostas" => intval($vencedor['total_respostas'])
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao sortear prêmio",
        "error" => $e->getMessage()
    ]);
}
