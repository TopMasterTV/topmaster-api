<?php
header("Content-Type: application/json");

$premio_id = $_REQUEST['premio_id'] ?? '';
$nome_premio = trim($_REQUEST['nome_premio'] ?? '');
$ordem = $_REQUEST['ordem'] ?? 1;

if ($premio_id === '' || $nome_premio === '') {
    echo json_encode([
        "success" => false,
        "message" => "premio_id e nome_premio obrigatórios"
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
        UPDATE public.enquete_premios
        SET
            nome_premio = :nome_premio,
            ordem = :ordem
        WHERE id = :premio_id
        RETURNING *
    ");

    $stmt->execute([
        ':nome_premio' => $nome_premio,
        ':ordem' => intval($ordem),
        ':premio_id' => $premio_id
    ]);

    $premio = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$premio) {
        echo json_encode([
            "success" => false,
            "message" => "Prêmio não encontrado"
        ]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "message" => "Prêmio atualizado com sucesso",
        "premio" => $premio
    ]);

} catch (Exception $e) {

    echo json_encode([
        "success" => false,
        "message" => "Erro ao editar prêmio",
        "error" => $e->getMessage()
    ]);
}
