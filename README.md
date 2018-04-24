# Mautic Saelos Bundle

## Installation

`composer require mautic/mautic-saelos-bundle`

## Usage

Setup a cron to run your sync on 5-10 min intervals:

```
*/5 * * * * /usr/bin/env php /var/www/html/app/console p:i:s Saelos > /dev/null 2>&1
```
