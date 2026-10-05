<?php

header('Content-Type: application/json; charset=utf-8');

$id = filter_var(
    $_POST['id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$nome = trim((string) ($_POST['nome'] ?? ''));
$usuario = trim((string) ($_POST['usuario'] ?? ''));
$senha = (string) ($_POST['senha'] ?? '');
$whatsapp = trim((string) ($_POST['whatsapp'] ?? ''));

if ($id === false || $nome === '' || $usuario === '' || $whatsapp === '') {
    echo json_encode(['success' => false, 'message' => 'Dados incompletos']);
    exit;
}

if ($senha !== '') {
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
}

$DATABASE_URL = getenv('DATABASE_URL');
$db = parse_url($DATABASE_URL);

$pdo = new PDO(
    "pgsql:host={$db['host']};port=" . ($db['port'] ?? 5432) . ";dbname=" . ltrim($db['path'], '/'),
    $db['user'],
    $db['pass'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$check = $pdo->prepare(<<<'SQL'
    SELECT id
    FROM admins
    WHERE usuario = :usuario
      AND id <> :id
      AND tipo = 'revendedor'
    SQL);
$check->execute([':usuario' => $usuario, ':id' => $id]);

if ($check->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Usuário já existe']);
    exit;
}

if ($senha !== '') {
    $senhaHash = password_hash($senha, PASSWORD_DEFAULT);
    if ($senhaHash === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erro ao atualizar revendedor']);
        exit;
    }

    $upd = $pdo->prepare(<<<'SQL'
        UPDATE admins
        SET nome = :nome,
            usuario = :usuario,
            senha = :senha,
            senha_visivel = :senha_visivel,
            whatsapp = :whatsapp
        WHERE id = :id
          AND tipo = 'revendedor'
        SQL);
    $upd->execute([
        ':nome' => $nome,
        ':usuario' => $usuario,
        ':senha' => $senhaHash,
        ':senha_visivel' => $senha,
        ':whatsapp' => $whatsapp,
        ':id' => $id,
    ]);
} else {
    $upd = $pdo->prepare(<<<'SQL'
        UPDATE admins
        SET nome = :nome,
            usuario = :usuario,
            whatsapp = :whatsapp
        WHERE id = :id
          AND tipo = 'revendedor'
        SQL);
    $upd->execute([
        ':nome' => $nome,
        ':usuario' => $usuario,
        ':whatsapp' => $whatsapp,
        ':id' => $id,
    ]);
}

if ($upd->rowCount() === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Revendedor não encontrado']);
    exit;
}

echo json_encode(['success' => true]);
