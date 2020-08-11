<?php

declare(strict_types=1);

namespace Whatwedo\SyliusGoCryptoPaymentPlugin;

use Sylius\Bundle\CoreBundle\Application\SyliusPluginTrait;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class WhatwedoSyliusGoCryptoPaymentPlugin extends Bundle
{
    use SyliusPluginTrait;
}
