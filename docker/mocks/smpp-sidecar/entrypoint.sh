#!/bin/sh
# mock-smpp-sidecar entrypoint (Doc 14 T-03, S14 step 4): mint the mTLS material the
# CONTRACT needs — a dev CA, the sidecar's server certificate and the CLIENT certificate
# the platform presents — into the shared /certs-smpp volume, then run the mock. These
# keys are FIXTURE material generated at container start, public by construction (the
# same posture as docker/app/generate-dev-certs.sh); the real sidecar's mTLS is Doc 18's.
set -eu
CERT_DIR="${CERT_DIR:-/certs-smpp}"
mkdir -p "$CERT_DIR"
if [ ! -f "$CERT_DIR/ca.crt" ]; then
  openssl req -x509 -newkey rsa:2048 -nodes -sha256 -days 3650 \
    -subj "/CN=NA LIVE SUPPORT DEV SMPP CA - NOT FOR PRODUCTION" \
    -keyout "$CERT_DIR/ca.key" -out "$CERT_DIR/ca.crt" >/dev/null 2>&1
  openssl req -newkey rsa:2048 -nodes -sha256 -subj "/CN=mock-smpp-sidecar" \
    -keyout "$CERT_DIR/server.key" -out "$CERT_DIR/server.csr" >/dev/null 2>&1
  printf 'subjectAltName=DNS:mock-smpp-sidecar,DNS:localhost\n' > "$CERT_DIR/server.ext"
  openssl x509 -req -in "$CERT_DIR/server.csr" -CA "$CERT_DIR/ca.crt" -CAkey "$CERT_DIR/ca.key" -CAcreateserial \
    -out "$CERT_DIR/server.crt" -days 3650 -sha256 -extfile "$CERT_DIR/server.ext" >/dev/null 2>&1
  openssl req -newkey rsa:2048 -nodes -sha256 -subj "/CN=na-live-support-app" \
    -keyout "$CERT_DIR/client.key" -out "$CERT_DIR/client.csr" >/dev/null 2>&1
  openssl x509 -req -in "$CERT_DIR/client.csr" -CA "$CERT_DIR/ca.crt" -CAkey "$CERT_DIR/ca.key" -CAcreateserial \
    -out "$CERT_DIR/client.crt" -days 3650 -sha256 >/dev/null 2>&1
  chmod 644 "$CERT_DIR"/*.crt "$CERT_DIR"/client.key "$CERT_DIR"/server.key
fi
exec node /srv/server.mjs
