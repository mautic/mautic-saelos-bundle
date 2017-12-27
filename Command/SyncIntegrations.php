<?php

/*
 * @copyright   2017 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticSaelosBundle\Command;

use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\PluginBundle\Integration\AbstractIntegration;
use MauticPlugin\MauticSaelosBundle\Contracts\{
    CanPullCompanies,
    CanPullContacts,
    CanPushCompanies,
    CanPushContacts
};
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Translation\TranslatorInterface;


/**
 * Class SyncIntegrations
 *
 * @package Mautic\PluginBundle\Command
 */
class SyncIntegrations extends Command
{
    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var IntegrationHelper
     */
    private $integrationHelper;

    /**
     * SyncIntegrations constructor.
     *
     * @param TranslatorInterface $translator
     */
    public function __construct(TranslatorInterface $translator, IntegrationHelper $integrationHelper)
    {
        $this->translator = $translator;
        $this->integrationHelper = $integrationHelper;

        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('plugin:integrations:sync')
            ->setDescription('Sync leads, contacts, and companies with the integration.')
            ->addArgument(
                'integration',
                InputArgument::REQUIRED,
                'The integration with which to sync.',
                null
            )
            ->addOption(
                'start-date',
                'b',
                InputOption::VALUE_OPTIONAL,
                'The date back to which changes will be synced. Defaults to NOW() - `--time-interval=15`',
                'now'
            )
            ->addOption(
                'end-date',
                'e',
                InputOption::VALUE_OPTIONAL,
                'The date to which changes will be synced. Defaults to NOW()',
                'now'
            )
            ->addOption(
                'fetch-all',
                'a',
                InputOption::VALUE_NONE,
                'Get all CRM changes whatever the date is. Should be used at instance initialization only. Overrides `--start-date` and `--end-date`'
            )
            ->addOption(
                'time-interval',
                't',
                InputOption::VALUE_OPTIONAL,
                'Send time interval to check updates, it should be a correct php formatted time interval in the past eg:(15 minutes)',
                '15 minutes'
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Number of records to process when syncing objects',
                100
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force execution even if another process is assumed running.'
            );

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        defined('MAUTIC_CONSOLE_VERBOSITY') or define('MAUTIC_CONSOLE_VERBOSITY', $output->getVerbosity());

        $integration       = $input->getArgument('integration');
        $integrationObject = $this->getIntegrationObject($integration);
        $params            = $this->getParametersFromInput($input, $output);

        $integrationObject->setCommandParameters($params);

        if ($integrationObject instanceof CanPullContacts && $integrationObject->shouldPullContacts()) {
            $output->writeln('<info>'.$this->translator->trans('mautic.plugin.command.fetch.leads', ['%integration%' => $integration]).'</info>');
            $output->writeln('<comment>'.$this->translator->trans('mautic.plugin.command.fetch.leads.starting').'</comment>');

            list($justUpdated, $justCreated) = $integrationObject->pullContacts($params);

            $output->writeln('');
            $output->writeln(
                '<comment>'.$this->translator->trans(
                    'mautic.plugin.command.fetch.leads.events_executed_breakout',
                    ['%updated%' => $justUpdated, '%created%' => $justCreated]
                )
                .'</comment>'."\n"
            );
        }

        if ($integrationObject instanceof CanPullCompanies && $integrationObject->shouldPullCompanies()) {
            $output->writeln('<info>'.$this->translator->trans('mautic.plugin.command.fetch.companies', ['%integration%' => $integration]).'</info>');
            $output->writeln('<comment>'.$this->translator->trans('mautic.plugin.command.fetch.companies.starting').'</comment>');

            list($justUpdated, $justCreated) = $integrationObject->pullCompanies($params);

            $output->writeln('');
            $output->writeln(
                '<comment>'.$this->translator->trans(
                    'mautic.plugin.command.fetch.companies.events_executed_breakout',
                    ['%updated%' => $justUpdated, '%created%' => $justCreated]
                )
                .'</comment>'."\n"
            );
        }

        if ($integrationObject instanceof CanPushContacts && $integrationObject->shouldPushContacts()) {
            $output->writeln('<info>'.$this->translator->trans('mautic.plugin.command.pushing.leads', ['%integration%' => $integration]).'</info>');
            $ignored = $errored = 0;

            list($justUpdated, $justCreated) = $integrationObject->pushContacts($params);

            $output->writeln('');
            $output->writeln(
                '<comment>'.$this->translator->trans(
                    'mautic.plugin.command.fetch.pushing.leads.events_executed',
                    [
                        '%updated%' => $justUpdated,
                        '%created%' => $justCreated,
                        '%errored%' => $errored,
                        '%ignored%' => $ignored,
                    ]
                )
                .'</comment>'."\n"
            );
        }

        if ($integrationObject instanceof CanPushCompanies && $integrationObject->shouldPushCompanies()) {
            $output->writeln('<info>'.$this->translator->trans('mautic.plugin.command.pushing.companies', ['%integration%' => $integration]).'</info>');
            $ignored = $errored = 0;

            list($justUpdated, $justCreated) = $integrationObject->pushCompanies($params);

            $output->writeln('');
            $output->writeln(
                '<comment>'.$this->translator->trans(
                    'mautic.plugin.command.fetch.pushing.companies.events_executed',
                    [
                        '%updated%' => $justUpdated,
                        '%created%' => $justCreated,
                        '%errored%' => $errored,
                        '%ignored%' => $ignored,
                    ]
                )
                .'</comment>'."\n"
            );
        }

        return 0;
    }

    /**
     * @param string $startDate
     * @param string $interval
     *
     * @return false|string
     */
    private function formatStartDate($startDate, $interval = '15 minutes')
    {
        return !$startDate || $startDate === 'now' ? date('c', strtotime('-'.$interval)) : date('c', strtotime($startDate));
    }

    /**
     * @param string $endDate
     *
     * @return false|string
     */
    private function formatEndDate($endDate)
    {
        return !$endDate ? date('c') : date('c', strtotime($endDate));
    }

    /**
     * @param $integration
     *
     * @return AbstractIntegration
     *
     * @throws \RuntimeException When the integration has not been propertly configured or authorized.
     */
    private function getIntegrationObject($integration)
    {
        $integrationObject = $this->integrationHelper->getIntegrationObject($integration);

        if (!$integrationObject instanceof AbstractIntegration) {
            $availableIntegrations = array_filter($this->integrationHelper->getIntegrationObjects(), function (AbstractIntegration $availableIntegration) {
                return $availableIntegration->isConfigured();
            });

            throw new \RuntimeException(
                sprintf(
                    'The Integration "%s" is not one of the available integrations (%s)',
                    $integration,
                    implode(', ', array_keys($availableIntegrations))
                ),
                255
            );
        }

        $config = $integrationObject->mergeConfigToFeatureSettings();

        if (!isset($config['objects'])) {
            throw new \RuntimeException(
                sprintf(
                    'The Integration %s is not configured to push or pull any objects.',
                    $integration
                ),
                255
            );
        }

        if (!$integrationObject->isAuthorized()) {
            throw new \RuntimeException(
                sprintf(
                    '<error>ERROR:</error> <info>'.$this->translator->trans('mautic.plugin.command.notauthorized').'</info>',
                    $integration
                ),
                255
            );
        }

        // Tell audit log to use integration name
        define('MAUTIC_AUDITLOG_USER', $integration);

        // set this constant to ensure that all contacts have the same date modified time and date synced time to prevent a pull/push loop
        define('MAUTIC_DATE_MODIFIED_OVERRIDE', time());

        return $integrationObject;
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return array
     *
     * @throws \RuntimeException
     */
    private function getParametersFromInput(InputInterface $input, OutputInterface $output)
    {
        $startDate = $this->formatStartDate($input->getOption('start-date'), $input->getOption('time-interval'));
        $endDate   = $this->formatEndDate($input->getOption('end-date'));

        if (!$startDate || !$endDate || ($startDate > $endDate)) {
            throw new \RuntimeException(
                sprintf(
                    '<info>Invalid date range given %s -> %s</info>',
                    $startDate,
                    $endDate
                ),
                255
            );
        }

        return [
            'start'    => $startDate,
            'end'      => $endDate,
            'limit'    => $input->getOption('limit'),
            'fetchAll' => $input->getOption('fetch-all'),
            'output'   => $output,
        ];
    }
}
