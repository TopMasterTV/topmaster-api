<?php
header("Content-Type: application/json");

$id = $_REQUEST['id'] ?? '';

$titulo = $_REQUEST['titulo'] ?? '';
$descricao = $_REQUEST['descricao'] ?? '';
$encerra_em = $_REQUEST['encerra_em'] ?? '';
$ativa = $_REQUEST['ativa'] ?? '';
$modo_participacao = $_REQUEST['modo_participacao'] ?? '';

$resultado_titulo = $_REQUEST['resultado_titulo'] ?? '';
$resultado_descricao = $_REQUEST['resultado_descricao'] ?? '';
$resultado_link = $_REQUEST['resultado_link'] ?? '';
$resultado_publicado = $_REQUEST['resultado_publicado'] ?? '';

if ($id === '') {
    echo json_encode([
        "success" => false,
        "message" => "id obrigatório"
    ]);
    exit;
}

if (
    $modo_participacao !== '' &&
    $modo_participacao !== 'codigo' &&
    $modo_participacao !== 'livre'
) {
    echo json_encode([
        "success" => false,
        "message" => "modo_participacao inválido"
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
    $params = [':id' => $id];

    if ($titulo !== '') {
        $campos[] = "titulo = :titulo";
        $params[':titulo'] = $titulo;
    }

    if ($descricao !== '') {
        $campos[] = "descricao = :descricao";
        $params[':descricao'] = $descricao;
    }

    if ($encerra_em !== '') {
        $campos[] = "encerra_em = :encerra_em";
        $params[':encerra_em'] = $encerra_em;
    }

    if ($ativa !== '') {
        $campos[] = "ativa = :ativa";
        $params[':ativa'] = (
            $ativa === '1' ||
            strtolower($ativa) === 'true' ||
            strtolower($ativa) === 'sim'
        );
    }

    if ($modo_participacao !== '') {
        $campos[] = "modo_participacao = :modo_participacao";
        $params[':modo_participacao'] = $modo_participacao;
    }

    if ($resultado_titulo !== '') {
        $campos[] = "resultado_titulo = :resultado_titulo";
        $params[':resultado_titulo'] = $resultado_titulo;
    }

    if ($resultado_descricao !== '') {
        $campos[] = "resultado_descricao = :resultado_descricao";
        $params[':resultado_descricao'] = $resultado_descricao;
    }

    if ($resultado_link !== '') {
        $campos[] = "resultado_link = :resultado_link";
        $params[':resultado_link'] = $resultado_link;
    }

    if ($resultado_publicado !== '') {
        $campos[] = "resultado_publicado = :resultado_publicado";
        $params[':resultado_publicado'] = (
            $resultado_publicado === '1' ||
            strtolower($resultado_publicado) === 'true' ||
            strtolower($resultado_publicado) === 'sim'
        );
    }

    if (count($campos) === 0) {
        echo json_encode([
            "success" => false,
            "message" => "Nenhum campo enviado para atualizar"
        ]);
        exit;
    }

    $sql = "
        UPDATE public.enquete_campanhas
        SET " . implode(", ", $campos) . "
        WHERE id = :id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode([
        "success" => true,
        "message" => "Campanha atualizada com sucesso"
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao editar campanha",
        "error" => $e->getMessage()
    ]);
}
