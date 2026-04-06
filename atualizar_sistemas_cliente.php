<?php
header("Content-Type: application/json");

// 🔥 NÃO MOSTRAR ERRO NO OUTPUT
error_reporting(0);
ini_set('display_errors', 0);

// =========================
// VALIDAR CLIENTE
// =========================
$cliente_id = $_POST['cliente_id'] ?? '';

if (!$cliente_id) {
    echo json_encode([
        "success" => false,
        "message" => "cliente_id obrigatório"
    ]);
    exit;
}

// =========================
// CONEXÃO BANCO (SEGURA)
// =========================
$DATABASE_URL = getenv("DATABASE_URL");

if (!$DATABASE_URL) {
    echo json_encode([
        "success" => false,
        "message" => "DATABASE_URL não encontrada"
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
        "message" => "Erro ao conectar ao banco"
    ]);
    exit;
}

// =========================
// BUSCAR SISTEMAS
// =========================
$stmt = $pdo->prepare("SELECT * FROM sistemas WHERE cliente_id = :cliente_id");
$stmt->execute([':cliente_id' => $cliente_id]);

$sistemas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =========================
// ATUALIZAR SISTEMAS
// =========================
foreach ($sistemas as $s) {

    $url = isset($s['url']) ? rtrim($s['url'], '/') : '';
    $user = $s['usuario'] ?? '';
    $pass = $s['senha'] ?? '';

    if (!$url || !$user || !$pass) continue;

    $status = null;
    $exp_date = null;

    // 🔥 PLAYER API (principal)
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

    // 🔥 FALLBACK M3U (Power / Live)
    if (!$status) {
        try {
            $m3u = $s['m3u_url'] ?? '';

            if ($m3u) {
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
            }
        } catch (Exception $e) {}
    }

    // 🔥 CONVERTER DATA
    $vencimento = null;

    if ($exp_date && is_numeric($exp_date)) {
        $vencimento = date('Y-m-d', (int)$exp_date);
    }

    // 🔥 ATUALIZAR
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

// =========================
// RESPOSTA FINAL
// =========================
echo json_encode([
    "success" => true,
    "message" => "Sistemas atualizados com sucesso"
]);
