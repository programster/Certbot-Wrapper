Certbot Wrapper
===============

A tool to automate the creation of TLS certificates through Let's Encrypt, and place them in an S3
bucket. This tool makes use of separate config files for each environment, which can be updated 
through git, but the credentials need to be stored and used in a file that is not committed, so they
are not in the git history, or the BASH history on the server.



## Building
Building the image can be done by simply executing:

```bash
bash build.sh
```

## Executing
To run the image we first need to create a config file

```yaml
email: my.email@gmail.com

dnsProvider:
  name: digitalocean
  apiKey: xxxxxxxxxxxxxxxxx

sites:
  - destFolder: mydomain
    domains:
      - mydomain.com

  - destFolder: some/long/path/myotherdomain
    domains:
      - myotherdomain.co.uk
      - www.myotherdomain.co.uk
```

For those using AWS Route53, one needs to provide the API key ID and scret like so:

```yaml
email: my.email@gmail.com

dnsProvider:
  name: route53
  apiKeyId: AKAIXXXXXXXXXXXX
  apiKey: xxxxxxxxxxxxxxxxx

sites:
  - destFolder: mydomain
    domains:
      - mydomain.com

  - destFolder: some/long/path/myotherdomain
    domains:
      - myotherdomain.co.uk
      - www.myotherdomain.co.uk
```

The `destFolder` is a relative path the certificates will be placed in, relative to the tls-certs
volume.

... before then being able to copy/paste the following command:

```bash
#!/bin/bash

docker run \
  --privileged \
  --rm \
  --name app \
  -v /var/run/docker.sock:/var/run/docker.sock \
  -v letsencrypt:/letsencrypt \
  -v credentials:/credentials \
  -v ./config.yaml:/config/config.yaml \
  -v ./tls-certs:/tls-certs \
  -e HOST_USER_ID=$(id -u) \
  -e HOST_GROUP_ID=$(id -g) \
  tls-generator
```

### Supported DNS Providers
At the moment, this tool is only tested to work with DigitalOcean and Cloudflare. One just needs
to change `name: digitalocean` to `name: cloudflare` for this to work.


## Why No Docker Compose?
This tool relies on docker-in-docker and passthru named volumes. Unfortunately when 
developing with docker-compose, it was prefixing the name of the folder, or the COMPOSE_PROJECT_NAME
on volumes, which was preventing it from working. Finally, there is no way to have docker compose 
*remove* the container after it is done, like is the case with `docker run --rm`. Using the script 
to just run the container is fast, simple, and works.
