<?php
header("Content-Type: application/json");

$cliente_id = $_REQUEST['cliente_id'] ?? '';
$enquete_id = $_REQUEST['enquete_id'] ?? '';
$opcao_id   = $_REQUEST['opcao_id'] ?? '';

if ($cliente_id === '' || $enquete_id === '' || $opcao_id === '') {
    echo json_encode([
        "success" => false,
        "message" => "cliente_id, enquete_id e opcao_id são obrigatórios"
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

    $check = $pdo->prepare("
        SELECT id
        FROM public.enquete_respostas
        WHERE cliente_id = :cliente_id
        AND enquete_id = :enquete_id
        LIMIT 1
    ");

    $check->execute([
        ':cliente_id' => $cliente_id,
        ':enquete_id' => $enquete_id
    ]);

    if ($check->fetch()) {
        echo json_encode([
            "success" => false,
            "message" => "Cliente já votou nesta enquete"
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO public.enquete_respostas
        (cliente_id, enquete_id, opcao_id)
        VALUES
        (:cliente_id, :enquete_id, :opcao_id)
    ");

    $stmt->execute([
        ':cliente_id' => $cliente_id,
        ':enquete_id' => $enquete_id,
        ':opcao_id' => $opcao_id
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Voto registrado com sucesso"
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao votar",
        "error" => $e->getMessage()
    ]);
}
