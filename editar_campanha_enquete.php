<?php
header("Content-Type: application/json");

$id = $_REQUEST['id'] ?? '';

$titulo = $_REQUEST['titulo'] ?? '';
$descricao = $_REQUEST['descricao'] ?? '';
$encerra_em = $_REQUEST['encerra_em'] ?? '';
$ativa = $_REQUEST['ativa'] ?? '';
$modo_participacao = $_REQUEST['modo_participacao'] ?? '';

$tipo_classificacao = $_REQUEST['tipo_classificacao'] ?? '';
$minimo_acertos = $_REQUEST['minimo_acertos'] ?? '';

$resultado_titulo = $_REQUEST['resultado_titulo'] ?? '';
$resultado_descricao = $_REQUEST['resultado_descricao'] ?? '';
$resultado_link = $_REQUEST['resultado_link'] ?? '';
$resultado_publicado = $_REQUEST['resultado_publicado'] ?? '';

$video_sorteio_url = $_REQUEST['video_sorteio_url'] ?? '';

$exige_versao_minima = $_REQUEST['exige_versao_minima'] ?? '';
$version_code_minimo = $_REQUEST['version_code_minimo'] ?? '';
$mensagem_app_desatualizado = $_REQUEST['mensagem_app_desatualizado'] ?? '';

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

if (
    $tipo_classificacao !== '' &&
    $tipo_classificacao !== 'minimo_acertos' &&
    $tipo_classificacao !== 'todos'
) {
    echo json_encode([
        "success" => false,
        "message" => "tipo_classificacao inválido"
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

    if ($tipo_classificacao !== '') {
        $campos[] = "tipo_classificacao = :tipo_classificacao";
        $params[':tipo_classificacao'] = $tipo_classificacao;

        if ($tipo_classificacao === 'todos') {
            $campos[] = "minimo_acertos = :minimo_acertos";
            $params[':minimo_acertos'] = 0;
        }
    }

    if ($minimo_acertos !== '' && $tipo_classificacao !== 'todos') {
        $minimo = intval($minimo_acertos);

        if ($minimo < 1) {
            $minimo = 1;
        }

        $campos[] = "minimo_acertos = :minimo_acertos";
        $params[':minimo_acertos'] = $minimo;
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

    if ($video_sorteio_url !== '') {
        $campos[] = "video_sorteio_url = :video_sorteio_url";
        $params[':video_sorteio_url'] = $video_sorteio_url;
    }

    if ($exige_versao_minima !== '') {
        $exige_versao_minima_bool = (
            $exige_versao_minima === '1' ||
            strtolower($exige_versao_minima) === 'true' ||
            strtolower($exige_versao_minima) === 'sim'
        );

        $campos[] = "exige_versao_minima = :exige_versao_minima";
        $params[':exige_versao_minima'] = $exige_versao_minima_bool;

        if ($exige_versao_minima_bool) {
            $version_code_minimo_int = intval($version_code_minimo);

            if ($version_code_minimo_int < 1) {
                echo json_encode([
                    "success" => false,
                    "message" => "version_code_minimo é obrigatório quando exige_versao_minima estiver ativo"
                ]);
                exit;
            }

            $campos[] = "version_code_minimo = :version_code_minimo";
            $params[':version_code_minimo'] = $version_code_minimo_int;

            $campos[] = "mensagem_app_desatualizado = :mensagem_app_desatualizado";
            $params[':mensagem_app_desatualizado'] = $mensagem_app_desatualizado;
        } else {
            $campos[] = "version_code_minimo = NULL";
            $campos[] = "mensagem_app_desatualizado = ''";
        }
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
