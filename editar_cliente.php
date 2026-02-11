<?php
header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'db.php';

try {

    $cliente_id = $_POST['cliente_id'] ?? null;
    $admin_id   = $_POST['admin_id'] ?? null;

    if (!$cliente_id || !$admin_id) {
        echo json_encode([
            'success' => false,
            'message' => 'cliente_id ou admin_id ausente'
        ]);
        exit;
    }

    $nome     = $_POST['nome'] ?? '';
    $usuario  = $_POST['usuario'] ?? '';
    $senha    = $_POST['senha'] ?? '';
    $whatsapp = $_POST['whatsapp'] ?? '';
    $m3u_url  = $_POST['m3u_url'] ?? '';

    // Verifica se pertence ao admin
    $check = $pdo->prepare("
        SELECT id FROM clientes
        WHERE id = :cliente_id
        AND admin_id = :admin_id
    ");
    $check->execute([
        ':cliente_id' => $cliente_id,
        ':admin_id'   => $admin_id
    ]);

    if ($check->rowCount() === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Cliente não pertence ao admin'
        ]);
        exit;
    }

    // UPDATE PRINCIPAL
    $sql = "
        UPDATE clientes SET
            nome = :nome,
            usuario = :usuario,
            whatsapp = :whatsapp,
            m3u_url = :m3u_url
        WHERE id = :cliente_id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':nome'       => $nome,
        ':usuario'    => $usuario,
        ':whatsapp'   => $whatsapp,
        ':m3u_url'    => $m3u_url,
        ':cliente_id' => $cliente_id
    ]);

    // Atualiza senha se enviada
    if (!empty($senha)) {
        $stmtSenha = $pdo->prepare("
            UPDATE clientes
            SET senha = :senha
            WHERE id = :cliente_id
        ");
        $stmtSenha->execute([
            ':senha' => password_hash($senha, PASSWORD_DEFAULT),
            ':cliente_id' => $cliente_id
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'OK ATUALIZADO'
    ]);

} catch (Throwable $e) {

    echo json_encode([
        'success' => false,
        'erro_real' => $e->getMessage(),
        'linha' => $e->getLine()
    ]);

}
