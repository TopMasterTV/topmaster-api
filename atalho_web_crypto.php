<?php

declare(strict_types=1);

require_once __DIR__ . '/client_credential_crypto.php';

final class AtalhoWebCryptoException extends RuntimeException
{
}

function montarAadAtalhoWeb(string $ownerType, int $ownerId, string $atalhoId): string
{
    if (!in_array($ownerType, ['master', 'revendedor'], true)) {
        throw new AtalhoWebCryptoException('Tipo de owner invalido');
    }
    if ($ownerId <= 0 || $atalhoId === '' || strlen($atalhoId) > 128) {
        throw new AtalhoWebCryptoException('Identificador de owner ou atalho invalido');
    }

    return 'atalho_web:' . $ownerType . ':' . $ownerId . ':' . $atalhoId;
}

function criptografarSenhaAtalhoWeb(
    string $senha,
    string $ownerType,
    int $ownerId,
    string $atalhoId
): ?string {
    if ($senha === '') {
        return null;
    }

    $chave = carregarChaveCriptografiaCredencialCliente();
    $aad = montarAadAtalhoWeb($ownerType, $ownerId, $atalhoId);

    try {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $senha,
            $aad,
            $nonce,
            $chave
        );
    } catch (Throwable $e) {
        throw new AtalhoWebCryptoException('Falha ao proteger senha do atalho');
    }

    return 'v1:' . base64_encode($nonce . $ciphertext);
}

function descriptografarSenhaAtalhoWeb(
    ?string $envelope,
    string $ownerType,
    int $ownerId,
    string $atalhoId
): string {
    if ($envelope === null || $envelope === '') {
        return '';
    }
    if (!str_starts_with($envelope, 'v1:')) {
        throw new AtalhoWebCryptoException('Envelope de senha invalido');
    }

    $chave = carregarChaveCriptografiaCredencialCliente();
    $aad = montarAadAtalhoWeb($ownerType, $ownerId, $atalhoId);
    $conteudo = base64_decode(substr($envelope, 3), true);
    $tamanhoNonce = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
    $tamanhoMinimo = $tamanhoNonce + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;

    if ($conteudo === false || strlen($conteudo) < $tamanhoMinimo) {
        throw new AtalhoWebCryptoException('Envelope de senha invalido');
    }

    try {
        $senha = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($conteudo, $tamanhoNonce),
            $aad,
            substr($conteudo, 0, $tamanhoNonce),
            $chave
        );
    } catch (Throwable $e) {
        throw new AtalhoWebCryptoException('Falha ao recuperar senha do atalho');
    }

    if ($senha === false) {
        throw new AtalhoWebCryptoException('Senha do atalho nao autenticada');
    }

    return $senha;
}
