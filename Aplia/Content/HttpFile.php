<?php
namespace Aplia\Content;

class HttpFile
{
    public $name;
    public $maxSize = false;
    public $isRequired = false;
    public $errors = array();

    public $_isValid;
    public $_hasFile = false;

    public function __construct($name, $isRequired = false)
    {
        $this->name = $name;
        $this->isRequired = $isRequired;
    }

    public function __get($name)
    {
        if ($name == 'errorText') {
            return $this->getErrorText();
        } else if ($name == 'hasFile') {
            return $this->getHasFile();
        } else if ($name == 'isValid') {
            return $this->getIsValid();
        } else {
            throw new \Exception("No such property: $name");
        }
    }

    public function getErrorText()
    {
        return implode('\n', $this->errors);
    }

    public function getIsValid()
    {
        if ($this->_isValid === null) {
            $this->validate();
        }
        return $this->_isValid;
    }

    public function getHasFile()
    {
        if ($this->_isValid === null) {
            $this->validate();
        }
        return $this->_hasFile;
    }

    protected function validate()
    {
    }
}
