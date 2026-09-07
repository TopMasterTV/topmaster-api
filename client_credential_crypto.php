<?php

declare(strict_types=1);

final class ClientCredentialCryptoException extends RuntimeException
{
    private string $codigoInterno;

    public function __construct(string $codigoInterno, string $mensagem)
    {
        parent::__construct($mensagem);
        $this->codigoInterno = $codigoInterno;
    }

    public function getCodigoInterno(): string
    {
        return $this->codigoInterno;
    }
}

function carregarChaveCriptografiaCredencialCliente(): string
{
    if (
        !extension_loaded('sodium')
        || !function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
        || !function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')
        || !defined('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES')
        || !defined('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES')
        || !defined('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES')
    ) {
        throw new ClientCredentialCryptoException(
            'CRYPTO_CONFIGURATION_INVALID',
            'Configuracao criptografica invalida'
        );
    }

    $chaveCodificada = getenv('CLIENT_CREDENTIAL_ENCRYPTION_KEY');
    if (!is_string($chaveCodificada) || $chaveCodificada === '') {
        throw new ClientCredentialCryptoException(
            'CRYPTO_CONFIGURATION_INVALID',
            'Configuracao criptografica invalida'
        );
    }

    $chave = base64_decode($chaveCodificada, true);
    if (
        $chave === false
        || strlen($chave) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES
    ) {
        throw new ClientCredentialCryptoException(
            'CRYPTO_CONFIGURATION_INVALID',
            'Configuracao criptografica invalida'
        );
    }

    return $chave;
}

function montarAadCredencialCliente(int $clienteId): string
{
    if ($clienteId <= 0) {
        throw new ClientCredentialCryptoException(
            'INVALID_CLIENTE_ID',
            'Cliente invalido'
        );
    }

    return 'cliente:' . $clienteId;
}

function criptografarSenhaRecuperavelCliente(string $senha, int $clienteId): string
{
    $chave = carregarChaveCriptografiaCredencialCliente();
    $aad = montarAadCredencialCliente($clienteId);

    try {
        $nonce = random_bytes(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
        );
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $senha,
            $aad,
            $nonce,
            $chave
        );
    } catch (Throwable $e) {
        throw new ClientCredentialCryptoException(
            'CRYPTO_ENCRYPTION_FAILED',
            'Falha ao proteger credencial'
        );
    }

    return 'v1:' . base64_encode($nonce . $ciphertext);
}

function descriptografarSenhaRecuperavelCliente(string $envelope, int $clienteId): string
{
    $chave = carregarChaveCriptografiaCredencialCliente();
    $aad = montarAadCredencialCliente($clienteId);

    if (!str_starts_with($envelope, 'v1:')) {
        throw new ClientCredentialCryptoException(
            'CRYPTO_ENVELOPE_INVALID',
            'Envelope criptografico invalido'
        );
    }

    $conteudo = base64_decode(substr($envelope, 3), true);
    $tamanhoNonce = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
    $tamanhoMinimo = $tamanhoNonce
        + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;

    if ($conteudo === false || strlen($conteudo) < $tamanhoMinimo) {
        throw new ClientCredentialCryptoException(
            'CRYPTO_ENVELOPE_INVALID',
            'Envelope criptografico invalido'
        );
    }

    $nonce = substr($conteudo, 0, $tamanhoNonce);
    $ciphertext = substr($conteudo, $tamanhoNonce);

    try {
        $senha = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            $aad,
            $nonce,
            $chave
        );
    } catch (Throwable $e) {
        throw new ClientCredentialCryptoException(
            'CRYPTO_DECRYPTION_FAILED',
            'Falha ao recuperar credencial'
        );
    }

    if ($senha === false) {
        throw new ClientCredentialCryptoException(
            'CRYPTO_AUTHENTICATION_FAILED',
            'Credencial criptografada nao autenticada'
        );
    }

    return $senha;
}
