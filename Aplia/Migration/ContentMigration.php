<?php
namespace Aplia\Migration;
use Phinx\Migration\AbstractMigration;
use Exception;
use eZDB;

/**
 * Defines a migration step which takes care of setting up
 * eZ publish database transactions.
 * 
 * Note: This requires that Phinx is installed.
 */
abstract class ContentMigration extends AbstractMigration
{
    public function change()
    {
        $db = eZDB::instance();
        $db->begin();

        try {
            $this->changeContent();

        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        } catch (ErrorException $e) {
            $db->rollback();
            throw $e;
        }
        $db->commit();
    }

    /**
     * Changes eZ publish content, migration class must implement this.
     */
    public abstract function changeContent();
}
