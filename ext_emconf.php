<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Connector service - XML Feed (mobile.de)',
    'description' => 'Connector service for mobile.de Search API XML Feed',
    'category' => 'services',
    'version' => '4.3.2-mobile.de',
    'state' => 'stable',
    'uploadfolder' => 0,
    'createDirs' => '',
    'clearcacheonload' => 1,
    'author' => 'Francois Suter (Idéative), Bastian Holzemn (teufels GmbH)',
    'author_email' => 'typo3@ideative.ch, digital@teufels.com',
    'author_company' => '',
    'constraints' =>
        [
            'depends' =>
                [
                    'typo3' => '11.5.0-12.4.99',
                    'svconnector' => '5.0.0-0.0.0',
                ],
            'conflicts' =>
                [
                ],
            'suggests' =>
                [
                ],
        ],
];

