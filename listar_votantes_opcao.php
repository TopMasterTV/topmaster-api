<?php
header("Content-Type: application/json");
date_default_timezone_set("America/Sao_Paulo");

$enquete_id = $_REQUEST['enquete_id'] ?? '';
$opcao_id = $_REQUEST['opcao_id'] ?? '';

if ($enquete_id === '' || $opcao_id === '') {
    echo json_encode([
        "success" => false,
        "message" => "enquete_id e opcao_id são obrigatórios"
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

    $stmtOpcao = $pdo->prepare("
        SELECT id, texto
        FROM public.enquete_opcoes
        WHERE id = :opcao_id
        AND enquete_id = :enquete_id
        LIMIT 1
    ");

    $stmtOpcao->execute([
        ':opcao_id' => $opcao_id,
        ':enquete_id' => $enquete_id
    ]);

    $opcao = $stmtOpcao->fetch(PDO::FETCH_ASSOC);

    if (!$opcao) {
        echo json_encode([
            "success" => false,
            "message" => "Opção não encontrada para esta enquete"
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT
            r.id,
            r.enquete_id,
            r.opcao_id,
            r.cliente_id,
            r.participacao_id,

            TO_CHAR(
                r.respondido_em AT TIME ZONE 'America/Sao_Paulo',
                'DD/MM/YYYY HH24:MI'
            ) AS respondido_em,

            c.nome,
            c.usuario
        FROM public.enquete_respostas r
        LEFT JOIN public.clientes c
        ON c.id = r.cliente_id
        WHERE r.enquete_id = :enquete_id
        AND r.opcao_id = :opcao_id
        ORDER BY c.nome ASC
    ");

    $stmt->execute([
        ':enquete_id' => $enquete_id,
        ':opcao_id' => $opcao_id
    ]);

    $votantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "opcao" => $opcao,
        "total" => count($votantes),
        "votantes" => $votantes
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao listar votantes",
        "error" => $e->getMessage()
    ]);
}
