<?php
header("Content-Type: application/json");

$cliente_id = $_POST['cliente_id'] ?? '';

if ($cliente_id === '') {
    echo json_encode([
        "success" => false,
        "message" => "cliente_id obrigatório"
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

    $stmt = $pdo->prepare("
        SELECT
            s.id,
            s.cliente_id,
            COALESCE(m.nome, '') AS nome_sistema,
            COALESCE(m.url_padrao, '') AS url,
            COALESCE(s.usuario, '') AS usuario,
            COALESCE(s.senha, '') AS senha,
            s.vencimento,
            COALESCE(s.m3u_url, '') AS m3u_url
        FROM sistemas s
        LEFT JOIN modelos_sistemas m 
            ON s.modelo_id = m.id
        WHERE s.cliente_id = :cliente_id
        ORDER BY s.id DESC
    ");

    $stmt->execute([
        ':cliente_id' => $cliente_id
    ]);

    $sistemas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 🔥 NOVO BLOCO (buscar status real)
    foreach ($sistemas as &$sistema) {

        $url = rtrim($sistema['url'], '/');
        $usuario = $sistema['usuario'];
        $senha = $sistema['senha'];

        if (!empty($url) && !empty($usuario) && !empty($senha)) {

            $apiUrl = "$url/player_api.php?username=$usuario&password=$senha";

            try {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $apiUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);

                $response = curl_exec($ch);
                curl_close($ch);

                $data = json_decode($response, true);

                if (isset($data['user_info'])) {
                    $sistema['status'] = $data['user_info']['status'] ?? 'Unknown';
                    $sistema['exp_date'] = $data['user_info']['exp_date'] ?? null;
                } else {
                    $sistema['status'] = 'Unknown';
                    $sistema['exp_date'] = null;
                }

            } catch (Exception $e) {
                $sistema['status'] = 'Error';
                $sistema['exp_date'] = null;
            }

        } else {
            $sistema['status'] = 'Sem dados';
            $sistema['exp_date'] = null;
        }
    }

    echo json_encode([
        "success" => true,
        "sistemas" => $sistemas
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao listar sistemas",
        "error" => $e->getMessage()
    ]);
}
