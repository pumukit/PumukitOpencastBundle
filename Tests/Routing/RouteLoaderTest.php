<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Tests\Routing;

use PHPUnit\Framework\TestCase;
use Pumukit\OpencastBundle\Routing\RouteLoader;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\Routing\Loader\AttributeDirectoryLoader;
use Symfony\Component\Routing\RouteCollection;

/**
 * @internal
 *
 * @coversNothing
 */
class RouteLoaderTest extends TestCase
{
    public function testNoRouteIsLoadedWhenItIsDisabled(): void
    {
        $this->assertCount(0, $this->load(false));
    }

    public function testTheControllerRoutesAreLoadedWhenItIsEnabled(): void
    {
        $routes = $this->load(true);

        $this->assertSame('/admin/opencast/mediapackage', $routes->get('pumukitopencast')?->getPath());
        $this->assertSame('/admin/opencast/mm/index/{id}', $routes->get('pumukit_opencast_mm_index')?->getPath());
        $this->assertSame('/api/opencast/import_event', $routes->get('pumukit_opencast_import_event')?->getPath());
    }

    private function load(bool $enabled): RouteCollection
    {
        $loader = new RouteLoader($enabled);
        $loader->setResolver(new LoaderResolver([$loader, new AttributeDirectoryLoader(new FileLocator(dirname(__DIR__, 2)), new AttributeRouteControllerLoader())]));

        return $loader->load('Controller/', RouteLoader::TYPE);
    }
}
