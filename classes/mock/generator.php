<?php

namespace mageekguy\atoum\mock;

use
	mageekguy\atoum,
	mageekguy\atoum\exceptions
;

class generator implements atoum\adapter\aggregator
{
	const defaultNamespace = 'mock';

	protected $adapter = null;
	protected $shuntedMethods = array();
	protected $overloadedMethods = array();

	private $defaultNamespace = null;
	private $reflectionClassInjector = null;

	public function __construct(atoum\adapter $adapter = null)
	{
		$this->setAdapter($adapter ?: new atoum\adapter());
	}

	public function setAdapter(atoum\adapter $adapter)
	{
		$this->adapter = $adapter;

		return $this;
	}

	public function getAdapter()
	{
		return $this->adapter;
	}

	public function setDefaultNamespace($namespace)
	{
		$this->defaultNamespace = '\\' . trim($namespace, '\\');

		return $this;
	}

	public function getDefaulNamespace()
	{
		return ($this->defaultNamespace === null ? self::defaultNamespace : $this->defaultNamespace);
	}

	public function getReflectionClass($class)
	{
		$reflectionClass = null;

		if ($this->reflectionClassInjector === null)
		{
			$reflectionClass = new \reflectionClass($class);
		}
		else
		{
			$reflectionClass = $this->reflectionClassInjector->__invoke($class);

			if ($reflectionClass instanceof \reflectionClass === false)
			{
				throw new exceptions\runtime\unexpectedValue('Reflection class injector must return a \reflectionClass instance');
			}
		}

		return $reflectionClass;
	}

	public function setReflectionClassInjector(\closure $reflectionClassInjector)
	{
		$closure = new \reflectionMethod($reflectionClassInjector, '__invoke');

		if ($closure->getNumberOfParameters() != 1)
		{
			throw new exceptions\logic\invalidArgument('Reflection class injector must take one argument');
		}

		$this->reflectionClassInjector = $reflectionClassInjector;

		return $this;
	}

	public function overload(php\method $method)
	{
		$this->overloadedMethods[$method->getName()] = $method;

		return $this;
	}

	public function shunt($method)
	{
		if ($this->isShunted($method) === false)
		{
			$this->shuntedMethods[] = strtolower($method);
		}

		return $this;
	}

	public function isShunted($method)
	{
		return (in_array(strtolower($method), $this->shuntedMethods) === true);
	}

	public function getMockedClassCode($class, $mockNamespace = null, $mockClass = null)
	{
		$code = null;

		$class = '\\' . ltrim($class, '\\');

		if ($mockNamespace === null)
		{
			$mockNamespace = $this->getNamespace($class);
		}

		if ($mockClass === null)
		{
			$mockClass = self::getClassName($class);
		}

		if ($this->adapter->class_exists($mockNamespace . '\\' . $mockClass, false) === true || $this->adapter->interface_exists($mockNamespace . '\\' . $mockClass, false) === true)
		{
			throw new exceptions\logic('Class \'' . $mockNamespace . '\\' . $mockClass . '\' already exists');
		}

		if ($this->adapter->class_exists($class, true) === false && $this->adapter->interface_exists($class, true) === false)
		{
			$code = self::generateUnknownClassCode($class, $mockNamespace, $mockClass);
		}
		else
		{
			$reflectionClass = $this->getReflectionClass($class);

			if ($reflectionClass instanceof \reflectionClass === false)
			{
				throw new exceptions\logic('Reflection class injector does not return a \reflectionClass instance');
			}

			if ($reflectionClass->isFinal() === true)
			{
				throw new exceptions\logic('Class \'' . $class . '\' is final, unable to mock it');
			}

			$code = $reflectionClass->isInterface() === false ? $this->generateClassCode($reflectionClass, $mockNamespace, $mockClass) : self::generateInterfaceCode($reflectionClass, $mockNamespace, $mockClass);
		}

		return $code;
	}

	public function generate($class, $mockNamespace = null, $mockClass = null)
	{
		eval($this->getMockedClassCode($class, $mockNamespace, $mockClass));

		$this->shuntedMethods = array();
		$this->overloadedMethods = array();

		return $this;
	}

	protected function generateClassCode(\reflectionClass $class, $mockNamespace, $mockClass)
	{
		return 'namespace ' . ltrim($mockNamespace, '\\') . ' {' . PHP_EOL .
			'final class ' . $mockClass . ' extends \\' . $class->getName() . ' implements \\' . __NAMESPACE__ . '\\aggregator' . PHP_EOL .
			'{' . PHP_EOL .
			self::generateMockControllerMethod() .
			$this->generateClassMethodCode($class) .
			'}' . PHP_EOL .
			'}'
		;
	}

	protected function generateClassMethodCode(\reflectionClass $class)
	{
		$mockedMethods = '';

		$hasConstructor = false;

		$className = $class->getName();

		foreach ($class->getMethods() as $method)
		{
			$isConstructor = $method->isConstructor();

			if ($isConstructor === true)
			{
				$hasConstructor = true;
			}

			if ($method->isFinal() === false && $method->isStatic() === false)
			{
				$methodCode = '';

				$methodName = $method->getName();

				switch (true)
				{
					case $method->isProtected() && $method->isAbstract():
						$methodCode = "\t" . 'protected function' . ($method->returnsReference() === false ? '' : ' &') . ' ' . $methodName . '(' . self::getParameters($method) . ') {}' . PHP_EOL;
						break;

					case $method->isPublic():
						$parameters = array();

						if (isset($this->overloadedMethods[$methodName]) === true)
						{
							foreach ($this->overloadedMethods[$methodName]->getArguments() as $argument)
							{
								$parameters[] = $argument->getVariable();
							}

							if ($isConstructor === true)
							{
								$this->overloadedMethods[$methodName]->addArgument(php\method\argument::get('mockController')
										->isObject('\\' . __NAMESPACE__ . '\\controller')
										->setDefaultValue(null)
									)
								;
							}

							$methodCode = "\t" . ((string) $this->overloadedMethods[$methodName]). PHP_EOL . "\t" . '{' . PHP_EOL;
						}
						else
						{
							$methodCode = "\t" . 'public function' . ($method->returnsReference() === false ? '' : ' &') . ' ' . $methodName . '(' . self::getParameters($method, $isConstructor) . ')' . PHP_EOL;
							$methodCode .= "\t" . '{' . PHP_EOL;

							$parameters = array();

							foreach ($method->getParameters() as $parameter)
							{
								$parameters[] = '$' . $parameter->getName();
							}
						}

						$parameters = join(', ', $parameters);
						$mockControllerParameters = ($parameters == '' ? 'func_get_args()' : 'array(' . $parameters . ')');

						if ($isConstructor === true)
						{
							$methodCode .= "\t\t" . 'if ($mockController === null)' . PHP_EOL;
							$methodCode .= "\t\t" . '{' . PHP_EOL;
							$methodCode .= "\t\t\t" . '$mockController = \mageekguy\atoum\mock\controller::get();' . PHP_EOL;
							$methodCode .= "\t\t" . '}' . PHP_EOL;
							$methodCode .= "\t\t" . 'if ($mockController !== null)' . PHP_EOL;
							$methodCode .= "\t\t" . '{' . PHP_EOL;
							$methodCode .= "\t\t\t" . '$this->setMockController($mockController);' . PHP_EOL;
							$methodCode .= "\t\t" . '}' . PHP_EOL;
						}

						if ($this->isShunted($methodName) === true || $method->isAbstract() === true)
						{
							$methodCode .= "\t\t" . 'if (isset($this->getMockController()->' . $methodName . ') === false)' . PHP_EOL;
							$methodCode .= "\t\t" . '{' . PHP_EOL;
							$methodCode .= "\t\t\t" . '$this->mockController->' . $methodName . ' = function() {};' . PHP_EOL;
							$methodCode .= "\t\t" . '}' . PHP_EOL;
							$methodCode .=	"\t\t" . ($isConstructor === true ? '' : 'return ') . '$this->mockController->invoke(\'' . $methodName . '\', ' . $mockControllerParameters . ');' . PHP_EOL;
						}
						else
						{
							$methodCode .= "\t\t" . 'if (isset($this->getMockController()->' . $methodName . ') === true)' . PHP_EOL;
							$methodCode .= "\t\t" . '{' . PHP_EOL;
							$methodCode .= "\t\t\t" . ($isConstructor === true ? '' : 'return ') . '$this->mockController->invoke(\'' . $methodName . '\', ' . $mockControllerParameters . ');' . PHP_EOL;
							$methodCode .= "\t\t" . '}' . PHP_EOL;
							$methodCode .= "\t\t" . 'else' . PHP_EOL;
							$methodCode .= "\t\t" . '{' . PHP_EOL;

							if ($isConstructor === false)
							{
								$methodCode .= "\t\t\t" . '$this->getMockController()->addCall(\'' . $methodName . '\', ' . $mockControllerParameters . ');' . PHP_EOL;
							}

							if ($parameters == '')
							{
								$parentMethodCall = 'call_user_func_array(\'parent::' . $methodName . '\', func_get_args())';
							}
							else
							{
								$parentMethodCall = 'parent::' . $methodName . '(' . $parameters . ')';
							}

							$methodCode .= "\t\t\t" . ($isConstructor === true ? '' : 'return ') . $parentMethodCall . ';' . PHP_EOL;

							$methodCode .= "\t\t" . '}' . PHP_EOL;
						}

						$methodCode .= "\t" . '}' . PHP_EOL;
						break;
				}

				$mockedMethods .= $methodCode;
			}
		}

		if ($hasConstructor === false)
		{
			$mockedMethods .= self::generateDefaultConstructor();
		}

		return $mockedMethods;
	}

	protected function getNamespace($class)
	{
		$class = ltrim($class, '\\');

		$lastAntiSlash = strrpos($class, '\\');

		return '\\' . $this->getDefaulNamespace() . ($lastAntiSlash === false ? '' : '\\' . substr($class, 0, $lastAntiSlash));
	}

	protected static function getClassName($class)
	{
		$class = ltrim($class, '\\');

		$lastAntiSlash = strrpos($class, '\\');

		return ($lastAntiSlash === false ? $class : substr($class, $lastAntiSlash + 1));
	}

	protected static function getParameterType(\reflectionParameter $parameter)
	{
		$type = '';

		if ($parameter->isArray() == true)
		{
			$type = 'array ';
		}
		else
		{
			$class = $parameter->getClass();

			if ($class !== null)
			{
				$type = '\\' . $class->getName() . ' ';
			}
		}

		return $type;
	}

	protected static function getParameters(\reflectionMethod $method, $addMockController = false)
	{
		$parameters = array();

		foreach ($method->getParameters() as $parameter)
		{
			$parameterCode = self::getParameterType($parameter) . ($parameter->isPassedByReference() == false ? '' : '& ') . '$' . $parameter->getName();

			if ($parameter->isDefaultValueAvailable() == true)
			{
				$parameterCode .= '=' . var_export($parameter->getDefaultValue(), true);
			}
			else if ($parameter->isOptional() === true)
			{
				$parameterCode .= '=null';
			}

			$parameters[] = $parameterCode;
		}

		if ($addMockController === true)
		{
			$parameters[] = '\\' . __NAMESPACE__ . '\\controller $mockController = null';
		}

		return join(', ', $parameters);
	}

	protected static function generateMockControllerMethod()
	{
		return
			"\t" . 'private $mockController = null;' . PHP_EOL .
			"\t" . 'public function getMockController()' . PHP_EOL .
			"\t" . '{' . PHP_EOL .
			"\t\t" . 'if ($this->mockController === null)' . PHP_EOL .
			"\t\t" . '{' . PHP_EOL .
			"\t\t\t" . '$this->setMockController(new \\' . __NAMESPACE__ . '\\controller());' . PHP_EOL .
			"\t\t" . '}' . PHP_EOL .
			"\t\t" . 'return $this->mockController;' . PHP_EOL .
			"\t" . '}' . PHP_EOL .
			"\t" . 'public function setMockController(\\' . __NAMESPACE__ . '\\controller $controller)' . PHP_EOL .
			"\t" . '{' . PHP_EOL .
			"\t\t" . 'if ($this->mockController !== $controller)' . PHP_EOL .
			"\t\t" . '{' . PHP_EOL .
			"\t\t\t" . '$this->mockController = $controller->control($this);' . PHP_EOL .
			"\t\t" . '}' . PHP_EOL .
			"\t\t" . 'return $this->mockController;' . PHP_EOL .
			"\t" . '}' . PHP_EOL .
			"\t" . 'public function resetMockController()' . PHP_EOL .
			"\t" . '{' . PHP_EOL .
			"\t\t" . 'if ($this->mockController !== null)' . PHP_EOL .
			"\t\t" . '{' . PHP_EOL .
			"\t\t\t" . '$mockController = $this->mockController;' . PHP_EOL .
			"\t\t\t" . '$this->mockController = null;' . PHP_EOL .
			"\t\t\t" . '$mockController->reset();' . PHP_EOL .
			"\t\t" . '}' . PHP_EOL .
			"\t\t" . 'return $this;' . PHP_EOL .
			"\t" . '}' . PHP_EOL
		;
	}

	protected static function generateUnknownClassCode($class, $mockNamespace, $mockClass)
	{
		return 'namespace ' . ltrim($mockNamespace, '\\') . ' {' . PHP_EOL .
			'final class ' . $mockClass . ' implements \\' . __NAMESPACE__ . '\\aggregator' . PHP_EOL .
			'{' . PHP_EOL .
			self::generateMockControllerMethod() .
			"\t" . 'public function __construct(\mageekguy\atoum\mock\controller $mockController = null)' . PHP_EOL .
			"\t" . '{' . PHP_EOL .
			"\t\t" . 'if ($mockController === null)' . PHP_EOL .
			"\t\t" . '{' . PHP_EOL .
			"\t\t\t" . '$mockController = \mageekguy\atoum\mock\controller::get();' . PHP_EOL .
			"\t\t" . '}' . PHP_EOL .
			"\t\t" . 'if ($mockController !== null)' . PHP_EOL .
			"\t\t" . '{' . PHP_EOL .
			"\t\t\t" . '$this->setMockController($mockController);' . PHP_EOL .
			"\t\t" . '}' . PHP_EOL .
			"\t\t" . '$this->getMockController()->disableMethodChecking();' . PHP_EOL .
			"\t\t" . 'if (isset($this->getMockController()->__construct) === true)' . PHP_EOL .
			"\t\t" . '{' . PHP_EOL .
			"\t\t\t" . '$this->mockController->invoke(\'__construct\', array());' . PHP_EOL .
			"\t\t" . '}' . PHP_EOL .
			"\t" . '}' . PHP_EOL .
			"\t" . 'public function __call($methodName, $arguments)' . PHP_EOL .
			"\t" . '{' . PHP_EOL .
			"\t\t" . 'if (isset($this->getMockController()->{$methodName}) === true)' . PHP_EOL .
			"\t\t" . '{' . PHP_EOL .
			"\t\t\t" . 'return $this->mockController->invoke($methodName, $arguments);' . PHP_EOL .
			"\t\t" . '}' . PHP_EOL .
			"\t\t" . 'else' . PHP_EOL .
			"\t\t" . '{' . PHP_EOL .
			"\t\t\t" . '$this->getMockController()->addCall($methodName, $arguments);' . PHP_EOL .
			"\t\t" . '}' . PHP_EOL .
			"\t" . '}' . PHP_EOL .
			'}' . PHP_EOL .
			'}'
		;
	}

	protected static function generateInterfaceMethodCode(\reflectionClass $class)
	{
		$mockedMethods = '';

		$hasConstructor = false;

		foreach ($class->getMethods(\reflectionMethod::IS_PUBLIC) as $method)
		{
			if ($method->isFinal() === false && $method->isStatic() === false)
			{
				$methodName = $method->getName();
				$isConstructor = $method->isConstructor();

				if ($isConstructor === true)
				{
					$hasConstructor = true;
				}

				$parameters = array();

				foreach ($method->getParameters() as $parameter)
				{
					$parameters[] = '$' . $parameter->getName();
				}

				$parameters = join(', ', $parameters);
				$mockControllerParameters = ($parameters == '' ? 'func_get_args()' : 'array(' . $parameters . ')');

				$methodCode = "\t" . 'public function' . ($method->returnsReference() === false ? '' : ' &') . ' ' . $methodName . '(' . self::getParameters($method, $isConstructor) . ')' . PHP_EOL;
				$methodCode .= "\t" . '{' . PHP_EOL;

				if ($isConstructor === true)
				{
					$methodCode .= "\t\t" . 'if ($mockController === null)' . PHP_EOL;
					$methodCode .= "\t\t" . '{' . PHP_EOL;
					$methodCode .= "\t\t\t" . '$mockController = \mageekguy\atoum\mock\controller::get();' . PHP_EOL;
					$methodCode .= "\t\t" . '}' . PHP_EOL;
					$methodCode .= "\t\t" . 'if ($mockController !== null)' . PHP_EOL;
					$methodCode .= "\t\t" . '{' . PHP_EOL;
					$methodCode .= "\t\t\t" . '$this->setMockController($mockController);' . PHP_EOL;
					$methodCode .= "\t\t" . '}' . PHP_EOL;
				}

				$methodCode .= "\t\t" . 'if (isset($this->getMockController()->' . $methodName . ') === false)' . PHP_EOL;
				$methodCode .= "\t\t" . '{' . PHP_EOL;
				$methodCode .= "\t\t\t" . '$this->mockController->' . $methodName . ' = function() {};' . PHP_EOL;
				$methodCode .= "\t\t" . '}' . PHP_EOL;
				$methodCode .=	"\t\t" . ($isConstructor === true ? '' : 'return ') . '$this->mockController->invoke(\'' . $methodName . '\', ' . $mockControllerParameters . ');' . PHP_EOL;
				$methodCode .= "\t" . '}' . PHP_EOL;

				$mockedMethods .= $methodCode;
			}
		}

		if ($hasConstructor === false)
		{
			$mockedMethods .= self::generateDefaultConstructor();
		}

		return $mockedMethods;
	}

	protected static function generateInterfaceCode(\reflectionClass $class, $mockNamespace, $mockClass)
	{
		return 'namespace ' . ltrim($mockNamespace, '\\') . ' {' . PHP_EOL .
			'final class ' . $mockClass . ' implements \\' . $class->getName() . ', \\' . __NAMESPACE__ . '\\aggregator' . PHP_EOL .
			'{' . PHP_EOL .
			self::generateMockControllerMethod() .
			self::generateInterfaceMethodCode($class) .
			'}' . PHP_EOL .
			'}'
		;
	}

	protected static function generateDefaultConstructor()
	{
		return
			  "\t" . 'public function __construct(\\' . __NAMESPACE__ . '\\controller $mockController = null)' . PHP_EOL
			. "\t" . '{' . PHP_EOL
			. "\t\t" . 'if ($mockController === null)' . PHP_EOL
			. "\t\t" . '{' . PHP_EOL
			. "\t\t\t" . '$mockController = \mageekguy\atoum\mock\controller::get();' . PHP_EOL
			. "\t\t" . '}' . PHP_EOL
			. "\t\t" . 'if ($mockController !== null)' . PHP_EOL
			. "\t\t" . '{' . PHP_EOL
			. "\t\t\t" . '$this->setMockController($mockController);' . PHP_EOL
			. "\t\t" . '}' . PHP_EOL
			. "\t\t" . 'if (isset($this->getMockController()->__construct) === true)' . PHP_EOL
			. "\t\t" . '{' . PHP_EOL
			. "\t\t\t" . '$this->mockController->invoke(\'__construct\', func_get_args());' . PHP_EOL
			. "\t\t" . '}' . PHP_EOL
			. "\t" . '}' . PHP_EOL
		;
	}
}

?>
