# Surcouche transitoire sur l'image du parc.
#
# apcu et redis ont ete ajoutes a `php/slim-8.3-fpm` dans le depot du registry
# (registry/images/php/slim-8.3-fpm), mais l'image publiee ne les porte que
# lorsqu'elle a ete reconstruite et poussee :
#
#     cd /data/www/docker-registry.confluent-digital.com
#     sudo ./push-images.sh php/slim-8.3-fpm
#
# Les deux RUN ci-dessous sont donc conditionnels : ils compilent l'extension
# tant que la base ne l'a pas, et ne font rien une fois qu'elle l'a. Le fichier
# reste juste pour couvrir la periode ou les deux etats coexistent — machines
# a jour et machines qui n'ont pas encore fait leur `docker pull`.
#
# UNE FOIS LE PUSH FAIT PARTOUT, ce Dockerfile peut etre supprime : il suffit
# alors de remplacer, dans docker-compose.yml, le bloc
#     build: { context: ./.docker/php }
#     image: vigil/php:8.3-fpm
# par
#     image: docker-registry.confluent-digital.com/php:slim-8.3-fpm

FROM docker-registry.confluent-digital.com/php:slim-8.3-fpm

USER root

# apcu : cache local des campagnes — evite un SELECT par clic (cf. tracking.md).
RUN php -m | grep -qix apcu \
    || { pecl install apcu && docker-php-ext-enable apcu; }

# redis : buffer de clics et compteur d'unicite (SETNX).
RUN php -m | grep -qix redis \
    || { pecl install redis && docker-php-ext-enable redis; }

RUN rm -rf /tmp/pear
