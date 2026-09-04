#!/bin/sh
# HARNESS entrypoint (R-D13-1.1): mint the harness certificate from the
# image-baked DEV CA, then run Caddy with the harness Caddyfile.
#
# The CA and its key are public by construction (docker/app/generate-dev-certs.sh
# — "NOT FOR PRODUCTION" is in the CN). Nothing here is a production path.
set -eu

CERT_DIR=/tmp/harness-certs
mkdir -p "$CERT_DIR"

openssl req -newkey rsa:2048 -nodes -sha256 \
	-keyout "$CERT_DIR/server.key" \
	-out "$CERT_DIR/server.csr" \
	-subj "/CN=harness.localhost" >/dev/null 2>&1

cat > "$CERT_DIR/server.ext" <<'EXT'
basicConstraints = CA:FALSE
keyUsage = digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = DNS:platform.harness.localhost, DNS:media.harness.localhost, DNS:customer.harness.localhost, DNS:harness.localhost
EXT

openssl x509 -req -in "$CERT_DIR/server.csr" \
	-CA /certs/dev-ca.crt -CAkey /certs/dev-ca.key -CAcreateserial \
	-out "$CERT_DIR/server.crt" -days 3650 -sha256 \
	-extfile "$CERT_DIR/server.ext" >/dev/null 2>&1

rm -f "$CERT_DIR/server.csr" "$CERT_DIR/server.ext"

# The CA certificate is copied where the dev box can fetch it for curl
# evidence (`docker compose cp harness:/tmp/harness-certs/dev-ca.crt …`).
cp /certs/dev-ca.crt "$CERT_DIR/dev-ca.crt"

exec frankenphp run --config /harness/Caddyfile --adapter caddyfile
