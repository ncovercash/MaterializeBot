<?php

require_once "vendor/autoload.php";

abstract class PlatformCheck {
	protected $checks = Array();
	protected $str = "platform error";

	public function __construct(string $explaination, string ...$checks) {
		$this->checks = $checks;
		$this->setExplaination($explaination);
	}

	public abstract function test(string $html="", string $js="") : bool;

	public function setExplaination(string $str) {
		$this->str = $str;
	}

	public function getExplaination() : string {
		return $this->str;
	}

	public function __toString() : string {
		return $this->str;
	}
}

class ExistsDomCheck extends PlatformCheck {
	public function __construct(string $explaination, string $check) {
		$this->checks = Array($check);
		$this->setExplaination($explaination);
	}

	public function test(string $html="", string $js="") : bool {
		$dom = pQuery::parseStr($html);

		return count($dom->query($this->checks[0])) > 0;
	}
}

class SingularOrNonexistentDomCheck extends PlatformCheck {
	public function __construct(string $explaination, string $check) {
		$this->checks = Array($check);
		$this->setExplaination($explaination);
	}

	public function test(string $html="", string $js="") : bool {
		$dom = pQuery::parseStr($html);

		var_dump($this->checks);

		return count($dom->query($this->checks[0])) <= 1;
	}
}

class SingularDomCheck extends PlatformCheck {
	public function __construct(string $explaination, string $check) {
		$this->checks = Array($check);
		$this->setExplaination($explaination);
	}

	public function test(string $html="", string $js="") : bool {
		$dom = pQuery::parseStr($html);

		return count($dom->query($this->checks[0])) == 1;
	}
}

class MultipleDomCheck extends PlatformCheck {
	public function test(string $html="", string $js="") : bool {
		$dom = pQuery::parseStr($html);

		$numEach = Array();

		foreach ($this->checks as $check) {
			$numEach[] = count($dom->query($check));
		}

		return count(array_unique($numEach)) == 1;
	}
}

class DomJSCheck extends PlatformCheck {
	public function __construct(string $explaination, string $domCheck, string $jsCheck) {
		$this->checks = Array($domCheck, $jsCheck);
		$this->setExplaination($explaination);
	}

	public function test(string $html="", string $js="") : bool {
		$dom = pQuery::parseStr($html);

		if (count($dom->query($this->checks[0])) > 0) {
			return strpos($js, $this->checks[1]) === true;
		}
		return true;
	}
}

class JSDomCheck extends PlatformCheck {
	public function __construct(string $explaination, string $jsCheck, string $domCheck) {
		$this->checks = Array($jsCheck, $domCheck);
		$this->setExplaination($explaination);
	}

	public function test(string $html="", string $js="") : bool {
		$dom = pQuery::parseStr($html);

		if (strpos($js, $this->checks[0]) === true) {
			return count($dom->query($this->checks[1])) > 0;
		}
		return true;
	}
}
