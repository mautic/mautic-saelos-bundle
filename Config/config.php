<?php

return [
    'name'        => 'Mautic Saelos Bundle',
    'description' => 'Mautic bundle for integrating with the Saelos CRM',
    'version'     => '1.0',
    'author'      => 'Don Gilbert',
    'services' => [
        'integrations' => [
            'mautic.integration.saelos' => [
                'class'     => \MauticPlugin\MauticSaelosBundle\Integration\SaelosIntegration::class,
                'arguments' => [
                ],
            ],
        ],
        'command' => [
            'mautic.integration.command.sync' => [
                'class' => \Mautic\PluginBundle\Command\SyncIntegrations::class,
                'arguments' => [

                ]
            ],
        ],
        'events' => [
            // Register any event listeners
        ],
    ],
];