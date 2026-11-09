FROM php:8.3-apache

# Microsoft ODBC Driver 18 + pdo_sqlsrv を導入する。
# $PHPIZE_DEPS は pecl ビルドに必要な autoconf/gcc 等（php公式イメージの環境変数）。
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        gnupg2 curl apt-transport-https ca-certificates unixodbc-dev unzip git $PHPIZE_DEPS \
    && curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/microsoft-prod.gpg] https://packages.microsoft.com/debian/12/prod bookworm main" > /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18 \
    && pecl install sqlsrv pdo_sqlsrv \
    && docker-php-ext-enable sqlsrv pdo_sqlsrv \
    && a2enmod rewrite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer 依存（公式SDK + predis）をインストール。
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-progress

# アプリ本体を配置し、DocumentRoot を public/ に向ける。
COPY . /var/www/html
COPY apache.conf /etc/apache2/sites-available/000-default.conf

# 起動時に初期管理者をシードしてから Apache を起動する。
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
