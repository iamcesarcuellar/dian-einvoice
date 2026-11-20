# The command line of the serverless service of the functions (osls, the free fork of the Serverless
# Framework) and the scripts of make cloud-up and make cloud-check, which call the Lambda API of the local
# emulator. Node LTS. The dependencies are installed one level up, in /workspace/node_modules, where Node
# and npx find them from the sources mounted at /workspace/functions, so the mount neither hides them nor
# gets a node_modules of its own (as in the image of the CDK app).
FROM node:26-alpine

WORKDIR /workspace

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund && npm cache clean --force

# No update checks, notices or telemetry: packaging works without a network connection, and nothing about
# this project leaves the machine. osls sends no telemetry; the variable also silences the plugins that do.
ENV NPM_CONFIG_UPDATE_NOTIFIER=false \
    SLS_TELEMETRY_DISABLED=1 \
    SLS_NOTIFICATIONS_MODE=off \
    PATH=/workspace/node_modules/.bin:$PATH

WORKDIR /workspace/functions
