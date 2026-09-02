# assetsync2.0

Barebone GLPI 11 plugin scaffold.

## Local plugin key

GLPI plugin keys are used in PHP function names, so the internal plugin key is
`assetsync20`. The user-facing plugin name is `assetsync2.0`.

## Docker deployment

Deploy this directory into the GLPI marketplace folder:

```sh
docker cp . glpi-glpi-1:/var/glpi/marketplace/assetsync20
docker exec glpi-glpi-1 chown -R www-data:www-data /var/glpi/marketplace/assetsync20
docker exec glpi-glpi-1 php /var/www/glpi/bin/console plugin:list
```
