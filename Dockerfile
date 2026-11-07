FROM php:8.2-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        python3 python3-pip python3-venv \
        libcurl4-openssl-dev pkg-config \
    && docker-php-ext-install curl opcache \
    && rm -rf /var/lib/apt/lists/*

# Keep compiled PHP in memory instead of re-parsing every file on each request. The code never changes inside
# a running container, so timestamp checks are skipped.
RUN printf "opcache.enable=1\nopcache.enable_cli=1\nopcache.validate_timestamps=0\nopcache.memory_consumption=64\n" \
        > /usr/local/etc/php/conf.d/zz-opcache.ini

WORKDIR /app
COPY . /app

RUN python3 -m venv /opt/nlp-venv \
    && /opt/nlp-venv/bin/pip install --no-cache-dir -r /app/nlp/requirements.txt

ENV PYTHON_BINARY=/opt/nlp-venv/bin/python
ENV CONTRACK_HTTPS=true
# The platform proxy sets X-Forwarded-For; trust it only because this image runs behind that proxy.
ENV CONTRACK_TRUST_PROXY=true
# php -S serves one request at a time by default, so a single contract upload (Python NLP) or a slow
# Supabase call froze the site for everyone. Workers let requests run side by side. Each worker is a
# separate PHP process (~20-30 MB); raise this on instances with more memory.
ENV PHP_CLI_SERVER_WORKERS=4

EXPOSE 10000
# router.php hides .git, sql, scripts, stored PDFs and other non-public paths from the built-in server.
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-10000} -t /app /app/router.php"]
