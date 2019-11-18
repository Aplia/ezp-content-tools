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
        $canFetchFile = eZHTTPFile::canFetch($this->name, $this->maxSize);
        if (!$canFetchFile) {
            if (!$this->isRequired) {
                return;
            }
        } else {
            $this->hasFile = true;
        }
        if ($this->isRequired && $canFetchFile === eZHTTPFile::UPLOADEDFILE_DOES_NOT_EXIST) {
            $this->errors[] = ezpI18n::tr( 'kernel/classes/datatypes',
                'A valid file file is required.' );
        }
        if ($canFetchFile === eZHTTPFile::UPLOADEDFILE_EXCEEDS_PHP_LIMIT) {
            $this->errors[] = ezpI18n::tr( 'kernel/classes/datatypes',
                'The size of the uploaded file exceeds limit set by upload_max_filesize directive in php.ini. Please contact the site administrator.' );
        }
        if ($canFetchFile === eZHTTPFile::UPLOADEDFILE_EXCEEDS_MAX_SIZE) {
            $this->errors[] = ezpI18n::tr( 'kernel/classes/datatypes',
                'The size of the uploaded file exceeds the limit set for this site: %1 bytes.', null, array('%1' => $this->maxSize));
        }

        $this->isValid = count($this->errors) == 0 && (bool)$canFetchFile;
    }
}
