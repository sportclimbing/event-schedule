<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Infrastructure\Container;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class SymfonyServiceContainerFactory
{
    public function create(): ContainerBuilder
    {
        $container = $this->build();
        $container->compile();

        return $container;
    }

    public function build(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../../../config/services'),
        );

        $loader->load('infrastructure.yaml');
        $loader->load('domain.yaml');

        return $container;
    }
}
