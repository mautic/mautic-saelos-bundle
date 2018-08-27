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
        'headers' => [
            'Accept' => 'application/json',
        ],
        'content_type' => 'application/json',
        'encode_parameters' => 'json',
        'return_raw' => true,
    ];

    /**
     * @var int
     */
    private $requestCounter = 0;

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
        $this->requestCounter++;

        if (!empty($operation) && strpos($operation, '/') !== 0) {
            $operation = '/' . $operation;
        }

        $url = $this->integration->getApiUrl() . $operation;
        $response = $this->integration->makeRequest($url, $parameters, $method, $this->requestSettings);

        if ($response->code > 299) {
            $message = $response->body;

            if ($response->code === 404 && $method === 'PATCH') {
                $message = 'Contact does not exist in Saelos.';
            }

            throw new ApiErrorException($message, $response->code);
        }

        return $this->integration->parseCallbackResponse($response->body);
    }

    /**
     * @return int
     */
    public function getRequestCounter() : int
    {
        return $this->requestCounter;
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
            case 'contact':
                $url = 'contexts/Contact';
                break;
            case 'company':
                $url = 'contexts/Company';
                break;
        }

        $response = $this->request($url, [], 'GET');

        if (!is_array($response)) {
            return [];
        }

        $fields = [];
        $fieldsToSkip = [
            'id',
            'opportunities',
            'companies',
            'company',
            'activities',
            'notes',
            'contacts',
        ];

        foreach ($response as $field => $details) {
            if (in_array($field, $fieldsToSkip)) {
                continue;
            }

            $key = isset($details['is_custom']) && $details['is_custom'] === true ? 'saelosCustom_' . $details['field_id'] : $field;

            $fields[$key] = [
                'label' => $details['label'],
                'required' => $details['required'],
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
        return $this->request('/contacts', $query);
    }

    /**
     * @param $query
     *
     * @return mixed|string
     *
     * @throws ApiErrorException
     */
    public function getCompanies($query)
    {
        return $this->request('/companies', $query);
    }

    /**
     * @param $data
     *
     * @return mixed|string
     * @throws ApiErrorException
     */
    public function pushContact($data)
    {
        return $this->request('/contacts', $data, 'POST');
    }

    /**
     * @param $data
     * @param $id
     *
     * @return mixed|string
     * @throws ApiErrorException
     */
    public function updateContact($data, $id)
    {
        return $this->request('/contacts/' . $id, $data, 'PATCH');
    }

    /**
     * @param $data
     *
     * @return mixed|string
     * @throws ApiErrorException
     */
    public function pushCompany($data)
    {
        return $this->request('/companies', $data, 'POST');
    }

    /**
     * @param $data
     * @param $id
     *
     * @return mixed|string
     * @throws ApiErrorException
     */
    public function updateCompany($data, $id)
    {
        return $this->request('/companies/' . $id, $data, 'PATCH');
    }
}
