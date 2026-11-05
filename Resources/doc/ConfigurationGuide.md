Opencast configuration
======================

Bundle
------

```yaml
pumukit_opencast:
  host: 'https://opencast.example.org'
  admin_host: 'https://admin.opencast.example.org'
  username: 'opencast_system_account'
  password: '%env(OPENCAST_DIGEST_PASSWORD)%'
  player: '/paella7/ui/watch.html'
  scheduler: '/admin-ui/index.html#/events/events'
  sync_series_with_opencast: true
  manage_opencast_users: true
  delete_archive_mediapackage: true
  url_mapping:
    - url: 'https://opencast.example.org/static/mh_default_org/'
      path: '/mnt/opencast/downloads/mh_default_org/'
    - url: 'https://admin.opencast.example.org/assets/assets/'
      path: '/mnt/opencast/archive/mh_default_org/'
  sbs:
    generate_sbs: true
    profile: sbs
    use_flavour: true
    flavour: composition/delivery
```

| Option | Default | Description |
|--------|---------|-------------|
| `enable` | `true` | If false, nothing connects to Opencast: the importer gives 404, the listeners do nothing and the commands fail |
| `host` | (required if enabled) | Opencast presentation (or all-in-one) node |
| `admin_host` | `null` | Opencast admin node. If null, it is asked to the server on every request that needs it |
| `username`, `password` | `''` | Digest account of the REST endpoints (`org.opencastproject.security.digest.user` / `.pass`) |
| `insecure` | `false` | Do not verify the TLS certificate of Opencast |
| `player` | `/paella7/ui/watch.html` | Opencast player, path relative to `host` or URL |
| `scheduler` | `/admin-ui/index.html#/events/events` | Recordings page of the Opencast admin, path relative to `admin_host` or URL |
| `show_importer_tab` | `true` | Show the Opencast importer in the back-office |
| `batchimport_inverted` | `false` | Import with presenter and presentation switched by default |
| `delete_archive_mediapackage` | `false` | Delete the Opencast event when its last multimedia object is deleted |
| `manage_opencast_users` | `false` | Create, update and delete the PuMuKIT users in Opencast (with a random password) |
| `sync_series_with_opencast` | `false` | Create, update and delete the PuMuKIT series in Opencast |
| `error_if_file_not_exist` | `true` | Fail the import if a track file is not reachable with `url_mapping`. If false, the track is imported with the duration and streams of Opencast |
| `url_mapping` | `[]` | Opencast URLs and the local paths where the same files are mounted. The archive URLs (`/assets/assets/{mp}/{element}/{version}/{file}`) are mapped to the storage layout (`{mp}/{version}/{element}.{ext}`) |
| `sbs.generate_sbs` | `false` | Generate the side by side video after the import |
| `sbs.profile` | `sbs` | Encoder profile of the side by side video |
| `sbs.use_flavour` | `true` | Use the Opencast track of `sbs.flavour` as side by side video instead of generating it |
| `sbs.flavour` | `composition/delivery` | Flavour of the Opencast side by side track |
| `default_tag_imported` | `TECHOPENCAST` | Tag added to the imported multimedia objects |
| `seconds_to_sleep_on_commands` | `1` | Seconds between the requests of the migration commands |
| `notifications.*` | disabled | Email to the owners and groups of an imported recording (see below) |

The importer needs the `ROLE_ACCESS_IMPORTER` permission.

Opencast workflow
-----------------

To import the recordings as soon as Opencast publishes them, create a PuMuKIT user with the "Access API" permission
and add this operation at the end of the publishing workflows:

```xml
<operation id="post-mediapackage" max-attempts="2" exception-handler-workflow="partial-error" description="Publishing to PuMuKIT">
  <configurations>
    <configuration key="url">https://pumukit.example.org/api/opencast/import_event</configuration>
    <configuration key="format">json</configuration>
    <configuration key="debug">no</configuration>
    <configuration key="mediapackage.type">search</configuration>
    <configuration key="auth.enabled">yes</configuration>
    <configuration key="auth.username">user_import</configuration>
    <configuration key="auth.password">password_import</configuration>
  </configurations>
</operation>
```

A recording that is already in PuMuKIT is synchronized (tracks and pictures) instead of imported again. If the
mediapackage has Galicaster properties with `pmk_mmobj`, that multimedia object is completed (or cloned, if it already
has tracks).

Import notifications
--------------------

```yaml
pumukit_opencast:
  notifications:
    enabled: true
    subject: 'Your recording "{{multimediaObject.title}}" is now available'
    template: '<p>Hello, {{username}}. Check it at <a href="{{url}}">{{url}}</a></p>'
    url: pumukitnewadmin_mms_shortener
```

After an import, the bundle dispatches the `Pumukit\OpencastBundle\Message\ImportNotification` Messenger message.
Without routing, it is handled at once; to send the emails in the background, route it to a transport and run a
worker:

```yaml
framework:
  messenger:
    transports:
      async: '%env(MESSENGER_TRANSPORT_DSN)%'
    routing:
      Pumukit\OpencastBundle\Message\ImportNotification: async
```

The optional `worker` service of the `docker-compose.yml` of the core consumes every transport:

```bash
docker compose --profile worker up -d
```

Without it, run `php bin/console messenger:consume async`.

Commands
--------

With the configured server:

```bash
php bin/console pumukit:opencast:check
php bin/console pumukit:opencast:list
php bin/console pumukit:opencast:import <mediapackage> [--invert] [--mmobjid=<id>] [--language=es]
php bin/console pumukit:opencast:batchimport [--invert=1|0]
php bin/console pumukit:opencast:workflow:stop [--mediaPackageId=<id>] [--workflow=retract] [--dry-run]
```

`batchimport` imports every published recording that is not in PuMuKIT; it can run from cron (e.g.
`40 23 * * * php bin/console pumukit:opencast:batchimport --env=prod`). `workflow:stop` deletes the events that are no
longer published and ran the `retract` workflow, when `delete_archive_mediapackage` is true.

With another server, e.g. when migrating Opencast (`--host`, `-u`, `-p`, and optionally `--admin-host`, `--insecure`
and `--id`). They only list what they would do; add `--force` to execute:

```bash
php bin/console pumukit:opencast:import:multiple:host --host=https://new.opencast.example.org -u user -p password [--master] --force
php bin/console pumukit:opencast:import:segments --host=https://new.opencast.example.org -u user -p password --force
php bin/console pumukit:opencast:sync:series --host=https://new.opencast.example.org -u user -p password --force
php bin/console pumukit:opencast:delete:empty:personal:series --host=https://new.opencast.example.org -u user -p password --force
```

- `import:multiple:host` imports the published (or archived, with `--master`) tracks into the multimedia objects of
  that server that do not have them. Configure `url_mapping` with the paths of the new server first.
- `import:segments` imports the segments of the published mediapackages.
- `sync:series` creates in that server the series that it does not have.
- `delete:empty:personal:series` deletes from that server the empty personal series ("Videos of ...") and the empty
  "Nuevo" series that Galicaster creates.

Other Opencast versions
-----------------------

Every Opencast REST call goes through `Pumukit\OpencastBundle\Api\OpencastApiInterface`. The bundle has
`Opencast16Api` (Opencast 16 or later); the version of the server is read from `/info/health` and the first service
whose `supports($version)` returns true is used. To support an older Opencast, add a class to the project that
implements the interface (extending `Opencast16Api` to override only the calls that change):

```php
use Pumukit\OpencastBundle\Api\Opencast16Api;

final class Opencast15Api extends Opencast16Api
{
    public function supports(string $version): bool
    {
        return version_compare($version, '15.0.0', '>=') && version_compare($version, '16.0.0', '<');
    }
}
```

With autoconfiguration, the class is registered by implementing the interface. If no service supports the version,
the importer shows the version and the commands fail with "Opencast X is not supported".

Other bundles
-------------

`Pumukit\OpencastBundle\Services\ClientService` gives the configured server: `getAdminUrl()`, `getSchedulerUrl()`,
`getPlayerUrl()`, `getMediaPackage()`, `getMediaPackages()`, the series and users methods, and `isEnabled()`.
`OpencastService::generateSbsTrack()` generates the side by side video of a multimedia object. After every import,
`Pumukit\OpencastBundle\Event\ImportEvent` is dispatched with the multimedia object and the mediapackage.

Logs
----

The bundle logs to its own `opencast` channel, in `%kernel.logs_dir%/opencast-YYYY-MM-DD.log` (`PUMUKIT_LOG_MAX_FILES` files kept, 15 by default):
imports, synchronizations, Opencast errors, deleted events, series and users.
