# The AWS CDK app and its command line, for the stages local (deployed to the local AWS emulator) and prod
# (synthesized and validated, never deployed). Node LTS runs the TypeScript sources directly (type stripping,
# no build step). The dependencies are installed one level up, in /workspace/node_modules, where Node, the
# CDK CLI and the npm scripts find them from the sources mounted at /workspace/cdk, so the mount neither hides
# them nor gets a node_modules of its own (as in the development image of the simulator).
FROM node:26-alpine

WORKDIR /workspace

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund && npm cache clean --force

# No update checks, no notices and no telemetry: synthesis works without a network connection, and nothing
# about this project leaves the machine.
ENV NPM_CONFIG_UPDATE_NOTIFIER=false \
    CDK_DISABLE_CLI_TELEMETRY=true \
    CDK_DISABLE_VERSION_CHECK=true \
    PATH=/workspace/node_modules/.bin:$PATH

WORKDIR /workspace/cdk
