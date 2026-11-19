# Local DIAN simulator: Node LTS runs the TypeScript sources directly (type stripping, no build step).
# libxml2-utils (xmllint) validates documents against the official XSD; xmlsec verifies their signatures.
# The XSDs come from the single copy in backend/resources/dian/xsd (build context dian-xsd, see compose.yaml).
# Two targets: production, the default (last stage), and development (lint, typecheck and tests).
FROM node:26-alpine AS base

RUN apk add --no-cache libxml2-utils xmlsec

# The dev tools run over the sources mounted at /workspace/simulator. The dev dependencies are installed
# one level up, in /workspace/node_modules, where Node, tsc and the npm scripts find them from the mount,
# so the mount neither hides them nor gets a node_modules of its own.
FROM base AS development

WORKDIR /workspace

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund && npm cache clean --force

ENV NPM_CONFIG_UPDATE_NOTIFIER=false

WORKDIR /workspace/simulator

FROM base AS production

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --omit=dev --no-audit --no-fund && npm cache clean --force

COPY --from=dian-xsd . ./xsd
COPY src ./src

# State lives in its own volume; the node user owns it.
RUN mkdir -p /data && chown node:node /data
USER node

ENV NODE_ENV=production \
    PORT=8602 \
    SIMULATOR_DATA=/data

EXPOSE 8602

CMD ["node", "src/server.ts"]
