FROM php:8.4-cli-bookworm

LABEL maintainer="SQLCraft Dev Environment"
LABEL description="PHP 8.4 with all PDO drivers, static-analysis tools, and dev utilities for SQLCraft"

# ── System dependencies ──────────────────────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        curl \
        unzip \
        gnupg2 \
        apt-transport-https \
        ca-certificates \
        libpq-dev \
        libsqlite3-dev \
        libzip-dev \
        unixodbc-dev \
    && rm -rf /var/lib/apt/lists/*

# ── Microsoft ODBC 18 driver (required for pdo_sqlsrv) ───────────────────────
RUN curl -sSL https://packages.microsoft.com/keys/microsoft.asc \
        | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
    && curl -sSL https://packages.microsoft.com/config/debian/12/prod.list \
        -o /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y --no-install-recommends \
        msodbcsql18 \
        mssql-tools18 \
    && rm -rf /var/lib/apt/lists/*

# ── Core PHP extensions ───────────────────────────────────────────────────────
RUN docker-php-ext-install \
        pdo \
        pdo_mysql \
        pdo_pgsql \
        pdo_sqlite \
        zip

# ── pdo_sqlsrv via PECL ───────────────────────────────────────────────────────
RUN pecl install pdo_sqlsrv sqlsrv \
    && docker-php-ext-enable pdo_sqlsrv sqlsrv

# ── pcov (lightweight coverage driver — preferred over Xdebug for CI speed) ──
RUN pecl install pcov \
    && docker-php-ext-enable pcov

# ── pdo_oci / Oracle Instant Client (optional) ───────────────────────────────
#
# Oracle Instant Client cannot be downloaded unattended (requires Oracle account).
# To enable pdo_oci:
#   1. Download from https://www.oracle.com/database/technologies/instant-client/
#      - instantclient-basic-linux.x64-21.*.zip
#      - instantclient-sdk-linux.x64-21.*.zip
#   2. Place both .zip files in docker/php/oracle/
#   3. Set build arg:  --build-arg ORACLE=1
#
ARG ORACLE=0
COPY oracle/ /tmp/oracle/
RUN if [ "$ORACLE" = "1" ]; then \
        apt-get update && apt-get install -y --no-install-recommends libaio1 \
        && rm -rf /var/lib/apt/lists/* \
        && mkdir -p /opt/oracle \
        && unzip -o "/tmp/oracle/instantclient-basic-*.zip" -d /opt/oracle \
        && unzip -o "/tmp/oracle/instantclient-sdk-*.zip" -d /opt/oracle \
        && IC_DIR=$(ls -d /opt/oracle/instantclient_*) \
        && echo "$IC_DIR" > /etc/ld.so.conf.d/oracle.conf \
        && ldconfig \
        && pecl install pdo_oci \
        && docker-php-ext-enable pdo_oci; \
    fi

# ── PHP runtime config ────────────────────────────────────────────────────────
COPY php.ini /usr/local/etc/php/conf.d/sqlcraft-dev.ini

# ── Composer ──────────────────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── Non-root user for file-permission hygiene ─────────────────────────────────
ARG UID=1000
ARG GID=1000
RUN groupadd --gid "$GID" sqlcraft \
    && useradd --uid "$UID" --gid "$GID" --shell /bin/bash --create-home sqlcraft

USER sqlcraft
WORKDIR /app

# Pre-warm the Composer cache directory (avoids permission issues on first install)
RUN mkdir -p /home/sqlcraft/.composer

CMD ["php", "-a"]
