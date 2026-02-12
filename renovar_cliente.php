<?php
header("Content-Type: application/json");
ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'db.php';

try {

    $cliente_id = $_POST['cliente_id'] ?? null;
    $admin_id   = $_POST['admin_id'] ?? null;

    if (!$cliente_id || !$admin_id) {
        echo json_encode([
            "success" => false,
            "message" => "cliente_id e admin_id são obrigatórios"
        ]);
        exit;
    }

    /* =========================
       VERIFICA SE CLIENTE PERTENCE AO ADMIN
    ========================= */
    $check = $pdo->prepare("
        SELECT plano 
        FROM public.clientes
        WHERE id = :cliente_id
        AND admin_id = :admin_id
        LIMIT 1
    ");

    $check->execute([
        ':cliente_id' => $cliente_id,
        ':admin_id'   => $admin_id
    ]);

    $cliente = $check->fetch(PDO::FETCH_ASSOC);

    if (!$cliente) {
        echo json_encode([
            "success" => false,
            "message" => "Cliente não pertence ao admin"
        ]);
        exit;
    }

    $plano = $cliente['plano'] ?? '';

    if ($plano === '') {
        echo json_encode([
            "success" => false,
            "message" => "Plano do cliente não definido"
        ]);
        exit;
    }

    /* =========================
       CONVERTE PLANO EM DIAS
    ========================= */
    switch ($plano) {
        case 'mensal':
            $dias = 30;
            break;
        case 'trimestral':
            $dias = 90;
            break;
        case 'semestral':
            $dias = 180;
            break;
        case 'anual':
            $dias = 365;
            break;
        default:
            echo json_encode([
                "success" => false,
                "message" => "Plano inválido"
            ]);
            exit;
    }

    /* =========================
       BUSCA TODOS OS SISTEMAS DO CLIENTE
    ========================= */
    $stmt = $pdo->prepare("
        SELECT id, vencimento
        FROM public.sistemas
        WHERE cliente_id = :cliente_id
    ");

    $stmt->execute([
        ':cliente_id' => $cliente_id
    ]);

    $sistemas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$sistemas) {
        echo json_encode([
            "success" => false,
            "message" => "Cliente não possui sistemas"
        ]);
        exit;
    }

    $hoje = new DateTime();
    $hoje->setTime(0,0,0);

    foreach ($sistemas as $sistema) {

        $vencimentoAtual = new DateTime($sistema['vencimento']);
        $vencimentoAtual->setTime(0,0,0);

        if ($vencimentoAtual < $hoje) {
            // Vencido → soma a partir de hoje
            $novaData = clone $hoje;
        } else {
            // Ativo → soma a partir do vencimento atual
            $novaData = clone $vencimentoAtual;
        }

        $novaData->modify("+$dias days");

        $update = $pdo->prepare("
            UPDATE public.sistemas
            SET vencimento = :nova_data
            WHERE id = :id
        ");

        $update->execute([
            ':nova_data' => $novaData->format('Y-m-d'),
            ':id'        => $sistema['id']
        ]);
    }

    echo json_encode([
        "success" => true,
        "message" => "Sistemas renovados com sucesso"
    ]);

} catch (Throwable $e) {

    echo json_encode([
        "success" => false,
        "erro_real" => $e->getMessage(),
        "linha" => $e->getLine()
    ]);
}
