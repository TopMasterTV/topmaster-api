<?php
header("Content-Type: application/json");

/* =========================
   RECEBE DADOS
   ========================= */
$id = $_POST['id'] ?? null;

if (!$id) {
    echo json_encode([
        "success" => false,
        "message" => "ID do cliente não informado"
    ]);
    exit;
}

/* =========================
   CONEXÃO COM BANCO
   ========================= */
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
        "pgsql:host={$db['host']};port=" . ($db['port'] ?? 5432) . ";dbname=" . ltrim($db['path'], '/') . ";sslmode=require",
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao conectar ao banco"
    ]);
    exit;
}

/* =========================
   EXCLUI CLIENTE
   ========================= */
try {
    $stmt = $pdo->prepare("DELETE FROM clientes WHERE id = :id");
    $stmt->execute([
        ':id' => $id
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Cliente excluído com sucesso"
    ]);
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao excluir cliente"
    ]);
}
