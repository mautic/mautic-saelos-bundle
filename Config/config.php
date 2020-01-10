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
                    'event_dispatcher',
                    'mautic.helper.cache_storage',
                    'doctrine.orm.entity_manager',
                    'session',
                    'request_stack',
                    'router',
                    'translator',
                    'logger',
                    'mautic.helper.encryption',
                    'mautic.lead.model.lead',
                    'mautic.lead.model.company',
                    'mautic.helper.paths',
                    'mautic.core.model.notification',
                    'mautic.lead.model.field',
                    'mautic.plugin.model.integration_entity',
                    'mautic.helper.user',
                ],
            ],
        ],
        'command' => [
            'mautic.integration.command.sync' => [
                'class' => \MauticPlugin\MauticSaelosBundle\Command\SyncIntegrations::class,
                'arguments' => [
                    'translator',
                    'mautic.helper.integration',
                ]
            ],
        ],
        'events' => [
            // Register any event listeners
        ],
    ],
];