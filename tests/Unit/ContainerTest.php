<?php

namespace Holvek\WordPress\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Holvek\WordPress\Core\Container\Container;
use Holvek\WordPress\Core\Container\ContainerException;

class ContainerTest extends TestCase
{
    public function testSingletonInstanceResolution(): void
    {
        $container = new Container();
        $container->singleton('sample_service', function () {
            return new \stdClass();
        });

        $instance1 = $container->get('sample_service');
        $instance2 = $container->get('sample_service');

        $this->assertInstanceOf(\stdClass::class, $instance1);
        $this->assertSame($instance1, $instance2);
    }

    public function testThrowsContainerExceptionOnUnregisteredBinding(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('No binding found for nonexistent_service');

        $container->get('nonexistent_service');
    }
}
