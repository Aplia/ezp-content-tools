<?php
namespace Aplia\Migration;
use eZScript;
use eZDB;
use eZINI;
use eZContentLanguage;

/**
 * Defines the configuration for the migration system.
 */
class Configuration
{
    /**
     * Bootstraps the eZ publish system.
     */
    public static function bootstrap()
    {
        // Initialze eZScript to initialize eZ publish then shutdown
        $script = eZScript::instance(
            array(
                'use-session' => false,
                'use-modules' => true,
                'use-extensions' => true
            )
        );
        $script->startup();
        $script->initialize();
        $script->shutdown();

        // Login admin user to ensure all content operations have sufficient permissions
        $projectIni = eZINI::instance('project.ini');
        /** @var eZUser|null */
        $user = null;
        if ($projectIni->hasVariable('Migration', 'User')) {
            /** @var mixed */
            $migrationUser = $projectIni->variable('Migration', 'User');
            if (is_numeric($migrationUser)) {
                // Fetch by object ID
                $user = \eZUser::instance($migrationUser);
            } elseif (is_string($migrationUser) && $migrationUser) {
                // Fetch by login name
                /** @var eZUser */
                $user = \eZUser::fetchByName($migrationUser);
            }
        }
        if (!$user) {
            // No user set, use default admin user
            $user = \eZUser::instance(14);
        }
        $user->loginCurrent();

        // Make sure all translations are fetched
        eZContentLanguage::setPrioritizedLanguages(eZContentLanguage::fetchLocaleList());
    }

    /**
     * Bootstrap eZ publish and returns migration configuration for Phinx.
     */
    public function setupPhinx()
    {
        self::bootstrap();
        return $this->makePhinxConfiguration();
    }

    /**
     * Returns migration configuration for Phinx.
     * The configuration is automatically set to use the database for the
     * eZ publish site, and a path for migrations.
     */
    public function makePhinxConfiguration() {
        $db = eZDB::instance();
        $host = $db->Server;
        $port = $db->Port;
        $user = $db->User;
        $pass = $db->Password;
        $dbname = $db->DB;

        // New sites should have migrations in extension/site/migrations
        // but still sites to redefine it to a different path if it needs to
        $projectIni = eZINI::instance('project.ini');
        if ($projectIni->hasVariable('Migration', 'Path')) {
            $migrationsPath = $projectIni->variable('Migration', 'Path');
        } else if ($projectIni->hasVariable('Migration', 'NamespacedPaths')) {
            $migrationsPath = $projectIni->variable('Migration', 'NamespacedPaths');
        } else {
            $migrationsPath = "extension/site/migrations";
        }
        if ($projectIni->hasVariable('Migration', 'SeedsPath')) {
            $seedsPath = $projectIni->variable('Migration', 'SeedsPath');
        } else {
            $seedsPath = "extension/site/migration/seeds";
        }
    
        $charset = "utf8";
        // TODO: Detect when utf8mb4 is needed
        $config = [
            "paths" => [
                "migrations" => $migrationsPath,
                "seeds" => $seedsPath,
            ],
            "environments" => [
                "default_migration_table" => "phinxlog",
                // Most sites only have one database, both on prod site and locally
                // So define that as 'default' and make it the default.
                "default_database" => "default",
                "default" => [
                    "adapter" => "mysql",
                    "host" => $host,
                    "name" => $dbname,
                    "user" => $user,
                    "pass" => $pass,
                    "charset" => $charset,
                ],
            ],
        ];
        if ($port) {
            $config['environments']['default']['port'] = $port;
        }

        return $config;
    }
}
