<?php
header("Content-Type: application/json; charset=utf-8");
date_default_timezone_set("America/Sao_Paulo");

$enquete_id = $_REQUEST["enquete_id"] ?? "";

if ($enquete_id === "") {
    echo json_encode(["success" => false, "message" => "enquete_id é obrigatório"]);
    exit;
}

$DATABASE_URL = getenv("DATABASE_URL");

try {
    $db = parse_url($DATABASE_URL);

    $pdo = new PDO(
        "pgsql:host={$db["host"]};port=" . ($db["port"] ?? 5432) . ";dbname=" . ltrim($db["path"], "/") . ";sslmode=require",
        $db["user"],
        $db["pass"],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $colunas = $pdo->query("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_name = 'enquete_opcoes'
    ")->fetchAll(PDO::FETCH_COLUMN);

    $possiveis = [
        "correta",
        "correto",
        "is_correta",
        "opcao_correta",
        "eh_correta",
        "certa",
        "vencedora"
    ];

    $colunaResultado = null;

    foreach ($possiveis as $coluna) {
        if (in_array($coluna, $colunas)) {
            $colunaResultado = $coluna;
            break;
        }
    }

    if (!$colunaResultado) {
        echo json_encode([
            "success" => false,
            "message" => "Nenhuma coluna de resultado encontrada em enquete_opcoes",
            "colunas_encontradas" => $colunas
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE enquete_opcoes
        SET {$colunaResultado} = false
        WHERE enquete_id = :enquete_id
    ");

    $stmt->execute([":enquete_id" => $enquete_id]);

    echo json_encode([
        "success" => true,
        "message" => "Resultado da enquete limpo com sucesso",
        "coluna_usada" => $colunaResultado
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao limpar resultado da enquete",
        "erro" => $e->getMessage()
    ]);
}
?>
