# glued-stor
Content aware storage microservice.

Current maximum file size is capped at 8000M and 1800s execution time per nginx/php settings in
- glued/Config/Nginx/sites-enabled/glued-stor
- glued/Config/Nginx/snippets/locations/glued-stor.conf
- glued/Config/Php/99-glued-stor.ini

Nginx is applied automatically.
TODO: patch glued-lib's composer hooks to install php ini files.
TODO: add a composer hook to restart php-fpm `systemctl restart php8.1-fpm`
TODO: make max upload filesize configurable.
