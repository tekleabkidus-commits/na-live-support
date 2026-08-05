# SEC-03 / CLAUDE.md §3: ALL third-party egress goes through this proxy with a
# per-destination allowlist. Built from alpine rather than pulled from a
# third-party Docker Hub image: the proxy is a security control, and an
# unofficial image would be a bigger supply-chain surface than 10 lines of
# Dockerfile (same reasoning as ADR 0008's hand-rolled clamd client).
FROM alpine:3.20

RUN apk add --no-cache tinyproxy

COPY tinyproxy.conf /etc/tinyproxy/tinyproxy.conf
COPY filter /etc/tinyproxy/filter

# tinyproxy's alpine package runs as user tinyproxy; the config keeps defaults.
USER tinyproxy

EXPOSE 8888

CMD ["tinyproxy", "-d", "-c", "/etc/tinyproxy/tinyproxy.conf"]
