<?php

declare(strict_types=1);

function contractAssert(string $name, bool $passed): void
{
    fwrite(STDOUT, ($passed ? 'OK: ' : 'FALHA: ') . $name . PHP_EOL);
    if (!$passed) { exit(1); }
}

$endpoint = file_get_contents(dirname(__DIR__) . '/samsung_verify_password.php');
$schema = file_get_contents(dirname(__DIR__) . '/migrations/002_criar_login_rate_limits.sql');
contractAssert('fontes de contrato disponiveis', is_string($endpoint) && is_string($schema));
contractAssert('app_tipo comporta samsung_adult', strpos($schema, 'app_tipo VARCHAR(30) NOT NULL') !== false && strlen('samsung_adult') <= 30);
contractAssert('escopo usuario permitido pelo schema', strpos($schema, "CHECK (escopo IN ('usuario', 'ip'))") !== false && strpos($endpoint, "escopo = 'usuario'") !== false);
contractAssert('limite configurado em cinco erros', strpos($endpoint, '$attempts >= 5') !== false);
contractAssert('bloqueio configurado em quinze minutos', strpos($endpoint, "INTERVAL '15 minutes'") !== false && strpos($endpoint, "'retry_after' => 900") !== false);
contractAssert('controle usa transacao e bloqueio de linha', strpos($endpoint, 'beginTransaction()') !== false && strpos($endpoint, 'FOR UPDATE') !== false);
contractAssert('estado persiste na tabela', strpos($endpoint, 'login_rate_limits') !== false);
