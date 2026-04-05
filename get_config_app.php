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

    $stmt = $pdo->prepare("
        SELECT chave, valor
        FROM configuracoes_app
    ");

    $stmt->execute();

    $dados = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    echo json_encode([
        "success" => true,
        "configuracoes" => $dados
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao buscar configurações",
        "error" => $e->getMessage()
    ]);
}
