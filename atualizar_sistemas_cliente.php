<?php
header("Content-Type: application/json");

ini_set('display_errors', 0);
error_reporting(0);

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

    $pdo = new PDO(
        "pgsql:host={$db['host']};port={$db['port']};dbname=" . ltrim($db['path'], '/') . ";sslmode=require",
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $pdo->prepare("SELECT * FROM sistemas WHERE cliente_id = :cliente_id");
    $stmt->execute([':cliente_id' => $cliente_id]);
    $sistemas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($sistemas as $s) {

        $url = rtrim($s['url'], '/');
        $usuario = $s['usuario'];
        $senha = $s['senha'];

        if (!$url || !$usuario || !$senha) continue;

        $status = null;
        $exp_date = null;
        $vencimento = null;

        // 🔥 PLAYER API (IGUAL APP)
        $apiUrl = "$url/player_api.php?username=$usuario&password=$senha";
        $response = @file_get_contents($apiUrl);

        if ($response) {
            $data = json_decode($response, true);

            if (isset($data['user_info'])) {
                $status = $data['user_info']['status'] ?? null;
                $exp_date = $data['user_info']['exp_date'] ?? null;
            }
        }

        // 🔥 SE TEM DATA REAL → USA
        if ($exp_date && is_numeric($exp_date)) {
            $vencimento = date('Y-m-d', intval($exp_date));
        }

        // 🔥 SE NÃO TEM DATA MAS ESTÁ ATIVO → GERA DATA
        if (!$vencimento && $status === 'Active') {
            $vencimento = date('Y-m-d', strtotime('+30 days'));
        }

        // 🔥 FALLBACK M3U (IGUAL APP)
        if (!$status) {

            $m3uUrl = "$url/get.php?username=$usuario&password=$senha&type=m3u_plus";
            $response = @file_get_contents($m3uUrl);

            if ($response && strlen($response) > 50) {
                $status = 'Active';

                if (!$vencimento) {
                    $vencimento = date('Y-m-d', strtotime('+30 days'));
                }
            }
        }

        // 🔥 SALVA
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
        "message" => "Erro interno"
    ]);
}
