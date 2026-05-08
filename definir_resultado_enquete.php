<?php
header("Content-Type: application/json");
date_default_timezone_set("America/Sao_Paulo");

$enquete_id = $_REQUEST['enquete_id'] ?? '';
$opcao_correta_id = $_REQUEST['opcao_correta_id'] ?? '';

if ($enquete_id === '' || $opcao_correta_id === '') {
    echo json_encode([
        "success" => false,
        "message" => "enquete_id e opcao_correta_id são obrigatórios"
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
        SELECT id
        FROM public.enquete_opcoes
        WHERE id = :opcao_correta_id
        AND enquete_id = :enquete_id
        LIMIT 1
    ");

    $stmtOpcao->execute([
        ':opcao_correta_id' => $opcao_correta_id,
        ':enquete_id' => $enquete_id
    ]);

    if (!$stmtOpcao->fetch()) {
        echo json_encode([
            "success" => false,
            "message" => "Opção correta inválida para esta enquete"
        ]);
        exit;
    }

    $stmtUpdate = $pdo->prepare("
        UPDATE public.enquetes
        SET opcao_correta_id = :opcao_correta_id
        WHERE id = :enquete_id
        RETURNING id, titulo, opcao_correta_id
    ");

    $stmtUpdate->execute([
        ':opcao_correta_id' => $opcao_correta_id,
        ':enquete_id' => $enquete_id
    ]);

    $enquete = $stmtUpdate->fetch(PDO::FETCH_ASSOC);

    if (!$enquete) {
        echo json_encode([
            "success" => false,
            "message" => "Enquete não encontrada"
        ]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "message" => "Resultado da enquete definido com sucesso",
        "enquete" => $enquete
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao definir resultado da enquete",
        "error" => $e->getMessage()
    ]);
}
