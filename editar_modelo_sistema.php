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
        "pgsql:host={$db['host']};port=" . ($db['port'] ?? 5432) . ";dbname=" . ltrim($db['path'], '/'),
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $id = $_POST['id'] ?? $_GET['id'] ?? '';
    $nome = $_POST['nome'] ?? $_GET['nome'] ?? '';
    $url_padrao = $_POST['url_padrao'] ?? $_GET['url_padrao'] ?? '';

    if ($id == '' || $nome == '' || $url_padrao == '') {
        echo json_encode([
            "success" => false,
            "message" => "ID, Nome e URL obrigatórios"
        ]);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE modelos_sistemas SET nome = ?, url_padrao = ? WHERE id = ?");
    $stmt->execute([$nome, $url_padrao, $id]);

    echo json_encode([
        "success" => true
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao editar modelo"
    ]);
}
