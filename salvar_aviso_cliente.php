<?php
header("Content-Type: application/json");

$titulo = $_REQUEST['titulo'] ?? '';
$mensagem = $_REQUEST['mensagem'] ?? '';
$link_url = $_REQUEST['link_url'] ?? '';
$link_texto = $_REQUEST['link_texto'] ?? '';
$ativo = $_REQUEST['ativo'] ?? '1';
$mostrar_uma_vez = $_REQUEST['mostrar_uma_vez'] ?? '0';
$destino = $_REQUEST['destino'] ?? 'todos';

if ($titulo === '' || $mensagem === '') {
    echo json_encode([
        "success" => false,
        "message" => "titulo e mensagem são obrigatórios"
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

    $ativo_bool = (
        $ativo === '1' ||
        strtolower($ativo) === 'true' ||
        strtolower($ativo) === 'sim'
    );

    $mostrar_uma_vez_bool = (
        $mostrar_uma_vez === '1' ||
        strtolower($mostrar_uma_vez) === 'true' ||
        strtolower($mostrar_uma_vez) === 'sim'
    );

    $destinos_validos = ['todos', 'celular_cliente', 'tv_cliente', 'notebook_cliente'];

    if (!in_array($destino, $destinos_validos)) {
        $destino = 'todos';
    }

    $pdo->beginTransaction();

    if ($ativo_bool) {
        $stmtDesativar = $pdo->prepare("
            UPDATE public.avisos_cliente
            SET ativo = false,
                atualizado_em = NOW()
            WHERE ativo = true
            AND destino = :destino
        ");

        $stmtDesativar->execute([
            ':destino' => $destino
        ]);
    }

    $stmt = $pdo->prepare("
        INSERT INTO public.avisos_cliente
        (
            titulo,
            mensagem,
            link_url,
            link_texto,
            ativo,
            mostrar_uma_vez,
            destino,
            atualizado_em
        )
        VALUES
        (
            :titulo,
            :mensagem,
            :link_url,
            :link_texto,
            :ativo,
            :mostrar_uma_vez,
            :destino,
            NOW()
        )
        RETURNING id
    ");

    $stmt->execute([
        ':titulo' => $titulo,
        ':mensagem' => $mensagem,
        ':link_url' => $link_url,
        ':link_texto' => $link_texto,
        ':ativo' => $ativo_bool,
        ':mostrar_uma_vez' => $mostrar_uma_vez_bool,
        ':destino' => $destino
    ]);

    $id = $stmt->fetchColumn();

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Aviso salvo com sucesso",
        "id" => $id
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode([
        "success" => false,
        "message" => "Erro ao salvar aviso",
        "error" => $e->getMessage()
    ]);
}
