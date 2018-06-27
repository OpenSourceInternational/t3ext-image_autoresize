<?php
/**
 * Created by PhpStorm.
 * User: user
 * Date: 6/26/18
 * Time: 1:33 PM
 */

namespace Causal\ImageAutoresize\Service;

use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Core\Configuration\ConfigurationManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ConfigurationService
{

    /**
     * @return array|mixed
     */
    static function getCurrentExtConfiguration()
    {
        /** @var $objectManager ObjectManager */
        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        /** @var $configurationManager ConfigurationManager */
        $configurationManager = $objectManager->get(ConfigurationManager::class);
        try {
            $configurationArray = $configurationManager->getLocalConfigurationValueByPath('EXTENSIONS/' . 'image_autoresize_ff');
        } catch (\Exception $exception) {
            $configurationArray =  [
                'directories' => 'fileadmin/,uploads/',
                'file_types' => 'jpg,jpeg,png',
                'threshold' => '400K',
                'max_width' => '1024',
                'max_height' => '768',
                'auto_orient' => '1',
                'keep_metadata' => 0,
                'resize_png_with_alpha' => 0,
                'conversion_mapping' => implode(',', [
                    'ai => jpg',
                    'bmp => jpg',
                    'pcx => jpg',
                    'tga => jpg',
                    'tif => jpg',
                    'tiff => jpg',
                ]),
            ];
        }
        return $configurationArray;
    }
}
