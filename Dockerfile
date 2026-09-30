FROM php:8.2-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        python3 python3-pip python3-venv \
        libcurl4-openssl-dev pkg-config \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app

RUN python3 -m venv /opt/nlp-venv \
    && /opt/nlp-venv/bin/pip install --no-cache-dir -r /app/nlp/requirements.txt

ENV PYTHON_BINARY=/opt/nlp-venv/bin/python
ENV CONTRACK_HTTPS=true

EXPOSE 10000
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-10000} -t /app"]
