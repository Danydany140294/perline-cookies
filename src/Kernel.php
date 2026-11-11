<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Fuseau horaire de l'application : indépendant du php.ini de la machine
     * (web, console, messenger:consume et tests passent tous par le Kernel).
     */
    public function boot(): void
    {
        date_default_timezone_set('Europe/Paris');

        parent::boot();
    }
}
