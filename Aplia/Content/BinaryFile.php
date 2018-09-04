<?php
namespace Aplia\Content;
use Aplia\Support\Arr;

class BinaryFile
{
    public $path;
    public $originalFilename;

    public function __construct($path, $originalFilename=null)
    {
        $argc = func_num_args();
        if ($argc === 1 && is_array($path)) {
            $originalFilename = Arr::get($path, 'original_filename');
            $path = Arr::get($path, 'path');
        }
        $this->path = $path;
        $this->originalFilename = $originalFilename;
    }
}
