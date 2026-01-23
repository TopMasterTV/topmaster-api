<?php
header('Content-Type: application/json');

$DATABASE_URL = getenv('DATABASE_URL');

if (!$DATABASE_URL) {
    echo json_encode([
        "success" => false,
        "message" => "DATABASE_URL não configurada"
    ]);
    exit;
}

$db = parse_url($DATABASE_URL);

$host = $db['host'];
$port = $db['port'] ?? 5432;
$dbname = ltrim($db['path'], '/');
$user = $db['user'];
$pass = $db['pass'];

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // 👉 DEFINA A NOVA SENHA AQUI
    $novaSenha = "123456";

    $hash = password_hash($novaSenha, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
        UPDATE admins 
        SET senha = :senha 
        WHERE tipo = 'master'
    ");

    $stmt->execute([
        ':senha' => $hash
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Senha do admin master redefinida com sucesso",
        "nova_senha" => $novaSenha
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao resetar senha"
    ]);
}
