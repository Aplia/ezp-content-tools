<?php
namespace Aplia\Content;
use Aplia\Support\Arr;

class ImageFile
{
    public $path;
    public $alternativeText;
    public $originalFilename;

    public function __construct($path, $alternativeText=null, $originalFilename=null)
    {
        $argc = func_num_args();
        if ($argc === 1 && is_array($path)) {
            $alternativeText = Arr::get($path, 'alternative_text');
            $originalFilename = Arr::get($path, 'original_filename');
            $path = Arr::get($path, 'path');
        }
        $this->path = $path;
        $this->alternativeText = $alternativeText;
        $this->originalFilename = $originalFilename;
    }
}
