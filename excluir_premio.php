<?php
header("Content-Type: application/json");

$premio_id = $_REQUEST['premio_id'] ?? '';

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

    $stmtBusca = $pdo->prepare("
        SELECT *
        FROM public.enquete_premios
        WHERE id = :premio_id
        LIMIT 1
    ");

    $stmtBusca->execute([
        ':premio_id' => $premio_id
    ]);

    $premio = $stmtBusca->fetch(PDO::FETCH_ASSOC);

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
            "message" => "Não é permitido excluir prêmio já sorteado"
        ]);
        exit;
    }

    $stmtDelete = $pdo->prepare("
        DELETE FROM public.enquete_premios
        WHERE id = :premio_id
    ");

    $stmtDelete->execute([
        ':premio_id' => $premio_id
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Prêmio excluído com sucesso"
    ]);

} catch (Exception $e) {

    echo json_encode([
        "success" => false,
        "message" => "Erro ao excluir prêmio",
        "error" => $e->getMessage()
    ]);
}
