<?php
/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with TYPO3 source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace Causal\ImageAutoresize\Hooks;

use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\DataHandling\DataHandlerProcessUploadHookInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use Causal\ImageAutoresize\Service\ImageResizer;

/**
 * This class extends \TYPO3\CMS\Core\DataHandling\DataHandler and hooks into the
 * upload of old, non-FAL files to uploads/ directory.
 *
 * @category    Hooks
 * @package     TYPO3
 * @subpackage  tx_imageautoresize
 * @author      Xavier Perseguers <xavier@causal.ch>
 * @license     http://www.gnu.org/copyleft/gpl.html
 */
class FileUploadHook implements DataHandlerProcessUploadHookInterface
{

    /**
     * @var ImageResizer
     */
    protected static $imageResizer;

    /**
     * FileUploadHook constructor.
     * @throws \TYPO3\CMS\Core\Exception
     */
    public function __construct()
    {
        if (static::$imageResizer === null) {
            static::$imageResizer = GeneralUtility::makeInstance(ImageResizer::class);

            $configuration = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['image_autoresize_ff'];
            if (!$configuration) {
                $this->notify(
                    $GLOBALS['LANG']->sL('LLL:EXT:image_autoresize/Resources/Private/Language/locallang.xml:message.emptyConfiguration'),
                    FlashMessage::ERROR
                );
            }
            $configuration = unserialize($configuration);
            if (is_array($configuration)) {
                static::$imageResizer->initializeRulesets($configuration);
            }
        }
    }

    /**
     * Post-processes a file upload.
     *
     * @param string $filename
     * @param DataHandler $pObj
     */
    public function processUpload_postProcessAction(&$filename, DataHandler $pObj)
    {
        $filename = static::$imageResizer->processFile(
            $filename,
            '', // Target file name
            '', // Target directory
            null,
            $GLOBALS['BE_USER'],
            [$this, 'notify']
        );
    }

    /**
     * Notifies the user using a Flash message.
     *
     * @param string $message The message
     * @param integer $severity Optional severity, must be either of \TYPO3\CMS\Core\Messaging\FlashMessage::INFO,
     *                          \TYPO3\CMS\Core\Messaging\FlashMessage::OK, \TYPO3\CMS\Core\Messaging\FlashMessage::WARNING
     *                          or \TYPO3\CMS\Core\Messaging\FlashMessage::ERROR.
     *                          Default is \TYPO3\CMS\Core\Messaging\FlashMessage::OK.
     * @return void
     * @throws \TYPO3\CMS\Core\Exception
     */
    public function notify($message, $severity = FlashMessage::OK)
    {
        if (TYPO3_MODE !== 'BE') {
            return;
        }
        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            $message,
            '',
            $severity,
            true
        );
        /** @var $flashMessageService FlashMessageService */
        $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
        /** @var $defaultFlashMessageQueue \TYPO3\CMS\Core\Messaging\FlashMessageQueue */
        $defaultFlashMessageQueue = $flashMessageService->getMessageQueueByIdentifier();
        $defaultFlashMessageQueue->enqueue($flashMessage);
    }

}
