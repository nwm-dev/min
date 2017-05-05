<?php

/*  | This extension is part of the TYPO3 project. The TYPO3 project is
 *  | free software and is licensed under GNU General Public License.
 *  |
 *  | (c) 2016-2017 Armin Vieweg <armin@v.ieweg.de>
 */

$EM_CONF[$_EXTKEY] = [
    'title' => 'Minifier for TYPO3',
    'description' => 'Extends TYPO3\'s compressor for JS and CSS with minifier. This may save you up to 60% of ' .
                     'default compressed file size. Also compresses HTML output of TYPO3. Full composer support.',
    'category' => 'services',
    'author' => 'Armin Vieweg',
    'author_email' => 'armin@v.ieweg.de',
    'state' => 'stable',
    'internal' => '',
    'uploadfolder' => '0',
    'createDirs' => '',
    'clearCacheOnLoad' => 0,
    'version' => '1.3.1',
    'constraints' => [
        'depends' => [
            'typo3' => '7.6.0-8.9.99',
        ],
        'conflicts' => [
            'tinysource' => ''
        ],
        'suggests' => [],
    ],
];
