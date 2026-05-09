<?php
header("Content-Type: application/json");
date_default_timezone_set("America/Sao_Paulo");

$enquete_id = $_REQUEST['enquete_id'] ?? '';
$opcoes_corretas_ids = $_REQUEST['opcoes_corretas_ids'] ?? '';

if ($enquete_id === '' || $opcoes_corretas_ids === '') {
    echo json_encode([
        "success" => false,
        "message" => "enquete_id e opcoes_corretas_ids são obrigatórios"
    ]);
    exit;
}

$ids = array_filter(array_map('trim', explode(',', $opcoes_corretas_ids)));

if (count($ids) === 0) {
    echo json_encode([
        "success" => false,
        "message" => "Nenhuma opção correta enviada"
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

    $pdo->beginTransaction();

    // limpa resultados antigos
    $stmtDelete = $pdo->prepare("
        DELETE FROM public.enquete_opcoes_corretas
        WHERE enquete_id = :enquete_id
    ");

    $stmtDelete->execute([
        ':enquete_id' => $enquete_id
    ]);

    $opcoesSalvas = [];

    foreach ($ids as $opcao_id) {

        // valida se opção pertence à enquete
        $stmtValidar = $pdo->prepare("
            SELECT id
            FROM public.enquete_opcoes
            WHERE id = :opcao_id
            AND enquete_id = :enquete_id
            LIMIT 1
        ");

        $stmtValidar->execute([
            ':opcao_id' => $opcao_id,
            ':enquete_id' => $enquete_id
        ]);

        if (!$stmtValidar->fetch()) {
            $pdo->rollBack();

            echo json_encode([
                "success" => false,
                "message" => "Opção inválida encontrada",
                "opcao_id" => $opcao_id
            ]);
            exit;
        }

        // salva opção correta
        $stmtInsert = $pdo->prepare("
            INSERT INTO public.enquete_opcoes_corretas
            (
                enquete_id,
                opcao_id
            )
            VALUES
            (
                :enquete_id,
                :opcao_id
            )
        ");

        $stmtInsert->execute([
            ':enquete_id' => $enquete_id,
            ':opcao_id' => $opcao_id
        ]);

        $opcoesSalvas[] = (int)$opcao_id;
    }

    // marca enquete como finalizada
    $stmtUpdate = $pdo->prepare("
        UPDATE public.enquetes
        SET resultado_definido = TRUE
        WHERE id = :enquete_id
    ");

    $stmtUpdate->execute([
        ':enquete_id' => $enquete_id
    ]);

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Resultados definidos com sucesso",
        "enquete_id" => (int)$enquete_id,
        "opcoes_corretas" => $opcoesSalvas
    ]);

} catch (Exception $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode([
        "success" => false,
        "message" => "Erro ao definir resultados",
        "error" => $e->getMessage()
    ]);
}
