<?php
/**
 * Calls method with errors trapped
 */
class ErrorTrap {
	protected $callback;
	protected $errors = array();
	
	function __construct($method) {
		$this->callback = $method;
	}
	
	function call() {
		$this->errors = array();
		$result = null;
		set_error_handler(array($this, 'onError'));
		try {
			$result = call_user_func_array($this->callback, func_get_args());
		} catch (Exception $ex) {
			restore_error_handler();        
			throw $ex;
		}
		restore_error_handler();
		return $result;
	}

	function onError($errno, $errstr, $errfile, $errline) {
		$this->errors[] = array($errno, $errstr, $errfile, $errline);
	}

	function ok() {
		return count($this->errors) == 0;
	}

	function errors() {
		return $this->errors;
	}

	function throwErrors() {
		if (count($this->errors) > 0) {
			$msg = "";
			foreach ($this->errors as $err) {
				$msg .= ", " . $err[0] . ": " . $err[1];
			}
			throw new Exception("Errors found calling " . $this->callback[1] . $msg);
		}
	}
}
?>