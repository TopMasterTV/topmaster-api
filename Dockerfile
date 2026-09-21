FROM php:8.2-apache

# Instala dependências do sistema
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libcurl4-openssl-dev \
    libsodium-dev \
    && docker-php-ext-install pdo pdo_pgsql curl sodium \
    && rm -rf /var/lib/apt/lists/*

RUN php -r "if (!extension_loaded('sodium') || !function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) { fwrite(STDERR, 'Sodium/XChaCha20-Poly1305 unavailable\n'); exit(1); } echo 'Sodium/XChaCha20-Poly1305 available\n';"

# Ativa mod_rewrite
RUN a2enmod rewrite

# Copia os arquivos para o Apache
COPY . /var/www/html/

# Mantem helpers internos fora do DocumentRoot publico do Apache.
ENV TOPMASTER_PRIVATE_DIR=/var/www/private
RUN mkdir -p "$TOPMASTER_PRIVATE_DIR" \
    && mv /var/www/html/aviso_cliente_action.php \
        "$TOPMASTER_PRIVATE_DIR/aviso_cliente_action.php"

# Permissões
RUN chown -R www-data:www-data /var/www/html "$TOPMASTER_PRIVATE_DIR"

EXPOSE 80
