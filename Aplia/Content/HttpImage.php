<?php
namespace Aplia\Content;

use eZHTTPFile;
use ezpI18n;

class HttpImage extends HttpFile
{
    protected function validate()
    {
        $canFetchImage = eZHTTPFile::canFetch($this->name, $this->maxSize);
        if (!$canFetchImage) {
            if (!$this->isRequired) {
                return;
            }
        } else {
            $this->hasFile = true;
        }
        if ($this->isRequired && $canFetchImage === eZHTTPFile::UPLOADEDFILE_DOES_NOT_EXIST) {
            $this->errors[] = ezpI18n::tr( 'kernel/classes/datatypes',
                'A valid image file is required.' );
        }
        if ($canFetchImage === eZHTTPFile::UPLOADEDFILE_EXCEEDS_PHP_LIMIT) {
            $this->errors[] = ezpI18n::tr( 'kernel/classes/datatypes',
                'The size of the uploaded image exceeds limit set by upload_max_filesize directive in php.ini. Please contact the site administrator.' );
        }
        if ($canFetchImage === eZHTTPFile::UPLOADEDFILE_EXCEEDS_MAX_SIZE) {
            $this->errors[] = ezpI18n::tr( 'kernel/classes/datatypes',
                'The size of the uploaded file exceeds the limit set for this site: %1 bytes.', null, array('%1' => $this->maxSize));
        }

        $this->isValid = count($this->errors) == 0 && (bool)$canFetchImage;
    }
}
