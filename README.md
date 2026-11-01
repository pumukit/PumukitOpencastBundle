PuMuKIT Opencast Bundle
=======================

Bundle based on [Symfony](http://symfony.com/) to work with the [PuMuKIT Video Platform 6.0](https://github.com/pumukit/PuMuKIT/tree/6.0.x) or higher.

The PumukitOpencastBundle connects PuMuKIT to an [Opencast](https://opencast.org/) server (16 or later):

- imports the published Opencast recordings, from the back-office importer, the console or an Opencast workflow,
- keeps the imported tracks synchronized when Opencast publishes them again,
- optionally creates the PuMuKIT series and users in Opencast and deletes the Opencast event with the last multimedia
  object,
- generates the side by side video of the imported recordings,
- migrates the PuMuKIT objects between Opencast servers with console commands.

Installation
------------

The PHP `curl`, `simplexml` and `libxml` extensions are required.

```bash
composer require teltek/pumukit-opencast-bundle
```

If Symfony Flex does not do it, add the bundle to `config/bundles.php`:

```php
Pumukit\OpencastBundle\PumukitOpencastBundle::class => ['all' => true],
```

and its routes:

```yaml
pumukit_opencast:
  resource: "@PumukitOpencastBundle/Resources/config/routing.yml"
```

Give the importer permission to the profiles that need it:

```bash
php bin/console pumukit:permission:update Administrator ROLE_ACCESS_IMPORTER
php bin/console pumukit:permission:update Publisher ROLE_ACCESS_IMPORTER
php bin/console pumukit:permission:update Ingestor ROLE_ACCESS_IMPORTER
```

Then configure the bundle as explained in the [configuration guide](Resources/doc/ConfigurationGuide.md), clear the
cache and check the connection:

```bash
php bin/console cache:clear
php bin/console pumukit:opencast:check
```

Upgrading from 5.0.x (PuMuKIT 5)
--------------------------------

- Opencast 16 or later is required. The Opencast REST calls are in `Pumukit\OpencastBundle\Api\Opencast16Api`; to
  support another version, add a service that implements `OpencastApiInterface` (see the configuration guide).
- Remove the options `use_redirect`, `scheduler_on_menu`, `dashboard_on_menu`, `dashboard` and
  `deletion_workflow_name`: they were not used (the Opencast event is deleted with the External API).
- New `enable` option.
- The users created in Opencast (`manage_opencast_users`) get a random password instead of `pumukit`, and their name
  and email.
- The series sent to Opencast keep the accents and the non-Latin characters (only the HTML is removed).
- Importing again a recording that is already in PuMuKIT updates its tracks (it failed in 6.0 before).
- A track whose file is not reachable with `url_mapping` is imported with the duration and the streams of Opencast
  when `error_if_file_not_exist` is false (it failed before).
- The default language of the imported tracks is the PuMuKIT default locale.
- The master tracks imported with `pumukit:opencast:import:multiple:host --master` get the `master` tag instead of
  `display`.
- The import notifications (`notifications`) work again; they are sent through Symfony Messenger and can be queued.
- The importer, the Opencast row of the tracks list and its dialogs use Bootstrap 5. Importing is a POST with a CSRF
  token, and `/api/opencast/import_event` only accepts POST.
- The migration commands verify the TLS certificate unless `--insecure` is given, list by default and execute with
  `--force`. `pumukit:opencast:workflow:stop` deletes the events of the `retract` workflow that are no longer
  published (`--dry-run` lists them).
- The bundle logs to its own `opencast` channel, in `var/log/opencast-YYYY-MM-DD.log`.
- `ClientService` returns `MediaPackage` objects and `SearchResult` instead of arrays; `OpencastEvents` was replaced by
  the `ImportEvent` class.
