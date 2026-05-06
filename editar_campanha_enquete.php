<?php
header("Content-Type: application/json");

$id         = $_REQUEST['id'] ?? '';
$titulo     = $_REQUEST['titulo'] ?? '';
$descricao  = $_REQUEST['descricao'] ?? '';
$encerra_em = $_REQUEST['encerra_em'] ?? '';
$ativa      = $_REQUEST['ativa'] ?? '';

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
        $ativaBool = ($ativa === '1' || strtolower($ativa) === 'true' || strtolower($ativa) === 'sim');
        $campos[] = "ativa = :ativa";
        $params[':ativa'] = $ativaBool;
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
