FROM dunglas/frankenphp:php8.4-bookworm

# PHP extensions required by the stack spec.
RUN install-php-extensions \
        pdo_pgsql \
        intl \
        bcmath \
        zip \
        gd \
        opcache

# WeasyPrint's native rendering stack, plus fonts with full German coverage.
# Fonts are installed deliberately rather than inherited: an invoice that
# renders differently after a base image update no longer matches the PDF
# that was stored and sent.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        git \
        unzip \
        python3 \
        python3-pip \
        libpango-1.0-0 \
        libpangoft2-1.0-0 \
        libharfbuzz0b \
        libcairo2 \
        libgdk-pixbuf-2.0-0 \
        fonts-dejavu \
        fonts-liberation2 \
 && rm -rf /var/lib/apt/lists/*

RUN pip3 install --break-system-packages --no-cache-dir weasyprint==70.0

COPY --from=composer/composer:2-bin /composer /usr/bin/composer

WORKDIR /app
