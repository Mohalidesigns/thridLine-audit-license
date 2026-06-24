#!/bin/sh
set -e
cd /var/www/html

# Ensure runtime dirs exist (named volume may start empty on first run)
mkdir -p storage/framework/cache storage/framework/sessions \
         storage/framework/views storage/logs storage/keys bootstrap/cache

# Generate the RSA-4096 keypair used to sign license JWTs (RS256) once.
# Persisted via the storage volume so the public key stays stable for clients.
if [ ! -f storage/keys/license_private.pem ]; then
    echo "[entrypoint] generating license RSA-4096 keypair..."
    openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:4096 \
        -out storage/keys/license_private.pem
    openssl rsa -in storage/keys/license_private.pem \
        -pubout -out storage/keys/license_public.pem
    chmod 600 storage/keys/license_private.pem
fi

chown -R www-data:www-data storage bootstrap/cache

exec "$@"
