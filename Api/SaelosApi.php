<?php

namespace MauticPlugin\MauticSaelosBundle\Api;

use Mautic\PluginBundle\Exception\ApiErrorException;
use MauticPlugin\MauticSaelosBundle\Integration\SaelosIntegration;

class SaelosApi
{
    /**
     * @var SaelosIntegration
     */
    protected $integration;

    /**
     * @var array
     */
    protected $requestSettings = [
        'content_type'      => 'application/json',
        'encode_parameters' => 'json',
    ];

    /**
     * SaelosApi constructor.
     *
     * @param SaelosIntegration $integration
     */
    public function __construct(SaelosIntegration $integration)
    {
        $this->integration = $integration;
    }

    /**
     * @param        $operation
     * @param array  $parameters
     * @param string $method
     *
     * @return mixed|string
     *
     * @throws ApiErrorException
     */
    public function request($operation, $parameters = [], $method = 'GET')
    {
        if (!empty($operation) && strpos($operation, '/') !== 0) {
            $operation = '/'.$operation;
        }

        $url      = $this->integration->getApiUrl().$operation;
        $response = $this->integration->makeRequest($url, $parameters, $method, $this->requestSettings);

        if (isset($response['code']) && $response['code'] > 299) {
            throw new ApiErrorException($response['message'], $response['code']);
        }

        return $response;
    }

    /**
     * @param $object
     *
     * @return array
     *
     * @throws ApiErrorException
     */
    public function getFields($object)
    {
        switch ($object) {
            case 'Lead':
            case 'person':
                $object = 'people';
                break;
            case 'company':
                $object = 'companies';
                break;
        }

        $response = $this->request($object, [], 'GET');

        if (!isset($response['data'])) {
            return [];
        }

        $object       = $response['data'][0];
        $fields       = [];
        $fieldsToSkip = [
            'id',
            'deals',
            'company',
        ];

        foreach ($object as $field => $value) {
            if (in_array($field, $fieldsToSkip)) {
                continue;
            }

            if (is_array($value)) {
                if ($field === 'custom_fields') {
                    foreach ($value as $customField => $customValue) {
                        $fields['saelosCustom_'.$customField] = [
                            'label'    => $customField,
                            'required' => false,
                        ];
                    }

                    continue;
                }
            }

            $fields[$field] = [
                'label'    => $field,
                'required' => false,
            ];
        }

        return $fields;
    }

    /**
     * @param $query
     *
     * @return mixed|string
     *
     * @throws ApiErrorException
     */
    public function getLeads($query)
    {
        return $this->request('/people', $query);
    }
}
