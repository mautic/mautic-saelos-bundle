<?php

namespace MauticPlugin\MauticSaelosBundle\Integration;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\PluginBundle\Exception\ApiErrorException;
use MauticPlugin\MauticSaelosBundle\Api\SaelosApi;
use MauticPlugin\MauticSaelosBundle\Contracts\CanPullContacts;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use MauticPlugin\MauticCrmBundle\Integration\CrmAbstractIntegration;

class SaelosIntegration extends CrmAbstractIntegration implements CanPullContacts
{
    private $objects = [
        'person',
        'company',
    ];

    /**
     * @var ProgressBar
     */
    private $progressBar;

    /**
     * @var SaelosApi
     */
    protected $apiHelper;

    /**
     * Get the API helper.
     *
     * @return SaelosApi
     */
    public function getApiHelper()
    {
        if (empty($this->apiHelper)) {
            $this->apiHelper = new SaelosApi($this);
        }

        return $this->apiHelper;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'Saelos';
    }

    /**
     * Get the array key for clientId.
     *
     * @return string
     */
    public function getClientIdKey()
    {
        return 'client_id';
    }

    /**
     * Get the array key for client secret.
     *
     * @return string
     */
    public function getClientSecretKey()
    {
        return 'client_secret';
    }

    /**
     * Get the array key for the auth token.
     *
     * @return string
     */
    public function getAuthTokenKey()
    {
        return 'access_token';
    }

    /**
     * {@inheritdoc}
     *
     * @return array
     */
    public function getRequiredKeyFields()
    {
        return [
            'client_id'     => 'mautic.integration.keyfield.consumerid',
            'client_secret' => 'mautic.integration.keyfield.consumersecret',
        ];
    }

    /**
     * Get the keys for the refresh token and expiry.
     *
     * @return array
     */
    public function getRefreshTokenKeys()
    {
        return ['refresh_token', ''];
    }

    /**
     * @return array
     */
    public function getSupportedFeatures()
    {
        return ['push_lead', 'get_leads', 'push_leads'];
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function getAccessTokenUrl()
    {
        return $this->settings->getFeatureSettings()['saelosUrl'].'/oauth/token';
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function getAuthenticationUrl()
    {
        return $this->settings->getFeatureSettings()['saelosUrl'].'/oauth/authorize';
    }

    /**
     * @return string
     */
    public function getApiUrl()
    {
        return $this->settings->getFeatureSettings()['saelosUrl'].'/api/v1';
    }

    /**
     * {@inheritdoc}
     *
     * @param bool $inAuthorization
     */
    public function getBearerToken($inAuthorization = false)
    {
        if (!$inAuthorization && isset($this->keys[$this->getAuthTokenKey()])) {
            return $this->keys[$this->getAuthTokenKey()];
        }

        return false;
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function getAuthenticationType()
    {
        return 'oauth2';
    }

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    public function getDataPriority()
    {
        return true;
    }

    /**
     * @return array
     */
    public function getFormSettings()
    {
        return [
            'requires_callback'      => false,
            'requires_authorization' => true,
            'default_features'       => [],
            'enable_data_priority'   => $this->getDataPriority(),
        ];
    }

    /**
     * @param $config
     *
     * @return array
     */
    public function getFetchQuery($config)
    {
        return [
            'page'           => $config['page'] ?? 1,
            'modified_since' => $config['start'] ?? new \DateTime(),
        ];
    }

    /**
     * @param        $progressBar
     * @param string $object
     */
    private function setProgressBar($progressBar, $object = '')
    {
        $this->progressBar = $progressBar;
        $this->progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% ('.$object.')');
    }

    /**
     * Instruct progress bar to advance.
     */
    private function advanceProgress()
    {
        if (isset($this->progessBar)) {
            $this->progressBar->advance();
        }
    }

    /**
     * Instruct progress bar to finish.
     */
    private function finishProgressBar()
    {
        if (isset($this->progressBar)) {
            $this->progressBar->finish();
        }
    }

    /**
     * @param $object
     *
     * @return string
     */
    public function getObjectName($object)
    {
        switch (strtolower($object)) {
            case 'lead':
            case 'leads':
            case 'contact':
            case 'contacts':
                return 'person';
            default:
                return $object;
        }
    }

    /**
     * Get available company fields for choices in the config UI.
     *
     * @param array $settings
     *
     * @return array
     *
     * @throws \Exception
     */
    public function getFormCompanyFields($settings = [])
    {
        $settings['feature_settings']['objects']['company'] = 'company';

        return ($this->isAuthorized()) ? $this->getAvailableLeadFields($settings) : [];
    }

    /**
     * Get available fields for choices in the config UI.
     *
     * @param array $settings
     *
     * @return array
     *
     * @throws \Exception
     */
    public function getFormLeadFields($settings = [])
    {
        if (isset($settings['feature_settings']['objects']['company'])) {
            unset($settings['feature_settings']['objects']['company']);
        }

        return ($this->isAuthorized()) ? $this->getAvailableLeadFields($settings)['person'] : [];
    }

    /**
     * @param array $settings
     *
     * @return array
     *
     * @throws \Exception
     */
    public function getAvailableLeadFields($settings = [])
    {
        $saelosFields      = [];
        $silenceExceptions = (isset($settings['silence_exceptions'])) ? $settings['silence_exceptions'] : true;

        if (isset($settings['feature_settings']['objects'])) {
            $saelosObjects = $settings['feature_settings']['objects'];
        } else {
            $saelosObjects = $this->objects;
        }

        try {
            if ($this->isAuthorized()) {
                if (!empty($saelosObjects) && is_array($saelosObjects)) {
                    foreach ($saelosObjects as $key => $object) {
                        $leadFields = $this->getApiHelper()->getFields($object);

                        if (isset($leadFields)) {
                            foreach ($leadFields as $fieldName => $details) {
                                if ($fieldName === 'company') {
                                    continue;
                                }

                                $saelosFields[$object][$fieldName] = [
                                    'type'     => 'string',
                                    'label'    => ucfirst($details['label']),
                                    'required' => $details['required'],
                                ];
                            }
                        }

                        if (isset($saelosFields[$object]['email'])) {
                            // Email is Required for this kind of integration
                            $saelosFields[$object]['email']['required'] = true;
                            $saelosFields[$object]['email']['type']     = 'string';

                            if (!isset($saelosFields[$object]['email']['label'])) {
                                $saelosFields[$object]['email']['label'] = 'Email';
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logIntegrationError($e);

            if (!$silenceExceptions) {
                throw $e;
            }
        }

        return $saelosFields;
    }

    /**
     * @param array  $params
     * @param null   $query
     * @param null   $executed
     * @param array  $result
     * @param string $object
     *
     * @return array|null
     */
    public function getLeads($params = [], $query = null, &$executed = null, $result = [], $object = 'person')
    {
        if (!$query) {
            $query = $this->getFetchQuery($params);
        }

        if (!is_array($executed)) {
            $executed = [
                0 => 0,
                1 => 0,
            ];
        }

        try {
            if ($this->isAuthorized()) {
                $processed = 0;
                $retry     = 0;
                $total     = 0;

                while (true) {
                    $results = $this->getApiHelper()->getLeads($query);

                    if (!isset($this->progressBar)) {
                        $total = $results['meta']['total'];
                        $this->setProgressBar(new ProgressBar($params['output'], $total), $object);
                    }

                    $results['data'] = $results['data'] ?? [];

                    list($justUpdated, $justCreated) = $this->amendLeadDataBeforeMauticPopulate($results['data'], $object);

                    $executed[0] += $justUpdated;
                    $executed[1] += $justCreated;
                    $processed += count($results['data']);

                    if (is_array($results) && array_key_exists('links', $results) && isset($results['links']['next'])) {
                        parse_str(parse_url($results['links']['next'], PHP_URL_QUERY), $linkParams);
                        $query['page'] = $linkParams['page'];
                    } else {
                        if ($processed < $total) {
                            // Something has gone wrong so try a few more times before giving up
                            if ($retry <= 5) {
                                $this->logger->debug("SAELOS: Processed less than total but didn't get a nextRecordsUrl in the response for getLeads ($object): ".var_export($result, true));

                                usleep(500);
                                ++$retry;

                                continue;
                            } else {
                                // Throw an exception cause something isn't right
                                throw new ApiErrorException("Expected to process $total but only processed $processed: ".var_export($result, true));
                            }
                        }
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logIntegrationError($e);
        }

        $this->finishProgressBar();

        return $executed;
    }

    /**
     * @param $data
     * @param $object
     *
     * @return array|null
     */
    public function amendLeadDataBeforeMauticPopulate($data, $object)
    {
        $updated               = 0;
        $created               = 0;
        $counter               = 0;
        $entity                = null;
        $detachClass           = null;
        $mauticObjectReference = null;
        $integrationMapping    = [];

        foreach ($data as $record) {
            $this->logger->debug('SAELOS: amendLeadDataBeforeMauticPopulate record '.var_export($record, true));
            $this->advanceProgress();
            $entity = false;

            foreach ($record['custom_fields'] as $customField) {
                $record['saelosCustom_'.$customField['alias']] = $customField['value'];
            }

            unset($record['deals']);
            unset($record['activities']);
            unset($record['user']);
            unset($record['customFields']);

            switch ($object) {
                case 'person':

                    unset($record['company']);

                    $entity                = $this->getMauticLead($record, true, null, null, 'lead');
                    $mauticObjectReference = 'lead';
                    $detachClass           = Lead::class;
                    break;
                default:
                    $this->logIntegrationError(
                        new \Exception(
                            sprintf('Received an unexpected object without an internalObjectReference "%s"', $object)
                        )
                    );
                    break;
            }

            if (!$entity) {
                continue;
            }

            $integrationMapping[$entity->getId()] = [
                'entity'                => $entity,
                'integration_entity_id' => $record['id'],
            ];

            if (method_exists($entity, 'isNewlyCreated') && $entity->isNewlyCreated()) {
                ++$created;
            } else {
                ++$updated;
            }

            ++$counter;

            if ($counter >= 100) {
                // Persist integration entities
                $this->buildIntegrationEntities($integrationMapping, $object, $mauticObjectReference, $params);
                $counter = 0;
                $this->em->clear($detachClass);
                $integrationMapping = [];
            }
        }

        if (count($integrationMapping)) {
            // Persist integration entities
            $this->buildIntegrationEntities($integrationMapping, $object, $mauticObjectReference, $params);
            $this->em->clear($detachClass);
        }

        $this->logger->debug('SALESFORCE: amendLeadDataBeforeMauticPopulate response '.var_export($data, true));

        unset($data);
        $this->persistIntegrationEntities = [];

        return [$updated, $created];
    }

    /**
     * @param \Mautic\PluginBundle\Integration\Form|FormBuilder $builder
     * @param array                                             $data
     * @param string                                            $formArea
     */
    public function appendToForm(&$builder, $data, $formArea)
    {
        if ($formArea == 'features') {
            $builder->add(
                'saelosUrl',
                UrlType::class,
                [
                    'label'    => 'mautic.saelos.form.url',
                    'required' => true,
                    'attr'     => [
                        'class'   => 'form-control',
                        'tooltip' => 'mautic.saelos.form.url.tooltip',
                    ],
                ]
            );

            $builder->add(
                'objects',
                'choice',
                [
                    'choices' => [
                        'person'  => 'mautic.saelos.object.lead',
                        'company' => 'mautic.saelos.object.company',
                    ],
                    'expanded'    => true,
                    'multiple'    => true,
                    'label'       => 'mautic.saelos.form.objects_to_pull_from',
                    'label_attr'  => ['class' => ''],
                    'empty_value' => false,
                    'required'    => false,
                ]
            );
        }
    }

    public function pullContacts($params = []): array
    {
        $query    = $this->getFetchQuery($params);
        $executed = [
            0 => 0,
            1 => 0,
        ];

        try {
            if ($this->isAuthorized()) {
                $processed = 0;
                $retry     = 0;
                $total     = 0;

                while (true) {
                    $results = $this->getApiHelper()->getLeads($query);

                    if (!isset($this->progressBar)) {
                        $total = $results['meta']['total'];
                        $this->setProgressBar(new ProgressBar($params['output'], $total), 'person');
                    }

                    $results['data'] = $results['data'] ?? [];

                    list($justUpdated, $justCreated) = $this->amendLeadDataBeforeMauticPopulate($results['data'], 'person');

                    $executed[0] += $justUpdated;
                    $executed[1] += $justCreated;
                    $processed += count($results['data']);

                    if (is_array($results) && array_key_exists('links', $results) && isset($results['links']['next'])) {
                        parse_str(parse_url($results['links']['next'], PHP_URL_QUERY), $linkParams);
                        $query['page'] = $linkParams['page'];
                    } else {
                        if ($processed < $total) {
                            // Something has gone wrong so try a few more times before giving up
                            if ($retry <= 5) {
                                $this->logger->debug("SAELOS: Processed less than total but didn't get a nextRecordsUrl in the response for getLeads (person): ".var_export($results, true));

                                usleep(500);
                                ++$retry;

                                continue;
                            } else {
                                // Throw an exception cause something isn't right
                                throw new ApiErrorException("Expected to process $total but only processed $processed: ".var_export($results, true));
                            }
                        }
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logIntegrationError($e);
        }

        $this->finishProgressBar();

        return $executed;
    }

    public function shouldPullContacts(): bool
    {
        $config = $this->settings->getFeatureSettings();

        return in_array('person', $config['objects']);
    }
}
