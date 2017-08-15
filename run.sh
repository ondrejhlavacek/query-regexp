#!/bin/sh
set -e

composer install -q -n
rm -rf /code/report
mkdir /code/report
php /code/src/test.php $@
