<?php
header("Content-Type: application/json");

$campanha_id = $_REQUEST['campanha_id'] ?? '';
$pergunta    = $_REQUEST['pergunta'] ?? '';
$max_opcoes  = $_REQUEST['max_opcoes'] ?? 1;
$opcoes      = $_REQUEST['opcoes'] ?? '';

if ($campanha_id === '' || $pergunta === '' || $opcoes === '') {
    echo json_encode([
        "success" => false,
        "message" => "campanha_id, pergunta e opções são obrigatórios"
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

    $stmt = $pdo->prepare("
        INSERT INTO public.enquetes
        (campanha_id, pergunta, max_opcoes)
        VALUES
        (:campanha_id, :pergunta, :max_opcoes)
        RETURNING id
    ");

    $stmt->execute([
        ':campanha_id' => $campanha_id,
        ':pergunta' => $pergunta,
        ':max_opcoes' => $max_opcoes
    ]);

    $enquete_id = $stmt->fetchColumn();

    $listaOpcoes = explode('|', $opcoes);

    foreach ($listaOpcoes as $texto) {
        $texto = trim($texto);

        if ($texto === '') continue;

        $stmtOpcao = $pdo->prepare("
            INSERT INTO public.enquete_opcoes
            (enquete_id, texto)
            VALUES
            (:enquete_id, :texto)
        ");

        $stmtOpcao->execute([
            ':enquete_id' => $enquete_id,
            ':texto' => $texto
        ]);
    }

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Enquete criada com sucesso",
        "campanha_id" => $campanha_id,
        "enquete_id" => $enquete_id
    ]);

} catch (Exception $e) {
    if (isset($pdo)) {
        $pdo->rollBack();
    }

    echo json_encode([
        "success" => false,
        "message" => "Erro ao criar enquete",
        "error" => $e->getMessage()
    ]);
}
