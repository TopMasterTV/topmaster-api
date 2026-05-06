<?php
header("Content-Type: application/json");

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
            titulo,
            descricao,
            ativa,
            encerra_em,
            criado_em,

            resultado_titulo,
            resultado_descricao,
            resultado_link,
            resultado_publicado

        FROM public.enquete_campanhas
        ORDER BY id DESC
    ");

    $campanhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "campanhas" => $campanhas
    ]);

} catch (Exception $e) {

    echo json_encode([
        "success" => false,
        "message" => "Erro ao listar campanhas",
        "error" => $e->getMessage()
    ]);
}
