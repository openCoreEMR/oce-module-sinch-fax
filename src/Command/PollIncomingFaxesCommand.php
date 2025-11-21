<?php

/**
 * Symfony CLI Command to poll for incoming faxes
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Command;

use OpenCoreEMR\Modules\SinchFax\Service\FaxService;
use OpenCoreEMR\Modules\SinchFax\GlobalConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class PollIncomingFaxesCommand extends Command
{
    /** @var string */
    protected static $defaultName = 'sinchfax:poll-incoming';
    /** @var string */
    protected static $defaultDescription = 'Poll Sinch API for new incoming faxes';

    private readonly GlobalConfig $config;
    private readonly FaxService $faxService;

    public function __construct()
    {
        parent::__construct();
        $this->config = new GlobalConfig();
        $this->faxService = new FaxService($this->config);
    }

    protected function configure(): void
    {
        $this->setHelp('This command polls the Sinch API for new incoming faxes and saves them to the database.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->config->isEnabled()) {
            $io->error('Sinch Fax module is not enabled');
            return Command::FAILURE;
        }

        if (!$this->config->isIncomingPollingEnabled()) {
            $io->warning('Incoming fax polling is not enabled in configuration');
            return Command::SUCCESS;
        }

        $io->title('Polling for Incoming Faxes');

        try {
            $io->text('Querying Sinch API for incoming faxes...');
            $newFaxCount = $this->faxService->pollIncomingFaxes();

            if ($newFaxCount === 0) {
                $io->success('No new incoming faxes found');
            } else {
                $io->success(sprintf('Processed %d new incoming fax(es)', $newFaxCount));
            }

            $lastPollTime = $this->config->getLastPollTime();
            $io->text(sprintf('Last poll time: %s', $lastPollTime ?? 'Never'));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error(sprintf('Error polling for incoming faxes: %s', $e->getMessage()));
            return Command::FAILURE;
        }
    }
}
