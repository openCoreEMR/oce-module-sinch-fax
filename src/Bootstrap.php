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

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Kernel;
use OpenEMR\Events\Globals\GlobalsInitializedEvent;
use OpenEMR\Menu\MenuEvent;
use OpenEMR\Services\Globals\GlobalSetting;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
    public const MODULE_NAME = "oce-module-sinch-fax";

    private readonly GlobalConfig $globalsConfig;
    private readonly \Twig\Environment $twig;
    private readonly SystemLogger $logger;

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        ?Kernel $kernel = null
    ) {
        if (!$kernel instanceof \OpenEMR\Core\Kernel) {
            $kernel = new Kernel();
        }

        $this->globalsConfig = new GlobalConfig();

        $templatePath = \dirname(__DIR__) . DIRECTORY_SEPARATOR . "templates" . DIRECTORY_SEPARATOR;
        $twig = new TwigContainer($templatePath, $kernel);
        $this->twig = $twig->getTwig();

        $this->logger = new SystemLogger();
        $this->logger->debug('Sinch Fax Bootstrap constructed');
    }

    public function subscribeToEvents(): void
    {
        $this->addGlobalSettings();
        $this->addMenuItems();

        if (!$this->globalsConfig->isConfigured()) {
            $this->logger->debug('Sinch Fax is not configured. Skipping event subscriptions.');
            return;
        }

        if (!$this->globalsConfig->isEnabled()) {
            $this->logger->debug('Sinch Fax is disabled. Skipping event subscriptions.');
            return;
        }

        $this->logger->debug('Sinch Fax module is enabled and configured');
    }

    public function addGlobalSettings(): void
    {
        $this->eventDispatcher->addListener(
            GlobalsInitializedEvent::EVENT_HANDLE,
            $this->addGlobalSettingsSection(...)
        );
    }

    public function addGlobalSettingsSection(GlobalsInitializedEvent $event): void
    {
        global $GLOBALS;

        $service = $event->getGlobalsService();
        $section = xlt("Sinch Fax");
        $service->createSection($section, 'Fax');

        $settings = $this->globalsConfig->getGlobalSettingSectionConfiguration();

        foreach ($settings as $key => $config) {
            $value = $GLOBALS[$key] ?? $config['default'];
            $service->appendToSection(
                $section,
                $key,
                new GlobalSetting(
                    xlt($config['title']),
                    $config['type'],
                    $value,
                    xlt($config['description']),
                    true
                )
            );
        }
    }

    public function addMenuItems(): void
    {
        $this->eventDispatcher->addListener(
            MenuEvent::MENU_UPDATE,
            $this->addSinchFaxMenuItem(...)
        );
    }

    public function addSinchFaxMenuItem(MenuEvent $event): void
    {
        $menu = $event->getMenu();

        $menuItem = [
            'label' => xlt('Sinch Fax'),
            'url' => '/interface/modules/custom_modules/' . self::MODULE_NAME . '/public/index.php',
            'icon' => 'fa-fax',
            'requirement' => 0,
            'global_req' => ['oce_sinch_fax_enabled' => true]
        ];

        foreach ($menu as $item) {
            if ($item->getMenuId() == 'modimg') {
                $item->addChild('sinchfax', $menuItem);
                break;
            }
        }
    }
}
