<?php
/**
 * This file is part of EnviarFacturaEmailApi plugin for FacturaScripts
 * Copyright (C) 2026 Anton Gomez
 */

namespace FacturaScripts\Plugins\EnviarFacturaEmailApi;

use FacturaScripts\Core\Controller\ApiRoot;
use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Template\InitClass;

class Init extends InitClass
{
    public function init(): void
    {
        // registramos la ruta del endpoint personalizado (con comodín para el id de la factura)
        Kernel::addRoute('/api/3/enviarFacturaCliente/*', 'ApiEnviarFacturaCliente', -1);

        // exponemos el recurso para que aparezca en el sistema de permisos de la API key
        ApiRoot::addCustomResource('enviarFacturaCliente');
    }

    public function update(): void
    {
    }

    public function uninstall(): void
    {
    }
}
