<?php

namespace MauticPlugin\MauticSaelosBundle\Integration;

use Mautic\LeadBundle\Entity\Company;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PluginBundle\Exception\ApiErrorException;
use MauticPlugin\MauticSaelosBundle\Api\SaelosApi;
use MauticPlugin\MauticSaelosBundle\Contracts\CanPullCompanies;
use MauticPlugin\MauticSaelosBundle\Contracts\CanPullContacts;
use MauticPlugin\MauticSaelosBundle\Contracts\CanPushCompanies;
use MauticPlugin\MauticSaelosBundle\Contracts\CanPushContacts;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use MauticPlugin\MauticCrmBundle\Integration\CrmAbstractIntegration;

class SaelosIntegration extends CrmAbstractIntegration implements CanPullContacts, CanPullCompanies, CanPushContacts, CanPushCompanies
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
        return ['refresh_token', 'expires_at'];
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
        return false;
    }

    /**
     * @return array
     */
    public function getFormSettings()
    {
        return [
            'requires_callback'      => true,
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
        $this->progressBar->setFormat(' [%bar%] %current%/%max% %percent:3s%% ('.$object.')');
        $this->progressBar->start();
    }

    /**
     * Instruct progress bar to advance.
     */
    private function advanceProgress()
    {
        if ($this->progressBar instanceof ProgressBar) {
            $this->progressBar->advance();
        }
    }

    /**
     * Instruct progress bar to finish.
     * Unsets the progress bar for future imports.
     */
    private function finishProgressBar()
    {
        if ($this->progressBar instanceof ProgressBar) {
            $this->progressBar->finish();

            $this->progressBar = null;
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

        return ($this->isAuthorized()) ? $this->getAvailableLeadFields($settings)['company'] : [];
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
     * @param array  $data
     * @param string $object
     * @oaram array  $params
     *
     * @return array|null
     */
    public function amendLeadDataBeforeMauticPopulate($data, $object, $params = [])
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
                $record['saelosCustom_'.$customField['custom_field_id']] = $customField['value'];
            }

            unset($record['deals']);
            unset($record['activities']);
            unset($record['user']);
            unset($record['custom_fields']);

            switch ($object) {
                case 'person':
                    if (isset($record['company'])) {
                        unset($record['company']['people']);
                        unset($record['company']['deals']);
                        unset($record['company']['activities']);
                        unset($record['company']['user']);
                        unset($record['company']['custom_fields']);

                        $record['company'] = $this->getMauticCompany($record['company'], 'company')->getName();
                    }

                    $mauticObjectReference = 'lead';
                    $entity                = $this->getMauticLead($record, true, null, null, $mauticObjectReference);
                    $detachClass           = Lead::class;
                    break;
                case 'company':
                    unset($record['people']);

                    $mauticObjectReference = 'company';
                    $entity                = $this->getMauticCompany($record, $mauticObjectReference);
                    $detachClass           = Company::class;

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

                    list($justUpdated, $justCreated) = $this->amendLeadDataBeforeMauticPopulate($results['data'], 'person', $params);

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

    /**
     * @return bool
     */
    public function shouldPullContacts(): bool
    {
        if (!empty($this->commandParameters) && !empty($this->commandParameters['objects'])) {
            $objects = $this->commandParameters['objects'];
        } else {
            $objects = $this->settings->getFeatureSettings()['objects'];
        }

        $supportedFeatures = $this->settings->getSupportedFeatures() ?? [];

        return in_array('person', $objects) && in_array('get_leads', $supportedFeatures);
    }

    /**
     * @param array $params
     *
     * @return array
     */
    public function pushContacts($params = []): array
    {
        $config                  = $this->mergeConfigToFeatureSettings($params);
        $integrationEntityRepo   = $this->getIntegrationEntityRepository();

        $totalUpdated    = 0;
        $totalCreated    = 0;
        $totalErrors     = 0;

        unset($config['leadFields']['mauticContactTimelineLink']);

        //get company fields from Mautic that have been mapped
        $mauticLeadFieldString = 'l.'.implode(', l.', $config['leadFields']);

        $fieldKeys      = array_keys($config['leadFields']);
        $fieldsToCreate = $this->prepareFieldsForSync($config['leadFields'], $fieldKeys, 'person');
        $fieldsToUpdate = $fieldsToCreate;

        // Get a total number of companies to be updated and/or created for the progress counter
        $totalToUpdate = $integrationEntityRepo->findLeadsToUpdate(
            'Saelos',
            'lead',
            $mauticLeadFieldString,
            false,
            $params['start'],
            $params['end'],
            'person'
        )['person'];

        $totalToCreate = $integrationEntityRepo->findLeadsToCreate(
            'Saelos',
            $mauticLeadFieldString,
            false,
            $params['start'],
            $params['end'],
            'lead'
        );

        $totalCount = $totalToProcess = $totalToCreate + $totalToUpdate;

        if (!isset($this->progressBar)) {
            $this->setProgressBar(new ProgressBar($params['output'], $totalCount), 'lead');
        }

        while ($totalCount > 0) {
            if ($totalToUpdate) {
                $toUpdate = $integrationEntityRepo->findLeadsToUpdate(
                    'Saelos',
                    'lead',
                    $mauticLeadFieldString,
                    $params['limit'],
                    $params['start'],
                    $params['end'],
                    'person'
                )['person'];

                foreach ($toUpdate as $update) {
                    try {
                        $updateData = [
                            'custom_fields' => []
                        ];

                        foreach ($fieldsToUpdate as $integrationField => $mauticField) {
                            // @TODO
                            if ($mauticField === 'mauticContactTimelineLink') {
                                continue;
                            }

                            if (strpos($integrationField, 'saelosCustom_') === 0) {
                                $fieldId = (int) substr($integrationField, strlen('saelosCustom_'));

                                $updateData['custom_fields'][] = [
                                    'custom_field_id' => $fieldId,
                                    'value' => $update[$mauticField],
                                ];
                            } else {
                                $updateData[$integrationField] = $update[$mauticField];
                            }
                        }

                        $companyId = $this->getCompanyIdForLead($update['internal_entity_id']);

                        if ($companyId) {
                            $updateData['company_id'] = (int) $companyId;
                        }

                        $updatedContact = $this->getApiHelper()->updateContact($updateData, $update['integration_entity_id']);

                        $this->createIntegrationEntity(
                            'person',
                            $updatedContact['data']['id'],
                            'lead',
                            $update['internal_entity_id']
                        );

                        $totalUpdated++;
                    } catch (ApiErrorException $e) {
                        $totalErrors++;
                        continue;
                    } finally {
                        $totalCount--;
                        $this->advanceProgress();
                    }
                }
            }

            if ($totalToCreate) {
                $toCreate = $integrationEntityRepo->findLeadsToCreate(
                    'Saelos',
                    $mauticLeadFieldString,
                    $params['limit'],
                    $params['start'],
                    $params['end'],
                    'lead'
                );

                foreach ($toCreate as $create) {
                    try {
                        $createData = [
                            'custom_fields' => []
                        ];

                        foreach ($fieldsToCreate as $integrationField => $mauticField) {
                            // @TODO
                            if ($mauticField === 'mauticContactTimelineLink') {
                                continue;
                            }

                            if (strpos($integrationField, 'saelosCustom_') === 0) {
                                $fieldId = (int) substr($integrationField, strlen('saelosCustom_'));

                                $createData['custom_fields'][] = [
                                    'custom_field_id' => $fieldId,
                                    'value' => $create[$mauticField],
                                ];
                            } else {
                                $createData[$integrationField] = $create[$mauticField];
                            }
                        }

                        $companyId = $this->getCompanyIdForLead($create['internal_entity_id']);

                        if ($companyId) {
                            $createData['company_id'] = (int) $companyId;
                        }

                        $createdContact = $this->getApiHelper()->pushContact($createData);

                        $this->createIntegrationEntity(
                            'person',
                            $createdContact['data']['id'],
                            'lead',
                            $create['internal_entity_id']
                        );

                        $totalCreated++;
                    } catch (ApiErrorException $e) {
                        $totalErrors++;
                        continue;
                    } finally {
                        $totalCount--;
                        $this->advanceProgress();
                    }
                }
            }
        }

        $this->finishProgressBar();

        $this->logger->debug('SAELOS: '.$this->getApiHelper()->getRequestCounter().' API requests made for pushContacts');

        // Assume that those not touched are ignored due to not having matching fields, duplicates, etc
        $totalIgnored = $totalToProcess - ($totalUpdated + $totalCreated + $totalErrors);

        if ($totalIgnored < 0) { //this could have been marked as deleted so it was not pushed
            $totalIgnored = $totalIgnored * -1;
        }

        return [$totalUpdated, $totalCreated, $totalErrors, $totalIgnored];
    }

    /**
     * @return bool
     */
    public function shouldPushContacts(): bool
    {
        if (!empty($this->commandParameters) && !empty($this->commandParameters['objects'])) {
            $objects = $this->commandParameters['objects'];
        } else {
            $objects = $this->settings->getFeatureSettings()['objects'];
        }

        $supportedFeatures = $this->settings->getSupportedFeatures() ?? [];

        return in_array('person', $objects) && in_array('push_leads', $supportedFeatures);
    }

    /**
     * @param array $params
     *
     * @return array
     */
    public function pullCompanies($params = []): array
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
                    $results = $this->getApiHelper()->getCompanies($query);

                    if (!isset($this->progressBar)) {
                        $total = $results['meta']['total'];
                        $this->setProgressBar(new ProgressBar($params['output'], $total), 'company');
                    }

                    $results['data'] = $results['data'] ?? [];

                    list($justUpdated, $justCreated) = $this->amendLeadDataBeforeMauticPopulate($results['data'], 'company', $params);

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
                                $this->logger->debug("SAELOS: Processed less than total but didn't get a nextRecordsUrl in the response for getCompanies (company): ".var_export($results, true));

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

    /**
     * @return bool
     */
    public function shouldPullCompanies(): bool
    {
        if (!empty($this->commandParameters) && !empty($this->commandParameters['objects'])) {
            $objects = $this->commandParameters['objects'];
        } else {
            $objects = $this->settings->getFeatureSettings()['objects'];
        }

        return in_array('company', $objects);
    }

    /**
     * @param array $params
     *
     * @return array
     * @throws \Exception
     */
    public function pushCompanies($params = []): array
    {
        $config                  = $this->mergeConfigToFeatureSettings($params);
        $integrationEntityRepo   = $this->getIntegrationEntityRepository();

        $totalUpdated    = 0;
        $totalCreated    = 0;
        $totalErrors     = 0;
        $object          = 'company';

        //get company fields from Mautic that have been mapped
        $mauticCompanyFieldString = 'l.'.implode(', l.', $config['companyFields']);

        $fieldKeys      = array_keys($config['companyFields']);
        $fieldsToCreate = $this->prepareFieldsForSync($config['companyFields'], $fieldKeys, $object);
        $fieldsToUpdate = $fieldsToCreate;

        // Get a total number of companies to be updated and/or created for the progress counter
        $totalToUpdate = $integrationEntityRepo->findLeadsToUpdate(
            'Saelos',
            'company',
            $mauticCompanyFieldString,
            false,
            $params['start'],
            $params['end'],
            $object
        )['company'];

        $totalToCreate = $integrationEntityRepo->findLeadsToCreate(
            'Saelos',
            $mauticCompanyFieldString,
            false,
            $params['start'],
            $params['end'],
            'company'
        );

        $totalCount = $totalToProcess = $totalToCreate + $totalToUpdate;

        if (!isset($this->progressBar)) {
            $this->setProgressBar(new ProgressBar($params['output'], $totalCount), 'company');
        }

        while ($totalCount > 0) {
            if ($totalToUpdate) {
                $toUpdate = $integrationEntityRepo->findLeadsToUpdate(
                    'Saelos',
                    'company',
                    $mauticCompanyFieldString,
                    $params['limit'],
                    $params['start'],
                    $params['end'],
                    'company'
                )['company'];

                foreach ($toUpdate as $update) {
                    try {
                        $updateData = [
                            'custom_fields' => []
                        ];

                        foreach ($fieldsToUpdate as $integrationField => $mauticField) {
                            if (strpos($integrationField, 'saelosCustom_') === 0) {
                                $fieldId = (int) substr($integrationField, strlen('saelosCustom_'));

                                $createData['custom_fields'][] = [
                                    'custom_field_id' => $fieldId,
                                    'value' => $update[$mauticField],
                                ];
                            } else {
                                $createData[$integrationField] = $update[$mauticField];
                            }
                        }

                        $updatedCompany = $this->getApiHelper()->updateCompany($updateData, $update['integration_entity_id']);

                        $this->createIntegrationEntity(
                            'company',
                            $updatedCompany['data']['id'],
                            'company',
                            $update['internal_entity_id']
                        );

                        $totalUpdated++;
                    } catch (ApiErrorException $e) {
                        $totalErrors++;
                        continue;
                    } finally {
                        $totalCount--;
                        $this->advanceProgress();
                    }
                }
            }

            if ($totalToCreate) {
              $toCreate = $integrationEntityRepo->findLeadsToCreate(
                  'Saelos',
                  $mauticCompanyFieldString,
                  $params['limit'],
                  $params['start'],
                  $params['end'],
                  'company'
              );

              foreach ($toCreate as $create) {
                  try {
                      $createData = [
                          'custom_fields' => []
                      ];

                      foreach ($fieldsToCreate as $integrationField => $mauticField) {
                          if (strpos($integrationField, 'saelosCustom_') === 0) {
                              $fieldId = (int) substr($integrationField, strlen('saelosCustom_'));

                              $createData['custom_fields'][] = [
                                  'custom_field_id' => $fieldId,
                                  'value' => $create[$mauticField],
                              ];
                          } else {
                              $createData[$integrationField] = $create[$mauticField];
                          }
                      }

                      $createdCompany = $this->getApiHelper()->pushCompany($createData);

                      $this->createIntegrationEntity(
                          'company',
                          $createdCompany['data']['id'],
                          'company',
                          $create['internal_entity_id']
                      );

                      $totalCreated++;
                  } catch (ApiErrorException $e) {
                      $totalErrors++;
                      continue;
                  } finally {
                      $totalCount--;
                      $this->advanceProgress();
                  }
              }
            }
        }

        $this->finishProgressBar();

        $this->logger->debug('SAELOS: '.$this->getApiHelper()->getRequestCounter().' API requests made for pushCompanies');

        // Assume that those not touched are ignored due to not having matching fields, duplicates, etc
        $totalIgnored = $totalToProcess - ($totalUpdated + $totalCreated + $totalErrors);

        if ($totalIgnored < 0) { //this could have been marked as deleted so it was not pushed
            $totalIgnored = $totalIgnored * -1;
        }

        $this->updateCompanyMappings();

        return [$totalUpdated, $totalCreated, $totalErrors, $totalIgnored];
    }

    /**
     * @return bool
     */
    public function shouldPushCompanies(): bool
    {
        if (!empty($this->commandParameters) && !empty($this->commandParameters['objects'])) {
            $objects = $this->commandParameters['objects'];
        } else {
            $objects = $this->settings->getFeatureSettings()['objects'];
        }

        return in_array('company', $objects);
    }

    /**
     * @param $leadId
     *
     * @return null
     */
    private function getCompanyIdForLead($leadId)
    {
        $query = $this->em->getConnection()->createQueryBuilder();

        $query->select('ie.integration_entity_id')
            ->from(MAUTIC_TABLE_PREFIX . 'integration_entity', 'ie')
            ->leftJoin('ie', MAUTIC_TABLE_PREFIX . 'companies_leads', 'cl', 'cl.company_id = ie.internal_entity_id')
            ->where('cl.lead_id = ' . (int) $leadId);

        $result = $query->execute()->fetch();

        if ($result) {
            return $result['integration_entity_id'];
        }

        return null;
    }
}
