<?php
header("Content-Type: application/json");

$campanha_id = $_REQUEST['campanha_id'] ?? '';
$pergunta = trim($_REQUEST['pergunta'] ?? '');
$subtitulo = trim($_REQUEST['subtitulo'] ?? '');
$max_opcoes = $_REQUEST['max_opcoes'] ?? '1';
$opcoes_raw = $_REQUEST['opcoes'] ?? '';

if ($campanha_id === '' || $pergunta === '' || $opcoes_raw === '') {
    echo json_encode([
        "success" => false,
        "message" => "campanha_id, pergunta e opcoes são obrigatórios"
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

    $opcoes_raw = trim($opcoes_raw);
    $opcoes_raw = str_replace(["[", "]", "\""], "", $opcoes_raw);
    $partes = preg_split('/[\r\n,]+/', $opcoes_raw);

    $opcoes = [];

    foreach ($partes as $opcao) {
        $opcao = trim($opcao);
        if ($opcao !== '') {
            $opcoes[] = $opcao;
        }
    }

    if (count($opcoes) === 0) {
        echo json_encode([
            "success" => false,
            "message" => "Nenhuma opção válida enviada"
        ]);
        exit;
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO public.enquetes
        (
            campanha_id,
            pergunta,
            subtitulo,
            max_opcoes,
            ativa
        )
        VALUES
        (
            :campanha_id,
            :pergunta,
            :subtitulo,
            :max_opcoes,
            true
        )
        RETURNING id
    ");

    $stmt->execute([
        ':campanha_id' => $campanha_id,
        ':pergunta' => $pergunta,
        ':subtitulo' => $subtitulo,
        ':max_opcoes' => intval($max_opcoes)
    ]);

    $enquete_id = $stmt->fetchColumn();

    $stmtOpcao = $pdo->prepare("
        INSERT INTO public.enquete_opcoes
        (
            enquete_id,
            texto
        )
        VALUES
        (
            :enquete_id,
            :texto
        )
    ");

    foreach ($opcoes as $opcao) {
        $stmtOpcao->execute([
            ':enquete_id' => $enquete_id,
            ':texto' => $opcao
        ]);
    }

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Enquete criada com sucesso",
        "enquete_id" => $enquete_id
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode([
        "success" => false,
        "message" => "Erro ao criar enquete",
        "error" => $e->getMessage()
    ]);
}
