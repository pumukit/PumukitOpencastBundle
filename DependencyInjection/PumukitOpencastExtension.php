<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\DependencyInjection;

use Pumukit\OpencastBundle\Api\OpencastApiInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class PumukitOpencastExtension extends Extension implements PrependExtensionInterface
{
    public const LOG_CHANNEL = 'opencast';
    public const API_TAG = 'pumukit_opencast.api';

    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('monolog')) {
            $container->prependExtensionConfig('monolog', [
                'channels' => [self::LOG_CHANNEL],
                'handlers' => [
                    self::LOG_CHANNEL => [
                        'type' => 'rotating_file',
                        'path' => '%kernel.logs_dir%/opencast.log',
                        'level' => 'info',
                        'max_files' => '%env(int:PUMUKIT_LOG_MAX_FILES)%',
                        'channels' => [self::LOG_CHANNEL],
                        'bubble' => false,
                        'priority' => 100,
                    ],
                ],
            ]);
        }
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $config['host'] = (string) $config['host'];
        if ($config['enable'] && !str_starts_with($config['host'], 'env_') && !filter_var($config['host'], FILTER_VALIDATE_URL)) {
            throw new InvalidConfigurationException(sprintf('The parameter "pumukit_opencast.host" is not a valid url: "%s"', $config['host']));
        }

        $container->setParameter('pumukit_opencast.enable', $config['enable']);
        $container->setParameter('pumukit_opencast.host', rtrim($config['host'], '/'));
        $container->setParameter('pumukit_opencast.admin_host', null === $config['admin_host'] ? null : rtrim($config['admin_host'], '/'));
        $container->setParameter('pumukit_opencast.username', $config['username']);
        $container->setParameter('pumukit_opencast.password', $config['password']);
        $container->setParameter('pumukit_opencast.insecure', $config['insecure']);
        $container->setParameter('pumukit_opencast.player', $config['player']);
        $container->setParameter('pumukit_opencast.scheduler', $config['scheduler']);
        $container->setParameter('pumukit_opencast.show_importer_tab', $config['enable'] && $config['show_importer_tab']);
        $container->setParameter('pumukit_opencast.batchimport_inverted', $config['batchimport_inverted']);
        $container->setParameter('pumukit_opencast.delete_archive_mediapackage', $config['delete_archive_mediapackage']);
        $container->setParameter('pumukit_opencast.manage_opencast_users', $config['manage_opencast_users']);
        $container->setParameter('pumukit_opencast.sync_series_with_opencast', $config['sync_series_with_opencast']);
        $container->setParameter('pumukit_opencast.error_if_file_not_exist', $config['error_if_file_not_exist']);
        $container->setParameter('pumukit_opencast.url_mapping', $config['url_mapping']);
        $container->setParameter('pumukit_opencast.sbs', $config['sbs']);
        $container->setParameter('pumukit_opencast.default_tag_imported', $config['default_tag_imported']);
        $container->setParameter('pumukit_opencast.seconds_to_sleep_on_commands', $config['seconds_to_sleep_on_commands']);
        $container->setParameter('pumukit_opencast.notifications', $config['notifications']);

        $container->setParameter('pumukitschema.external_permissions', array_merge(
            $container->getParameter('pumukitschema.external_permissions'),
            [['role' => 'ROLE_ACCESS_IMPORTER', 'description' => 'Access Importer']],
        ));

        $container->registerForAutoconfiguration(OpencastApiInterface::class)->addTag(self::API_TAG);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('pumukit_opencast.yaml');
    }
}
