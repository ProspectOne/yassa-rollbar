<?php
return array(
    'service_manager' => [
        'factories' => [
            'Yassa\Rollbar\Options\ModuleOptions' => 'Yassa\Rollbar\Options\ModuleOptionsFactory',
            'RollbarNotifier'                     => 'Yassa\Rollbar\Factory\RollbarNotifierFactory',
            'Yassa\Rollbar\Log\Writer\Rollbar'    => 'Yassa\Rollbar\Factory\RollbarLogWriterFactory',
        ],
    ],
    'view_helpers' => [
        'factories' => [
            'rollbar'    => 'Yassa\Rollbar\Factory\RollbarViewHelperFactory',
        ],
    ],
    'ignored_exceptions' => [
        
    ],
);
