<?php

/**
 * Mock MenuEvent for testing
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenEMR\Menu;

class MenuEvent
{
    public const MENU_UPDATE = 'menu.update';

    private array $menu = [];

    public function __construct()
    {
        // Create a sample menu structure
        $item = new \stdClass();
        $item->menu_id = 'modimg';
        $item->children = [];
        $this->menu[] = $item;
    }

    public function getMenu(): array
    {
        return $this->menu;
    }
}
