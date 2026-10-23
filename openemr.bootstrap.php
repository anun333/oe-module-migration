<?php

/**
 * Migration module bootstrap, loaded by OpenEMR on every request while the module is enabled.
 *
 * @package   OpenHealthcare\Migration
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

use OpenEMR\Core\ModulesClassLoader;
use OpenEMR\Core\OEGlobalsBag;
use OpenHealthcare\Migration\Bootstrap;

// OEGlobalsBag came with OpenEMR 7.0.4 and 8.0. OpenEMR loads this file on every request, the login
// page included, so on an older OpenEMR the module stays switched off rather than stop every page.
if (!class_exists(OEGlobalsBag::class)) {
    return;
}

$classLoader = new ModulesClassLoader(OEGlobalsBag::getInstance()->getProjectDir());
$classLoader->registerNamespaceIfNotExists('OpenHealthcare\\Migration\\', __DIR__ . DIRECTORY_SEPARATOR . 'src');

(new Bootstrap(OEGlobalsBag::getInstance()->getKernel()->getEventDispatcher()))->subscribeToEvents();
