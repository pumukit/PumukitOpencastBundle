<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\DependencyInjection;

use Pumukit\OpencastBundle\Shared\Config\NotificationConfig;
use Pumukit\OpencastBundle\Shared\Config\OpencastConfig;
use Pumukit\OpencastBundle\Shared\Config\SbsConfig;
use Pumukit\OpencastBundle\Shared\Config\UrlMappingConfig;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Definition;

class PumukitOpencastExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $env = $container->getParameter('kernel.environment');
        $this->validateOpencastConfiguration($config['host'], $config['url_mapping'], $env);

        $this->generateServiceConfiguration($config, $container);

        $container->setParameter('pumukit_opencast.show_importer_tab', $config['show_importer_tab']);
        $container->setParameter('pumukit_opencast.seconds_to_sleep_on_commands', $config['seconds_to_sleep_on_commands']);

        $container->setParameter('pumukit_opencast.sbs', $config['sbs']);
        $container->setParameter('pumukit_opencast.sbs.generate_sbs', $config['sbs']['generate_sbs'] ?: false);
        $container->setParameter('pumukit_opencast.sbs.profile', $config['sbs']['generate_sbs'] ? $config['sbs']['profile'] : null);
        $container->setParameter('pumukit_opencast.sbs.use_flavour', $config['sbs']['generate_sbs'] ? $config['sbs']['use_flavour'] : false);
        $container->setParameter('pumukit_opencast.sbs.flavour', $config['sbs']['use_flavour'] ? $config['sbs']['flavour'] : null);

        $container->setParameter('pumukit_opencast.use_redirect', $config['use_redirect']);
        $container->setParameter('pumukit_opencast.error_if_file_not_exist', $config['error_if_file_not_exist']);
        $container->setParameter('pumukit_opencast.batchimport_inverted', $config['batchimport_inverted']);
        $container->setParameter('pumukit_opencast.delete_archive_mediapackage', $config['delete_archive_mediapackage']);
        $container->setParameter('pumukit_opencast.deletion_workflow_name', $config['deletion_workflow_name']);
        $container->setParameter('pumukit_opencast.url_mapping', $config['url_mapping']);
        $container->setParameter('pumukit_opencast.manage_opencast_users', $config['manage_opencast_users']);
        $container->setParameter('pumukit_opencast.seconds_to_sleep_on_commands', $config['seconds_to_sleep_on_commands']);

        $container->setParameter('pumukit_opencast.scheduler_on_menu', $config['scheduler_on_menu']);
        $container->setParameter('pumukit_opencast.scheduler', $config['scheduler']);
        $container->setParameter('pumukit_opencast.host', $config['host']);
        $container->setParameter('pumukit_opencast.admin_host', $config['admin_host']);
        $container->setParameter('pumukit_opencast.username', $config['username']);
        $container->setParameter('pumukit_opencast.password', $config['password']);
        $container->setParameter('pumukit_opencast.player', $config['player']);
        $container->setParameter('pumukit_opencast.dashboard_on_menu', $config['dashboard_on_menu']);
        $container->setParameter('pumukit_opencast.dashboard', $config['dashboard']);
        $container->setParameter('pumukit_opencast.default_tag_imported', $config['default_tag_imported']);
        $container->setParameter('pumukit_opencast.notifications', $config['notifications']);
        $container->setParameter('pumukit_opencast.default_vars', []);
        $container->setParameter('pumukit_opencast.insecure', $config['insecure']);

        $permissions = [['role' => 'ROLE_ACCESS_IMPORTER', 'description' => 'Access Importer']];
        $newPermissions = array_merge($container->getParameter('pumukitschema.external_permissions'), $permissions);
        $container->setParameter('pumukitschema.external_permissions', $newPermissions);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('pumukit_opencast.yaml');

        if ($config['sync_series_with_opencast']) {
            $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
            $loader->load('pumukit_opencast_series_listener.yaml');
        }
    }

    private function validateOpencastConfiguration(string $host, array $urlMapping, string $env): void
    {
        // Note: %env(SECRET)% is env_SECRET_%rand% in the DependencyInjection Extension.
        $isHostEnvVar = str_starts_with($host, 'env_');

        if (!$isHostEnvVar && !filter_var($host, FILTER_VALIDATE_URL)) {
            throw new InvalidConfigurationException(sprintf(
                'The parameter "pumukit_opencast.host" is not a valid url: "%s" ',
                $host
            ));
        }
    }

    private function generateServiceConfiguration(array $config, ContainerBuilder $container): void
    {
        $sbsConfig = new Definition(SbsConfig::class, [
            $config['sbs']['generate_sbs'],
            $config['sbs']['profile'],
            $config['sbs']['use_flavour'],
            $config['sbs']['flavour'],
        ]);

        $notificationConfig = new Definition(NotificationConfig::class, [
            $config['notifications']['enabled'] ?? false,
            $config['notifications']['template'] ?? null,
            $config['notifications']['url'] ?? null,
            $config['notifications']['subject'] ?? null,
        ]);

        $urlMappings = array_map(
            fn (array $m) => new Definition(UrlMappingConfig::class, [$m['url'], $m['path']]),
            $config['url_mapping'] ?? []
        );

        $container->register(OpencastConfig::class, OpencastConfig::class)
            ->setArguments([
                $config['host'],
                $config['admin_host'],
                $config['username'],
                $config['password'],
                $config['player'],
                $config['use_redirect'],
                $config['batchimport_inverted'],
                $config['show_importer_tab'],
                $config['delete_archive_mediapackage'],
                $config['deletion_workflow_name'],
                $config['scheduler_on_menu'],
                $config['scheduler'],
                $config['manage_opencast_users'],
                $config['sync_series_with_opencast'],
                $config['insecure'],
                $notificationConfig,
                $sbsConfig,
                $config['error_if_file_not_exist'],
                $urlMappings,
            ])
            ->setPublic(true);
    }
}
