<?php
/***************************************************************
 *  Copyright notice
 *
 *  (c) 2013 Claus Due <claus@wildside.dk>, Wildside A/S
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

/**
 * Flux FlexForm integration Service
 *
 * Main API Service for interacting with Flux-based FlexForms
 *
 * @package Flux
 * @subpackage Service
 */
class Tx_Flux_Service_FluxService implements t3lib_Singleton {

	/**
	 * @var array
	 */
	private static $sentDebugMessages = array();

	/**
	 * @var array
	 */
	private static $friendlySeverities = array(
		t3lib_div::SYSLOG_SEVERITY_INFO,
		t3lib_div::SYSLOG_SEVERITY_NOTICE
	);

	/**
	 * @var array
	 */
	private static $cache = array();

	/**
	 * @var string
	 */
	protected $raw;

	/**
	 * @var array
	 */
	protected $contentObjectData;

	/**
	 *
	 * @var Tx_Extbase_Configuration_ConfigurationManagerInterface
	 */
	protected $configurationManager;

	/**
	 * @var Tx_Extbase_Object_ObjectManagerInterface
	 */
	protected $objectManager;

	/**
	 * @var Tx_Extbase_Reflection_Service
	 */
	protected $reflectionService;

	/**
	 * @param Tx_Extbase_Configuration_ConfigurationManagerInterface $configurationManager
	 * @return void
	 */
	public function injectConfigurationManager(Tx_Extbase_Configuration_ConfigurationManagerInterface $configurationManager) {
		$this->configurationManager = $configurationManager;
	}

	/**
	 * @param Tx_Extbase_Object_ObjectManagerInterface $objectManager
	 * @return void
	 */
	public function injectObjectManager(Tx_Extbase_Object_ObjectManagerInterface $objectManager) {
		$this->objectManager = $objectManager;
	}

	/**
	 * @param Tx_Extbase_Reflection_Service $reflectionService
	 * @return void
	 */
	public function injectReflectionService(Tx_Extbase_Reflection_Service $reflectionService) {
		$this->reflectionService = $reflectionService;
	}

	/**
	 * @param string $extensionKey
	 * @param string $controllerName
	 * @param array $paths
	 * @param array $variables
	 * @return Tx_Flux_MVC_View_ExposedTemplateView
	 */
	public function getPreparedExposedTemplateView($extensionKey = NULL, $controllerName = NULL, $paths = array(), $variables = array()) {
		$extensionKey = t3lib_div::camelCaseToLowerCaseUnderscored($extensionKey);
		if (NULL === $extensionKey || FALSE === t3lib_extMgm::isLoaded($extensionKey)) {
			// Note here: a default value of the argument would not be adequate; outside callers could still pass NULL.
			$extensionKey = 'Flux';
		}
		if (NULL === $controllerName) {
			$controllerName = 'Flux';
		}
		/** @var $context Tx_Extbase_MVC_Controller_ControllerContext */
		$context = $this->objectManager->get('Tx_Extbase_MVC_Controller_ControllerContext');
		/** @var $request Tx_Extbase_MVC_Web_Request */
		$request = $this->objectManager->get('Tx_Extbase_MVC_Web_Request');
		/** @var $response Tx_Extbase_MVC_Web_Response */
		$response = $this->objectManager->get('Tx_Extbase_MVC_Web_Response');
		$request->setControllerExtensionName($extensionKey);
		$request->setControllerName($controllerName);
		$request->setDispatched(TRUE);
		/** @var $uriBuilder Tx_Extbase_Mvc_Web_Routing_UriBuilder */
		$uriBuilder = $this->objectManager->get('Tx_Extbase_Mvc_Web_Routing_UriBuilder');
		$uriBuilder->setRequest($request);
		$context->setUriBuilder($uriBuilder);
		$context->setRequest($request);
		$context->setResponse($response);
		/** @var $exposedView Tx_Flux_MVC_View_ExposedTemplateView */
		$exposedView = $this->objectManager->get('Tx_Flux_MVC_View_ExposedTemplateView');
		$exposedView->setControllerContext($context);
		if (0 < count($variables)) {
			$exposedView->assignMultiple($variables);
		}
		if (TRUE === isset($paths['layoutRootPath']) && FALSE === empty($paths['layoutRootPath'])) {
			$exposedView->setLayoutRootPath($paths['layoutRootPath']);
		}
		if (TRUE === isset($paths['partialRootPath']) && FALSE === empty($paths['partialRootPath'])) {
			$exposedView->setPartialRootPath($paths['partialRootPath']);
		}
		return $exposedView;
	}

	/**
	 * @param string $templatePathAndFilename
	 * @param string $section
	 * @param string $formName
	 * @param array $paths
	 * @param string $extensionName
	 * @param array $variables
	 * @return Tx_Flux_Form
	 * @throws Exception
	 */
	public function getFormFromTemplateFile($templatePathAndFilename, $section = 'Configuration', $formName = 'form', $paths = array(), $extensionName = NULL, $variables = array()) {
		try {
			if (FALSE === file_exists($templatePathAndFilename)) {
				throw new Exception('The template file "' . $templatePathAndFilename . '" was not found.', 1366824347);
			}
			$variableCheck = json_encode($variables);
			$cacheKey = md5($templatePathAndFilename . $formName . $extensionName . implode('', $paths) . $section . $variableCheck);
			if (TRUE === isset(self::$cache[$cacheKey])) {
				return self::$cache[$cacheKey];
			}
			$exposedView = $this->getPreparedExposedTemplateView($extensionName, 'Flux', $paths, $variables);
			$exposedView->setTemplatePathAndFilename($templatePathAndFilename);
			$form = $exposedView->getForm($section, $formName);
		} catch (Exception $error) {
			$this->debug($error);
			/** @var Tx_Flux_Form $form */
			$form = $this->objectManager->get('Tx_Flux_Form');
			$form->add($form->createField('UserFunction', 'func')->setFunction('Tx_Flux_UserFunction_ErrorReporter->renderField'));
		}
		return $form;
	}

	/**
	 * Reads a Grid constructed using flux:flexform.grid, returning an array of
	 * defined rows and columns along with any content areas.
	 *
	 * Note about specific implementations:
	 *
	 * * EXT:fluidpages uses the Grid to render a BackendLayout on TYPO3 6.0 and above
	 * * EXT:flux uses the Grid to render content areas inside content elements
	 *   registered with Flux
	 *
	 * But your custom extension is of course allowed to use the Grid for any
	 * purpose. You can even read the Grid from - for example - the currently
	 * selected page template to know exactly how the BackendLayout looks.
	 *
	 * @param string $templatePathAndFilename
	 * @param string $section
	 * @param string $gridName
	 * @param array $paths
	 * @param string $extensionName
	 * @param array $variables
	 * @return array
	 * @throws Exception
	 */
	public function getGridFromTemplateFile($templatePathAndFilename, $section = 'Configuration', $gridName = 'grid', array $paths = array(), $extensionName = NULL, array $variables = array()) {
		$exposedView = $this->getPreparedExposedTemplateView($extensionName, 'Flux', $paths, $variables);
		$exposedView->setTemplatePathAndFilename($templatePathAndFilename);
		$grid = $exposedView->getGrid($section, $gridName);
		return $grid;
	}

	/**
	 * @param string $extensionName
	 * @return array|NULL
	 */
	public function getViewConfigurationForExtensionName($extensionName) {
		$configuration = $this->getTypoScriptSubConfiguration(NULL, 'view', $extensionName);
		if (FALSE === is_array($configuration) || 0 === count($configuration)) {
			$configuration = array(
				'templateRootPath' => 'EXT:' . $extensionName . '/Resources/Private/Templates',
				'partialRootPath' => 'EXT:' . $extensionName . '/Resources/Private/Partials',
				'layoutRootPath' => 'EXT:' . $extensionName . '/Resources/Private/Layouts',
			);
		}
		return $configuration;
	}

	/**
	 * @param string $extensionName
	 * @return array|NULL
	 */
	public function getBackendViewConfigurationForExtensionName($extensionName) {
		$configuration = $this->getTypoScriptSubConfiguration(NULL, 'view', $extensionName, 'module');
		return $configuration;
	}

	/**
	 * Gets an array of TypoScript configuration from below plugin.tx_fed -
	 * if $extensionName is set in parameters it is used to indicate which sub-
	 * section of the result to return.
	 *
	 * @param string $extensionName
	 * @param string $memberName
	 * @param string $containerExtensionScope If TypoScript is not located under plugin.tx_fed, change the tx_<scope> part by specifying this argument
	 * @param string $superScope Either "plugin" or "module", depending on the root scope
	 * @return array
	 */
	public function getTypoScriptSubConfiguration($extensionName, $memberName, $containerExtensionScope = 'fed', $superScope = 'plugin') {
		$containerExtensionScope = str_replace('_', '', $containerExtensionScope);
		$cacheKey = $extensionName . $memberName . $containerExtensionScope;
		if (TRUE === isset(self::$cache[$cacheKey])) {
			return self::$cache[$cacheKey];
		}
		$config = $this->configurationManager->getConfiguration(Tx_Extbase_Configuration_ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT);
		if (FALSE === isset($config[$superScope . '.']['tx_' . $containerExtensionScope . '.'][$memberName . '.'])) {
			return NULL;
		}
		$config = $config[$superScope . '.']['tx_' . $containerExtensionScope . '.'][$memberName . '.'];
		$config = Tx_Flux_Utility_Array::convertTypoScriptArrayToPlainArray($config);
		if ($extensionName) {
			$config = $config[$extensionName];
		}
		if (FALSE === is_array($config)) {
			$config = array();
		}
		$config = Tx_Flux_Utility_Path::translatePath($config);
		self::$cache[$cacheKey] = $config;
		return $config;
	}

	/**
	 * @param string $extensionKey
	 * @param string $controllerName
	 * @return boolean
	 */
	public function detectControllerClassPresenceFromExtensionKeyAndControllerType($extensionKey, $controllerName) {
		$potentialClassName = $this->buildControllerClassNameFromExtensionKeyAndControllerType($extensionKey, $controllerName);
		return class_exists($potentialClassName);
	}

	/**
	 * @param string $extensionKey
	 * @param string $controllerName
	 * @return boolean|string
	 */
	public function buildControllerClassNameFromExtensionKeyAndControllerType($extensionKey, $controllerName) {
		if (FALSE !== strpos($extensionKey, '.')) {
			list ($vendorName, $extensionName) = explode('.', $extensionKey);
			$potentialClassName = $vendorName . '\\' . $extensionName . '\\Controller\\' . $controllerName . 'Controller';
		} else {
			$extensionName = t3lib_div::underscoredToUpperCamelCase($extensionKey);
			$potentialClassName = 'Tx_' . $extensionName . '_Controller_' . $controllerName . 'Controller';
		}
		return $potentialClassName;
	}

	/**
	 * Resolve the top-priority ConfigurationPrivider which can provide
	 * a working FlexForm configuration baed on the given parameters.
	 *
	 * @param string $table
	 * @param string $fieldName
	 * @param array $row
	 * @param string $extensionKey
	 * @return Tx_Flux_Provider_ConfigurationProviderInterface|NULL
	 */
	public function resolvePrimaryConfigurationProvider($table, $fieldName, array $row = NULL, $extensionKey = NULL) {
		if (is_array($row) === FALSE) {
			$row = array();
		}
		$rowIdentity = TRUE === isset($row['uid']) ? $row['uid'] : NULL;
		$cacheKey = $table . $fieldName . $rowIdentity . $extensionKey . 'top';
		if (TRUE === isset(self::$cache[$cacheKey])) {
			return self::$cache[$cacheKey];
		}
		$providers = $this->resolveConfigurationProviders($table, $fieldName, $row, $extensionKey);
		$priority = 0;
		$providerWithTopPriority = NULL;
		foreach ($providers as $provider) {
			if ($provider->getPriority($row) >= $priority) {
				$providerWithTopPriority = $provider;
			}
		}
		self::$cache[$cacheKey] = $providerWithTopPriority;
		return $providerWithTopPriority;
	}

	/**
	 * Resolves a ConfigurationProvider which can provide a working FlexForm
	 * configuration based on the given parameters.
	 *
	 * @param string $table
	 * @param string $fieldName
	 * @param array $row
	 * @param string $extensionKey
	 * @return Tx_Flux_Provider_ConfigurationProviderInterface[]
	 */
	public function resolveConfigurationProviders($table, $fieldName, array $row=NULL, $extensionKey=NULL) {
		if (is_array($row) === FALSE) {
			$row = array();
		}
		$rowIdentity = TRUE === isset($row['uid']) ? $row['uid'] : NULL;
		$cacheKey = $table . $fieldName . $rowIdentity . $extensionKey;
		if (TRUE === isset(self::$cache[$cacheKey])) {
			return self::$cache[$cacheKey];
		}
		$providers = Tx_Flux_Core::getRegisteredFlexFormProviders();
		$prioritizedProviders = array();
		foreach ($providers as $providerClassNameOrInstance) {
			if (is_object($providerClassNameOrInstance)) {
				$provider = &$providerClassNameOrInstance;
			} else {
				$providerCacheKey = $table . $fieldName . $rowIdentity . $extensionKey . $providerClassNameOrInstance;
				if (TRUE === isset(self::$cache[$providerCacheKey])) {
					$provider = &self::$cache[$providerCacheKey];
				} else {
					$provider = $this->objectManager->get($providerClassNameOrInstance);
				}
			}
			$priority = $provider->getPriority($row);
			$providerFieldName = $provider->getFieldName($row);
			$providerExtensionKey = $provider->getExtensionKey($row);
			$providerTableName = $provider->getTableName($row);
			if (FALSE === is_array($prioritizedProviders[$priority])) {
				$prioritizedProviders[$priority] = array();
			}
			$matchesTableName = ($providerTableName === $table);
			$matchesFieldName = ($providerFieldName === $fieldName || NULL === $fieldName);
			$matchesExtensionKey = ($providerExtensionKey === $extensionKey || NULL === $extensionKey);
			/** @var Tx_Flux_Provider_ConfigurationProviderInterface $provider */
			if ($matchesExtensionKey && $matchesTableName && $matchesFieldName) {
				if ($provider instanceof Tx_Flux_Provider_ContentObjectConfigurationProviderInterface) {
					/** @var Tx_Flux_Provider_ContentObjectConfigurationProviderInterface $provider */
					if (FALSE === isset($row['CType']) || $provider->getContentObjectType($row) === $row['CType']) {
						$prioritizedProviders[$priority][] = $provider;
					}
				} elseif (TRUE === $provider instanceof Tx_Flux_Provider_PluginConfigurationProviderInterface) {
					/** @var Tx_Flux_Provider_PluginConfigurationProviderInterface $provider */
					if (FALSE === isset($row['list_type']) || $provider->getListType($row) === $row['list_type']) {
						$prioritizedProviders[$priority][] = $provider;
					}
				} else {
					$prioritizedProviders[$priority][] = $provider;
				}
			}
		}
		ksort($prioritizedProviders);
		$providersToReturn = array();
		foreach ($prioritizedProviders as $providerSet) {
			foreach ($providerSet as $provider) {
				array_push($providersToReturn, $provider);
			}
		}
		self::$cache[$cacheKey] = $providersToReturn;
		return $providersToReturn;
	}

	/**
	 * Parses the flexForm content and converts it to an array
	 * The resulting array will be multi-dimensional, as a value "bla.blubb"
	 * results in two levels, and a value "bla.blubb.bla" results in three levels.
	 *
	 * Note: multi-language flexForms are not supported yet
	 *
	 * @param string $flexFormContent flexForm xml string
	 * @param Tx_Flux_Form $form An instance of Tx_Flux_Form. If transformation instructions are contained in this configuration they are applied after conversion to array
	 * @param string $languagePointer language pointer used in the flexForm
	 * @param string $valuePointer value pointer used in the flexForm
	 * @return array the processed array
	 */
	public function convertFlexFormContentToArray($flexFormContent, Tx_Flux_Form $form = NULL, $languagePointer = 'lDEF', $valuePointer = 'vDEF') {
		$settings = array();
		if (TRUE === empty($languagePointer)) {
			$languagePointer = 'lDEF';
		}
		if (TRUE === empty($valuePointer)) {
			$valuePointer = 'vDEF';
		}
		$flexFormArray = t3lib_div::xml2array($flexFormContent);
		$flexFormArray = (TRUE === isset($flexFormArray['data']) && TRUE === is_array($flexFormArray['data']) ? $flexFormArray['data'] : $flexFormArray);
		if (FALSE === is_array($flexFormArray)) {
			return $settings;
		}
		foreach (array_values($flexFormArray) as $languages) {
			if (!is_array($languages) || !isset($languages[$languagePointer])) {
				continue;
			}
			if (!is_array($languages[$languagePointer])) {
				$currentNode = $languages[$languagePointer];
				continue;
			}
			foreach ($languages[$languagePointer] as $valueKey => $valueDefinition) {
				if (FALSE === strpos($valueKey, '.')) {
					$settings[$valueKey] = $this->walkFlexFormNode($valueDefinition, $valuePointer);
				} else {
					$valueKeyParts = explode('.', $valueKey);
					$currentNode =& $settings;

					foreach ($valueKeyParts as $valueKeyPart) {
						$currentNode =& $currentNode[$valueKeyPart];
					}

					if (is_array($valueDefinition)) {
						if (array_key_exists($valuePointer, $valueDefinition)) {
							$currentNode = $valueDefinition[$valuePointer];
						} else {
							$currentNode = $this->walkFlexFormNode($valueDefinition, $valuePointer);
						}
					} else {
						$currentNode = $valueDefinition;
					}
				}
			}
		}
		if (NULL !== $form) {
			$settings = $this->transformAccordingToConfiguration($settings, $form);
		}
		return $settings;
	}

	/**
	 * Parses a flexForm node recursively and takes care of sections etc
	 *
	 * @param array $nodeArray The flexForm node to parse
	 * @param string $valuePointer The valuePointer to use for value retrieval
	 * @return array
	 */
	private function walkFlexFormNode($nodeArray, $valuePointer = 'vDEF') {
		if (is_array($nodeArray)) {
			$return = array();
			foreach ($nodeArray as $nodeKey => $nodeValue) {
				if ($nodeKey === $valuePointer) {
					return $nodeValue;
				}
				if (in_array($nodeKey, array('el', '_arrayContainer'))) {
					return $this->walkFlexFormNode($nodeValue, $valuePointer);
				}
				if (substr($nodeKey, 0, 1) === '_') {
					continue;
				}
				if (strpos($nodeKey, '.')) {
					$nodeKeyParts = explode('.', $nodeKey);
					$currentNode = &$return;
					$total = (count($nodeKeyParts) - 1);
					for ($i = 0; $i < $total; $i++) {
						$currentNode = &$currentNode[$nodeKeyParts[$i]];
					}
					$newNode = array(next($nodeKeyParts) => $nodeValue);
					$currentNode = $this->walkFlexFormNode($newNode, $valuePointer);
				} else if (is_array($nodeValue)) {
					if (array_key_exists($valuePointer, $nodeValue)) {
						$return[$nodeKey] = $nodeValue[$valuePointer];
					} else {
						$return[$nodeKey] = $this->walkFlexFormNode($nodeValue, $valuePointer);
					}
				} else {
					$return[$nodeKey] = $nodeValue;
				}
			}
			return $return;
		}
		return $nodeArray;
	}

	/**
	 * Transforms members on $values recursively according to the provided
	 * Flux configuration extracted from a Flux template. Uses "transform"
	 * attributes on fields to determine how to transform values.
	 *
	 * @param array $values
	 * @param Tx_Flux_Form $form
	 * @param string $prefix
	 * @return array
	 */
	public function transformAccordingToConfiguration($values, Tx_Flux_Form $form = NULL, $prefix = '') {
		if (FALSE === is_array($values) || NULL === $form) {
			return $values;
		}
		foreach ($values as $index => $value) {
			if (TRUE === is_array($value)) {
				$value = $this->transformAccordingToConfiguration($value, $form, $prefix . (FALSE === empty($prefix) ? '.' : '') . $index);
			} else {
				/** @var Tx_Flux_Form_FieldInterface $field */
				$field = $form->get($index, TRUE, 'Tx_Flux_Form_FieldInterface');
				if (FALSE !== $field) {
					$transformType = $field->getTransform();
					$fieldName = $field->getName();
				}
				if ($fieldName === $prefix . (FALSE === empty($prefix) ? '.' : '') . $index && FALSE === empty($transformType)) {
					$value = $this->transformValueToType($value, $transformType);
				}
			}
			$values[$index] = $value;
		}
		return $values;
	}

	/**
	 * Transforms a single value to $dataType
	 *
	 * @param string $value
	 * @param string $dataType
	 * @return mixed
	 */
	private function transformValueToType($value, $dataType) {
		if ($dataType == 'int' || $dataType == 'integer') {
			return intval($value);
		} else if ($dataType == 'float') {
			return floatval($value);
		} else if ($dataType == 'array') {
			return explode(',', $value);
		} else if (strpos($dataType, 'Tx_') === 0) {
			return $this->getObjectOfType($dataType, $value);
		}
		return $value;
	}

	/**
	 * Gets a DomainObject or QueryResult of $dataType
	 *
	 * @param string $dataType
	 * @param string $uids
	 * @return mixed
	 */
	private function getObjectOfType($dataType, $uids) {
		$uids = trim($uids, ',');
		$identifiers = explode(',', $uids);
		// Fast decisions
		if (FALSE !== strpos($dataType, '_Domain_Model_') && FALSE === strpos($dataType, '<')) {
			$repositoryClassName = str_replace('_Model_', '_Repository_', $dataType) . 'Repository';
			if (class_exists($repositoryClassName)) {
				$repository = $this->objectManager->get($repositoryClassName);
				$uid = array_pop($identifiers);
				return $repository->findOneByUid($uid);
			}
		} else if (class_exists($dataType)) {
			// using constructor value to support objects like DateTime
			return $this->objectManager->get($dataType, $uids);
		}
		// slower decisions with support for type-hinted collection objects
		list ($container, $object) = explode('<', trim($dataType, '>'));
		if ($container && $object) {
			if (FALSE !== strpos($object, '_Domain_Model_') && $uids) {
				$repositoryClassName = str_replace('_Model_', '_Repository_', $object) . 'Repository';
				/** @var $repository Tx_Extbase_Persistence_Repository */
				$repository = $this->objectManager->get($repositoryClassName);
				$query = $repository->createQuery();
				$query->matching($query->in('uid', $uids));
				return $query->execute();
			} else {
				$container = $this->objectManager->get($container);
				return $container;
			}
		} else {
			// passthrough; neither object nor type hinted collection object
			return $uids;
		}
	}

	/**
	 * @param mixed $instance
	 * @return void
	 */
	public function debug($instance) {
		if (1 > $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['flux']['setup']['debugMode']) {
			if (TRUE === $instance instanceof Exception) {
				t3lib_div::sysLog('Flux Debug: Suppressed Exception - "' . $instance->getMessage() . '" (' . $instance->getCode() . ')', 'flux');
			}
			return;
		}
		if (TRUE === is_object($instance)) {
			$hash = spl_object_hash($instance);
		} else {
			$hash = microtime(TRUE);
		}
		if (TRUE === isset(self::$sentDebugMessages[$hash])) {
			return;
		}
		if (TRUE === $instance instanceof Tx_Flux_MVC_View_ExposedTemplateView) {
			$this->debugView($instance);
		} elseif (TRUE === $instance instanceof Tx_Flux_Provider_ConfigurationProviderInterface) {
			$this->debugProvider($instance);
		} elseif (TRUE === $instance instanceof Exception) {
			$this->debugException($instance);
		} else {
			$this->debugMixed($instance);
		}
		self::$sentDebugMessages[$hash] = TRUE;
	}

	/**
	 * @param mixed $variable
	 * @return void
	 */
	public function debugMixed($variable) {
		Tx_Extbase_Utility_Debugger::var_dump($variable);
	}

	/**
	 * @param Exception $error
	 * @return void
	 */
	public function debugException(Exception $error) {
		$this->message($error->getMessage() . ' (' . $error->getCode() . ')', t3lib_div::SYSLOG_SEVERITY_FATAL);
	}

	/**
	 * @param Tx_Flux_MVC_View_ExposedTemplateView $view
	 * @return void
	 */
	public function debugView(Tx_Flux_MVC_View_ExposedTemplateView $view) {
		Tx_Extbase_Utility_Debugger::var_dump($view);
	}

	/**
	 * @param Tx_Flux_Provider_ConfigurationProviderInterface $provider
	 * @return void
	 */
	public function debugProvider(Tx_Flux_Provider_ConfigurationProviderInterface $provider) {
		Tx_Extbase_Utility_Debugger::var_dump($provider);
	}

	/**
	 * @param string $message
	 * @param integer $severity
	 * @param string $title
	 * @return NULL
	 */
	public function message($message, $severity = t3lib_div::SYSLOG_SEVERITY_INFO, $title = 'Flux Debug') {
		if (1 > $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['flux']['setup']['debugMode']) {
			return NULL;
		}
		$hash = $message . $severity;
		if (TRUE === isset(self::$sentDebugMessages[$hash])) {
			return NULL;
		}
		if (2 == $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['flux']['setup']['debugMode'] && TRUE === in_array($severity, self::$friendlySeverities)) {
			return NULL;
		}
		$isAjaxCall = (boolean) 0 < t3lib_div::_GET('ajaxCall');
		$flashMessage = new t3lib_FlashMessage($message, $title, $severity);
		$flashMessage->setStoreInSession($isAjaxCall);
		t3lib_FlashMessageQueue::addMessage($flashMessage);
		self::$sentDebugMessages[$hash] = TRUE;
		return NULL;
	}

	/**
	 * @param string $file
	 * @param string $identifier
	 * @param string $id
	 */
	public function updateLanguageSourceFileIfUpdateFeatureIsEnabledAndIdentifierIsMissing($file, $identifier, $id) {
		if (1 > $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['flux']['setup']['rewriteLanguageFiles']) {
			return;
		}
		$this->message('Generated automatic LLL path for entity called "' . $identifier . '" which is a ' .
		get_class($this), t3lib_div::SYSLOG_SEVERITY_INFO, 'Flux FlexForm LLL label generation');
		$debugTitle = 'Flux LLL file rewriting';
		$allowed = 'a-z\.';
		$pattern = '/[^' . $allowed . ']+/i';
		if (preg_match($pattern, $id) || preg_match($pattern, $identifier)) {
			$this->message('Cowardly refusing to create an invalid LLL reference called "' . $identifier . '" ' .
			' in a Flux form called "' . $id . '" - one or both contains invalid characters. Allowed: dots and "' .
			$allowed . '".', t3lib_div::SYSLOG_SEVERITY_NOTICE, $debugTitle);
			return;
		}
		$file = substr($file, 4);
		$filePathAndFilename = t3lib_div::getFileAbsFileName($file);
		$dom = new DOMDocument('1.0', 'utf-8');
		$dom->preserveWhiteSpace = FALSE;
		$dom->load($filePathAndFilename);
		$dom->formatOutput = TRUE;
		foreach ($dom->getElementsByTagName('languageKey') as $languageNode) {
			$nodes = array();
			foreach ($languageNode->getElementsByTagName('label') as $labelNode) {
				$key = (string) $labelNode->attributes->getNamedItem('index')->firstChild->textContent;
				if ($key === $identifier) {
					$this->message('Skipping LLL file merge for label "' . $identifier.
					'"; it already exists in file "' . $filePathAndFilename . '"');
					return;
				}
				$nodes[$key] = $labelNode;
			}
			$node = $dom->createElement('label', $identifier);
			$attribute = $dom->createAttribute('index');
			$attribute->appendChild($dom->createTextNode($identifier));
			$node->appendChild($attribute);
			$nodes[$identifier] = $node;
			ksort($nodes);
			foreach ($nodes as $labelNode) {
				$languageNode->appendChild($labelNode);
			}
		}
		$xml = $dom->saveXML();
		if (FALSE === $xml) {
			$this->message('Skipping LLL file saving due to an error while generating the XML.',
				t3lib_div::SYSLOG_SEVERITY_FATAL);
		} else {
			$this->message('Rewrote "' . $file . '" by adding placeholder label for "' . $identifier . '"',
				t3lib_div::SYSLOG_SEVERITY_INFO, $debugTitle);
			file_put_contents($filePathAndFilename, $xml);
		}
	}

}
