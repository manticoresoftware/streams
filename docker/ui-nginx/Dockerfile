FROM nginx:1.24-alpine@sha256:77e5d4a6ad906c5d3793764085706577fa705b1dc6f244ea0241c4b5e2155385

RUN apk add --no-cache curl \
    && mkdir -p /var/www/html/public \
    && chown -R 33:33 /var/www/html

COPY --chown=33:33 source/public /var/www/html/public/

USER 33
ENTRYPOINT []
CMD ["nginx", "-g", "daemon off;"]
