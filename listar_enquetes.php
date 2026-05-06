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
        SELECT *
        FROM public.enquetes
        WHERE ativa = true
        ORDER BY id DESC
    ");

    $enquetes = [];

    while ($e = $stmt->fetch(PDO::FETCH_ASSOC)) {

        $stmtOpcoes = $pdo->prepare("
            SELECT *
            FROM public.enquete_opcoes
            WHERE enquete_id = :id
        ");

        $stmtOpcoes->execute([
            ':id' => $e['id']
        ]);

        $e['opcoes'] = $stmtOpcoes->fetchAll(PDO::FETCH_ASSOC);

        $enquetes[] = $e;
    }

    echo json_encode([
        "success" => true,
        "enquetes" => $enquetes
    ]);

} catch (Exception $e) {

    echo json_encode([
        "success" => false,
        "message" => "Erro ao listar enquetes",
        "error" => $e->getMessage()
    ]);
}
