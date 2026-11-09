<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Pumukit\OpencastBundle\Api\OpencastApiInterface;
use Pumukit\OpencastBundle\Controller\MultimediaObjectController;
use Pumukit\OpencastBundle\DependencyInjection\PumukitOpencastExtension;
use Pumukit\OpencastBundle\EventListener\SeriesListener;
use Pumukit\OpencastBundle\Routing\RouteLoader;
use Pumukit\OpencastBundle\Services\ClientService;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

/**
 * @internal
 *
 * @coversNothing
 */
class PumukitOpencastExtensionTest extends TestCase
{
    private const PERMISSION = ['role' => 'ROLE_ACCESS_IMPORTER', 'description' => 'Access Importer'];

    public function testTheDisabledBundleOnlyRegistersTheRouteLoader(): void
    {
        $container = $this->load(['enable' => false]);

        $this->assertSame([RouteLoader::class], $this->opencastServices($container));
        $this->assertSame('%pumukit_opencast.enable%', $container->getDefinition(RouteLoader::class)->getArgument(0));
        $this->assertSame([], $container->getParameter('pumukitschema.external_permissions'));
        $this->assertFalse($container->hasAlias('pumukit_opencast.client'));
        $this->assertArrayNotHasKey(OpencastApiInterface::class, $container->getAutoconfiguredInstanceof());
        $this->assertFalse($container->getParameter('pumukit_opencast.show_importer_tab'));
    }

    public function testTheEnabledBundleRegistersItsServicesAndItsPermission(): void
    {
        $container = $this->load(['host' => 'https://opencast.example.org']);

        $this->assertTrue($container->hasDefinition(ClientService::class));
        $this->assertTrue($container->hasDefinition(SeriesListener::class));
        $this->assertTrue($container->hasDefinition(MultimediaObjectController::class));
        $this->assertTrue($container->hasDefinition(RouteLoader::class));
        $this->assertTrue($container->hasAlias('pumukit_opencast.client'));
        $this->assertArrayHasKey(OpencastApiInterface::class, $container->getAutoconfiguredInstanceof());
        $this->assertSame([self::PERMISSION], $container->getParameter('pumukitschema.external_permissions'));
    }

    public function testTheDisabledBundleAddsNoLogChannel(): void
    {
        $this->assertSame([], $this->prepend(['enable' => false])->getExtensionConfig('monolog'));
    }

    public function testTheEnabledBundleAddsItsLogChannel(): void
    {
        $this->assertSame([PumukitOpencastExtension::LOG_CHANNEL], $this->prepend(['host' => 'https://opencast.example.org'])->getExtensionConfig('monolog')[0]['channels']);
    }

    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('pumukitschema.external_permissions', []);
        (new PumukitOpencastExtension())->load([$config], $container);

        return $container;
    }

    private function prepend(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void {}

            public function getAlias(): string
            {
                return 'monolog';
            }
        });
        $extension = new PumukitOpencastExtension();
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), $config);
        $extension->prepend($container);

        return $container;
    }

    private function opencastServices(ContainerBuilder $container): array
    {
        return array_values(array_filter(array_keys($container->getDefinitions()), static fn (string $id): bool => str_starts_with($id, 'Pumukit\OpencastBundle\\')));
    }
}
