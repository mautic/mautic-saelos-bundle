<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\PluginBundle\Command;

use Mautic\PluginBundle\Integration\AbstractIntegration;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Mautic\CoreBundle\Translation\Translator;

/**
 * Class SyncIntegrations
 *
 * @package Mautic\PluginBundle\Command
 */
class SyncIntegrations extends ContainerAwareCommand
{
    /**
     * @var Translator
     */
    private $translator;

    /**
     * SyncIntegrations constructor.
     *
     * @param Translator $translator
     */
    public function __construct(Translator $translator)
    {
        $this->translator = $translator;

        $this->configure();
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
                '--start-date',
                '-s',
                InputOption::VALUE_OPTIONAL,
                'The date back to which changes will be synced. Defaults to NOW() - `--time-interval=15`',
                'now'
            )
            ->addOption(
                '--end-date',
                '-e',
                InputOption::VALUE_OPTIONAL,
                'The date to which changes will be synced. Defaults to NOW()',
                'now'
            )
            ->addOption(
                '--fetch-all',
                null,
                InputOption::VALUE_NONE,
                'Get all CRM changes whatever the date is. Should be used at instance initialization only. Overrides `--start-date` and `--end-date`'
            )
            ->addOption(
                '--time-interval',
                '-t',
                InputOption::VALUE_OPTIONAL,
                'Send time interval to check updates, it should be a correct php formatted time interval in the past eg:(15 minutes)',
                '15 minutes'
            )
            ->addOption(
                '--limit',
                '-l',
                InputOption::VALUE_OPTIONAL,
                'Number of records to process when syncing objects',
                100
            )
            ->addOption(
                '--force',
                '-f',
                InputOption::VALUE_NONE,
                'Force execution even if another process is assumed running.'
            );

        parent::configure();
    }

    private function formatStartDate($startDate, $interval = '15 minutes')
    {
        return !$startDate || $startDate === 'now' ? date('c', strtotime('-'.$interval)) : date('c', strtotime($startDate));
    }

    private function formatEndDate($endDate)
    {
        return !$endDate ? date('c') : date('c', strtotime($endDate));
    }

    /**
     * @param $integration
     *
     * @return AbstractIntegration
     *
     * @throws \RuntimeException When the integration has not been configured or authorized.
     */
    private function getIntegrationObject($integration)
    {
        $integrationHelper = $this->getContainer()->get('mautic.helper.integration');
        $integrationObject = $integrationHelper->getIntegrationObject($integration);

        if (!$integrationObject instanceof AbstractIntegration) {
            $availableIntegrations = array_filter($integrationHelper->getIntegrationObjects(), function (AbstractIntegration $availableIntegration) {
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
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        defined('MAUTIC_CONSOLE_VERBOSITY') or define('MAUTIC_CONSOLE_VERBOSITY', $output->getVerbosity());

        $integration       = $input->getArgument('integration');
        $startDate         = $this->formatStartDate($input->getOption('start-date'), $this->getOption('time-interval'));
        $endDate           = $this->formatEndDate($input->getOption('end-date'));
        $limit             = $input->getOption('limit');
        $fetchAll          = $input->getOption('fetch-all');
        $leadsExecuted     = $contactsExecuted = null;
        $integrationObject = $this->getIntegrationObject($integration);

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

        $params['start']    = $startDate;
        $params['end']      = $endDate;
        $params['limit']    = $limit;
        $params['fetchAll'] = $fetchAll;
        $params['output']   = $output;

        $integrationObject->setCommandParameters($params);

        $config            = $integrationObject->mergeConfigToFeatureSettings();
        $supportedFeatures = $integrationObject->getIntegrationSettings()->getSupportedFeatures();

        if (!isset($config['objects'])) {
            throw new \RuntimeException(
                sprintf(
                    'The %s integration is not configured to push or pull any objects.',
                    $integration
                ),
                255
            );
        }

        if (isset($supportedFeatures) && in_array('get_leads', $supportedFeatures)) {
            if ($integrationObject !== null && method_exists($integrationObject, 'getLeads') && isset($config['objects'])) {
                $output->writeln('<info>'.$this->translator->trans('mautic.plugin.command.fetch.leads', ['%integration%' => $integration]).'</info>');
                $output->writeln('<comment>'.$this->translator->trans('mautic.plugin.command.fetch.leads.starting').'</comment>');

                //Handle case when integration object are named "Contacts" and "Leads"
                $leadObjectName = 'Lead';
                if (in_array('Leads', $config['objects'])) {
                    $leadObjectName = 'Leads';
                }
                $contactObjectName = 'Contact';
                if (in_array(strtolower('Contacts'), array_map(function ($i) {
                    return strtolower($i);
                }, $config['objects']), true)) {
                    $contactObjectName = 'Contacts';
                }

                $updated = $created = $processed = 0;
                if (in_array($leadObjectName, $config['objects'])) {
                    $leadList = [];
                    $results  = $integrationObject->getLeads($params, null, $leadsExecuted, $leadList, $leadObjectName);
                    if (is_array($results)) {
                        list($justUpdated, $justCreated) = $results;
                        $updated += (int) $justUpdated;
                        $created += (int) $justCreated;
                    } else {
                        $processed += (int) $results;
                    }
                }
                if (in_array(strtolower($contactObjectName), array_map(function ($i) {
                    return strtolower($i);
                }, $config['objects']), true)) {
                    $output->writeln('');
                    $output->writeln('<comment>'.$this->translator->trans('mautic.plugin.command.fetch.contacts.starting').'</comment>');
                    $contactList = [];
                    $results     = $integrationObject->getLeads($params, null, $contactsExecuted, $contactList, $contactObjectName);
                    if (is_array($results)) {
                        list($justUpdated, $justCreated) = $results;
                        $updated += (int) $justUpdated;
                        $created += (int) $justCreated;
                    } else {
                        $processed += (int) $results;
                    }
                }

                $output->writeln('');

                if ($processed) {
                    $output->writeln(
                        '<comment>'.$this->translator->trans('mautic.plugin.command.fetch.leads.events_executed', ['%events%' => $processed])
                        .'</comment>'."\n"
                    );
                } else {
                    $output->writeln(
                        '<comment>'.$this->translator->trans(
                            'mautic.plugin.command.fetch.leads.events_executed_breakout',
                            ['%updated%' => $updated, '%created%' => $created]
                        )
                        .'</comment>'."\n"
                    );
                }
            }

            if ($integrationObject !== null && method_exists($integrationObject, 'getCompanies') && isset($config['objects'])
                && in_array(
                    'company',
                    $config['objects']
                )
            ) {
                $updated = $created = $processed = 0;
                $output->writeln('<info>'.$this->translator->trans('mautic.plugin.command.fetch.companies', ['%integration%' => $integration]).'</info>');
                $output->writeln('<comment>'.$this->translator->trans('mautic.plugin.command.fetch.companies.starting').'</comment>');

                $results = $integrationObject->getCompanies($params);
                if (is_array($results)) {
                    list($justUpdated, $justCreated) = $results;
                    $updated += (int) $justUpdated;
                    $created += (int) $justCreated;
                } else {
                    $processed += (int) $results;
                }
                $output->writeln('');
                if ($processed) {
                    $output->writeln(
                        '<comment>'.$this->translator->trans('mautic.plugin.command.fetch.companies.events_executed', ['%events%' => $processed])
                        .'</comment>'."\n"
                    );
                } else {
                    $output->writeln(
                        '<comment>'.$this->translator->trans(
                            'mautic.plugin.command.fetch.companies.events_executed_breakout',
                            ['%updated%' => $updated, '%created%' => $created]
                        )
                        .'</comment>'."\n"
                    );
                }
            }
        }

        if (isset($supportedFeatures) && in_array('push_leads', $supportedFeatures) && method_exists($integrationObject, 'pushLeads')) {
            $output->writeln('<info>'.$this->translator->trans('mautic.plugin.command.pushing.leads', ['%integration%' => $integration]).'</info>');
            $result  = $integrationObject->pushLeads($params);
            $ignored = 0;

            if (4 === count($result)) {
                list($updated, $created, $errored, $ignored) = $result;
            } elseif (3 === count($result)) {
                list($updated, $created, $errored) = $result;
            } else {
                $errored                 = '?';
                list($updated, $created) = $result;
            }
            $output->writeln(
                '<comment>'.$this->translator->trans(
                    'mautic.plugin.command.fetch.pushing.leads.events_executed',
                    [
                        '%updated%' => $updated,
                        '%created%' => $created,
                        '%errored%' => $errored,
                        '%ignored%' => $ignored,
                    ]
                )
                .'</comment>'."\n"
            );
        }

        if (method_exists($integrationObject, 'pushCompanies')) {
            $output->writeln('<info>'.$this->translator->trans('mautic.plugin.command.pushing.companies', ['%integration%' => $integration]).'</info>');
            $result  = $integrationObject->pushCompanies($params);
            $ignored = 0;

            if (4 === count($result)) {
                list($updated, $created, $errored, $ignored) = $result;
            } elseif (3 === count($result)) {
                list($updated, $created, $errored) = $result;
            } else {
                $errored                 = '?';
                list($updated, $created) = $result;
            }
            $output->writeln(
                '<comment>'.$this->translator->trans(
                    'mautic.plugin.command.fetch.pushing.companies.events_executed',
                    [
                        '%updated%' => $updated,
                        '%created%' => $created,
                        '%errored%' => $errored,
                        '%ignored%' => $ignored,
                    ]
                )
                .'</comment>'."\n"
            );
        }

        return 0;
    }
}
