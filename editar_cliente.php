<?php
header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'db.php';
require_once __DIR__ . '/client_credential_crypto.php';

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

    $nome           = $_POST['nome'] ?? '';
    $usuario        = $_POST['usuario'] ?? '';
    $senha          = $_POST['senha'] ?? '';
    $whatsapp       = $_POST['whatsapp'] ?? '';
    $m3u_url        = $_POST['m3u_url'] ?? '';
    $link_pagamento = $_POST['link_pagamento'] ?? '';
    $plano          = $_POST['plano'] ?? '';

    $check = $pdo->prepare("
        SELECT id FROM public.clientes
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

    $pdo->beginTransaction();

    if ($link_pagamento !== '') {

        $sql = "
            UPDATE public.clientes SET
                nome = :nome,
                usuario = :usuario,
                whatsapp = :whatsapp,
                m3u_url = :m3u_url,
                link_pagamento = :link_pagamento,
                plano = :plano
            WHERE id = :cliente_id
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':nome'           => $nome,
            ':usuario'        => $usuario,
            ':whatsapp'       => $whatsapp,
            ':m3u_url'        => $m3u_url,
            ':link_pagamento' => $link_pagamento,
            ':plano'          => $plano,
            ':cliente_id'     => $cliente_id
        ]);

    } else {

        $sql = "
            UPDATE public.clientes SET
                nome = :nome,
                usuario = :usuario,
                whatsapp = :whatsapp,
                m3u_url = :m3u_url,
                plano = :plano
            WHERE id = :cliente_id
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':nome'       => $nome,
            ':usuario'    => $usuario,
            ':whatsapp'   => $whatsapp,
            ':m3u_url'    => $m3u_url,
            ':plano'      => $plano,
            ':cliente_id' => $cliente_id
        ]);
    }

    if (!empty($senha)) {
        $senhaRecuperavel = criptografarSenhaRecuperavelCliente(
            $senha,
            (int) $cliente_id
        );

        $stmtSenha = $pdo->prepare("
            UPDATE public.clientes
            SET
                senha = :senha,
                senha_recuperavel = :senha_recuperavel
            WHERE id = :cliente_id
        ");
        $stmtSenha->execute([
            ':senha' => password_hash($senha, PASSWORD_DEFAULT),
            ':senha_recuperavel' => $senhaRecuperavel,
            ':cliente_id' => $cliente_id
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'CLIENTE ATUALIZADO'
    ]);

} catch (Throwable $e) {

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode([
        'success' => false,
        'message' => 'Erro ao atualizar cliente'
    ]);
}
