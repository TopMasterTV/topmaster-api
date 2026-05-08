<?php
header("Content-Type: application/json");

$campanha_id = $_REQUEST['campanha_id'] ?? '';
$cliente_id = $_REQUEST['cliente_id'] ?? '';
$participacao_id = $_REQUEST['participacao_id'] ?? '';

if ($campanha_id === '' || $cliente_id === '') {
    echo json_encode([
        "success" => false,
        "message" => "campanha_id e cliente_id são obrigatórios"
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

    if ($participacao_id !== '') {
        $stmt = $pdo->prepare("
            SELECT
                enquete_id,
                opcao_id
            FROM public.enquete_respostas
            WHERE campanha_id = :campanha_id
            AND cliente_id = :cliente_id
            AND participacao_id = :participacao_id
        ");

        $stmt->execute([
            ':campanha_id' => $campanha_id,
            ':cliente_id' => $cliente_id,
            ':participacao_id' => $participacao_id
        ]);
    } else {
        $stmt = $pdo->prepare("
            SELECT
                enquete_id,
                opcao_id
            FROM public.enquete_respostas
            WHERE campanha_id = :campanha_id
            AND cliente_id = :cliente_id
            AND participacao_id IS NULL
        ");

        $stmt->execute([
            ':campanha_id' => $campanha_id,
            ':cliente_id' => $cliente_id
        ]);
    }

    echo json_encode([
        "success" => true,
        "respostas" => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao listar respostas",
        "error" => $e->getMessage()
    ]);
}
