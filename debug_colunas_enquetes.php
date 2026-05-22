<?php
header("Content-Type: application/json; charset=utf-8");

$DATABASE_URL = getenv("DATABASE_URL");

try {
    $db = parse_url($DATABASE_URL);

    $pdo = new PDO(
        "pgsql:host={$db["host"]};port=" . ($db["port"] ?? 5432) . ";dbname=" . ltrim($db["path"], "/") . ";sslmode=require",
        $db["user"],
        $db["pass"],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $tabelas = ["enquetes", "enquete_opcoes", "enquete_respostas"];

    $resultado = [];

    foreach ($tabelas as $tabela) {
        $stmt = $pdo->prepare("
            SELECT column_name
            FROM information_schema.columns
            WHERE table_name = :tabela
            ORDER BY ordinal_position
        ");
        $stmt->execute([":tabela" => $tabela]);
        $resultado[$tabela] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    echo json_encode([
        "success" => true,
        "colunas" => $resultado
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "erro" => $e->getMessage()
    ]);
}
?>
