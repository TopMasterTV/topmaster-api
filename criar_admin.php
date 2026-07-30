<?php
header('Content-Type: application/json');

require_once __DIR__ . "/db.php";

$nome    = $_POST['nome']    ?? '';
$usuario = $_POST['usuario'] ?? '';
$senha   = $_POST['senha']   ?? '';

if ($nome === '' || $usuario === '' || $senha === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Todos os campos são obrigatórios'
    ]);
    exit;
}

// força tipo funcionario
$tipo = 'funcionario';

// hash da senha
$senha_hash = password_hash($senha, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare("
        INSERT INTO admins (nome, usuario, senha, tipo)
        VALUES (:nome, :usuario, :senha, :tipo)
    ");

    $stmt->execute([
        ':nome'    => $nome,
        ':usuario' => $usuario,
        ':senha'   => $senha_hash,
        ':tipo'    => $tipo
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Admin funcionário criado com sucesso'
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao criar admin',
        'erro' => 'CRIAR_ADMIN_INTERNAL_ERROR'
    ]);
}
