FROM nginx:1.24-alpine

RUN apk add --no-cache curl \
    && mkdir -p /var/www/html/public \
    && chown -R 33:33 /var/www/html

COPY --chown=33:33 source/public /var/www/html/public/

USER 33
ENTRYPOINT []
CMD ["nginx", "-g", "daemon off;"]
