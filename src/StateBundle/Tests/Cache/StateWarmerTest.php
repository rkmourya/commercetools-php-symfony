<?php
/**
 *
 */

namespace Commercetools\Symfony\StateBundle\Tests\Cache;

use Commercetools\Symfony\StateBundle\Cache\StateKeyResolver;
use Commercetools\Symfony\StateBundle\Cache\StateWarmer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StateWarmerTest extends TestCase
{
    public function testWarmUp()
    {
        $stateKeyResolver = $this->prophesize(StateKeyResolver::class);
        $logger = $this->prophesize(LoggerInterface::class);
        $stateKeyResolver->fillCache()->shouldBeCalledOnce();

        $stateWarmer = new StateWarmer($stateKeyResolver->reveal(), $logger->reveal());
        $this->assertTrue($stateWarmer->isOptional());
        $stateWarmer->warmUp(null);
    }
}
