<?php
header("Content-Type: application/json");

$cliente_id = $_POST['cliente_id'] ?? '';

if ($cliente_id === '') {
    echo json_encode([
        "success" => false,
        "message" => "cliente_id obrigatório"
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
            s.id,
            s.cliente_id,
            s.modelo_id,
            m.nome AS nome_modelo,
            m.url_padrao AS url,
            s.usuario,
            s.senha,
            s.vencimento,
            s.m3u_url
        FROM sistemas s
        LEFT JOIN modelos_sistemas m 
            ON s.modelo_id = m.id
        WHERE s.cliente_id = :cliente_id
        ORDER BY s.id DESC
    ");

    $stmt->execute([
        ':cliente_id' => $cliente_id
    ]);

    $sistemas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "sistemas" => $sistemas
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
