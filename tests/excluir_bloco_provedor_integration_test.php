<?php

declare(strict_types=1);

const MASTER_TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const RESELLER_TOKEN = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

function exigirTeste(bool $condicao, string $mensagem): void
{
    if (!$condicao) {
        throw new RuntimeException($mensagem);
    }
    fwrite(STDOUT, "OK: {$mensagem}\n");
}

function chamarEndpoint(?string $token, int $id): array
{
    $baseUrl = getenv('PROVIDER_BLOCKS_TEST_BASE_URL');
    if (!is_string($baseUrl) || $baseUrl !== 'http://127.0.0.1:8080') {
        throw new RuntimeException('URL de teste deve apontar para o servidor local isolado');
    }
    $curl = curl_init($baseUrl . '/excluir_bloco_provedor.php');
    if ($curl === false) {
        throw new RuntimeException('Falha ao iniciar cliente HTTP de teste');
    }
    $headers = ['Content-Type: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode(['id' => $id], JSON_THROW_ON_ERROR),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($curl);
    if (!is_string($body)) {
        $erro = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('Falha HTTP local: ' . $erro);
    }
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $json = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    exigirTeste(is_array($json), 'endpoint retorna JSON');
    return [$status, $json];
}

$databaseUrl = getenv('DATABASE_URL');
if (!is_string($databaseUrl) || !str_contains($databaseUrl, '@127.0.0.1:5432/provider_blocks_test')) {
    throw new RuntimeException('DATABASE_URL deve apontar exclusivamente para PostgreSQL local de teste');
}
$db = parse_url($databaseUrl);
if (!is_array($db) || !isset($db['host'], $db['path'], $db['user'], $db['pass'])) {
    throw new RuntimeException('DATABASE_URL de teste invalida');
}
$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%d;dbname=%s', $db['host'], $db['port'] ?? 5432, ltrim($db['path'], '/')),
    rawurldecode((string) $db['user']),
    rawurldecode((string) $db['pass']),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pdo->exec(<<<'SQL'
    DROP TABLE IF EXISTS administrative_tokens, modelos_sistemas, provedor_blocos, admins CASCADE;
    CREATE TABLE admins (
        id INTEGER PRIMARY KEY,
        tipo VARCHAR(20) NOT NULL
    );
    CREATE TABLE administrative_tokens (
        id BIGSERIAL PRIMARY KEY,
        actor_type VARCHAR(20) NOT NULL,
        actor_id INTEGER NOT NULL REFERENCES admins(id),
        token_hash CHAR(64) NOT NULL UNIQUE,
        expira_em TIMESTAMPTZ NOT NULL,
        ultimo_uso_em TIMESTAMPTZ,
        revogado_em TIMESTAMPTZ
    );
    CREATE TABLE provedor_blocos (
        id BIGINT PRIMARY KEY,
        nome VARCHAR(100) NOT NULL,
        descricao VARCHAR(500) NOT NULL DEFAULT '',
        criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        atualizado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );
    CREATE TABLE modelos_sistemas (
        id BIGINT PRIMARY KEY,
        nome VARCHAR(150) NOT NULL,
        url_padrao TEXT,
        bloco_id BIGINT REFERENCES provedor_blocos(id) ON DELETE RESTRICT
    );
    SQL);

$inserirAdmin = $pdo->prepare('INSERT INTO admins (id, tipo) VALUES (?, ?)');
$inserirAdmin->execute([1, 'master']);
$inserirAdmin->execute([2, 'revendedor']);
$inserirToken = $pdo->prepare(
    "INSERT INTO administrative_tokens (actor_type, actor_id, token_hash, expira_em) VALUES (?, ?, ?, NOW() + INTERVAL '1 hour')"
);
$inserirToken->execute(['master', 1, hash('sha256', MASTER_TOKEN)]);
$inserirToken->execute(['revendedor', 2, hash('sha256', RESELLER_TOKEN)]);

$pdo->exec(<<<'SQL'
    INSERT INTO provedor_blocos (id, nome) VALUES
        (1, 'Vazio'), (2, 'Com modelos'), (3, 'Rollback');
    INSERT INTO modelos_sistemas (id, nome, url_padrao, bloco_id) VALUES
        (10, 'Modelo A', 'https://a.invalid', 2),
        (11, 'Modelo B', 'https://b.invalid', 2),
        (12, 'Modelo rollback', 'https://rollback.invalid', 3),
        (13, 'Modelo sem bloco', 'https://livre.invalid', NULL);
    SQL);

[$status, $json] = chamarEndpoint(null, 1);
exigirTeste($status === 401 && ($json['error'] ?? null) === 'AUTH_REQUIRED', 'requisicao sem token e rejeitada');
[$status, $json] = chamarEndpoint(RESELLER_TOKEN, 1);
exigirTeste($status === 403 && ($json['error'] ?? null) === 'ACCESS_DENIED', 'revendedor nao pode excluir bloco');
[$status, $json] = chamarEndpoint(MASTER_TOKEN, 999);
exigirTeste($status === 404 && ($json['error'] ?? null) === 'BLOCK_NOT_FOUND', 'bloco inexistente retorna 404');

[$status, $json] = chamarEndpoint(MASTER_TOKEN, 1);
exigirTeste($status === 200 && ($json['modelos_transferidos'] ?? null) === 0, 'bloco vazio e excluido com contagem zero');
exigirTeste((int) $pdo->query('SELECT COUNT(*) FROM provedor_blocos WHERE id = 1')->fetchColumn() === 0, 'registro do bloco vazio foi excluido');

$antes = $pdo->query('SELECT id, nome, url_padrao FROM modelos_sistemas WHERE bloco_id = 2 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
[$status, $json] = chamarEndpoint(MASTER_TOKEN, 2);
exigirTeste($status === 200 && ($json['modelos_transferidos'] ?? null) === 2, 'bloco com modelos retorna quantidade transferida');
$depois = $pdo->query('SELECT id, nome, url_padrao FROM modelos_sistemas WHERE id IN (10, 11) ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
exigirTeste($antes === $depois, 'IDs, nomes e URLs dos modelos permanecem inalterados');
exigirTeste((int) $pdo->query('SELECT COUNT(*) FROM modelos_sistemas WHERE id IN (10, 11) AND bloco_id IS NULL')->fetchColumn() === 2, 'modelos foram transferidos para Sem bloco');
exigirTeste((int) $pdo->query('SELECT COUNT(*) FROM provedor_blocos WHERE id = 2')->fetchColumn() === 0, 'somente o bloco com modelos foi excluido');
exigirTeste((int) $pdo->query('SELECT COUNT(*) FROM modelos_sistemas WHERE id = 13 AND bloco_id IS NULL')->fetchColumn() === 1, 'modelo previamente Sem bloco permanece intacto');

$pdo->exec(<<<'SQL'
    CREATE OR REPLACE FUNCTION impedir_exclusao_bloco_teste() RETURNS trigger AS $$
    BEGIN
        IF OLD.id = 3 THEN
            RAISE EXCEPTION 'falha sintetica para validar rollback';
        END IF;
        RETURN OLD;
    END;
    $$ LANGUAGE plpgsql;
    CREATE TRIGGER impedir_exclusao_bloco_teste
        BEFORE DELETE ON provedor_blocos
        FOR EACH ROW EXECUTE FUNCTION impedir_exclusao_bloco_teste();
    SQL);
[$status, $json] = chamarEndpoint(MASTER_TOKEN, 3);
exigirTeste($status === 500 && ($json['error'] ?? null) === 'INTERNAL_ERROR', 'falha intermediaria retorna erro interno sanitizado');
exigirTeste((int) $pdo->query('SELECT COUNT(*) FROM provedor_blocos WHERE id = 3')->fetchColumn() === 1, 'rollback preserva o bloco');
exigirTeste((int) $pdo->query('SELECT COUNT(*) FROM modelos_sistemas WHERE id = 12 AND bloco_id = 3')->fetchColumn() === 1, 'rollback restaura a associacao do modelo');

fwrite(STDOUT, "Todos os testes isolados de exclusao de bloco passaram.\n");
