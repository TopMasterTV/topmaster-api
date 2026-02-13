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

    // 🔥 AGORA ACEITA POST E GET
    $nome = $_POST['nome'] ?? $_GET['nome'] ?? '';
    $url_padrao = $_POST['url_padrao'] ?? $_GET['url_padrao'] ?? '';

    if ($nome == '' || $url_padrao == '') {
        echo json_encode([
            "success" => false,
            "message" => "Nome e URL obrigatórios"
        ]);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO modelos_sistemas (nome, url_padrao) VALUES (?, ?)");
    $stmt->execute([$nome, $url_padrao]);

    echo json_encode([
        "success" => true
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao criar modelo"
    ]);
}
