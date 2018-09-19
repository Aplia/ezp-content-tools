<?php
namespace Aplia\Content;
use Aplia\Support\Arr;
use Aplia\Content\Query\QuerySet;
use Aplia\Content\ContentObject;
use eZContentObjectTreeNode;

class BatchProcessor
{
    public $globalLimits = false;
    public $objectMode = true;
    public $readOnly = false;
    public $visitedCount = 0;
    public $modifiedCount = 0;
    // Callbacks
    public $matchCallback;
    public $processCallback;
    public $visitCallback;
    public $completedCallback;
    public $skippedCallback;
    public $visitedCallback;

    public function __construct($params=null)
    {
        $queryParams = null;
        if (is_array($params)) {
            $this->globalLimits = Arr::get($params, 'globalLimits', false);
            $query = Arr::get($params, 'query');
            if (is_array($query)) {
                $queryParams = $query;
                $query = null;
            }
            $this->matchCallback = Arr::get($params, 'match');
            $this->processCallback = Arr::get($params, 'process');
            $this->visitCallback = Arr::get($params, 'visit');
            $this->completedCallback = Arr::get($params, 'completed');
            $this->skippedCallback = Arr::get($params, 'skipped');
            $this->visitedCallback = Arr::get($params, 'visited');
            $this->objectMode = Arr::get($params, 'objectMode', true);
            $this->readOnly = Arr::get($params, 'readOnly', false);
        } else {
            $query = $params;
        }
        if (!$this->globalLimits) {
            $defaultParams = array(
                'useVisibility' => false,
                'useRoles' => false,
            );
            // When using object mode the default should be to only visit nodes once
            if ($this->objectMode) {
                $defaultParams['mainNodeOnly'] = true;
            }
            if ($queryParams === null) {
                $queryParams = $defaultParams;
            } else {
                $queryParams = array_merge(
                    $defaultParams, $queryParams
                );
            }
        }
        if (!$query) {
            $query = new QuerySet($queryParams);
        } else if (!($query instanceof QuerySet)) {
            throw new TypeError("Parameter to " . get_class($this) . " must either be an array or a Aplia\Content\Query\QuerySet instance");
        }
        $this->query = $query;
    }

    public function process()
    {
        if ($this->objectMode) {
            foreach ($this->query as $node) {
                if (!$this->isMatch($node)) {
                    continue;
                }
                $this->onVisit($node);
                if (!$this->readOnly) {
                    if ($this->processObject($node)) {
                        $this->modifiedCount += 1;
                        $this->onCompleted($node);
                    } else {
                        $this->onSkipped($node);
                    }
                }
                $this->visitedCount += 1;
                $this->onVisited($node);
            }
        } else {
            foreach ($this->query as $node) {
                if (!$this->isMatch($node)) {
                    continue;
                }
                $this->onVisit($node);
                if (!$this->readOnly) {
                    if ($this->processNode($node)) {
                        $this->modifiedCount += 1;
                        $this->onCompleted($node);
                    } else {
                        $this->onSkipped($node);
                    }
                }
                $this->visitedCount += 1;
                $this->onVisited($node);
            }
        }
    }

    public function processNode(eZContentObjectTreeNode $node)
    {
        if ($this->processCallback) {
            return call_user_func($this->processCallback, $node);
        }
        return false;
    }

    public function processObject(eZContentObjectTreeNode $node)
    {
        $contentObject = $node->object();
        $object = new ContentObject(array(
            'contentObject' => $contentObject,
        ));
        if ($this->processCallback) {
            return call_user_func($this->processCallback, $object);
        }
        return false;
    }

    public function isMatch(eZContentObjectTreeNode $node)
    {
        if ($this->matchCallback) {
            return call_user_func($this->matchCallback, $node);
        }
        return true;
    }

    public function onVisit(eZContentObjectTreeNode $node)
    {
        if ($this->visitCallback) {
            return call_user_func($this->visitCallback, $node);
        }
    }

    public function onCompleted(eZContentObjectTreeNode $node)
    {
        if ($this->completedCallback) {
            return call_user_func($this->completedCallback, $node);
        }
    }

    public function onSkipped(eZContentObjectTreeNode $node)
    {
        if ($this->skippedCallback) {
            return call_user_func($this->skippedCallback, $node);
        }
    }

    public function onVisited(eZContentObjectTreeNode $node)
    {
        if ($this->visitedCallback) {
            return call_user_func($this->visitedCallback, $node);
        }
    }
}
