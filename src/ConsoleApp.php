<?php declare(strict_types=1);

namespace Romanzaycev\Vibe;

use Symfony\Component\Console\Application;

class ConsoleApp extends Application
{
    public function __construct(string $name = 'Vibeduck CLI', string $version = '0.0.1')
    {
        parent::__construct($name, $version);
    }
}
