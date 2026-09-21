<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/aviso_cliente_action.php';

function avisoClienteAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

avisoClienteAssert(
    normalizarAvisoClienteButtonAction(null) === 'url',
    'Ação ausente deve usar URL.'
);
avisoClienteAssert(
    normalizarAvisoClienteButtonAction('') === 'url',
    'Ação vazia deve usar URL.'
);
avisoClienteAssert(
    normalizarAvisoClienteButtonAction('url') === 'url',
    'URL deve ser aceita.'
);
avisoClienteAssert(
    normalizarAvisoClienteButtonAction(' UPDATE ') === 'update',
    'UPDATE deve ser normalizada.'
);
avisoClienteAssert(
    normalizarAvisoClienteButtonAction('desconhecida') === 'url',
    'Ação desconhecida deve usar URL.'
);

$saveSource = file_get_contents(dirname(__DIR__) . '/salvar_aviso_cliente.php');
$readSource = file_get_contents(
    dirname(__DIR__) . '/buscar_aviso_cliente_ativo.php'
);

avisoClienteAssert(is_string($saveSource), 'Endpoint de gravação não lido.');
avisoClienteAssert(is_string($readSource), 'Endpoint de leitura não lido.');
avisoClienteAssert(
    str_contains($saveSource, 'AND destino = :destino'),
    'A desativação deve ficar restrita ao destino.'
);
avisoClienteAssert(
    str_contains($readSource, "destino IN ('todos', :app_tipo)"),
    'A leitura do cliente deve manter o fallback global.'
);
avisoClienteAssert(
    str_contains($readSource, 'CASE WHEN destino = :app_tipo THEN 0 ELSE 1 END'),
    'O aviso específico deve continuar prioritário.'
);
avisoClienteAssert(
    str_contains($readSource, 'AND destino = :app_tipo'),
    'A leitura administrativa exata deve existir.'
);

echo "AVISO_CLIENTE_ACTION_CONTRACT_TEST_PASS\n";
