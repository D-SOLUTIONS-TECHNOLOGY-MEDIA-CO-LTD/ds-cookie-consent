# Playwright image must match the playwright version pinned in package.json (1.49.1).
FROM mcr.microsoft.com/playwright:v1.49.1-noble

WORKDIR /app
COPY package.json package-lock.json ./
# Browsers already ship in the base image; skip the download.
ENV PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1
RUN npm ci --omit=dev
COPY deep-scan.mjs ./
COPY lib ./lib

# Base image provides the unprivileged "pwuser".
USER pwuser
ENTRYPOINT ["node", "/app/deep-scan.mjs"]
