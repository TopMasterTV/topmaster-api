<?php
header("Content-Type: application/json; charset=utf-8");

function responder($success, $message, $extra = []) {
    echo json_encode(array_merge([
        "success" => $success,
        "message" => $message
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function lerBooleano($valor, $padrao = false) {
    if ($valor === null || $valor === '') {
        return $padrao;
    }

    if (is_bool($valor)) {
        return $valor;
    }

    if (is_numeric($valor)) {
        return ((int)$valor) !== 0;
    }

    $texto = strtolower(trim((string)$valor));

    if (in_array($texto, ['1', 'true', 'sim', 's', 'yes', 'y', 'on'], true)) {
        return true;
    }

    if (in_array($texto, ['0', 'false', 'nao', 'não', 'n', 'no', 'off'], true)) {
        return false;
    }

    return $padrao;
}

$titulo = trim($_REQUEST['titulo'] ?? '');
$mensagem = trim($_REQUEST['mensagem'] ?? '');
$link_url = trim($_REQUEST['link_url'] ?? '');
$link_texto = trim($_REQUEST['link_texto'] ?? '');
$destino = trim($_REQUEST['destino'] ?? 'todos');

$ativo_bool = lerBooleano($_REQUEST['ativo'] ?? 'true', true);
$mostrar_uma_vez_bool = lerBooleano($_REQUEST['mostrar_uma_vez'] ?? 'false', false);

$destinos_validos = [
    'todos',
    'celular_cliente',
    'tv_cliente',
    'notebook_cliente'
];

if (!in_array($destino, $destinos_validos, true)) {
    $destino = 'todos';
}

/*
    Se for salvar aviso ativo, título e mensagem são obrigatórios.
    Se for desativar aviso, não precisa exigir título/mensagem,
    porque a intenção é apenas parar de exibir o aviso.
*/
if ($ativo_bool && ($titulo === '' || $mensagem === '')) {
    responder(false, "Título e mensagem são obrigatórios");
}

$DATABASE_URL = getenv("DATABASE_URL");

if (!$DATABASE_URL) {
    responder(false, "DATABASE_URL não definida");
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

    /*
        Sempre desativa aviso ativo anterior para o destino informado.
        Isso é necessário tanto ao criar um novo aviso ativo quanto ao desativar.
    */
    $stmtDesativar = $pdo->prepare("
    UPDATE public.avisos_cliente
    SET ativo = false,
        atualizado_em = NOW()
    WHERE ativo = true
");

$stmtDesativar->execute();

    /*
        Se veio ativo=false, a intenção é só desativar.
        Não precisa inserir novo aviso inativo.
    */
    if (!$ativo_bool) {
        $pdo->commit();

        responder(true, "Aviso desativado com sucesso", [
            "destino" => $destino
        ]);
    }

    /*
        Insere novo aviso ativo.
        Os booleanos são enviados como texto 'true'/'false'
        e convertidos explicitamente para boolean no PostgreSQL.
        Isso evita o erro de string vazia no campo booleano.
    */
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
            CAST(:ativo AS boolean),
            CAST(:mostrar_uma_vez AS boolean),
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
        ':ativo' => $ativo_bool ? 'true' : 'false',
        ':mostrar_uma_vez' => $mostrar_uma_vez_bool ? 'true' : 'false',
        ':destino' => $destino
    ]);

    $id = $stmt->fetchColumn();

    $pdo->commit();

    responder(true, "Aviso salvo com sucesso", [
        "id" => $id,
        "destino" => $destino
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    responder(false, "Erro ao salvar aviso", [
        "error" => $e->getMessage()
    ]);
}
