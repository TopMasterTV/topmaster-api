<?php
header("Content-Type: application/json");

/* AJUSTE AQUI */
$usuario_funcionario = "joao";
$nova_senha = "123456";

/* CONEXÃO */
$DATABASE_URL = getenv("DATABASE_URL");
$db = parse_url($DATABASE_URL);

$pdo = new PDO(
    "pgsql:host={$db['host']};port=" . ($db['port'] ?? 5432) . ";dbname=" . ltrim($db['path'], '/'),
    $db['user'],
    $db['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

/* RESET */
$hash = password_hash($nova_senha, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("
    UPDATE admins
    SET senha = :senha
    WHERE usuario = :usuario
      AND tipo = 'funcionario'
");

$stmt->execute([
    ":senha"   => $hash,
    ":usuario" => $usuario_funcionario
]);

echo json_encode([
    "success" => true,
    "message" => "Senha do admin funcionário redefinida",
    "usuario" => $usuario_funcionario,
    "senha"   => $nova_senha
]);
