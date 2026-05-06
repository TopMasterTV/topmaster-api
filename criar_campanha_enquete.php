<?php
header("Content-Type: application/json");

$titulo = $_REQUEST['titulo'] ?? '';
$descricao = $_REQUEST['descricao'] ?? '';
$encerra_em = $_REQUEST['encerra_em'] ?? '';

if ($titulo === '' || $encerra_em === '') {
    echo json_encode([
        "success" => false,
        "message" => "titulo e encerra_em são obrigatórios"
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
        INSERT INTO public.enquete_campanhas
        (titulo, descricao, encerra_em)
        VALUES
        (:titulo, :descricao, :encerra_em)
        RETURNING id
    ");

    $stmt->execute([
        ':titulo' => $titulo,
        ':descricao' => $descricao,
        ':encerra_em' => $encerra_em
    ]);

    $campanha_id = $stmt->fetchColumn();

    echo json_encode([
        "success" => true,
        "message" => "Campanha criada com sucesso",
        "campanha_id" => $campanha_id
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao criar campanha",
        "error" => $e->getMessage()
    ]);
}
