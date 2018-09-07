<?php
namespace Aplia\Content;

interface ContentObjectTransformation
{
    /**
     * Transform object data including attributes and locations.
     * Must return transformed data.
     */
    public function transformContentObject($data);
}
