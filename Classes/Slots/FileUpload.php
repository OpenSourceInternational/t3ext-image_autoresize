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

namespace Causal\ImageAutoresize\Slots;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\FileInterface;
use Causal\ImageAutoresize\Utility\FAL;
use Causal\ImageAutoresize\Service\ImageResizer;
use Causal\ImageAutoresize\Service\ConfigurationService;

/**
 * Slot implementation when a file is uploaded but before it is processed
 * by \TYPO3\CMS\Core\Resource\ResourceStorage to automatically resize
 * huge pictures.
 *
 * @category    Slots
 * @package     TYPO3
 * @subpackage  tx_imageautoresize
 * @author      Xavier Perseguers <xavier@causal.ch>
 * @license     http://www.gnu.org/copyleft/gpl.html
 */
class FileUpload
{

    const SIGNAL_SanitizeFileName = 'sanitizeFileName';
    const SIGNAL_PostFileReplace = 'postFileReplace';
    const SIGNAL_PreFileAdd = 'preFileAdd';
    const SIGNAL_PopulateMetadata = 'populateMetadata';

    /**
     * @var ImageResizer
     */
    protected static $imageResizer;

    /**
     * @var array|null
     */
    protected static $metadata;

    /**
     * @var string|null
     */
    protected static $originalFileName;

    /**
     * FileUpload constructor.
     * @throws \TYPO3\CMS\Core\Exception
     */
    public function __construct()
    {
        if (static::$imageResizer === null) {
            static::$imageResizer = GeneralUtility::makeInstance(ImageResizer::class);

            $configuration = ConfigurationService::getCurrentExtConfiguration();
            if (is_array($configuration)) {
                static::$imageResizer->initializeRulesets($configuration);
            }
        }
    }

    /**
     * Sanitizes the file name.
     *
     * @param string $fileName
     * @param Folder $folder
     * @return void|array
     */
    public function sanitizeFileName($fileName, Folder $folder)
    {
        $slotArguments = func_get_args();
        // Last parameter is the signal name itself and is not actually part of the arguments
        array_pop($slotArguments);

        $storageConfiguration = $folder->getStorage()->getConfiguration();
        $storageRecord = $folder->getStorage()->getStorageRecord();
        if ($storageRecord['driver'] !== 'Local') {
            // Unfortunately unsupported yet
            return;
        }

        $targetDirectory = $storageConfiguration['pathType'] === 'relative' ? PATH_site : '';
        $targetDirectory .= rtrim(rtrim($storageConfiguration['basePath'], '/') . $folder->getIdentifier(), '/');

        $processedFileName = static::$imageResizer->getProcessedFileName(
            $targetDirectory . '/' . $fileName,
            $GLOBALS['BE_USER']
        );
        if ($processedFileName !== null) {
            static::$originalFileName = $fileName;
            $slotArguments[0] = PathUtility::basename($processedFileName);

            return $slotArguments;
        }
    }

    /**
     * A file is about to be added as a *replacement* of an existing
     * one.
     *
     * @param File $file
     * @param string $uploadedFileName
     * @return void
     */
    public function postFileReplace(File $file, $uploadedFileName)
    {
        $folder = $file->getParentFolder();
        $storageConfiguration = $folder->getStorage()->getConfiguration();
        $storageRecord = $folder->getStorage()->getStorageRecord();
        if ($storageRecord['driver'] !== 'Local') {
            // Unfortunately unsupported yet
            return;
        }

        $targetDirectory = $storageConfiguration['pathType'] === 'relative' ? PATH_site : '';
        $targetDirectory .= rtrim(rtrim($storageConfiguration['basePath'], '/') . $folder->getIdentifier(), '/');
        $targetFileName = $targetDirectory . '/' . $file->getName();

        $this->processFile($targetFileName, PathUtility::basename($targetFileName), $targetDirectory, $file);
        $this->populateMetadata($file, $folder);
    }

    /**
     * Auto-resizes a given source file (possibly converting it as well).
     *
     * @param string $targetFileName
     * @param Folder $folder
     * @param string $sourceFile
     * @return void
     */
    public function preFileAdd(&$targetFileName, Folder $folder, $sourceFile)
    {
        $storageConfiguration = $folder->getStorage()->getConfiguration();
        $storageRecord = $folder->getStorage()->getStorageRecord();
        if ($storageRecord['driver'] !== 'Local') {
            // Unfortunately unsupported yet
            return;
        }

        if (static::$originalFileName) {
            // Temporarily change back the file name to ensure original format is used
            // when converting from one format to another with IM/GM
            $targetFileName = static::$originalFileName;
            static::$originalFileName = null;
        }

        $targetDirectory = $storageConfiguration['pathType'] === 'relative' ? PATH_site : '';
        $targetDirectory .= rtrim(rtrim($storageConfiguration['basePath'], '/') . $folder->getIdentifier(), '/');

        $extension = strtolower(substr($targetFileName, strrpos($targetFileName, '.') + 1));

        // Various operation (including IM/GM) relies on a file WITH an extension
        $originalSourceFile = $sourceFile;
        $sourceFile .= '.' . $extension;

        if (rename($originalSourceFile, $sourceFile)) {
            $newSourceFile = $this->processFile($sourceFile, $targetFileName, $targetDirectory);
            $newExtension = strtolower(substr($newSourceFile, strrpos($newSourceFile, '.') + 1));

            // We must go back to original (temporary) file name
            rename($newSourceFile, $originalSourceFile);

            if ($newExtension !== $extension) {
                $targetFileName = substr($targetFileName, 0, -strlen($extension)) . $newExtension;
            }
        }
    }

    /**
     * @param string $fileName Full path to the file to be processed
     * @param string $targetFileName Target file name if not converted, no path included
     * @param string $targetDirectory
     * @param File $file
     * @return string
     */
    protected function processFile($fileName, &$targetFileName, $targetDirectory, File $file = null)
    {
        $newFileName = static::$imageResizer->processFile(
            $fileName,
            $targetFileName,
            $targetDirectory,
            $file,
            $GLOBALS['BE_USER'],
            [$this, 'notify']
        );

        static::$metadata = static::$imageResizer->getLastMetadata();

        return $newFileName;
    }

    /**
     * Populates the FAL metadata of the resized image.
     *
     * @param FileInterface $file
     * @param Folder $folder
     * @return void
     */
    public function populateMetadata(FileInterface $file, Folder $folder)
    {
        if (is_array(static::$metadata) && count(static::$metadata)) {
            FAL::indexFile(
                $file,
                '', '',
                static::$metadata['COMPUTED']['Width'],
                static::$metadata['COMPUTED']['Height'],
                static::$metadata
            );
        }
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
     * @internal This method is public only to be callable from a callback
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
