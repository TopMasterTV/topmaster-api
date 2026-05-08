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
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]
    );

    // =========================
    // CAMPANHA POR CÓDIGO
    // =========================
    if ($participacao_id !== '') {

        $stmt = $pdo->prepare("
            SELECT
                er.enquete_id,
                er.opcao_id
            FROM public.enquete_respostas er
            INNER JOIN public.enquetes e
                ON e.id = er.enquete_id
            WHERE e.campanha_id = :campanha_id
            AND er.cliente_id = :cliente_id
            AND er.participacao_id = :participacao_id
        ");

        $stmt->execute([
            ':campanha_id' => $campanha_id,
            ':cliente_id' => $cliente_id,
            ':participacao_id' => $participacao_id
        ]);

    }

    // =========================
    // CAMPANHA LIVRE
    // =========================
    else {

        $stmt = $pdo->prepare("
            SELECT
                er.enquete_id,
                er.opcao_id
            FROM public.enquete_respostas er
            INNER JOIN public.enquetes e
                ON e.id = er.enquete_id
            WHERE e.campanha_id = :campanha_id
            AND er.cliente_id = :cliente_id
            AND er.participacao_id IS NULL
        ");

        $stmt->execute([
            ':campanha_id' => $campanha_id,
            ':cliente_id' => $cliente_id
        ]);
    }

    $respostas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "respostas" => $respostas
    ]);

} catch (Exception $e) {

    echo json_encode([
        "success" => false,
        "message" => "Erro ao listar respostas",
        "error" => $e->getMessage()
    ]);
}
