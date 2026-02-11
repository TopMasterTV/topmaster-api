<?php
header("Content-Type: application/json");

/* =========================
   RECEBE DADOS
   ========================= */
$admin_id      = $_POST['admin_id'] ?? '';
$tipo          = $_POST['tipo'] ?? '';
$revendedor_id = $_POST['revendedor_id'] ?? null;

if ($admin_id === '' || $tipo === '') {
    echo json_encode([
        "success" => false,
        "message" => "admin_id e tipo são obrigatórios"
    ]);
    exit;
}

/* =========================
   CONEXÃO COM BANCO
   ========================= */
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
        "pgsql:host={$db['host']};port=" . ($db['port'] ?? 5432) . ";dbname=" . ltrim($db['path'], '/') . ";sslmode=require",
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

/* =========================
   LISTAGEM DE CLIENTES
   ========================= */
try {

    if ($tipo === 'master') {

        $sql = "
            SELECT
                id,
                nome,
                usuario,
                m3u_url,
                whatsapp,
                admin_id,
                revendedor_id,
                revendedor_nome,
                criado_em
            FROM public.clientes
            ORDER BY id DESC
        ";

        $stmt = $pdo->query($sql);

    } else {

        if ($revendedor_id === null || $revendedor_id === '') {
            echo json_encode([
                "success" => false,
                "message" => "revendedor_id é obrigatório para revendedor"
            ]);
            exit;
        }

        $sql = "
            SELECT
                id,
                nome,
                usuario,
                m3u_url,
                whatsapp,
                admin_id,
                revendedor_id,
                revendedor_nome,
                criado_em
            FROM public.clientes
            WHERE revendedor_id = :revendedor_id
            ORDER BY id DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':revendedor_id' => $revendedor_id
        ]);
    }

    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "total"   => count($clientes),
        "clientes"=> $clientes
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao listar clientes",
        "error"   => $e->getMessage()
    ]);
}
