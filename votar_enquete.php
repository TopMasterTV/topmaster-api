<?php
header("Content-Type: application/json");

$campanha_id = $_REQUEST['campanha_id'] ?? '';
$cliente_id  = $_REQUEST['cliente_id'] ?? '';
$codigo      = trim($_REQUEST['codigo'] ?? '');
$respostas_raw = $_REQUEST['respostas'] ?? '';

if ($campanha_id === '' || $cliente_id === '' || $codigo === '' || $respostas_raw === '') {
    echo json_encode([
        "success" => false,
        "message" => "campanha_id, cliente_id, codigo e respostas são obrigatórios"
    ]);
    exit;
}

$DATABASE_URL = getenv("DATABASE_URL");

if (!$DATABASE_URL) {
    echo json_encode([
        "success" => false,
        "message" => "DATABASE_URL não definida"
    ]);
    exit;
}

$db = parse_url($DATABASE_URL);

try {
    $pdo = new PDO(
        "pgsql:host={$db['host']};port=" . ($db['port'] ?? 5432) .
        ";dbname=" . ltrim($db['path'], '/') .
        ";sslmode=require",
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmtCampanha = $pdo->prepare("
        SELECT id, ativa, encerra_em
        FROM public.enquete_campanhas
        WHERE id = :campanha_id
        LIMIT 1
    ");

    $stmtCampanha->execute([
        ':campanha_id' => $campanha_id
    ]);

    $campanha = $stmtCampanha->fetch(PDO::FETCH_ASSOC);

    if (!$campanha) {
        echo json_encode([
            "success" => false,
            "message" => "Campanha não encontrada"
        ]);
        exit;
    }

    if ($campanha['ativa'] !== true && $campanha['ativa'] !== 't' && $campanha['ativa'] !== '1') {
        echo json_encode([
            "success" => false,
            "message" => "Campanha inativa"
        ]);
        exit;
    }

    $agora = new DateTime();
    $encerra = new DateTime($campanha['encerra_em']);

    if ($agora > $encerra) {
        echo json_encode([
            "success" => false,
            "message" => "Enquete encerrada. Aguarde o resultado."
        ]);
        exit;
    }

    $checkCodigo = $pdo->prepare("
        SELECT id
        FROM public.enquete_participacoes
        WHERE campanha_id = :campanha_id
        AND cliente_id = :cliente_id
        AND codigo = :codigo
        LIMIT 1
    ");

    $checkCodigo->execute([
        ':campanha_id' => $campanha_id,
        ':cliente_id' => $cliente_id,
        ':codigo' => $codigo
    ]);

    if ($checkCodigo->fetch()) {
        echo json_encode([
            "success" => false,
            "message" => "Este código já foi usado por este cliente nesta campanha"
        ]);
        exit;
    }

    $blocos = array_filter(array_map('trim', explode('|', $respostas_raw)));

    if (count($blocos) === 0) {
        echo json_encode([
            "success" => false,
            "message" => "Nenhuma resposta válida enviada"
        ]);
        exit;
    }

    $pdo->beginTransaction();

    $stmtParticipacao = $pdo->prepare("
        INSERT INTO public.enquete_participacoes
        (campanha_id, cliente_id, codigo)
        VALUES
        (:campanha_id, :cliente_id, :codigo)
        RETURNING id
    ");

    $stmtParticipacao->execute([
        ':campanha_id' => $campanha_id,
        ':cliente_id' => $cliente_id,
        ':codigo' => $codigo
    ]);

    $participacao_id = $stmtParticipacao->fetchColumn();

    foreach ($blocos as $bloco) {
        $partes = explode(':', $bloco);

        if (count($partes) !== 2) {
            throw new Exception("Formato inválido em respostas");
        }

        $enquete_id = trim($partes[0]);
        $opcoes = array_filter(array_map('trim', explode(',', $partes[1])));

        if ($enquete_id === '' || count($opcoes) === 0) {
            continue;
        }

        $stmtEnquete = $pdo->prepare("
            SELECT id, max_opcoes
            FROM public.enquetes
            WHERE id = :enquete_id
            AND campanha_id = :campanha_id
            AND ativa = true
            LIMIT 1
        ");

        $stmtEnquete->execute([
            ':enquete_id' => $enquete_id,
            ':campanha_id' => $campanha_id
        ]);

        $enquete = $stmtEnquete->fetch(PDO::FETCH_ASSOC);

        if (!$enquete) {
            throw new Exception("Enquete inválida ou não pertence a esta campanha");
        }

        $max_opcoes = intval($enquete['max_opcoes']);

        if (count($opcoes) > $max_opcoes) {
            throw new Exception("A enquete {$enquete_id} permite no máximo {$max_opcoes} opção/opções");
        }

        foreach ($opcoes as $opcao_id) {
            $stmtOpcao = $pdo->prepare("
                SELECT id
                FROM public.enquete_opcoes
                WHERE id = :opcao_id
                AND enquete_id = :enquete_id
                LIMIT 1
            ");

            $stmtOpcao->execute([
                ':opcao_id' => $opcao_id,
                ':enquete_id' => $enquete_id
            ]);

            if (!$stmtOpcao->fetch()) {
                throw new Exception("Opção inválida para a enquete {$enquete_id}");
            }

            $stmtResposta = $pdo->prepare("
                INSERT INTO public.enquete_respostas
                (participacao_id, enquete_id, opcao_id, cliente_id)
                VALUES
                (:participacao_id, :enquete_id, :opcao_id, :cliente_id)
            ");

            $stmtResposta->execute([
                ':participacao_id' => $participacao_id,
                ':enquete_id' => $enquete_id,
                ':opcao_id' => $opcao_id,
                ':cliente_id' => $cliente_id
            ]);
        }
    }

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Participação registrada com sucesso",
        "participacao_id" => $participacao_id
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode([
        "success" => false,
        "message" => "Erro ao registrar participação",
        "error" => $e->getMessage()
    ]);
}
