<?php

declare(strict_types=1);

require_once __DIR__ . '/samsung_xtream_client.php';

/**
 * Normaliza um identificador de categoria sem conversão numérica.
 */
function samsungNormalizeXtreamCategoryId(mixed $valor): ?string
{
    if (is_int($valor)) {
        return $valor >= 0 ? (string) $valor : null;
    }

    if (!is_string($valor)) {
        return null;
    }

    $id = trim($valor);

    if ($id === '' || strlen($id) > 32 || !ctype_digit($id)) {
        return null;
    }

    return $id;
}

/**
 * Normaliza um nome de categoria sem alterar seu conteúdo UTF-8 válido.
 */
function samsungNormalizeXtreamCategoryName(mixed $valor): ?string
{
    if (!is_string($valor)) {
        return null;
    }

    if (preg_match('//u', $valor) !== 1) {
        return null;
    }

    $valorSemTabulacoes = str_replace("\t", '', $valor);

    if (preg_match('/\p{Cc}/u', $valorSemTabulacoes) === 1) {
        return null;
    }

    $nome = trim($valor);

    if ($nome === '' || strlen($nome) > 500) {
        return null;
    }

    return $nome;
}

/**
 * Normaliza o identificador opcional da categoria-pai.
 */
function samsungNormalizeXtreamCategoryParentId(mixed $valor): ?string
{
    return samsungNormalizeXtreamCategoryId($valor);
}

/**
 * Consulta e sanitiza categorias Xtream para uso interno do backend.
 *
 * A URL, o usuário e a senha são dados internos e sensíveis. O retorno já é
 * sanitizado, e somente a chamada explícita desta função realiza uma
 * requisição. O array bruto do fornecedor nunca deve ser enviado à Samsung.
 *
 * @return list<array{
 *     id: string,
 *     nome: string,
 *     tipo: 'live'|'vod'|'series',
 *     parent_id: string|null
 * }>
 */
function samsungGetXtreamCategories(
    string $fornecedorUrl,
    string $usuario,
    string $senha,
    string $tipo
): array {
    $actionsPorTipo = [
        'live' => 'get_live_categories',
        'vod' => 'get_vod_categories',
        'series' => 'get_series_categories',
    ];

    if (!array_key_exists($tipo, $actionsPorTipo)) {
        throw new InvalidArgumentException('Tipo de categoria inválido');
    }

    $resposta = samsungRequestXtreamJson(
        $fornecedorUrl,
        $usuario,
        $senha,
        $actionsPorTipo[$tipo]
    );

    if (!array_is_list($resposta)) {
        throw new SamsungXtreamException(
            502,
            'INVALID_PROVIDER_RESPONSE',
            'Resposta inválida do sistema',
            'INVALID_RESPONSE'
        );
    }

    if ($resposta === []) {
        return [];
    }

    $categorias = [];
    $categoriasVistas = [];

    foreach ($resposta as $item) {
        if (!is_array($item)) {
            continue;
        }

        $id = samsungNormalizeXtreamCategoryId($item['category_id'] ?? null);
        $nome = samsungNormalizeXtreamCategoryName($item['category_name'] ?? null);

        if ($id === null || $nome === null) {
            continue;
        }

        $chaveDuplicidade = $tipo . ':' . $id;

        if (isset($categoriasVistas[$chaveDuplicidade])) {
            continue;
        }

        $parentId = array_key_exists('parent_id', $item) && $item['parent_id'] !== null
            ? samsungNormalizeXtreamCategoryParentId($item['parent_id'])
            : null;

        $categoriasVistas[$chaveDuplicidade] = true;
        $categorias[] = [
            'id' => $id,
            'nome' => $nome,
            'tipo' => $tipo,
            'parent_id' => $parentId,
        ];

        if (count($categorias) > 5000) {
            throw new SamsungXtreamException(
                502,
                'INVALID_PROVIDER_RESPONSE',
                'Resposta inválida do sistema',
                'INVALID_RESPONSE'
            );
        }
    }

    if ($categorias === []) {
        throw new SamsungXtreamException(
            502,
            'INVALID_PROVIDER_RESPONSE',
            'Resposta inválida do sistema',
            'INVALID_RESPONSE'
        );
    }

    return $categorias;
}
