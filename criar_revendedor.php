<?php
header('Content-Type: application/json');

require_once _DIR_ . "/db.php";

$nome     = $_POST['nome']     ?? '';
$usuario  = $_POST['usuario']  ?? '';
$senha    = $_POST['senha']    ?? '';
$whatsapp = $_POST['whatsapp'] ?? '';

if ($nome === '' || $usuario === '' || $senha === '' || $whatsapp === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Todos os campos são obrigatórios'
    ]);
    exit;
}

try {
    // 🔎 verifica se usuário já existe
    $check = $pdo->prepare("SELECT id FROM revendedores WHERE usuario = :usuario");
    $check->execute([':usuario' => $usuario]);

    if ($check->fetch()) {
        echo json_encode([
            'success' => false,
            'message' => 'Usuário já cadastrado'
        ]);
        exit;
    }

    // ✅ INSERE REVENDEDOR (SEM HASH – compatível com Flutter)
    $stmt = $pdo->prepare("
        INSERT INTO revendedores (nome, usuario, senha, whatsapp)
        VALUES (:nome, :usuario, :senha, :whatsapp)
    ");

    $stmt->execute([
        ':nome'     => $nome,
        ':usuario'  => $usuario,
        ':senha'    => $senha,
        ':whatsapp' => $whatsapp
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Revendedor criado com sucesso'
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao criar revendedor',
        'erro' => $e->getMessage()
    ]);
}
