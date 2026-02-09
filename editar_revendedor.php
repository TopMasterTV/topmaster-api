<?php
header("Content-Type: application/json");

$id       = $_POST['id']       ?? '';
$nome     = $_POST['nome']     ?? '';
$usuario  = $_POST['usuario']  ?? '';
$senha    = $_POST['senha']    ?? '';
$whatsapp = $_POST['whatsapp'] ?? '';

if ($id === '' || $nome === '' || $usuario === '' || $senha === '' || $whatsapp === '') {
    echo json_encode(['success' => false, 'message' => 'Dados incompletos']);
    exit;
}

$DATABASE_URL = getenv("DATABASE_URL");
$db = parse_url($DATABASE_URL);

$pdo = new PDO(
    "pgsql:host={$db['host']};port=" . ($db['port'] ?? 5432) . ";dbname=" . ltrim($db['path'], '/'),
    $db['user'],
    $db['pass'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
);

// verifica usuário duplicado
$check = $pdo->prepare("
    SELECT id FROM admins
    WHERE usuario = :usuario AND id <> :id AND tipo = 'revendedor'
");
$check->execute([':usuario' => $usuario, ':id' => $id]);

if ($check->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Usuário já existe']);
    exit;
}

// 🔴 ATUALIZA COM SENHA EM TEXTO
$upd = $pdo->prepare("
    UPDATE admins
    SET nome = :nome,
        usuario = :usuario,
        senha = :senha,
        whatsapp = :whatsapp
    WHERE id = :id AND tipo = 'revendedor'
");

$upd->execute([
    ':nome'     => $nome,
    ':usuario'  => $usuario,
    ':senha'    => $senha,
    ':whatsapp' => $whatsapp,
    ':id'       => $id
]);

echo json_encode(['success' => true]);
exit;
