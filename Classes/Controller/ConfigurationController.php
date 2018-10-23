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

namespace Causal\ImageAutoresize\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Form\FormResultCompiler;
use TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseEditRow;
use TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord;
use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Utility\HttpUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Core\Configuration\ConfigurationManager;
use TYPO3\CMS\Backend\Form\NodeFactory;
use TYPO3\CMS\Backend\Form\FormEngine;
use TYPO3\CMS\Core\DataHandling\DataHandler as BaseDataHandler;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use Causal\ImageAutoresize\Backend\Form\FormDataProvider\VirtualDatabaseEditRow;
use Causal\ImageAutoresize\Service\ConfigurationService;
/**
 * Configuration controller.
 *
 * @package     TYPO3
 * @subpackage  tx_imageautoresize
 * @author      Xavier Perseguers <xavier@causal.ch>
 * @license     http://www.gnu.org/copyleft/gpl.html
 */
class ConfigurationController
{

    const virtualTable = 'tx_imageautoresize';
    const virtualRecordId = 1;

    /**
     * @var string
     */
    protected $extKey = 'image_autoresize';

    /**
     * @var array
     */
    protected $expertKey = 'image_autoresize_ff';

    /**
     * @var \TYPO3\CMS\Lang\LanguageService
     */
    protected $languageService;

    /**
     * @var \TYPO3\CMS\Backend\Form\FormEngine
     */
    protected $tceforms;

    /**
     * @var \TYPO3\CMS\Backend\Form\FormResultCompiler $formResultCompiler
     */
    protected $formResultCompiler;

    /**
     * @var \TYPO3\CMS\Backend\Template\ModuleTemplate
     */
    protected $moduleTemplate;

    /**
     * @var array
     */
    protected $config;

    /**
     * Generally used for accumulating the output content of backend modules
     *
     * @var string
     */
    public $content = '';

    /**
     * Default constructor
     */
    public function __construct()
    {
        $this->moduleTemplate = GeneralUtility::makeInstance(ModuleTemplate::class);
        $this->languageService = $GLOBALS['LANG'];

        $this->config = ConfigurationService::getCurrentExtConfiguration();
        $this->config['conversion_mapping'] = implode(LF, explode(',', $this->config['conversion_mapping']));
    }

    /**
     * Injects the request object for the current request or subrequest
     * As this controller goes only through the main() method, it is rather simple for now
     *
     * @param ServerRequestInterface $request the current request
     * @param ResponseInterface $response
     * @return ResponseInterface the response with the content
     * @throws \TYPO3\CMS\Backend\Form\Exception
     */
    public function mainAction(ServerRequestInterface $request, ResponseInterface $response)
    {
        $this->languageService->includeLLFile('EXT:image_autoresize/Resources/Private/Language/locallang_mod.xlf');
        $this->processData();

        $formTag = '<form action="" method="post" name="editform">';

        $this->moduleTemplate->setForm($formTag);

        $this->content .= sprintf('<h3>%s</h3>', $this->languageService->getLL('title'));
        $this->addStatisticsAndSocialLink();

        $row = $this->config;
        $this->moduleContent($row);

        // Compile document
        $this->addToolbarButtons();
        $this->moduleTemplate->setContent($this->content);
        $content = $this->moduleTemplate->renderContent();

        $response->getBody()->write($content);

        return $response;
    }

    /**
     * FormEngine now expects an array of data instead of a comma-separated list of
     * values for select fields. This method ensures the corresponding fields in $row
     * are of the expected type and "fix" them if needed.
     *
     * @param array &$row
     * @param array $tcaSelectFields
     * @return void
     */
    protected function fixRecordForFormEngine(array &$row, array $tcaSelectFields)
    {
        foreach ($tcaSelectFields as $tcaField) {
            if (isset($row[$tcaField])) {
                $row[$tcaField] = GeneralUtility::trimExplode(',', $row[$tcaField], true);
            }
        }
        if (isset($row['rulesets']['data']['sDEF']['lDEF']['ruleset']['el'])) {
            foreach ($row['rulesets']['data']['sDEF']['lDEF']['ruleset']['el'] as &$el) {
                foreach ($tcaSelectFields as $tcaField) {
                    if (isset($el['container']['el'][$tcaField]['vDEF'])) {
                        $el['container']['el'][$tcaField]['vDEF'] = GeneralUtility::trimExplode(',', $el['container']['el'][$tcaField]['vDEF'], true);
                    }
                }
            }
        }
    }

    /**
     * Generates the module content.
     *
     * @param array $row
     * @return void
     * @throws \TYPO3\CMS\Backend\Form\Exception
     */
    protected function moduleContent(array $row)
    {
        $this->formResultCompiler = GeneralUtility::makeInstance(FormResultCompiler::class);

        $wizard = $this->buildForm($row);
        $wizard .= $this->formResultCompiler->printNeededJSFunctions();

        $this->content .= $wizard;
    }

    /**
     * Builds the expert configuration form.
     *
     * @param array $row
     * @return string
     * @throws \TYPO3\CMS\Backend\Form\Exception
     */
    protected function buildForm(array $row)
    {
        $record = [
            'uid' => static::virtualRecordId,
            'pid' => 0,
        ];
        $record = array_merge($record, $row);

        // Trick to use a virtual record
        $dataProviders =& $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['formDataGroup']['tcaDatabaseRecord'];

        // Recent version of TYPO3 is since 7.6.17 for TYPO3 v7 and > 8.6.1 for TYPO3 v8
        $isRecentV7OrV8 = version_compare(TYPO3_version, '8.6.1', '>')
            || (version_compare(TYPO3_version, '7.6.17', '>=') && version_compare(TYPO3_version, '8.0', '<'));
        if ($isRecentV7OrV8) {
            $dataProviders[VirtualDatabaseEditRow::class] = [
                'before' => [
                    DatabaseEditRow::class,
                ]
            ];
        } else {
            // Either TYPO3 < 7.6.17 or TYPO3 8.0.0 - 8.6.1
            $originalProvider = DatabaseEditRow::class;
            $databaseEditRowProvider = $dataProviders[$originalProvider];
            unset($dataProviders[$originalProvider]);
            $dataProviders[VirtualDatabaseEditRow::class] = $databaseEditRowProvider;
        }

        // Initialize record in our virtual provider
        VirtualDatabaseEditRow::initialize($record);

        /** @var TcaDatabaseRecord $formDataGroup */
        $formDataGroup = GeneralUtility::makeInstance(TcaDatabaseRecord::class);
        /** @var FormDataCompiler $formDataCompiler */
        $formDataCompiler = GeneralUtility::makeInstance(FormDataCompiler::class, $formDataGroup);
        /** @var NodeFactory $nodeFactory */
        $nodeFactory = GeneralUtility::makeInstance(NodeFactory::class);

        $formDataCompilerInput = [
            'tableName' => static::virtualTable,
            'vanillaUid' => $record['uid'],
            'command' => 'edit',
            'returnUrl' => '',
        ];

        // Load the configuration of virtual table 'tx_imageautoresize'
        $this->loadVirtualTca();

        $formData = $formDataCompiler->compile($formDataCompilerInput);
        $formData['renderType'] = 'outerWrapContainer';
        $formResult = $nodeFactory->create($formData)->render();

        // Remove header and footer
        $html = preg_replace('/<h1>.*<\/h1>/', '', $formResult['html']);

        $startFooter = strrpos($html, '<div class="help-block text-right">');
        $endTag = '</div>';

        if ($startFooter !== false) {
            $endFooter = strpos($html, $endTag, $startFooter);
            $html = substr($html, 0, $startFooter) . substr($html, $endFooter + strlen($endTag));
        }

        $formResult['html'] = '';
        $formResult['doSaveFieldName'] = 'doSave';

        // @todo: Put all the stuff into FormEngine as final "compiler" class
        // @todo: This is done here for now to not rewrite JStop()
        // @todo: and printNeededJSFunctions() now
        $this->formResultCompiler->mergeResult($formResult);

        // Combine it all
        $formContent = '
			<!-- EDITING FORM -->
			' . $html . '

			<input type="hidden" name="returnUrl" value="' . htmlspecialchars($this->retUrl) . '" />
			<input type="hidden" name="closeDoc" value="0" />
			<input type="hidden" name="doSave" value="0" />
			<input type="hidden" name="_serialNumber" value="' . md5(microtime()) . '" />
			<input type="hidden" name="_scrollPosition" value="" />';

            $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
            $overriddenAjaxUrl = GeneralUtility::quoteJSvalue($uriBuilder->buildUriFromRoute('TxImageAutoresize::record_flex_container_add'));
            $formContent .= <<<HTML
<script type="text/javascript">
    TYPO3.settings.ajaxUrls['record_flex_container_add'] = $overriddenAjaxUrl;
</script>
HTML;

        return $formContent;
    }

    /**
     * Creates the toolbar buttons.
     *
     * @return void
     */
    protected function addToolbarButtons()
    {
        // Render SAVE type buttons:
        // The action of each button is decided by its name attribute. (See doProcessData())
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $saveSplitButton = $buttonBar->makeSplitButton();

        // SAVE button:
        $saveButton = $buttonBar->makeInputButton()
            ->setTitle($this->languageService->sL('LLL:EXT:lang/Resources/Private/Language/locallang_core.xlf:rm.saveDoc'))
            ->setName('_savedok')
            ->setValue('1')
            ->setIcon($this->moduleTemplate->getIconFactory()->getIcon(
                'actions-document-save',
                Icon::SIZE_SMALL
            ));
        $saveSplitButton->addItem($saveButton, true);

        // SAVE & CLOSE button:
        $saveAndCloseButton = $buttonBar->makeInputButton()
            ->setName('_saveandclosedok')
            ->setClasses('t3js-editform-submitButton')
            ->setValue('1')
            ->setTitle($this->languageService->sL('LLL:EXT:lang/Resources/Private/Language/locallang_core.xlf:rm.saveCloseDoc'))
            ->setIcon($this->moduleTemplate->getIconFactory()->getIcon(
                'actions-document-save-close',
                Icon::SIZE_SMALL
            ));
        $saveSplitButton->addItem($saveAndCloseButton);

        $buttonBar->addButton($saveSplitButton, ButtonBar::BUTTON_POSITION_LEFT, 2);

        // CLOSE button:
        $closeButton = $buttonBar->makeLinkButton()
            ->setHref('#')
            ->setClasses('t3js-editform-close')
            ->setTitle($this->languageService->sL('LLL:EXT:lang/Resources/Private/Language/locallang_core.xlf:rm.closeDoc'))
            ->setIcon($this->moduleTemplate->getIconFactory()->getIcon(
                'actions-view-go-back',
                Icon::SIZE_SMALL
            ));
        $buttonBar->addButton($closeButton);
    }

    /**
     * Prints out the module HTML.
     *
     * @return string HTML output
     */
    public function printContent()
    {
        echo $this->content;
    }


    /**
     * Processes submitted data and stores it to localconf.php.
     *
     * @return void
     */
    protected function processData()
    {
        $close = GeneralUtility::_GP('closeDoc');
        $save = GeneralUtility::_GP('_savedok');
        $saveAndClose = GeneralUtility::_GP('_saveandclosedok');

        if ($save || $saveAndClose) {
            $table = static::virtualTable;
            $id = static::virtualRecordId;
            $field = 'rulesets';

            $inputData_tmp = GeneralUtility::_GP('data');
            $data = $inputData_tmp[$table][$id];

            if (count($inputData_tmp[$table]) > 1) {
                foreach ($inputData_tmp[$table] as $key => $values) {
                    if ($key === $id) continue;
                    ArrayUtility::mergeRecursiveWithOverrule($data, $values);
                }
            }

            $newConfig = $this->config;
            ArrayUtility::mergeRecursiveWithOverrule($newConfig, $data);

            // Action commands (sorting order and removals of FlexForm elements)
            $ffValue = &$data[$field];
            if ($ffValue) {
                $actionCMDs = GeneralUtility::_GP('_ACTION_FLEX_FORMdata');
                if (is_array($actionCMDs[$table][$id][$field]['data'])) {
                    $dataHandler = new CustomDataHandler();
                    $dataHandler->_ACTION_FLEX_FORMdata($ffValue['data'], $actionCMDs[$table][$id][$field]['data']);
                }
                // Renumber all FlexForm temporary ids
                $this->persistFlexForm($ffValue['data']);

                // Keep order of FlexForm elements
                $newConfig[$field] = $ffValue;
            }

            // Write back configuration to localconf.php
            $localconfConfig = $newConfig;
            $localconfConfig['conversion_mapping'] = implode(',', GeneralUtility::trimExplode(LF, $localconfConfig['conversion_mapping'], true));

            if ($this->writeToLocalconf($this->expertKey, $localconfConfig)) {
                $this->config = $newConfig;
            }
        }

        if ($close || $saveAndClose) {
            $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
            $closeUrl = $uriBuilder->buildUriFromRoute('tools_ExtensionmanagerExtensionmanager');
            HttpUtility::redirect($closeUrl);
        }
    }

    /**
     * Writes a configuration line to AdditionalConfiguration.php.
     * We don't use the <code>tx_install</code> methods as they add unneeded
     * comments at the end of the file.
     *
     * @param string $key
     * @param array $config
     * @return boolean
     */
    protected function writeToLocalconf($key, array $config)
    {
        /** @var $objectManager ObjectManager */
        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        /** @var $configurationManager ConfigurationManager */
        $configurationManager = $objectManager->get(ConfigurationManager::class);
        return $configurationManager->setLocalConfigurationValueByPath('EXTENSIONS/' . $key, $config);
    }

    /**
     * Initializes <code>\TYPO3\CMS\Backend\Form\FormEngine</code> class for use in this module.
     *
     * @return void
     */
    protected function initTCEForms()
    {
        $this->tceforms = GeneralUtility::makeInstance(FormEngine::class);
        $this->tceforms->doSaveFieldName = 'doSave';
        $this->tceforms->palettesCollapsed = 0;
    }

    /**
     * Loads the configuration of the virtual table 'tx_imageautoresize'.
     *
     * @return void
     */
    protected function loadVirtualTca()
    {
        $GLOBALS['TCA'][static::virtualTable] = include(ExtensionManagementUtility::extPath($this->extKey) . 'Configuration/TCA/Module/Options.php');
        ExtensionManagementUtility::addLLrefForTCAdescr(static::virtualTable, 'EXT:' . $this->extKey . '/Resource/Private/Language/locallang_csh_' . static::virtualTable . '.xlf');
    }

    /**
     * Persists FlexForm items by removing 'ID-' in front of new
     * items.
     *
     * @param array &$valueArray : by reference
     * @return void
     */
    protected function persistFlexForm(array &$valueArray)
    {
        foreach ($valueArray as $key => $value) {
            if ($key === 'el') {
                foreach ($value as $idx => $v) {
                    if ($v && substr($idx, 0, 3) === 'ID-') {
                        $valueArray[$key][substr($idx, 3)] = $v;
                        unset($valueArray[$key][$idx]);
                    }
                }
            } elseif (isset($valueArray[$key])) {
                $this->persistFlexForm($valueArray[$key]);
            }
        }
    }

    /**
     * Returns some statistics and a social link to Twitter.
     *
     * @return void
     */
    protected function addStatisticsAndSocialLink()
    {
        $fileName = PATH_site . 'typo3conf/.tx_imageautoresize';

        if (!is_file($fileName)) {
            return;
        }

        $data = json_decode(file_get_contents($fileName), true);
        if (!is_array($data) || !(isset($data['images']) && isset($data['bytes']))) {
            return;
        }

        $resourcesPath = ExtensionManagementUtility::extPath($this->extKey) . 'Resources/Public/';
        $pageRenderer = $this->moduleTemplate->getPageRenderer();
        $pageRenderer->addCssFile($resourcesPath . 'Css/twitter.css');
        $pageRenderer->addJsFile($resourcesPath . 'JavaScript/popup.js');

        $totalSpaceClaimed = GeneralUtility::formatSize((int)$data['bytes']);
        $messagePattern = $this->languageService->getLL('storage.claimed');
        $message = sprintf($messagePattern, $totalSpaceClaimed, (int)$data['images']);

        $flashMessage = htmlspecialchars($message);

        $twitterMessagePattern = $this->languageService->getLL('social.twitter');
        $message = sprintf($twitterMessagePattern, $totalSpaceClaimed);
        $url = 'https://typo3.org/extensions/repository/view/image_autoresize';

        $twitterLink = 'https://twitter.com/intent/tweet?text=' . urlencode($message) . '&url=' . urlencode($url);
        $twitterLink = GeneralUtility::quoteJSvalue($twitterLink);
        $flashMessage .= '
            <div class="custom-tweet-button">
                <a href="#" onclick="popitup(' . $twitterLink . ',\'twitter\')" title="' . $this->languageService->getLL('social.share') . '">
                    <i class="btn-icon"></i>
                    <span class="btn-text">Tweet</span>
                </a>
            </div>';

        $this->content .= '
            <div class="alert alert-info">
                <div class="media">
                    <div class="media-left">
                        <span class="fa-stack fa-lg">
                            <i class="fa fa-circle fa-stack-2x"></i>
                            <i class="fa fa-info fa-stack-1x"></i>
                        </span>
                    </div>
                    <div class="media-body">
                        ' . $flashMessage . '
                    </div>
                </div>
            </div>
        ';
    }
}

// ReflectionMethod does not work properly with arguments passed as reference thus
// using a trick here
class CustomDataHandler extends BaseDataHandler
{

    /**
     * Actions for flex form element (move, delete)
     * allows to remove and move flexform sections
     *
     * @param array &$valueArray by reference
     * @param array $actionCMDs
     * @return void
     */
    public function _ACTION_FLEX_FORMdata(&$valueArray, $actionCMDs)
    {
        parent::_ACTION_FLEX_FORMdata($valueArray, $actionCMDs);
    }

}
