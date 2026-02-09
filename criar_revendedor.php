<?php
header('Content-Type: application/json');

$DATABASE_URL = getenv("DATABASE_URL");
if (!$DATABASE_URL) {
    echo json_encode(['success' => false, 'message' => 'DATABASE_URL não definida']);
    exit;
}

$db = parse_url($DATABASE_URL);

$pdo = new PDO(
    "pgsql:host={$db['host']};port=" . ($db['port'] ?? 5432) . ";dbname=" . ltrim($db['path'], '/'),
    $db['user'],
    $db['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$nome     = $_REQUEST['nome']     ?? '';
$usuario  = $_REQUEST['usuario']  ?? '';
$senha    = $_REQUEST['senha']    ?? '';
$whatsapp = $_REQUEST['whatsapp'] ?? '';

if ($nome === '' || $usuario === '' || $senha === '' || $whatsapp === '') {
    echo json_encode(['success' => false, 'message' => 'Todos os campos são obrigatórios']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO admins (nome, usuario, senha, whatsapp, tipo)
        VALUES (:nome, :usuario, :senha, :whatsapp, 'revendedor')
    ");

    $stmt->execute([
        ':nome'     => $nome,
        ':usuario'  => $usuario,
        ':senha'    => $senha,      // 🔴 TEXTO PURO
        ':whatsapp' => $whatsapp
    ]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao criar revendedor',
        'erro' => $e->getMessage()
    ]);
}
