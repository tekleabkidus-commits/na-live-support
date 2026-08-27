# ADR 0018 — local embeddings sidecar. The model is downloaded ONCE at image
# build (prefetch.mjs) and baked into /models; the running container has
# allowRemoteModels=false, so it never reaches the internet (a build needs
# network; a runtime does not). CLAUDE.md §3's /sidecars shape: a small
# non-PHP service behind a private HTTP API.
FROM node:20-bookworm-slim
WORKDIR /srv
COPY package.json ./
RUN npm install --omit=dev --no-audit --no-fund && npm cache clean --force
COPY model.mjs prefetch.mjs server.mjs ./
RUN node prefetch.mjs
ENV PORT=8081 NODE_ENV=production
EXPOSE 8081
HEALTHCHECK --interval=15s --timeout=5s --start-period=90s --retries=8 \
  CMD node -e "fetch('http://localhost:8081/healthz').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"
CMD ["node", "server.mjs"]
