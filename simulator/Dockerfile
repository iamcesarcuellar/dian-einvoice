# Local DIAN simulator: Node LTS runs the TypeScript sources directly (type stripping, no build step).
# libxml2-utils (xmllint) validates documents against the official XSD; xmlsec verifies their signatures.
# The XSDs come from the single copy in backend/resources/dian/xsd (build context dian-xsd, see compose.yaml).
FROM node:26-alpine

RUN apk add --no-cache libxml2-utils xmlsec

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
