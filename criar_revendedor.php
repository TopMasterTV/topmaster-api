<?php

header('Content-Type: application/json; charset=utf-8');

$DATABASE_URL = getenv('DATABASE_URL');
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

$nome = trim((string) ($_REQUEST['nome'] ?? ''));
$usuario = trim((string) ($_REQUEST['usuario'] ?? ''));
$senha = (string) ($_REQUEST['senha'] ?? '');
$whatsapp = trim((string) ($_REQUEST['whatsapp'] ?? ''));

if ($nome === '' || $usuario === '' || $senha === '' || $whatsapp === '') {
    echo json_encode(['success' => false, 'message' => 'Todos os campos são obrigatórios']);
    exit;
}

$tamanhoSenha = function_exists('mb_strlen')
    ? mb_strlen($senha, 'UTF-8')
    : strlen($senha);

if ($tamanhoSenha > 50) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'code' => 'PASSWORD_TOO_LONG',
        'message' => 'A senha deve ter no máximo 50 caracteres',
    ]);
    exit;
}

$senhaHash = password_hash($senha, PASSWORD_DEFAULT);
if ($senhaHash === false) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao criar revendedor',
        'erro' => 'CRIAR_REVENDEDOR_INTERNAL_ERROR',
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare(<<<'SQL'
        INSERT INTO admins (
            nome,
            usuario,
            senha,
            senha_visivel,
            whatsapp,
            tipo
        ) VALUES (
            :nome,
            :usuario,
            :senha,
            :senha_visivel,
            :whatsapp,
            'revendedor'
        )
        SQL);

    $stmt->execute([
        ':nome' => $nome,
        ':usuario' => $usuario,
        ':senha' => $senhaHash,
        ':senha_visivel' => $senha,
        ':whatsapp' => $whatsapp,
    ]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao criar revendedor',
        'erro' => 'CRIAR_REVENDEDOR_INTERNAL_ERROR',
    ]);
}
