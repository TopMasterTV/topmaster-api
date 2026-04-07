<?php
header("Content-Type: application/json");

error_reporting(0);
ini_set('display_errors', 0);

$cliente_id = $_POST['cliente_id'] ?? '';

if (!$cliente_id) {
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
        "message" => "Erro DB"
    ]);
    exit;
}

$db = parse_url($DATABASE_URL);

$host = $db['host'] ?? '';
$port = $db['port'] ?? 5432;
$user = $db['user'] ?? '';
$pass = $db['pass'] ?? '';
$dbname = ltrim($db['path'] ?? '', '/');

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro conexão"
    ]);
    exit;
}

// 🔥 BUSCA SISTEMAS
$stmt = $pdo->prepare("SELECT * FROM sistemas WHERE cliente_id = :cliente_id");
$stmt->execute([':cliente_id' => $cliente_id]);

$sistemas = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($sistemas as $s) {

    $url = isset($s['url']) ? rtrim($s['url'], '/') : '';
    $user = $s['usuario'] ?? '';
    $pass = $s['senha'] ?? '';

    if (!$url || !$user || !$pass) continue;

    $status = null;
    $exp_date = null;

    // =========================
    // TENTA PLAYER API
    // =========================
    try {
        $apiUrl = "$url/player_api.php?username=$user&password=$pass";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $data = json_decode($response, true);

            if (isset($data['user_info'])) {
                $status = $data['user_info']['status'] ?? null;
                $exp_date = $data['user_info']['exp_date'] ?? null;
            }
        }

    } catch (Exception $e) {}

    // =========================
    // FALLBACK: M3U (IMPORTANTE)
    // =========================
    if (!$status) {
        $m3u = $s['m3u_url'] ?? '';

        if ($m3u) {
            try {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $m3u,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                ]);

                $response = curl_exec($ch);
                curl_close($ch);

                if ($response && strpos($response, "#EXTM3U") !== false) {
                    $status = 'Active';
                }
            } catch (Exception $e) {}
        }
    }

    // =========================
    // DEFINIR VENCIMENTO
    // =========================
    $vencimento = null;

    if ($exp_date && is_numeric($exp_date)) {
        $vencimento = date('Y-m-d', (int)$exp_date);
    }

    // 🔥 SE NÃO TEM DATA MAS ESTÁ ATIVO
    if (!$vencimento && $status === 'Active') {
        $vencimento = date('Y-m-d', strtotime('+30 days'));
    }

    // =========================
    // ATUALIZA BANCO
    // =========================
    try {
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
    } catch (Exception $e) {}
}

echo json_encode([
    "success" => true,
    "message" => "Atualizado com sucesso"
]);
