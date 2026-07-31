#!/bin/sh
# Deterministic local/CI TLS material (Doc 02 SEC-06).
#
# WHAT THIS IS AND IS NOT. This generates a development certificate authority
# and one server certificate AT IMAGE BUILD TIME, so every container from a
# given image presents the same chain and the CA is already in the image's
# trust store. It exists so the whole stack can speak TLS the way production
# does — including Reverb's websockets — without any runtime certificate
# acquisition, which CI (network-isolated) could not perform anyway.
#
# It is NOT a production certificate path. Doc 18 FR-12 owns real certificates,
# issuance, rotation and the load-balancer termination posture. These keys are
# generated inside an image layer, are identical for everyone who builds it, and
# are therefore public by construction — they must never be used anywhere a real
# client trusts. That is also why the CA's common name says so out loud: if this
# certificate ever appears in a browser outside a developer machine, the name is
# the alarm.
#
# Names cover every way a service is addressed in this stack: the compose
# service names (container-to-container), localhost (host-side and in-container
# healthchecks), and the loopback IPs.
set -eu

CERT_DIR=/certs
mkdir -p "$CERT_DIR"

openssl req -x509 -newkey rsa:2048 -nodes -sha256 -days 3650 \
	-keyout "$CERT_DIR/dev-ca.key" \
	-out "$CERT_DIR/dev-ca.crt" \
	-subj "/CN=Na Live Support DEV CA — NOT FOR PRODUCTION"

openssl req -newkey rsa:2048 -nodes -sha256 \
	-keyout "$CERT_DIR/server.key" \
	-out "$CERT_DIR/server.csr" \
	-subj "/CN=localhost"

cat > "$CERT_DIR/server.ext" <<'EXT'
basicConstraints = CA:FALSE
keyUsage = digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = DNS:localhost, DNS:app, DNS:reverb, DNS:worker, DNS:scheduler, IP:127.0.0.1, IP:::1
EXT

openssl x509 -req -in "$CERT_DIR/server.csr" \
	-CA "$CERT_DIR/dev-ca.crt" -CAkey "$CERT_DIR/dev-ca.key" -CAcreateserial \
	-out "$CERT_DIR/server.crt" -days 3650 -sha256 \
	-extfile "$CERT_DIR/server.ext"

rm -f "$CERT_DIR/server.csr" "$CERT_DIR/server.ext"

# Trust the dev CA inside the image so in-container clients — the compose
# healthcheck's curl, StackSmokeTest, any service-to-service HTTP — validate the
# chain instead of being told to skip validation. A stack whose own tests pass
# `--insecure` cannot notice a broken certificate.
cp "$CERT_DIR/dev-ca.crt" /usr/local/share/ca-certificates/na-dev-ca.crt
update-ca-certificates

# Reverb runs as the web user and reads the key directly (ReactPHP TLS context,
# not Caddy), so the key must be readable by it and by nobody else.
chown -R www-data:www-data "$CERT_DIR"
chmod 640 "$CERT_DIR/server.key" "$CERT_DIR/dev-ca.key"
chmod 644 "$CERT_DIR/server.crt" "$CERT_DIR/dev-ca.crt"
