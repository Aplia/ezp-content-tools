<?php
$namespaceDefinition
use Aplia\Migration\ContentMigration;

/**
 * Customized version of the phinx migration template, for eZ content migrations
 * using aplia content tools. See https://bitbucket.org/aplia/library-content-tools
 * for more details and documentation.
 */
class $className extends ContentMigration
{
    public function changeContent()
    {
        if ($this->isMigratingUp()) { // Do migration

        } else { // Reverse migration

        }
    }
}
