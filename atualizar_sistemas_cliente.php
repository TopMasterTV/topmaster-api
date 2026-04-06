<?php
header("Content-Type: application/json");

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

try {
    $pdo = new PDO(
        "pgsql:host={$db['host']};port={$db['port']};dbname=" . ltrim($db['path'], '/') . ";sslmode=require",
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao conectar ao banco"
    ]);
    exit;
}

// 🔥 BUSCA SISTEMAS DO CLIENTE
$stmt = $pdo->prepare("SELECT * FROM sistemas WHERE cliente_id = :cliente_id");
$stmt->execute([':cliente_id' => $cliente_id]);

$sistemas = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($sistemas as $s) {

    $url = rtrim($s['url'], '/');
    $user = $s['usuario'];
    $pass = $s['senha'];

    if (!$url || !$user || !$pass) continue;

    $status = null;
    $exp_date = null;

    // 🔥 TENTA PLAYER API
    try {
        $apiUrl = "$url/player_api.php?username=$user&password=$pass";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);

        if (isset($data['user_info'])) {
            $status = $data['user_info']['status'] ?? null;
            $exp_date = $data['user_info']['exp_date'] ?? null;
        }

    } catch (Exception $e) {}

    // 🔥 FALLBACK M3U (resolve Power, Live, etc)
    if (!$status) {
        try {
            $m3u = $s['m3u_url'] ?? null;

            if ($m3u) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $m3u);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);

                $response = curl_exec($ch);
                curl_close($ch);

                if (strpos($response, "#EXTM3U") !== false) {
                    $status = 'Active';
                }
            }
        } catch (Exception $e) {}
    }

    // 🔥 CONVERTE DATA
    $vencimento = null;

    if ($exp_date) {
        $vencimento = date('Y-m-d', $exp_date);
    }

    // 🔥 ATUALIZA BANCO
    $update = $pdo->prepare("
        UPDATE sistemas
        SET
            status = :status,
            exp_date = :exp_date,
            vencimento = COALESCE(:vencimento, vencimento)
        WHERE id = :id
    ");

    $update->execute([
        ':status' => $status,
        ':exp_date' => $exp_date,
        ':vencimento' => $vencimento,
        ':id' => $s['id']
    ]);
}

echo json_encode([
    "success" => true,
    "message" => "Sistemas atualizados"
]);
