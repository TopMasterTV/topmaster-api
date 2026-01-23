[10:50, 23/01/2026] Erick Rangel: <?php
header('Content-Type: application/json');

$url = getenv('DATABASE_URL');

if (!$url) {
    echo json_encode([
        "success" => false,
        "message" => "DATABASE_URL não encontrada"
    ]);
    exit;
}

$db = parse_url($url);

$host = $db['host'];
$dbname = ltrim($db['path'], '/');
$user = $db['user'];
$pass = $db['pass'];
$port = $db['port'] ?? 5432;

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $sql = "
        CREATE TABLE IF NOT EXISTS clientes (
            id SERIAL PRIMARY KEY,
            nome VARCHAR(100),
            usuario VARCHAR(50) UNIQUE,
            senha VARCHAR(255),
            url_m3u TEXT,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
    ";

    $pdo->exec($sql);

    echo json_encode([
        "success" => true,
        "message" => "Tabela clientes criada com sucesso"
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
[10:57, 23/01/2026] Erick Rangel: <?php
header('Content-Type: application/json');

// 1️⃣ Pega a variável de ambiente do Render
$DATABASE_URL = getenv('DATABASE_URL');

if (!$DATABASE_URL) {
    echo json_encode([
        "success" => false,
        "message" => "DATABASE_URL não encontrada no ambiente"
    ]);
    exit;
}

// 2️⃣ Quebra a URL do banco (forma correta no PHP)
$db = parse_url($DATABASE_URL);

// 3️⃣ Extrai os dados corretamente
$host = $db['host'];
$dbname = ltrim($db['path'], '/');
$user = $db['user'];
$pass = $db['pass'];
$port = $db['port'] ?? 5432;

// 4️⃣ Conecta no PostgreSQL
try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]
    );
} catch (PDOException $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao conectar ao banco",
        "erro" => $e->getMessage()
    ]);
    exit;
}

// 5️⃣ Cria a tabela de clientes
$sql = "
CREATE TABLE IF NOT EXISTS clientes (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    usuario VARCHAR(50) UNIQUE NOT NULL,
    senha VARCHAR(255) NOT NULL,
    url_m3u TEXT NOT NULL,
    ativo BOOLEAN DEFAULT TRUE,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
";

try {
    $pdo->exec($sql);
    echo json_encode([
        "success" => true,
        "message" => "Tabela clientes criada com sucesso"
    ]);
} catch (PDOException $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao criar tabela",
        "erro" => $e->getMessage()
    ]);
}
