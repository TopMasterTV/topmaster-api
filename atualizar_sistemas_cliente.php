<?php
header("Content-Type: application/json");

try {

    $cliente_id = $_POST['cliente_id'] ?? '';

    if (!$cliente_id) {
        echo json_encode([
            "success" => false,
            "message" => "cliente_id obrigatório"
        ]);
        exit;
    }

    $DATABASE_URL = getenv("DATABASE_URL");
    $db = parse_url($DATABASE_URL);

    $host = $db['host'] ?? '';
    $port = $db['port'] ?? '5432';
    $user = $db['user'] ?? '';
    $pass = $db['pass'] ?? '';
    $dbname = ltrim($db['path'] ?? '', '/');

    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $pdo->prepare("SELECT * FROM sistemas WHERE cliente_id = :cliente_id");
    $stmt->execute([':cliente_id' => $cliente_id]);
    $sistemas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($sistemas as $s) {

        $url = rtrim($s['url'], '/');
        $user = $s['usuario'];
        $pass = $s['senha'];

        if (!$url || !$user || !$pass) continue;

        $exp_date = null;
        $vencimento = null;

        // 🔥 1 - TENTA PLAYER API
        $apiUrl = "$url/player_api.php?username=$user&password=$pass";

        $response = @file_get_contents($apiUrl);

        if ($response) {
            $data = json_decode($response, true);

            if (isset($data['user_info']['exp_date'])) {
                $exp_date = $data['user_info']['exp_date'];
            }
        }

        // 🔥 2 - SE TEM DATA → USA
        if ($exp_date && is_numeric($exp_date)) {
            $vencimento = date('Y-m-d', intval($exp_date));
        }

        // 🔥 3 - FALLBACK M3U (IGUAL AO PLAYER)
        if (!$vencimento && !empty($s['m3u_url'])) {

            $m3u = $s['m3u_url'];

            $response = @file_get_contents($m3u);

            if ($response && strpos($response, "#EXTM3U") !== false) {
                // sistema ativo → joga 30 dias pra frente
                $vencimento = date('Y-m-d', strtotime('+30 days'));
            }
        }

        // 🔥 4 - ATUALIZA
        if ($vencimento) {
            $update = $pdo->prepare("
                UPDATE sistemas
                SET vencimento = :vencimento
                WHERE id = :id
            ");

            $update->execute([
                ':vencimento' => $vencimento,
                ':id' => $s['id']
            ]);
        }
    }

    echo json_encode([
        "success" => true,
        "message" => "Atualizado com sucesso"
    ]);

} catch (Throwable $e) {

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
