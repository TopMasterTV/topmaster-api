<?php
header("Content-Type: application/json");

$enquete_id = $_REQUEST['enquete_id'] ?? '';

if ($enquete_id === '') {
    echo json_encode([
        "success" => false,
        "message" => "enquete_id obrigatório"
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

    $stmt = $pdo->prepare("
        SELECT
            o.id,
            o.texto,

            (
                SELECT COUNT(*)
                FROM public.enquete_respostas r
                WHERE r.opcao_id = o.id
            ) AS votos

        FROM public.enquete_opcoes o
        WHERE o.enquete_id = :enquete_id
        ORDER BY o.id ASC
    ");

    $stmt->execute([
        ':enquete_id' => $enquete_id
    ]);

    $resultado = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "resultado" => $resultado
    ]);

} catch (Exception $e) {

    echo json_encode([
        "success" => false,
        "message" => "Erro ao carregar resultado",
        "error" => $e->getMessage()
    ]);
}
