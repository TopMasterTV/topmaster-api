<?php
header("Content-Type: application/json");
date_default_timezone_set("America/Sao_Paulo");

$cliente_id = $_REQUEST['cliente_id'] ?? '';
$limit = intval($_REQUEST['limit'] ?? 500);

if ($limit < 1) $limit = 500;
if ($limit > 1000) $limit = 1000;

$DATABASE_URL = getenv("DATABASE_URL");

if (!$DATABASE_URL) {
    echo json_encode([
        "success" => false,
        "message" => "DATABASE_URL não definida"
    ]);
    exit;
}

function consultarXtream($apiUrl) {
    $ultimaResposta = false;
    $ultimoErro = '';
    $ultimoHttpCode = 0;

    for ($tentativa = 1; $tentativa <= 3; $tentativa++) {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => "TOPMASTER-TV",
        ]);

        $resposta = curl_exec($ch);
        $erro = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        $ultimaResposta = $resposta;
        $ultimoErro = $erro;
        $ultimoHttpCode = $httpCode;

        if ($resposta !== false && trim($resposta) !== '' && $httpCode !== 503) {
            return [
                "success" => true,
                "resposta" => $resposta,
                "http_code" => $httpCode,
                "curl_error" => $erro,
                "tentativas" => $tentativa
            ];
        }

        usleep(500000);
    }

    return [
        "success" => false,
        "resposta" => $ultimaResposta,
        "http_code" => $ultimoHttpCode,
        "curl_error" => $ultimoErro,
        "tentativas" => 3
    ];
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

    if ($cliente_id !== '') {
        $stmt = $pdo->prepare("
            SELECT id, cliente_id, nome_sistema, url, usuario, senha, vencimento
            FROM public.sistemas
            WHERE cliente_id = :cliente_id
            ORDER BY id ASC
        ");

        $stmt->execute([
            ':cliente_id' => $cliente_id
        ]);
    } else {
        $stmt = $pdo->prepare("
            SELECT id, cliente_id, nome_sistema, url, usuario, senha, vencimento
            FROM public.sistemas
            ORDER BY id ASC
            LIMIT :limit
        ");

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
    }

    $sistemas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = count($sistemas);
    $atualizados = 0;
    $sem_exp_date = 0;
    $falhas = 0;
    $detalhes = [];

    foreach ($sistemas as $sistema) {
        $id = $sistema['id'];
        $url = rtrim(trim($sistema['url'] ?? ''), '/');
        $usuario = trim($sistema['usuario'] ?? '');
        $senha = trim($sistema['senha'] ?? '');

        if ($url === '' || $usuario === '' || $senha === '') {
            $falhas++;
            $detalhes[] = [
                "id" => (int)$id,
                "cliente_id" => (int)$sistema['cliente_id'],
                "nome_sistema" => $sistema['nome_sistema'],
                "status" => "falha",
                "motivo" => "url, usuário ou senha vazio"
            ];
            continue;
        }

        $apiUrl = $url . "/player_api.php?username=" . urlencode($usuario) . "&password=" . urlencode($senha);

        $consulta = consultarXtream($apiUrl);

        if (!$consulta["success"] || $consulta["resposta"] === false || trim($consulta["resposta"]) === '') {
            $falhas++;
            $detalhes[] = [
                "id" => (int)$id,
                "cliente_id" => (int)$sistema['cliente_id'],
                "nome_sistema" => $sistema['nome_sistema'],
                "status" => "falha",
                "motivo" => "sem resposta da Xtream",
                "http_code" => $consulta["http_code"],
                "curl_error" => $consulta["curl_error"],
                "tentativas" => $consulta["tentativas"]
            ];
            continue;
        }

        $resposta = $consulta["resposta"];
        $json = json_decode($resposta, true);

        if (!is_array($json) || !isset($json['user_info'])) {
            $falhas++;
            $detalhes[] = [
                "id" => (int)$id,
                "cliente_id" => (int)$sistema['cliente_id'],
                "nome_sistema" => $sistema['nome_sistema'],
                "status" => "falha",
                "motivo" => "resposta inválida da Xtream",
                "http_code" => $consulta["http_code"],
                "tentativas" => $consulta["tentativas"],
                "resposta_inicio" => substr($resposta, 0, 120)
            ];
            continue;
        }

        $userInfo = $json['user_info'];

        $statusXtream = $userInfo['status'] ?? '';
        $expDateRaw = $userInfo['exp_date'] ?? '';
        $expDate = intval($expDateRaw);

        if ($expDate <= 0) {
            $sem_exp_date++;
            $detalhes[] = [
                "id" => (int)$id,
                "cliente_id" => (int)$sistema['cliente_id'],
                "nome_sistema" => $sistema['nome_sistema'],
                "status" => $statusXtream,
                "motivo" => "sem exp_date",
                "exp_date_raw" => $expDateRaw,
                "tentativas" => $consulta["tentativas"]
            ];
            continue;
        }

        $novoVencimento = date("Y-m-d", $expDate);

        $stmtUpdate = $pdo->prepare("
            UPDATE public.sistemas
            SET vencimento = :vencimento
            WHERE id = :id
        ");

        $stmtUpdate->execute([
            ':vencimento' => $novoVencimento,
            ':id' => $id
        ]);

        $atualizados++;

        $detalhes[] = [
            "id" => (int)$id,
            "cliente_id" => (int)$sistema['cliente_id'],
            "nome_sistema" => $sistema['nome_sistema'],
            "status" => "atualizado",
            "status_xtream" => $statusXtream,
            "exp_date_raw" => $expDateRaw,
            "vencimento_antigo" => $sistema['vencimento'],
            "vencimento_novo" => $novoVencimento,
            "tentativas" => $consulta["tentativas"]
        ];
    }

    $pdo->exec("
        UPDATE public.clientes c
        SET ativo = EXISTS (
            SELECT 1
            FROM public.sistemas s
            WHERE s.cliente_id = c.id
            AND s.vencimento >= CURRENT_DATE
        )
    ");

    echo json_encode([
        "success" => true,
        "message" => "Atualização concluída",
        "cliente_id" => $cliente_id !== '' ? intval($cliente_id) : null,
        "total_sistemas" => $total,
        "atualizados" => $atualizados,
        "sem_exp_date" => $sem_exp_date,
        "falhas" => $falhas,
        "clientes_sincronizados" => true,
        "detalhes" => $detalhes
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao atualizar sistemas",
        "error" => $e->getMessage()
    ]);
}
