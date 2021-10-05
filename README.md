PuMuKIT Opencast Bundle
=======================

This bundle is used to add [Opencast](http://www.opencast.org/) support to the PuMuKIT platform. With it, videos hosted in your Opencast server can be imported and published into your PuMuKIT Web TV Portal.

```bash
composer require teltek/pumukit-opencast-bundle
```

if not, add this to config/bundles.php

```
Pumukit\OpencastBundle\PumukitOpencastBundle::class => ['all' => true]
```

Import specific permission profiles for Opencast using `pumukit:init:repo` command:
```bash
php bin/console pumukit:permission:update Administrator ROLE_ACCESS_IMPORTER
php bin/console pumukit:permission:update Publisher ROLE_ACCESS_IMPORTER
php bin/console pumukit:permission:update Ingestor ROLE_ACCESS_IMPORTER
```

Then execute the following commands

```bash
php bin/console cache:clear
php bin/console cache:clear --env=prod
php bin/console assets:install
```
