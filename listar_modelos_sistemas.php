<?php
header("Content-Type: application/json");

/* ===============================
   CONEXÃO COM BANCO
================================= */

$URL_DO_BANCO_DE_DADOS = getenv("URL_DO_BANCO_DE_DADOS");

if (!$URL_DO_BANCO_DE_DADOS) {
    echo json_encode([
        "success" => false,
        "message" => "URL_DO_BANCO_DE_DADOS não definida"
    ]);
    exit;
}

$db = parse_url($URL_DO_BANCO_DE_DADOS);

try {
    $pdo = new PDO(
        "pgsql:host={$db['host']};port=" . ($db['port'] ?? 5432) . ";dbname=" . ltrim($db['path'], '/'),
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $pdo->query("SELECT id, nome, url_padrao FROM modelos_sistemas ORDER BY nome ASC");
    $modelos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "modelos" => $modelos
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao listar modelos"
    ]);
}
