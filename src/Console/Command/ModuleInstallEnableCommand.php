<?php

/**
 * Command to register, install SQL, and enable an OpenEMR module in one step
 *
 * @package   OpenEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright 2026 OpenCoreEMR Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'module:install-enable',
    description: 'Register, install SQL, and enable a module'
)]
class ModuleInstallEnableCommand extends AbstractModuleCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this->addArgument(
            'module',
            InputArgument::REQUIRED,
            'Module directory name (e.g., oce-module-sinch-fax)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->bootstrapOpenEmr($input, $output)) {
            return Command::FAILURE;
        }

        $moduleName = $this->getModuleName($input);
        $output->writeln("<info>Installing and enabling module: $moduleName</info>");
        $output->writeln('');

        try {
            $installer = $this->getInstaller();
            $installer->register($moduleName);
            $installer->install($moduleName);
            $installer->enable($moduleName);

            $output->writeln('');
            $output->writeln('<info>Module installed and enabled successfully.</info>');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
