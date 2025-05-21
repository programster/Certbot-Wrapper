FROM debian:12

# Declare /certs as a volume, as certs will be placed here.
VOLUME /letsencrypt
VOLUME /credentials

# Install php
RUN apt-get update && apt-get install -y php8.2-cli php8.2-yaml php8.2-mbstring php8.2-curl php8.2-xml php8.2-simplexml curl composer
RUN useradd admin
COPY docker/install-docker.sh /root/install-docker.sh
RUN bash /root/install-docker.sh && rm /root/install-docker.sh

RUN adduser admin docker
USER admin
COPY --chown=admin:admin src /home/admin/src
RUN cd /home/admin/src && composer install --no-dev

USER root
COPY docker/startup.sh /home/admin/startup.sh
CMD ["/bin/bash", "/home/admin/startup.sh"]
