<?php

namespace MauticPlugin\MauticSaelosBundle\Integration;

use Mautic\LeadBundle\Entity\Company;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PluginBundle\Entity\IntegrationEntity;
use Mautic\PluginBundle\Exception\ApiErrorException;
use MauticPlugin\MauticCrmBundle\Integration\CrmAbstractIntegration;
use MauticPlugin\MauticSaelosBundle\Api\SaelosApi;
use MauticPlugin\MauticSaelosBundle\Contracts\CanPullCompanies;
use MauticPlugin\MauticSaelosBundle\Contracts\CanPullContacts;
use MauticPlugin\MauticSaelosBundle\Contracts\CanPushCompanies;
use MauticPlugin\MauticSaelosBundle\Contracts\CanPushContacts;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Tightenco\Collect\Support\Collection;

class SaelosIntegration extends CrmAbstractIntegration implements CanPullContacts, CanPullCompanies, CanPushContacts, CanPushCompanies
{
    const UPDATED = 0;
    const CREATED = 1;

    private $objects = [
        'contact',
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
            'client_id' => 'mautic.integration.keyfield.consumerid',
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
        return $this->settings->getFeatureSettings()['saelosUrl'] . '/oauth/token';
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function getAuthenticationUrl()
    {
        return $this->settings->getFeatureSettings()['saelosUrl'] . '/oauth/authorize';
    }

    /**
     * @return string
     */
    public function getApiUrl()
    {
        return $this->settings->getFeatureSettings()['saelosUrl'] . '/api/v1';
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
     * @param       $fieldsToUpdate
     * @param array $objects
     *
     * @return array
     */
    protected function cleanPriorityFields($fieldsToUpdate, $objects = null)
    {
        return isset($fieldsToUpdate['leadFields']) ? array_values($fieldsToUpdate['leadFields']) : $fieldsToUpdate;;
    }

    /**
     * @return array
     */
    public function getFormSettings()
    {
        return [
            'requires_callback' => true,
            'requires_authorization' => true,
            'default_features' => [],
            'enable_data_priority' => $this->getDataPriority(),
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
            'page' => $config['page'] ?? 1,
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
        $this->progressBar->setFormat(' [%bar%] %current%/%max% %percent:3s%% (' . $object . ')');
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
        $objects = (array)($settings['feature_settings']['objects'] ?? $this->objects);

        if ($index = array_search('contact', $objects)) {
            unset($objects[$index]);
        }

        $settings['feature_settings'] = $objects;

        return $this->getAvailableLeadFields($settings)['company'];
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
        $objects = (array)($settings['feature_settings']['objects'] ?? $this->objects);

        if ($index = array_search('company', $objects)) {
            unset($objects[$index]);
        }

        $settings['feature_settings'] = $objects;

        return $this->getAvailableLeadFields($settings)['contact'];
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
        $saelosObjects = (array)($settings['feature_settings']['objects'] ?? $this->objects);
        $saelosFields = [
            'contact' => [],
            'company' => []
        ];

        try {
            if ($this->isAuthorized()) {
                foreach ($saelosObjects as $key => $object) {
                    $leadFields = $this->getApiHelper()->getFields($object);

                    foreach ($leadFields as $fieldName => $details) {
                        $saelosFields[$object][$fieldName] = [
                            'type' => 'string',
                            'label' => ucfirst($details['label']),
                            'required' => $details['required'],
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logIntegrationError($e);

            if (!($settings['silence_exceptions'] ?? true)) {
                throw $e;
            }
        }

        return $saelosFields;
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
        $updated = 0;
        $created = 0;
        $counter = 0;
        $entity = null;
        $detachClass = null;
        $mauticObjectReference = null;
        $integrationMapping = [];

        foreach ($data as $record) {
            $this->logger->debug('SAELOS: amendLeadDataBeforeMauticPopulate record ' . var_export($record, true));
            $this->advanceProgress();
            $entity = false;

            foreach ($record['custom_fields'] as $customField) {
                $record['saelosCustom_' . $customField['custom_field_id']] = $customField['value'];
            }

            // Unset unused properties
            unset($record['opportunities']);
            unset($record['activities']);
            unset($record['custom_fields']);
            unset($record['notes']);
            unset($record['tags']);

            if (isset($record['user'])) {
                $record['owner_email'] = $record['user']['email'];
                unset($record['user']);
            }

            switch ($object) {
                case 'contact':
                    if (count($record['companies'])) {
                        $primaryCompany = array_reduce(
                            $record['companies'],
                            function ($carry, $company) {
                                return $company['pivot']['primary'] ? $company : null;
                            }
                        );

                        if ($primaryCompany) {
                            // Set the position if we have it from the primary company
                            $record['position'] = $primaryCompany['pivot']['position'];

                            unset($primaryCompany['pivot']);

                            $co = $this->getMauticCompany($primaryCompany, 'company');

                            if ($co instanceof Company) {
                                $record['company'] = $co->getName();
                            }
                        }
                    }

                    if (isset($record['status']) && isset($record['status']['name'])) {
                        $record['status'] = $record['status']['name'];
                    }

                    $mauticObjectReference = 'lead';
                    $entity = $this->getMauticLead($record, true, null, null, $mauticObjectReference);
                    $detachClass = Lead::class;
                    break;
                case 'company':
                    unset($record['contacts']);

                    $mauticObjectReference = 'company';
                    $entity = $this->getMauticCompany($record, $mauticObjectReference);
                    $detachClass = Company::class;

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
                'entity' => $entity,
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

        $this->logger->debug('SALESFORCE: amendLeadDataBeforeMauticPopulate response ' . var_export($data, true));

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
                    'label' => 'mautic.saelos.form.url',
                    'required' => true,
                    'attr' => [
                        'class' => 'form-control',
                        'tooltip' => 'mautic.saelos.form.url.tooltip',
                    ],
                ]
            );

            $builder->add(
                'objects',
                ChoiceType::class,
                [
                    'choices' => [
                        'mautic.saelos.object.lead'    => 'contact',
                        'mautic.saelos.object.company' => 'company',
                    ],
                    'expanded' => true,
                    'multiple' => true,
                    'label' => 'mautic.saelos.form.objects_to_pull_from',
                    'label_attr' => ['class' => ''],
                    'required' => false,
                ]
            );

            $builder->add(
                'updateOwner',
                ChoiceType::class,
                [
                    'choices' => [
                        'mautic.saelos.updateOwner' => 'updateOwner',
                    ],
                    'expanded' => true,
                    'multiple' => true,
                    'label' => 'mautic.saelos.form.updateOwner',
                    'label_attr' => ['class' => 'control-label'],
                    'required' => false,
                ]
            );

            $builder->add(
                'activityEvents',
                ChoiceType::class,
                [
                    'choices' => array_flip($this->leadModel->getEngagementTypes()),
                    'expanded' => true,
                    'multiple' => true,
                    'label' => 'mautic.saelos.form.activityEvents',
                    'label_attr' => ['class' => 'control-label'],
                    'required' => false,
                ]
            );
        }
    }

    public function pullContacts($params = []) : array
    {
        $query = $this->getFetchQuery($params);
        $executed = [
            static::UPDATED => 0,
            static::CREATED => 0,
        ];

        try {
            if ($this->isAuthorized()) {
                $processed = 0;
                $retry = 0;
                $total = 0;

                while ($processed <= $total) {
                    $results = (array)$this->getApiHelper()->getLeads($query);

                    if (!isset($this->progressBar)) {
                        $total = $results['meta']['total'];
                        $this->setProgressBar(new ProgressBar($params['output'], $total), 'contact');
                    }

                    $results['data'] = $results['data'] ?? [];

                    list($justUpdated, $justCreated) = $this->amendLeadDataBeforeMauticPopulate($results['data'], 'contact', $params);

                    $executed[static::UPDATED] += $justUpdated;
                    $executed[static::CREATED] += $justCreated;
                    $processed += count($results['data']);

                    if (array_key_exists('links', $results) && $results['links']['next'] !== null) {
                        $query['page'] = $this->getNextPageNumberFromResults($results['links']);
                    } else {
                        if ($processed < $total) {
                            // Something has gone wrong so try a few more times before giving up
                            if ($retry <= 5) {
                                $this->logger->debug("SAELOS: Processed less than total but didn't get a nextRecordsUrl in the response for getLeads (contact): " . var_export($results, true));

                                usleep(500);
                                ++$retry;

                                continue;
                            } else {
                                // Throw an exception cause something isn't right
                                throw new ApiErrorException("Expected to process $total but only processed $processed: " . var_export($results, true));
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
    public function shouldPullContacts() : bool
    {
        if (!empty($this->commandParameters) && !empty($this->commandParameters['objects'])) {
            $objects = $this->commandParameters['objects'];
        } else {
            $objects = $this->settings->getFeatureSettings()['objects'];
        }

        $supportedFeatures = $this->settings->getSupportedFeatures() ?? [];

        return in_array('contact', $objects) && in_array('get_leads', $supportedFeatures);
    }

    /**
     * @param array $params
     *
     * @return array
     */
    public function pushContacts($params = []) : array
    {
        $config = $this->mergeConfigToFeatureSettings($params);
        $integrationEntityRepo = $this->getIntegrationEntityRepository();

        $totalUpdated = 0;
        $totalCreated = 0;
        $totalErrors = 0;

        unset($config['leadFields']['mauticContactTimelineLink']);

        //get company fields from Mautic that have been mapped
        $mauticLeadFieldString = 'l.' . implode(', l.', $config['leadFields']);

        $fieldKeys = array_keys($config['leadFields']);
        $fieldsToCreate = $this->prepareFieldsForSync($config['leadFields'], $fieldKeys, 'contact');
        $fieldsToUpdate = $fieldsToCreate;

        // Get a total number of companies to be updated and/or created for the progress counter
        $totalToUpdate = $integrationEntityRepo->findLeadsToUpdate(
            'Saelos',
            'lead',
            $mauticLeadFieldString,
            false,
            $params['start'],
            $params['end'],
            'contact'
        )['contact'];

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

        while ($totalToUpdate > 0) {
            $toUpdate = $integrationEntityRepo->findLeadsToUpdate(
                'Saelos',
                'lead',
                $mauticLeadFieldString,
                $params['limit'],
                $params['start'],
                $params['end'],
                'contact'
            )['contact'];

            // We've ran out of leads to process.
            if (count($toUpdate) === 0) {
                break;
            }

            foreach ($toUpdate as $update) {
                try {
                    $updateData = [
                        'custom_fields' => []
                    ];

                    $this->normalize($fieldsToUpdate, $update, $updateData);

                    $companyId = $this->getCompanyIdForLead($update['internal_entity_id']);

                    if ($companyId) {
                        $updateData['companies'] = [];
                        $updateData['companies'][] = [
                            'id' => (int)$companyId,
                            'pivot' => [
                                'primary' => 1
                            ]
                        ];
                    }

                    $ownerName = $this->getOwnerNameForLead($update['internal_entity_id']);

                    if ($ownerName) {
                        $updateData['user_id'] = $ownerName;
                    }

                    if ($activity = $this->getActivityForContact($update['internal_entity_id'], $params['start'], $params['end'])) {
                        $updateData['activities'] = $activity;
                    }

                    $response = $this->getApiHelper()->updateContact($updateData, $update['integration_entity_id']);

                    /** @var IntegrationEntity $contactIntegrationEntity */
                    $contactIntegrationEntity = $integrationEntityRepo->getEntity($update['id']);
                    $contactIntegrationEntity->setLastSyncDate(new \DateTime);

                    $integrationEntityRepo->saveEntity($contactIntegrationEntity);

                    $totalUpdated++;
                } catch (ApiErrorException $e) {
                    $totalErrors++;
                    $e->setContactId($update['internal_entity_id']);
                    $this->logIntegrationError($e);
                }
                finally {
                    $totalToUpdate--;
                    $this->advanceProgress();
                }
            }
        }

        while ($totalToCreate > 0) {
            $toCreate = $integrationEntityRepo->findLeadsToCreate(
                'Saelos',
                $mauticLeadFieldString,
                $params['limit'],
                $params['start'],
                $params['end'],
                'lead'
            );

            // We've ran out of leads to process.
            if (count($toCreate) === 0) {
                break;
            }

            foreach ($toCreate as $create) {
                try {
                    $createData = [
                        'custom_fields' => []
                    ];

                    $this->normalize($fieldsToCreate, $create, $createData);

                    $companyId = $this->getCompanyIdForLead($create['internal_entity_id']);

                    if ($companyId) {
                        $createData['companies'] = [];
                        $createData['companies'][] = [
                            'id' => (int)$companyId,
                            'pivot' => [
                                'primary' => 1
                            ]
                        ];
                    }

                    $ownerName = $this->getOwnerNameForLead($create['internal_entity_id']);

                    if ($ownerName) {
                        $createData['user_id'] = $ownerName;
                    }

                    if ($activity = $this->getActivityForContact($create['internal_entity_id'], $params['start'], $params['end'])) {
                        $createData['activities'] = $activity;
                    }
                    $createdContact = $this->getApiHelper()->pushContact($createData);

                    if (isset($createdContact['data']['id'])) {
                        $this->createIntegrationEntity(
                            'contact',
                            $createdContact['data']['id'],
                            'lead',
                            $create['internal_entity_id']
                        );

                        $totalCreated++;
                    }

                } catch (ApiErrorException $e) {
                    $totalErrors++;
                    $e->setContactId($create['internal_entity_id']);
                    $this->logIntegrationError($e);
                }
                finally {
                    $totalToCreate--;
                    $this->advanceProgress();
                }
            }
        }

        $this->finishProgressBar();

        $this->logger->debug('SAELOS: ' . $this->getApiHelper()->getRequestCounter() . ' API requests made for pushContacts');

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
    public function shouldPushContacts() : bool
    {
        if (!empty($this->commandParameters) && !empty($this->commandParameters['objects'])) {
            $objects = $this->commandParameters['objects'];
        } else {
            $objects = $this->settings->getFeatureSettings()['objects'];
        }

        $supportedFeatures = $this->settings->getSupportedFeatures() ?? [];

        return in_array('contact', $objects) && in_array('push_leads', $supportedFeatures);
    }

    /**
     * @param array $params
     *
     * @return array
     */
    public function pullCompanies($params = []) : array
    {
        $query = $this->getFetchQuery($params);
        $executed = [
            static::UPDATED => 0,
            static::CREATED => 0,
        ];

        try {
            if ($this->isAuthorized()) {
                $processed = 0;
                $retry = 0;
                $total = 0;

                while ($processed <= $total) {
                    $results = (array)$this->getApiHelper()->getCompanies($query);

                    if (!isset($this->progressBar)) {
                        $total = $results['meta']['total'];
                        $this->setProgressBar(new ProgressBar($params['output'], $total), 'company');
                    }

                    $results['data'] = $results['data'] ?? [];

                    list($justUpdated, $justCreated) = $this->amendLeadDataBeforeMauticPopulate($results['data'], 'company', $params);

                    $executed[static::UPDATED] += $justUpdated;
                    $executed[static::CREATED] += $justCreated;
                    $processed += count($results['data']);

                    if (array_key_exists('links', $results) && $results['links']['next'] !== null) {
                        $query['page'] = $this->getNextPageNumberFromResults($results['links']);
                    } else {
                        if ($processed < $total) {
                            // Something has gone wrong so try a few more times before giving up
                            if ($retry <= 5) {
                                $this->logger->debug("SAELOS: Processed less than total but didn't get a nextRecordsUrl in the response for getCompanies (company): " . var_export($results, true));

                                usleep(500);
                                ++$retry;

                                continue;
                            } else {
                                // Throw an exception cause something isn't right
                                throw new ApiErrorException("Expected to process $total but only processed $processed: " . var_export($results, true));
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
    public function shouldPullCompanies() : bool
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
    public function pushCompanies($params = []) : array
    {
        $config = $this->mergeConfigToFeatureSettings($params);
        $integrationEntityRepo = $this->getIntegrationEntityRepository();

        $totalUpdated = 0;
        $totalCreated = 0;
        $totalErrors = 0;
        $object = 'company';

        //get company fields from Mautic that have been mapped
        $mauticCompanyFieldString = 'l.' . implode(', l.', $config['companyFields']);

        $fieldKeys = array_keys($config['companyFields']);
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

        while ($totalToUpdate > 0) {
            $toUpdate = $integrationEntityRepo->findLeadsToUpdate(
                'Saelos',
                'company',
                $mauticCompanyFieldString,
                $params['limit'],
                $params['start'],
                $params['end'],
                'company'
            )['company'];

            // We've run out of companies
            if (count($toUpdate) === 0) {
                break;
            }

            foreach ($toUpdate as $update) {
                try {
                    $updateData = [
                        'custom_fields' => []
                    ];

                    foreach ($fieldsToUpdate as $integrationField => $mauticField) {
                        if (strpos($integrationField, 'saelosCustom_') === 0) {
                            $fieldId = (int)substr($integrationField, strlen('saelosCustom_'));

                            $createData['custom_fields'][] = [
                                'custom_field_id' => $fieldId,
                                'value' => $update[$mauticField],
                            ];
                        } else {
                            $createData[$integrationField] = $update[$mauticField];
                        }
                    }

                    $this->getApiHelper()->updateCompany($updateData, $update['integration_entity_id']);

                    /** @var IntegrationEntity $contactIntegrationEntity */
                    $companyIntegrationEntity = $integrationEntityRepo->getEntity($update['id']);
                    $companyIntegrationEntity->setLastSyncDate(new \DateTime);

                    $integrationEntityRepo->saveEntity($companyIntegrationEntity);

                    $totalUpdated++;
                } catch (ApiErrorException $e) {
                    $totalErrors++;
                    $this->logger->debug(sprintf('SAELOS: Error updating company: %s. The error was: %s', var_export($update, true), $e->getMessage()));
                }
                finally {
                    $totalToUpdate--;
                    $this->advanceProgress();
                }
            }
        }

        while ($totalToCreate > 0) {
            $toCreate = $integrationEntityRepo->findLeadsToCreate(
                'Saelos',
                $mauticCompanyFieldString,
                $params['limit'],
                $params['start'],
                $params['end'],
                'company'
            );

            // We've run out of companies
            if (count($toCreate) === 0) {
                break;
            }

            foreach ($toCreate as $create) {
                try {
                    $createData = [
                        'custom_fields' => []
                    ];

                    foreach ($fieldsToCreate as $integrationField => $mauticField) {
                        if (strpos($integrationField, 'saelosCustom_') === 0) {
                            $fieldId = (int)substr($integrationField, strlen('saelosCustom_'));

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
                    $this->logger->debug(sprintf('SAELOS: Error creating company: %s. The error was: %s', var_export($create, true), $e->getMessage()));
                }
                finally {
                    $totalToCreate--;
                    $this->advanceProgress();
                }
            }
        }

        $this->finishProgressBar();

        $this->logger->debug('SAELOS: ' . $this->getApiHelper()->getRequestCounter() . ' API requests made for pushCompanies');

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
    public function shouldPushCompanies() : bool
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
     * @return string|null
     */
    private function getCompanyIdForLead($leadId)
    {
        $query = $this->em->getConnection()->createQueryBuilder();

        $query->select('ie.integration_entity_id')
            ->from(MAUTIC_TABLE_PREFIX . 'integration_entity', 'ie')
            ->leftJoin('ie', MAUTIC_TABLE_PREFIX . 'companies_leads', 'cl', 'cl.company_id = ie.internal_entity_id')
            ->where('ie.integration_entity = "company"')
            ->andWhere('cl.lead_id = ' . (int)$leadId);

        $result = $query->execute()->fetch();

        if ($result) {
            return $result['integration_entity_id'];
        }

        return null;
    }

    /**
     * @param $leadId
     *
     * @return string|null
     */
    private function getOwnerNameForLead($leadId)
    {
        $query = $this->em->getConnection()->createQueryBuilder();

        $query->select('u.first_name, u.last_name')
            ->from(MAUTIC_TABLE_PREFIX . 'users', 'u')
            ->leftJoin('u', MAUTIC_TABLE_PREFIX . 'leads', 'l', 'l.owner_id = u.id')
            ->where('l.id = ' . (int) $leadId);

            $result = $query->execute()->fetch();

            if ($result) {
                return $result['first_name'] . ' ' . $result['last_name'];
            }

            return null;
    }

    /**
     * @param array $fields
     * @param array $data
     * @param array $newData
     */
    protected function normalize($fields, $data, &$newData)
    {
        foreach ($fields as $integrationField => $mauticField) {
            // @TODO
            if ($mauticField === 'mauticContactTimelineLink') {
                continue;
            }

            if (strpos($integrationField, 'saelosCustom_') === 0) {
                $fieldId = (int)substr($integrationField, strlen('saelosCustom_'));

                $newData['custom_fields'][] = [
                    'custom_field_id' => $fieldId,
                    'value' => $data[$mauticField],
                ];
            } else {
                $newData[$integrationField] = $data[$mauticField];
            }
        }
    }

    /**
     * Parse query results to fetch the next page number
     * 
     * @param array $links
     * 
     * @return int
     */
    private function getNextPageNumberFromResults(array $links) : int
    {
        parse_str(parse_url($links['next'], PHP_URL_QUERY), $linkParams);

        return (int)$linkParams['page'];
    }

    /**
     * @param array  $params
     * @param null   $query
     * @param null   $executed
     * @param array  $result
     * @param string $object
     *
     * @return array|null
     * 
     * @DEPRECATED: Use pullContacts
     */
    public function getLeads($params = [], $query = null, &$executed = null, $result = [], $object = 'contact')
    {
        return $this->pullContacts($params);
    }

    /**
     * Fetch activity and format for Saelos
     *
     * @param integer $contactId
     * @param string $start
     * @param string $end
     *
     * @return array
     */
    protected function getActivityForContact($contactId, $start = null, $end = null)
    {
        return $this->getContactData(new \DateTime($start), new \DateTime($end), $contactId);
    }

    /**
     * @param \DateTime|null $startDate
     * @param \DateTime|null $endDate
     * @param                $contactId
     *
     * @return array
     */
    private function getContactData(\DateTime $startDate = null, \DateTime $endDate = null, $contactId)
    {
        $config = $this->mergeConfigToFeatureSettings();

        if (empty($config['activityEvents'])) {
            // Inclusive filter meaning we only send events if something is selected
            return [];
        }

        $filters = [
            'search'        => '',
            'includeEvents' => $config['activityEvents'],
            'excludeEvents' => [],
        ];

        if ($startDate) {
            $filters['dateFrom'] = $startDate;
            $filters['dateTo']   = $endDate;
        }

        $includeEvents = Collection::make($filters['includeEvents']);
        $detailsTypeLookup = Collection::make([
            'email.read'     => 'App\\EmailActivity',
            'page.hit'       => 'App\\PageHitActivity',
            'form.submitted' => 'App\\FormSubmitActivity',
            'point.gained'   => 'App\\PointChangeActivity',
        ]);

        $activity = Collection::make();
        $contact  = $this->em->getReference('MauticLeadBundle:Lead', $contactId);
        $page     = 1;

        while (true) {
            $engagements = $this->leadModel->getEngagements($contact, $filters, null, $page, 100, false);
            $events      = Collection::make($engagements[0]['events']);

            if ($events->count() === 0) {
                break;
            }

            $events->filter(function ($event) use ($includeEvents) {
                // for some reason we're getting events that don't match the filter
                return $includeEvents->contains($event['event']);
            })->each(function ($event) use ($activity, $detailsTypeLookup) {
                $link  = '';
                $label = $event['eventLabel'] ?? $event['eventType'] ?? 'Unknown';

                if (is_array($label)) {
                    $link  = $label['href'];
                    $label = $label['label'];
                }

                $activityData = [
                    'details_type' => $detailsTypeLookup->get($event['event'], $event['event']),
                    'name'         => $label,
                    'description'  => $link,
                    'completed'    => 1,
                    'details'      => [
                        'created_at' => $event['timestamp']->format('Y-m-d H:i:s')
                    ]
                ];

                if ($activityData['details_type'] !== 'none') {
                    switch($event['event']) {
                        case 'email.read':
                            $activityData['details']['content'] = 'Unknown';
                            break;
                    }
                }

                $activity[] = $activityData;
            });

            ++$page;

            // Lots of entities will be loaded into memory while compiling these events so let's prevent memory overload by clearing the EM
            $this->em->clear();
        }

        return $activity->all();
    }
}
