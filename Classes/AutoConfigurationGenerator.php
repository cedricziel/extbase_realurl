<?php
class Tx_ExtbaseRealurl_AutoConfigurationGenerator {

	/**
	 * @var Tx_Extbase_Object_ObjectManagerInterface
	 */
	protected $objectManager;

	/**
	 * @var array
	 */
	private static $tableSetupCache = array();

	/**
	 * CONSTRUCTOR
	 */
	public function __construct() {
		$this->objectManager = t3lib_div::makeInstance('Tx_Extbase_Object_ObjectManager');
	}

	/**
	 * Builds automatic rules for every Extbase plugin's controllers
	 * in relation to the pages on which the plugins are inserted.
	 *
	 * Does this by looping through all configured Extbase plugins and
	 * checking those against the tt_content records currently active
	 * and prioritizes those records so that only the topmost active
	 * (depending on routing configuration) plugin on any one page is
	 * able to receive the default request arguments. This is done in
	 * order to prevent colissions.
	 *
	 * However, it is still possible to switch controllers in the rule
	 * that is build for your particular Controller - this only requires
	 * that you add this annotation to each Controller which should be
	 * able to include the "controller" and "action" arguments as segments
	 * in the nice URLs: @route NoMatch(NULL). This annotation is set
	 * on the class itself when it applies to the "controller" and
	 * "action" arguments; if it applies to a controller action argument
	 * then it must be placed in the parent method's annotations.
	 *
	 * @param array $params
	 * @param object $reference
	 */
	public function buildAutomaticRules($params, $reference) {
		$extensionsAndPluginNames = array();
		$configuration = $params['config'];
		foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['extbase']['extensions'] as $extensionName => $extensionConfiguration) {
			foreach ($extensionConfiguration['plugins'] as $pluginName => $pluginConfiguration) {
				$routable = FALSE;
				foreach ($pluginConfiguration['controllers'] as $controllerName => $controllerConfiguration) {
					if ($routable === TRUE) {
						break;
					}
					$controllerClassName = 'Tx_' . $extensionName . '_Controller_' . $controllerName . 'Controller';
					if (FALSE === class_exists($controllerClassName)) {
						continue;
					}
					$controllerClassReflection = new ReflectionClass($controllerClassName);
					$controllerClassAnnotations = $this->getRoutingAnnotations($controllerClassReflection->getDocComment());
					if ($this->assertIsRoutable($controllerClassAnnotations) === FALSE && $routable === FALSE) {
						break;
					}
					foreach ($controllerConfiguration['actions'] as $actionName) {
						if (method_exists($controllerClassName, $actionName . 'Action') === FALSE) {
							continue;
						}
						$methodReflection = $controllerClassReflection->getMethod($actionName . 'Action');
						$methodAnnotations = $this->getRoutingAnnotations($methodReflection->getDocComment());
						if ($this->assertIsRoutable($methodAnnotations) === TRUE) {
							$routable = TRUE;
							break;
						}
					}
				}
				if ($routable === TRUE) {
					array_push($extensionsAndPluginNames, $extensionName . '->' . $pluginName);
					unset($pluginConfiguration);
				}
			}
			unset($extensionConfiguration);
		}

		$configuration['fileName']['defaultToHTMLsuffixOnPrev'] = 1;
		if (isset($configuration['fixedPostVars']) === FALSE || is_array($configuration['fixedPostVars']) === FALSE) {
			$configuration['fixedPostVars'] = array();
		}
		$definitions = $this->buildFixedPostVarsForExtensionsAndPluginNames($extensionsAndPluginNames);

			// note: foreach-style mapping because array_merge would re-index the numeric
			// indices which are page UIDs - so this would not suit the purpose of mapping
		foreach ($definitions as $pidOrName => $definitionOrMappingTarget) {
			$configuration['fixedPostVars'][$pidOrName] = $definitionOrMappingTarget;
		}

		unset($reference);
		return $configuration;
	}

	/**
	 * Builds and stores internally the fixed post var sets for all
	 * extensions and plugin names in $extensionsAndPluginNames
	 *
	 * @param array $extensionsAndPluginNames
	 * @return void
	 */
	protected function buildFixedPostVarsForExtensionsAndPluginNames($extensionsAndPluginNames) {
		$definitions = array();
		$pluginSignatures = array();
		foreach ($extensionsAndPluginNames as $extensionAndPluginName) {
			list ($extensionName, $pluginName) = explode('->', $extensionAndPluginName);
			$pluginSignature = strtolower(str_replace('_', '', $extensionName) . '_' . str_replace('_', '', $pluginName));
			$pluginSignatures[$extensionAndPluginName] = $pluginSignature;
		}
		$registeredPluginSignatureValues = array_values($pluginSignatures);
		foreach ($extensionsAndPluginNames as $extensionAndPluginName) {
			list ($extensionName, $pluginName) = explode('->', $extensionAndPluginName);
			$this->currentExtensionName = $extensionName;

			$pluginSignature = $pluginSignatures[$extensionAndPluginName];
			$urlPrefix = 'tx_' . $pluginSignature;
			foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['extbase']['extensions'][$extensionName]['plugins'][$pluginName]['controllers'] as $controllerName => $controllerConfiguration) {
				$controllerClassName = 'Tx_' . $extensionName . '_Controller_' . $controllerName . 'Controller';
				$controllerClassReflection = new ReflectionClass($controllerClassName);
				$controllerClassAnnotations = $this->getRoutingAnnotations($controllerClassReflection->getDocComment());
				if ($this->assertIsRoutable($controllerClassAnnotations) === FALSE) {
					continue;
				}
				foreach ($controllerConfiguration['actions'] as $actionName) {
					if (method_exists($controllerClassName, $actionName . 'Action') === FALSE) {
						continue;
					}
					$identity = $pluginSignature . '_' . $controllerName . '_' . $actionName;
					$methodReflection = $controllerClassReflection->getMethod($actionName . 'Action');
					$annotations = $this->getRoutingAnnotations($methodReflection->getDocComment());
					if ($this->assertIsRoutable($annotations) === FALSE) {
						continue;
					}
					$arguments = $methodReflection->getParameters();
					$definitions[$identity] = array(
						$this->buildFixedPostVarsForController($urlPrefix, $controllerClassAnnotations),
						$this->buildFixedPostVarsForControllerAction($urlPrefix, $annotations),
					);
					foreach ($arguments as $argumentReflection) {
						$segment = $this->buildFixedPostVarsForControllerActionArgument($argumentReflection, $actionName, $urlPrefix);
						if ($segment !== NULL) {
							array_push($definitions[$identity], $segment);
						}
					}
					$pageUids = $this->getAllPAgeUidsWithPluginSignatureInColPosZeroTop($extensionName, $pluginName, $controllerName, $actionName, $registeredPluginSignatureValues);
					foreach ($pageUids as $pid) {
						$definitions[$pid] = $identity;
					}
				}
			}
		}
		return $definitions;
	}

	/**
	 * Assert wether this any one of this set of annotations disables routing.
	 *
	 * @param Tx_ExtbaseRealurl_RoutingAnnotation[] $annotations
	 * @return boolean
	 */
	protected function assertIsRoutable(array $annotations) {
		foreach ($annotations as $annotation) {
			if ($annotation->assertRoutingDisabled() === TRUE) {
				return FALSE;
			}
		}
		return TRUE;
	}

	/**
	 * Assert the noMatch rule for this set of annotations. Last one has precedense.
	 *
	 * @param Tx_ExtbaseRealurl_RoutingAnnotation[] $annotations
	 * @param string|NULL $argumentName
	 * @return string|NULL
	 */
	protected function assertNoMatchRule(array $annotations, $argumentName = NULL) {
		$rule = NULL;
		foreach ($annotations as $annotation) {
			$noMatchRule = $annotation->getNoMatchRule();
			$appliesToArgument = $annotation->assertAppliesToVariable($argumentName);
			if ($noMatchRule !== NULL && $appliesToArgument) {
				$rule = $noMatchRule;
			}
		}
		return $rule;
	}

	/**
	 * Assert the redirect rule for this set of annotations. Last one has precedense.
	 *
	 * @param Tx_ExtbaseRealurl_RoutingAnnotation[] $annotations
	 * @param string|NULL $argumentName
	 * @return Tx_ExtbaseRealurl_Rule_RedirectRule|NULL
	 */
	protected function assertRedirectRule(array $annotations, $argumentName = NULL) {
		$rule = NULL;
		foreach ($annotations as $annotation) {
			$currentRule = $annotation->getRedirectRule();
			$appliesToArgument = $annotation->assertAppliesToVariable($argumentName);
			if ($currentRule instanceof Tx_ExtbaseRealurl_Rule_RedirectRule && $appliesToArgument) {
				$rule = $currentRule;
			}
		}
		return $rule;
	}

	/**
	 * @param string $docComment
	 * @return ExtbaseRealurl_Routing_RoutingAnnotation[]
	 */
	protected function getRoutingAnnotations($docComment) {
		$pattern = '/@route[\s]+(.[^\n]+)[\n]{1,1}/';
		$matches = array();
		$annotations = array();
		preg_match_all($pattern, $docComment, $matches);
		array_shift($matches);
		$annotationLines = array_shift($matches);
		foreach ($annotationLines as $matchedPattern) {
			/** @var $annotation Tx_ExtbaseRealurl_RoutingAnnotation */
			$annotation = $this->objectManager->create('Tx_ExtbaseRealurl_RoutingAnnotation');
			$annotation->setMatchedPattern($matchedPattern);
			array_push($annotations, $annotation);
		}
		return $annotations;
	}

	/**
	 * Get an array of UIDs of all pages on which $pluginSignature is the first
	 * Extbase plugin in colPos zero.
	 *
	 * @param string $extensionName
	 * @param string $pluginName
	 * @param string $controllerName
	 * @param string $actionName
	 * @param array $registeredExtbasePluginSignatures
	 * @return array
	 */
	protected function getAllPAgeUidsWithPluginSignatureInColPosZeroTop($extensionName, $pluginName, $controllerName, $actionName, $registeredExtbasePluginSignatures) {
		$pluginSignature = strtolower(str_replace('_', '', $extensionName) . '_' . str_replace('_', '', $pluginName));
		$clause = "t.deleted = '0' AND t.hidden = '0' AND t.starttime <= '" . time() . "' AND (t.endtime >= '" . time() . "' OR t.endtime = '0') AND p.deleted = '0' AND p.uid = t.pid";
		$orderedPageSignatures = array();
		$contentRecords = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows('t.pid, t.CType, t.list_type, t.pi_flexform', 'tt_content t, pages p', $clause, 'p.uid', 't.sorting ASC');
		$pageUids = array();
		foreach ($contentRecords as $contentRecord) {
			$pid = $contentRecord['pid'];
			$signature = $contentRecord['list_type'] ? $contentRecord['list_type'] : $contentRecord['CType'];
			if (in_array($signature, $registeredExtbasePluginSignatures)) {
				$orderedPageSignatures[$pid] = array(
					$signature,
					$contentRecord['pi_flexform']
				);
			}
		}
		foreach ($orderedPageSignatures as $pid => $signatureAndFlexform) {
			list ($signature, $flexFormSource) = $signatureAndFlexform;
			if ($flexFormSource) {
				$decoded = $this->convertFlexFormContentToArray($flexFormSource);
			} else {
				$decoded = NULL;
			}
			if (isset($decoded['switchableControllerActions']) && strpos($decoded['switchableControllerActions'], '->') !== FALSE) {
				list ($contentRecordController, $contentRecordControllerAction) = explode('->', $decoded['switchableControllerActions']);
			} else {
				reset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['extbase']['extensions'][$extensionName]['plugins'][$pluginName]['controllers']);
				$contentRecordController = key($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['extbase']['extensions'][$extensionName]['plugins'][$pluginName]['controllers']);
				$contentRecordControllerAction = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['extbase']['extensions'][$extensionName]['plugins'][$pluginName]['controllers'][$contentRecordController]['actions'][0];
			}
			if ($signature === $pluginSignature && $contentRecordController === $controllerName && $contentRecordControllerAction === $actionName) {
				array_push($pageUids, $pid);
			}
		}
		return $pageUids;
	}

	/**
	 * @param string $urlPrefix
	 * @param Tx_ExtbaseRealurl_RoutingAnnotation[] $annotations
	 * @return array
	 */
	protected function buildFixedPostVarsForController($urlPrefix, $annotations) {
		$definition = array(
			'GETvar' => $urlPrefix . '[controller]',
		);
		$noMatchRule = $this->assertNoMatchRule($annotations);
		if ($noMatchRule !== NULL) {
			$definition['noMatch'] = $noMatchRule;
		}
		return $definition;
	}

	/**
	 * @param string $urlPrefix
	 * @param Tx_ExtbaseRealurl_RoutingAnnotation[] $annotations
	 * @return array
	 */
	protected function buildFixedPostVarsForControllerAction($urlPrefix, $annotations) {
		$definition = array(
			'GETvar' => $urlPrefix . '[action]',
		);
		$noMatchRule = $this->assertNoMatchRule($annotations);
		if ($noMatchRule !== NULL) {
			$definition['noMatch'] = $noMatchRule;
		}
		return $definition;
	}

	/**
	 * @param ReflectionParameter $argument
	 * @param string $actionName
	 * @param string $urlPrefix
	 * @return array
	 */
	protected function buildFixedPostVarsForControllerActionArgument(ReflectionParameter $argument, $actionName, $urlPrefix) {
		$argumentName = $argument->getName();
		$annotations = $this->getRoutingAnnotations($argument->getDeclaringFunction()->getDocComment());
		$definition = array(
			'GETvar' => $urlPrefix . '[' . $argumentName . ']',
		);
		$noMatchRule = $this->assertNoMatchRule($annotations, $argumentName);
		$redirectRule = $this->assertRedirectRule($annotations, $argumentName);

		$docComment = $argument->getDeclaringFunction()->getDocComment();
		$matches = array();
		preg_match('/@param[\s]+([a-zA-Z_0-9\\^\s]+)[\s]+\$' . $argumentName . '/', $docComment, $matches);
		$argumentDataType = trim($matches[1]);
		$tableName = $labelField = NULL;
		switch ($argumentDataType) {
			case '': $conversionMethod = Tx_ExtbaseRealurl_SegmentValueProcessor::CONVERT_NULL; break;
			case 'DateTime': $conversionMethod = Tx_ExtbaseRealurl_SegmentValueProcessor::CONVERT_DATETIME; break;
			case 'float':
			case 'integer':
			case 'string': $conversionMethod = Tx_ExtbaseRealurl_SegmentValueProcessor::CONVERT_PASSTHROUGH; break;
			default:
				$conversionMethod = Tx_ExtbaseRealurl_SegmentValueProcessor::CONVERT_MODEL;
				$tableName = strtolower($argumentDataType);
				if (isset(self::$tableSetupCache[$tableName]) === TRUE) {
					$TCA = self::$tableSetupCache[$tableName];
				} else {
					$_EXTKEY = t3lib_div::camelCaseToLowerCaseUnderscored($this->currentExtensionName);
					$extensionConfigurationFile = t3lib_extMgm::extPath($_EXTKEY, 'ext_tables.php');
					if (file_exists($extensionConfigurationFile)) {
						eval('?>' . file_get_contents($extensionConfigurationFile));
						$labelField = $TCA[$tableName]['ctrl']['label'];
					}
				}
				break;
		}
		$definition['userFunc'] = 'Tx_ExtbaseRealurl_SegmentValueProcessor->translateSegmentValue';
		$definition['parameters'] = array(
			'conversionMethod' => $conversionMethod,
			'className' => $argumentDataType,
			'tableName' => $tableName,
			'labelField' => $labelField
		);
		if ($noMatchRule !== NULL) {
			$definition['parameters']['noMatch'] = $noMatchRule;
		}
		if ($redirectRule instanceof Tx_ExtbaseRealurl_Rule_RedirectRule) {
			$definition['parameters']['redirect'] = $redirectRule->getArrayCopy();
		}

		if ($argument->isDefaultValueAvailable()) {
			$definition['optional'] = $definition['parameters']['optional'] = TRUE;
		}
		return $definition;
	}

	/**
	 * Parses the flexForm content and converts it to an array
	 * The resulting array will be multi-dimensional, as a value "bla.blubb"
	 * results in two levels, and a value "bla.blubb.bla" results in three levels.
	 *
	 * Note: multi-language flexForms are not supported yet
	 *
	 * @param string $flexFormContent flexForm xml string
	 * @param string $languagePointer language pointer used in the flexForm
	 * @param string $valuePointer value pointer used in the flexForm
	 * @return array the processed array
	 */
	public function convertFlexFormContentToArray($flexFormContent, $languagePointer = 'lDEF', $valuePointer = 'vDEF') {
		$settings = array();

		$flexFormArray = t3lib_div::xml2array($flexFormContent);
		$flexFormArray = (isset($flexFormArray['data']) && is_array($flexFormArray['data']) ? $flexFormArray['data'] : $flexFormArray);
		if (is_array($flexFormArray) === FALSE) {
			return $settings;
		}
		foreach (array_values($flexFormArray) as $languages) {
			if (!is_array($languages) || !isset($languages[$languagePointer])) {
				continue;
			}
			foreach ($languages[$languagePointer] as $valueKey => $valueDefinition) {
				if (strpos($valueKey, '.') === FALSE) {
					$settings[$valueKey] = $this->walkFlexFormNode($valueDefinition, $valuePointer);
				} else {
					$valueKeyParts = explode('.', $valueKey);
					$currentNode = &$settings;

					foreach ($valueKeyParts as $valueKeyPart) {
						$currentNode = &$currentNode[$valueKeyPart];
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
		return $settings;
	}

	/**
	 * Parses a flexForm node recursively and takes care of sections etc
	 *
	 * @param array $nodeArray The flexForm node to parse
	 * @param string $valuePointer The valuePointer to use for value retrieval
	 * @return array
	 */
	public function walkFlexFormNode($nodeArray, $valuePointer = 'vDEF') {
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
					$nodeCount = count($nodeKeyParts);
					for ($i = 0; $i < ($nodeCount - 1); $i++) {
						$currentNode = &$currentNode[$nodeKeyParts[$i]];
					}

					$newNode = array(next($nodeKeyParts) => $nodeValue);
					$currentNode = $this->walkFlexFormNode($newNode, $valuePointer);
				} elseif (is_array($nodeValue)) {
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

}