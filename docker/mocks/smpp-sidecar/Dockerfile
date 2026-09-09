# mock-smpp-sidecar (Doc 14 T-03 / AC-08): the SMPP sidecar's CONTRACT half as a FIXTURE —
# Node stdlib only, plus openssl for the mTLS material minted at start (entrypoint.sh).
FROM node:20-alpine
RUN apk add --no-cache openssl
WORKDIR /srv
COPY server.mjs ./server.mjs
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
