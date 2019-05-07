<?php
$namespaceDefinition
use Aplia\Migration\ContentMigration;
use Aplia\Content\ContentType;

/**
 * Customized version of the phinx migration template, for eZ content migrations
 * using aplia content tools. See https://bitbucket.org/aplia/library-content-tools
 * for more details and documentation.
 *
 * For an existing content class, use `php bin/dump_contentclass <class> --php-style=migrate`
 * to dump the class in migration format, and remove unneeded fields.
 */
class $className extends ContentMigration
{
    public function changeContent()
    {
        // $my_class = new ContentType('my_class');

        if ($this->isMigratingUp()) { // Do migration

            // $my_class
            //     ->addAttribute('ezboolean', array(
            //         'identifier' => 'my_attribute',
            //         'name' => 'My attribute',
            //     ))
            //     ->update();

            // $this->output->writeln(
            //     "Added my_attribute ezboolean attribute content-class <options=bold>my_class</>"
            // );

        } else { // Reverse migration

            // $my_class
            //     ->removeAttribute('my_attribute')
            //     ->update();

            // $this->output->writeln(
            //     "Removed my_attribute ezboolean attribute from content-class <options=bold>my_class</>"
            // );

        }
    }
}
