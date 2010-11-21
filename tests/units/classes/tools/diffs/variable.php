<?php

namespace mageekguy\atoum\tests\units\tools\diffs;

use \mageekguy\atoum;
use \mageekguy\atoum\tools;

require_once(__DIR__ . '/../../../runner.php');

class variable extends atoum\test
{
	public function test__construct()
	{
		$diff = new tools\diffs\variable();

		$this->assert
			->variable($diff->getReference())->isNull()
			->variable($diff->getData())->isNull()
		;
	}

	public function testSetReference()
	{
		$diff = new tools\diffs\variable();

		$this->assert
			->object($diff->setReference($variable = uniqid()))->isIdenticalTo($diff)
			->string($diff->getReference())->isEqualTo(var_export($variable, true))
		;
	}

	public function testSetData()
	{
		$diff = new tools\diffs\variable();

		$this->assert
			->object($diff->setData($variable = uniqid()))->isIdenticalTo($diff)
			->string($diff->getData())->isEqualTo(var_export($variable, true))
		;
	}

	public function testMake()
	{
		$diff = new tools\diffs\variable();

		$exception = null;

		try
		{
			$diff->make();
		}
		catch (\exception $exception) {}

		$this->assert
			->exception($exception)
				->isInstanceOf('\runtimeException')
				->hasMessage('Reference is undefined')
		;

		$diff->setReference($reference = uniqid());

		try
		{
			$diff->make();
		}
		catch (\exception $exception) {}

		$this->assert
			->exception($exception)
				->isInstanceOf('\runtimeException')
				->hasMessage('Data is undefined')
		;

		$diff->setData($reference);

		$this->assert
			->array($diff->make())->isEqualTo(array(var_export($reference, true)))
		;
	}
}

?>