<?php
header("Content-Type: application/json; charset=utf-8");
date_default_timezone_set("America/Sao_Paulo");

$id          = $_REQUEST['id'] ?? '';
$pergunta    = trim($_REQUEST['pergunta'] ?? '');
$subtitulo   = trim($_REQUEST['subtitulo'] ?? '');
$max_opcoes  = $_REQUEST['max_opcoes'] ?? '';
$ativa       = $_REQUEST['ativa'] ?? '';
$opcoes_json = $_REQUEST['opcoes_json'] ?? '';

if ($id === '') {
    echo json_encode(["success" => false, "message" => "id obrigatório"]);
    exit;
}

$DATABASE_URL = getenv("DATABASE_URL");

if (!$DATABASE_URL) {
    echo json_encode(["success" => false, "message" => "DATABASE_URL não definida"]);
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

    $campos = [];
    $params = [':id' => $id];

    if ($pergunta !== '') {
        $campos[] = "pergunta = :pergunta";
        $params[':pergunta'] = $pergunta;
    }

    if (array_key_exists('subtitulo', $_REQUEST)) {
        $campos[] = "subtitulo = :subtitulo";
        $params[':subtitulo'] = $subtitulo;
    }

    if ($max_opcoes !== '') {
        $campos[] = "max_opcoes = :max_opcoes";
        $params[':max_opcoes'] = intval($max_opcoes);
    }

    if ($ativa !== '') {
        $ativaBool = (
            $ativa === '1' ||
            strtolower($ativa) === 'true' ||
            strtolower($ativa) === 'sim'
        );

        $campos[] = "ativa = :ativa";
        $params[':ativa'] = $ativaBool;
    }

    $enquete = null;

    if (count($campos) > 0) {
        $sql = "
            UPDATE public.enquetes
            SET " . implode(", ", $campos) . "
            WHERE id = :id
            RETURNING id, pergunta, subtitulo, max_opcoes, ativa
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $enquete = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    $opcoesAtualizadas = 0;

    if ($opcoes_json !== '') {
        $opcoes = json_decode($opcoes_json, true);

        if (!is_array($opcoes)) {
            $pdo->rollBack();
            echo json_encode([
                "success" => false,
                "message" => "opcoes_json inválido"
            ]);
            exit;
        }

        $stmtOpcao = $pdo->prepare("
            UPDATE public.enquete_opcoes
            SET texto = :texto
            WHERE id = :opcao_id
            AND enquete_id = :enquete_id
        ");

        foreach ($opcoes as $opcao) {
            $opcaoId = $opcao['id'] ?? '';
            $enqueteIdOpcao = $opcao['enquete_id'] ?? $id;
            $texto = trim($opcao['texto'] ?? '');

            if ($opcaoId === '' || $texto === '') {
                continue;
            }

            $stmtOpcao->execute([
                ':texto' => $texto,
                ':opcao_id' => $opcaoId,
                ':enquete_id' => $enqueteIdOpcao
            ]);

            $opcoesAtualizadas += $stmtOpcao->rowCount();
        }
    }

    $stmtBusca = $pdo->prepare("
        SELECT id, pergunta, subtitulo, max_opcoes, ativa
        FROM public.enquetes
        WHERE id = :id
    ");
    $stmtBusca->execute([':id' => $id]);
    $enqueteFinal = $stmtBusca->fetch(PDO::FETCH_ASSOC);

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Enquete atualizada com sucesso",
        "enquete" => $enqueteFinal,
        "opcoes_atualizadas" => $opcoesAtualizadas
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode([
        "success" => false,
        "message" => "Erro ao editar enquete",
        "error" => $e->getMessage()
    ]);
}
?>
