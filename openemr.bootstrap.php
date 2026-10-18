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

$classLoader = new ModulesClassLoader(OEGlobalsBag::getInstance()->getProjectDir());
$classLoader->registerNamespaceIfNotExists('OpenHealthcare\\Migration\\', __DIR__ . DIRECTORY_SEPARATOR . 'src');

(new Bootstrap(OEGlobalsBag::getInstance()->getKernel()->getEventDispatcher()))->subscribeToEvents();
