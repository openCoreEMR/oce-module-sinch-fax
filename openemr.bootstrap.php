<?php

/**
 * Initializes the OpenCoreEmr Sinch Fax Module
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax;

/**
 * @var \OpenEMR\Core\ModulesClassLoader $classLoader Injected by the OpenEMR module loader
 */
$classLoader->registerNamespaceIfNotExists(
    'OpenCoreEMR\\Modules\\SinchFax\\',
    __DIR__ . DIRECTORY_SEPARATOR . 'src'
);

/**
 * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
 *      Injected by the OpenEMR module loader
 */
$bootstrap = new Bootstrap($eventDispatcher);
$bootstrap->subscribeToEvents();
