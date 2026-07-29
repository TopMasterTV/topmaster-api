# Idempotencia do fallback de audio Roku

## Escopo

Este contrato define a identidade deterministica de uma tentativa de fallback
`vod_audio_stereo`. Ele permite reconstruir o mesmo identificador interno e o
mesmo token publico depois de retry, timeout ou reinicio do backend, sem
persistir o token bruto.

O contrato nao autentica a Roku e nao substitui a autorizacao por
`cliente_id` e `sistema_id`. URL de origem e credenciais Xtream continuam
resolvidas somente no servidor e nao participam da derivacao.

## Invariantes do schema

- `internal_session_id` e unico e aceita de 16 a 128 caracteres
  `[A-Za-z0-9_-]`.
- `public_token_hash` e unico, com 64 caracteres SHA-256 hexadecimais
  minusculos.
- A migration 005 troca apenas o indice parcial unico ativo por um indice
  parcial nao unico de lookup.
- As constraints, FKs e demais indices das migrations 003 e 004 permanecem
  inalterados.

## Request ID

`request_id` tem exatamente 43 caracteres Base64URL sem padding e representa
32 bytes aleatorios. A allowlist e `[A-Za-z0-9_-]`.

O aplicativo Roku deve usar uma fonte criptograficamente segura. O valor:

- permanece estavel em todos os retries da mesma tentativa;
- muda em uma nova tentativa deliberada;
- nao usa UUID previsivel, timestamp, contador, `rand()` ou `mt_rand()`;
- nao e segredo;
- nao autentica;
- nao concede acesso ao HLS.

O `request_id` nao e armazenado em coluna propria. Ele fica representado
criptograficamente no `internal_session_id`, que identifica a tentativa
logica.

## Canonicalizacao V1

A canonicalizacao possui exatamente oito linhas:

```text
TOPMASTER_ROKU_AUDIO_FALLBACK
v1
<CLIENTE_ID>
<SISTEMA_ID>
<STREAM_ID>
<EXTENSAO>
<REQUEST_ID>
vod_audio_stereo
```

As linhas usam um unico byte LF (`\n`) como separador e nao existe LF final.
O resultado e codificado em UTF-8 e comparado byte a byte, sem dependencia de
locale.

Regras dos campos:

- `cliente_id` e `sistema_id`: decimal canonico, positivo, sem sinal e sem
  zeros a esquerda;
- `stream_id`: preservado depois da validacao, nao vazio e sem NUL, CR ou LF;
- `extensao`: minuscula e limitada a `mp4`, `mov`, `m4v` ou `mkv`;
- `request_id`: formato exato definido acima;
- nenhuma URL ou credencial participa;
- JSON nao e usado para canonicalizacao.

## Segredo de derivacao

O nome futuro da configuracao e:

```text
ROKU_AUDIO_FALLBACK_DERIVATION_SECRET
```

O segredo deve:

- ser diferente de `ROKU_TRANSCODER_HMAC_SECRET`;
- ter no minimo 32 bytes de entropia;
- nunca ser armazenado no banco;
- nunca ser enviado a Roku ou ao transcoder;
- nunca ser registrado;
- nunca aparecer em URL ou mensagem de erro.

## Derivacoes

### Internal session ID

A mensagem e:

```text
internal-session
<CANONICALIZACAO>
```

Entre o dominio e a canonicalizacao existe um unico LF. Calcula-se
HMAC-SHA-256 com o segredo de derivacao, codifica-se o resultado em Base64URL
sem padding e adiciona-se o prefixo `raf_`.

Formato final:

```text
raf_<43_CARACTERES_BASE64URL>
```

O comprimento total e 47 caracteres.

### Token publico

A mensagem e:

```text
public-token
<CANONICALIZACAO>
```

Calcula-se HMAC-SHA-256 com o mesmo segredo e codifica-se em Base64URL sem
padding. Nao ha prefixo e o comprimento final e 43 caracteres.

Os dominios `internal-session` e `public-token` impedem que o mesmo HMAC seja
reutilizado nas duas finalidades.

### Hash do token publico

Calcula-se SHA-256 sobre os bytes ASCII do token publico final e codifica-se
em hexadecimal minusculo. O resultado tem 64 caracteres.

Somente `public_token_hash` e persistido. Nao sao persistidos token bruto,
token criptografado, segredo, canonicalizacao, `source_url` ou credenciais
Xtream.

## Fluxo idempotente

### Primeira solicitacao

1. Autenticar a Roku.
2. Resolver `cliente_id` exclusivamente no servidor.
3. Validar que o sistema pertence ao cliente.
4. Validar `stream_id` e extensao.
5. Validar `request_id`.
6. Derivar `internal_session_id`.
7. Derivar o token publico.
8. Calcular `public_token_hash`.
9. Consultar primeiro por `internal_session_id`.
10. Se ausente, solicitar a criacao ao transcoder.
11. Devolver somente a resposta publica sanitizada.

Ao localizar uma sessao, o backend deve comparar, em conjunto:

- `cliente_id`;
- `sistema_id`;
- `stream_id`;
- `extensao_sanitizada`;
- `fallback_kind`;
- `public_token_hash`.

Qualquer divergencia produz conflito sanitizado. A sessao nao e reutilizada,
sobrescrita nem cancelada automaticamente, e nenhuma `playback_url` e
devolvida.

### Retry e resposta perdida

O retry conserva o mesmo `request_id`, logo reconstroi os mesmos IDs, token e
hash. O backend consulta primeiro por `internal_session_id` e nao cria nova
tentativa quando a sessao ja existe. A recuperacao independe da memoria do
backend e sobrevive a reinicio.

Depois de timeout com resultado indeterminado:

1. fazer poucas consultas locais ao banco;
2. se continuar ausente, repetir no maximo uma vez a criacao com os mesmos
   IDs;
3. consultar novamente depois de conflito;
4. usar nonce HMAC interno novo em cada chamada;
5. nunca gerar token novo durante o retry;
6. nunca repetir POST indefinidamente.

Nao deve existir retry cego de POST sem o mesmo identificador deterministico.

### Concorrencia e duas TVs

`request_id` diferentes criam tentativas diferentes, inclusive para o mesmo
filme. Assim, duas TVs podem possuir sessoes ativas independentes. Cancelar
uma tentativa nao afeta a outra.

Dois envios concorrentes da mesma tentativa convergem pelas constraints
unicas de `internal_session_id` e `public_token_hash`. O perdedor do conflito
consulta e valida a sessao persistida.

O mesmo `request_id` continua apontando para a mesma tentativa nos estados
`failed`, `cancelled`, `expired` e `finished`. IDs nao sao reciclados. Novo
processamento exige novo `request_id`.

## Playback HLS

Formato conceitual:

```text
<ROKU_TRANSCODER_PUBLIC_URL>/media/<TOKEN>/index.m3u8
```

A base vem somente da configuracao. O token aparece apenas no path, sem query
ou fragmento. A URL e devolvida somente quando o estado for utilizavel; nao e
persistida, registrada ou exposta em erros.

O token no path autentica somente a entrega HLS daquela sessao. Ele nao
autoriza criacao, consulta administrativa ou cancelamento.

## Rotacao do segredo

A primeira versao nao possui `key_version`. Trocar o segredo muda IDs e tokens
e impede reconstruir sessoes antigas. A rotacao exige janela de manutencao:

1. bloquear novas criacoes;
2. aguardar ou terminalizar sessoes ativas;
3. trocar o segredo;
4. reiniciar o backend;
5. reativar o fallback.

A migration 005 nao adiciona coluna de versao.

## Vetores sinteticos

Todos os vetores usam o segredo textual
`TEST_ONLY_DO_NOT_USE__DERIVATION_SECRET_32_BYTES`. Ele existe somente para
teste e nunca deve ser usado fora dos vetores.

### Vetor 1

Entradas:

```text
cliente_id: 101
sistema_id: 202
stream_id: movie-alpha-001
extensao: mp4
request_id: AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8
```

Canonicalizacao escapada:

```text
TOPMASTER_ROKU_AUDIO_FALLBACK\nv1\n101\n202\nmovie-alpha-001\nmp4\nAAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8\nvod_audio_stereo
```

Mensagem internal escapada:

```text
internal-session\nTOPMASTER_ROKU_AUDIO_FALLBACK\nv1\n101\n202\nmovie-alpha-001\nmp4\nAAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8\nvod_audio_stereo
```

Resultados:

```text
internal_hmac_hex: 0cd3e318ffc75e3d91a969037b478a14300a4793b8d36f3508c5b07ee67859e6
internal_session_id: raf_DNPjGP_HXj2RqWkDe0eKFDAKR5O40281CMWwfuZ4WeY
```

Mensagem public escapada:

```text
public-token\nTOPMASTER_ROKU_AUDIO_FALLBACK\nv1\n101\n202\nmovie-alpha-001\nmp4\nAAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8\nvod_audio_stereo
```

Resultados:

```text
public_hmac_hex: 92ee88c9702871cef69f037fabf4aa2b3687a0a87491d75b8dbc49a6ca393cc0
public_token: ku6IyXAocc72nwN_q_SqKzaHoKh0kddbjbxJpso5PMA
public_token_hash: 762c6db79a9ef04613ce60d396e426c4eac1ab006bf3103880982b081411122f
```

### Vetor 2

Entradas:

```text
cliente_id: 303
sistema_id: 404
stream_id: vod_Beta-987654321
extensao: mkv
request_id: ICEiIyQlJicoKSorLC0uLzAxMjM0NTY3ODk6Ozw9Pj8
```

Canonicalizacao escapada:

```text
TOPMASTER_ROKU_AUDIO_FALLBACK\nv1\n303\n404\nvod_Beta-987654321\nmkv\nICEiIyQlJicoKSorLC0uLzAxMjM0NTY3ODk6Ozw9Pj8\nvod_audio_stereo
```

Mensagem internal escapada:

```text
internal-session\nTOPMASTER_ROKU_AUDIO_FALLBACK\nv1\n303\n404\nvod_Beta-987654321\nmkv\nICEiIyQlJicoKSorLC0uLzAxMjM0NTY3ODk6Ozw9Pj8\nvod_audio_stereo
```

Resultados:

```text
internal_hmac_hex: 0773dd9ec0b0d299502a4acc06c8f07e4c813cf81eff7f51dae34c807e6e8522
internal_session_id: raf_B3PdnsCw0plQKkrMBsjwfkyBPPge_39R2uNMgH5uhSI
```

Mensagem public escapada:

```text
public-token\nTOPMASTER_ROKU_AUDIO_FALLBACK\nv1\n303\n404\nvod_Beta-987654321\nmkv\nICEiIyQlJicoKSorLC0uLzAxMjM0NTY3ODk6Ozw9Pj8\nvod_audio_stereo
```

Resultados:

```text
public_hmac_hex: e3c11bfa9f7974882d32c544eb74bd2f87a14e9f01c1dd85618805fc6e967404
public_token: 48Eb-p95dIgtMsVE63S9L4ehTp8Bwd2FYYgF_G6WdAQ
public_token_hash: 9c5f2e5e046a802cd8fa8a869ab3b19fa7a06f864012b5541921161b0d68c3da
```

Os dois vetores foram calculados de forma independente com Python padrao
(`hmac`, `hashlib`, `base64.urlsafe_b64encode`) e PowerShell/.NET
(`HMACSHA256`, `SHA256`, `Convert.ToBase64String`). Foram comparados byte a
byte: canonicalizacao, HMAC internal, `internal_session_id`, HMAC public,
token e `public_token_hash`.

## Referencia PHP 8.2

Esta referencia usa somente os dois vetores sinteticos e funcoes padrao. O
segredo de teste nao deve ser impresso nem registrado.

```php
<?php

declare(strict_types=1);

function base64UrlSemPadding(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

$secret = 'TEST_ONLY_DO_NOT_USE__DERIVATION_SECRET_32_BYTES';
$vectors = [
    [
        'fields' => [
            '101', '202', 'movie-alpha-001', 'mp4',
            'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8',
        ],
        'id' => 'raf_DNPjGP_HXj2RqWkDe0eKFDAKR5O40281CMWwfuZ4WeY',
        'token' => 'ku6IyXAocc72nwN_q_SqKzaHoKh0kddbjbxJpso5PMA',
        'hash' => '762c6db79a9ef04613ce60d396e426c4eac1ab006bf3103880982b081411122f',
    ],
    [
        'fields' => [
            '303', '404', 'vod_Beta-987654321', 'mkv',
            'ICEiIyQlJicoKSorLC0uLzAxMjM0NTY3ODk6Ozw9Pj8',
        ],
        'id' => 'raf_B3PdnsCw0plQKkrMBsjwfkyBPPge_39R2uNMgH5uhSI',
        'token' => '48Eb-p95dIgtMsVE63S9L4ehTp8Bwd2FYYgF_G6WdAQ',
        'hash' => '9c5f2e5e046a802cd8fa8a869ab3b19fa7a06f864012b5541921161b0d68c3da',
    ],
];

foreach ($vectors as $vector) {
    $canonical = implode("\n", [
        'TOPMASTER_ROKU_AUDIO_FALLBACK',
        'v1',
        ...$vector['fields'],
        'vod_audio_stereo',
    ]);
    $internalMac = hash_hmac(
        'sha256',
        "internal-session\n" . $canonical,
        $secret,
        true
    );
    $publicMac = hash_hmac(
        'sha256',
        "public-token\n" . $canonical,
        $secret,
        true
    );
    $internalSessionId = 'raf_' . base64UrlSemPadding($internalMac);
    $publicToken = base64UrlSemPadding($publicMac);
    $publicTokenHash = hash('sha256', $publicToken);

    if (
        !hash_equals($vector['id'], $internalSessionId)
        || !hash_equals($vector['token'], $publicToken)
        || !hash_equals($vector['hash'], $publicTokenHash)
    ) {
        throw new RuntimeException('PHP_8_2_VECTOR_MISMATCH');
    }
}
```

`PHP_8_2_VECTOR_EXECUTION_PENDING`

Antes do commit dos endpoints PHP, os dois vetores devem ser executados em
PHP 8.2 e comparados exatamente com todos os valores documentados. Qualquer
divergencia bloqueia a implementacao e o commit dos endpoints.

## Migration 005 e reversao

A migration 005 valida nome, tabela, metodo, colunas, unicidade, validade e
predicado do indice antigo antes de remove-lo. Em seguida cria
`idx_roku_audio_fallback_sessions_ativas_lookup`, com as mesmas colunas, na
mesma ordem, e os mesmos estados:

```text
created, validating, starting, preparing, ready, streaming, cancelling
```

O novo indice e parcial e nao unico. Isso permite tentativas independentes
para o mesmo filme, enquanto as constraints unicas de `internal_session_id` e
`public_token_hash` preservam a idempotencia de cada tentativa.

Uma segunda execucao e aceita somente quando o indice antigo esta ausente e o
novo existe com a definicao esperada. Ausencia ou incompatibilidade falha com
mensagem fixa. A migration nao altera dados nem adiciona colunas.

Nao existe rollback embutido ou arquivo separado. A reversao exige migration
posterior; recriar a unicidade ativa pode falhar se houver mais de uma
tentativa ativa para o mesmo filme.
