<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Pumukit\OpencastBundle\DependencyInjection\Configuration;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

/**
 * @internal
 *
 * @coversNothing
 */
class ConfigurationTest extends TestCase
{
    public function testTheDefaults(): void
    {
        $config = $this->process(['host' => 'https://opencast.example.org']);

        $this->assertTrue($config['enable']);
        $this->assertFalse($config['insecure']);
        $this->assertFalse($config['notifications']['enabled']);
        $this->assertSame('/paella7/ui/watch.html', $config['player']);
        $this->assertFalse($config['sbs']['generate_sbs']);
    }

    public function testTheRemovedOptionsAreRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['host' => 'https://opencast.example.org', 'use_redirect' => false]);
    }

    public function testTheHostIsRequired(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([]);
    }

    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }
}
