# Shared image for the three mock provider stubs. Which stub runs is chosen
# by the SERVICE build/run arg — they are Node-stdlib-only, no npm install.
FROM node:20-alpine
WORKDIR /srv
COPY telegram/server.mjs ./telegram.mjs
COPY sms/server.mjs ./sms.mjs
COPY ai/server.mjs ./ai.mjs
ARG SERVICE=telegram
ENV SERVICE=${SERVICE}
CMD node ${SERVICE}.mjs
