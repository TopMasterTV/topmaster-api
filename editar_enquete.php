<?php
header("Content-Type: application/json");

$id          = $_REQUEST['id'] ?? '';
$pergunta    = trim($_REQUEST['pergunta'] ?? '');
$subtitulo   = trim($_REQUEST['subtitulo'] ?? '');
$max_opcoes  = $_REQUEST['max_opcoes'] ?? '';
$ativa       = $_REQUEST['ativa'] ?? '';

if ($id === '') {
    echo json_encode([
        "success" => false,
        "message" => "id obrigatório"
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

    $campos = [];
    $params = [
        ':id' => $id
    ];

    if ($pergunta !== '') {
        $campos[] = "pergunta = :pergunta";
        $params[':pergunta'] = $pergunta;
    }

    // permite salvar subtítulo vazio também
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

    if (count($campos) === 0) {
        echo json_encode([
            "success" => false,
            "message" => "Nenhum campo enviado"
        ]);
        exit;
    }

    $sql = "
        UPDATE public.enquetes
        SET " . implode(", ", $campos) . "
        WHERE id = :id
        RETURNING id, pergunta, subtitulo, max_opcoes, ativa
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $enquete = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "message" => "Enquete atualizada com sucesso",
        "enquete" => $enquete
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao editar enquete",
        "error" => $e->getMessage()
    ]);
}
