<?php

declare(strict_types=1);

define('TOPMASTER_AVISO_CLIENTE_TEST_MODE', true);
require_once dirname(__DIR__) . '/buscar_aviso_cliente_ativo.php';

function avisoListaAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function avisoListaRow(int $id, string $destino, bool $ativo = true): array
{
    return [
        'id' => $id,
        'titulo' => "Aviso {$id}",
        'mensagem' => "Mensagem {$id}",
        'link_url' => '',
        'link_texto' => '',
        'button_action' => 'url',
        'ativo' => $ativo,
        'mostrar_uma_vez' => true,
        'destino' => $destino,
    ];
}

avisoListaAssert(normalizarLimiteListaAvisosCliente(null) === 20, 'Limite padrão inválido.');
avisoListaAssert(normalizarLimiteListaAvisosCliente('') === 20, 'Limite vazio deve usar o padrão.');
avisoListaAssert(normalizarLimiteListaAvisosCliente('20') === 20, 'Limite 20 deve ser aceito.');
avisoListaAssert(normalizarLimiteListaAvisosCliente('0') === 1, 'Limite deve respeitar mínimo 1.');
avisoListaAssert(normalizarLimiteListaAvisosCliente('-5') === 1, 'Limite negativo deve respeitar mínimo 1.');
avisoListaAssert(normalizarLimiteListaAvisosCliente('51') === 50, 'Limite deve respeitar máximo 50.');
avisoListaAssert(normalizarLimiteListaAvisosCliente('999') === 50, 'Limite alto deve respeitar máximo 50.');
avisoListaAssert(normalizarLimiteListaAvisosCliente('1 OR 1=1') === 20, 'Limite inválido deve usar o padrão.');
avisoListaAssert(normalizarLimiteListaAvisosCliente('2.5') === 20, 'Limite decimal deve usar o padrão.');

$especifico = avisoListaRow(30, 'tv_cliente');
$geral = avisoListaRow(40, 'todos');
$resposta = montarRespostaListaAvisosCliente([$especifico, $geral]);

avisoListaAssert($resposta['success'] === true, 'Lista deve indicar sucesso.');
avisoListaAssert(is_array($resposta['aviso']), 'Campo aviso deve continuar sendo objeto associativo.');
avisoListaAssert($resposta['aviso']['id'] === 30, 'Campo aviso deve ser o primeiro item da lista.');
avisoListaAssert(array_is_list($resposta['avisos']), 'Campo avisos deve ser uma lista JSON.');
avisoListaAssert(count($resposta['avisos']) === 2, 'Específico e geral devem permanecer na coleção.');
avisoListaAssert($resposta['avisos'][0]['destino'] === 'tv_cliente', 'Ordem específica deve ser preservada.');
avisoListaAssert($resposta['avisos'][1]['destino'] === 'todos', 'Fallback geral deve vir depois.');

$semAvisos = montarRespostaListaAvisosCliente([]);
avisoListaAssert($semAvisos === [
    'success' => true,
    'aviso' => null,
    'avisos' => [],
], 'Resposta vazia incompatível.');

$somenteEspecifico = montarRespostaListaAvisosCliente([$especifico]);
avisoListaAssert($somenteEspecifico['aviso'] === $especifico, 'Aviso específico único inválido.');
avisoListaAssert($somenteEspecifico['avisos'] === [$especifico], 'Lista específica única inválida.');

$somenteGeral = montarRespostaListaAvisosCliente([$geral]);
avisoListaAssert($somenteGeral['aviso'] === $geral, 'Aviso geral único inválido.');
avisoListaAssert($somenteGeral['avisos'] === [$geral], 'Lista geral única inválida.');

$mesmoDestino = [
    avisoListaRow(33, 'tv_cliente'),
    avisoListaRow(32, 'tv_cliente'),
    avisoListaRow(31, 'tv_cliente'),
];
$respostaMesmoDestino = montarRespostaListaAvisosCliente($mesmoDestino);
avisoListaAssert(
    array_column($respostaMesmoDestino['avisos'], 'id') === [33, 32, 31],
    'Vários avisos do mesmo destino devem preservar a ordem recebida do SQL.'
);

$acoes = normalizarAcoesAvisosCliente([
    array_replace($especifico, ['button_action' => ' UPDATE ']),
    array_replace($geral, ['button_action' => 'desconhecida']),
]);
avisoListaAssert($acoes[0]['button_action'] === 'update', 'Ação update deve ser normalizada na lista.');
avisoListaAssert($acoes[1]['button_action'] === 'url', 'Ação inválida deve preservar fallback URL.');

$source = file_get_contents(dirname(__DIR__) . '/buscar_aviso_cliente_ativo.php');
avisoListaAssert(is_string($source), 'Endpoint não pôde ser lido.');
avisoListaAssert(substr_count($source, 'LIMIT 1') === 2, 'Os dois caminhos legados devem preservar LIMIT 1.');
avisoListaAssert(str_contains($source, "destino IN ('todos', :app_tipo)"), 'Fallback todos deve ser preservado.');
avisoListaAssert(str_contains($source, 'CASE WHEN destino = :app_tipo THEN 0 ELSE 1 END'), 'Prioridade específica deve ser preservada.');
avisoListaAssert(str_contains($source, "destino IN ('todos', :app_tipo_filtro)"), 'Lista deve usar parâmetro exclusivo no filtro.');
avisoListaAssert(str_contains($source, 'CASE WHEN destino = :app_tipo_ordem THEN 0 ELSE 1 END'), 'Lista deve usar parâmetro exclusivo na ordenação.');
avisoListaAssert(str_contains($source, 'id DESC'), 'Ordenação por ID decrescente deve ser preservada.');
avisoListaAssert(str_contains($source, 'WHERE ativo = true'), 'Somente avisos ativos devem ser consultados.');
avisoListaAssert(str_contains($source, 'AND destino = :app_tipo'), 'destino_exato legado deve ser preservado.');
avisoListaAssert(str_contains($source, 'LIMIT :limite'), 'Modo lista deve usar limite parametrizado.');
avisoListaAssert(str_contains($source, "bindValue(':app_tipo_filtro', \$app_tipo, PDO::PARAM_STR)"), 'Filtro da lista deve receber app_tipo como string.');
avisoListaAssert(str_contains($source, "bindValue(':app_tipo_ordem', \$app_tipo, PDO::PARAM_STR)"), 'Ordenação da lista deve receber o mesmo app_tipo como string.');
avisoListaAssert(str_contains($source, "bindValue(':limite', \$limite_lista, PDO::PARAM_INT)"), 'Limite deve ser vinculado como inteiro.');
avisoListaAssert(str_contains($source, '$modo_lista = $formato_lista && !$destino_exato;'), 'destino_exato deve manter o modo legado.');

foreach (['DATABASE_URL', 'db[\'pass\']', 'getTraceAsString', 'var_dump', 'print_r'] as $sensitive) {
    $responseBuilder = implode("\n", array_map(
        static fn (array $row): string => json_encode($row) ?: '',
        [$resposta, $semAvisos]
    ));
    avisoListaAssert(!str_contains($responseBuilder, $sensitive), 'Resposta de lista contém dado sensível.');
}

echo "BUSCAR_AVISO_CLIENTE_ATIVO_LISTA_CONTRACT_TEST_PASS\n";
