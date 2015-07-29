<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Modules;

use Spiral\Components\DBAL\DatabaseManager;
use Spiral\Components\Files\FileManager;
use Spiral\Components\Modules\InstallerInterface;
use Spiral\Core\Component;
use Spiral\Support\Generators\Config\ConfigWriter;

class Installer extends Component implements InstallerInterface
{
    /**
     * Logging!
     */
    use Component\LoggerTrait;

    /**
     * FileManager component.
     *
     * @var FileManager
     */
    protected $file = null;

    /**
     * ModuleManager component.
     *
     * @var ModuleManager
     */
    protected $modules = null;

    /**
     * DatabaseManager component.
     *
     * @var DatabaseManager
     */
    protected $dbal = null;

    /**
     * Directory where module located in, all public files, configs and views should be defined
     * relative to this directory.
     *
     * @var string
     */
    protected $moduleDirectory = '';

    /**
     * Directory where module configuration files located, configurations will be merged with already
     * existed files by one selected merge methods.
     *
     * @var string
     */
    protected $configDirectory = '';

    /**
     * Flag to indicate that following module requires bootstrap() method call on application
     * initialization.
     *
     * @var bool
     */
    protected $boostrappable = false;

    /**
     * Files registered to be moved to public application directory ("root" directory alias).
     *
     * @var array
     */
    protected $files = [];

    /**
     * Bindings defined by module and should be mounted during application initialization. This is
     * alternative and lighter way to extend core by module without actually loading module class.
     *
     * @var array
     */
    protected $bindings = [];

    /**
     * Configs to be moved to application or merged with existed config.
     *
     * @var ConfigWriter[]
     */
    protected $configs = [];

    /**
     * Migration classes to be registered in DBAL component.
     *
     * @var array
     */
    protected $migrations = [];

    /**
     * Module installer responsible for operations like copying resources, registering configs, view
     * namespaces and declaring that bootstrap() call is required. Installer declaration should be
     * located in Module::getInstaller() method.
     *
     * Example:
     * $installer = Installer::make(array(
     *      'moduleDirectory' => __DIR__
     * ));
     *
     * Due organization of constructor parameters it's recommended to create Installer using make()
     * method.
     *
     * @param FileManager     $file            FileManager component.
     * @param ModuleManager   $modules         ModuleManager component.
     * @param DatabaseManager $dbal            DatabaseManager component.
     * @param string          $moduleDirectory Primary module directory.
     * @param string          $configDirectory Module config directory, by default moduleDirectory/config.
     */
    public function __construct(
        FileManager $file,
        ModuleManager $modules,
        DatabaseManager $dbal,
        $moduleDirectory,
        $configDirectory = ''
    )
    {
        $this->file = $file;
        $this->modules = $modules;
        $this->dbal = $dbal;

        $this->moduleDirectory = $this->file->normalizePath($moduleDirectory, true);

        if (!empty($configDirectory))
        {
            $this->configDirectory = $this->file->normalizePath($configDirectory, true);
        }
        else
        {
            //Default config directory
            $this->configDirectory = $this->file->normalizePath($moduleDirectory . '/config', true);
        }
    }

    /**
     * Module root directory.
     *
     * @return string
     */
    public function moduleDirectory()
    {
        return $this->moduleDirectory;
    }

    /**
     * Check if modules requires bootstrapping.
     *
     * @return bool
     */
    public function isBootstrappable()
    {
        return $this->boostrappable;
    }

    /**
     * Set flag to let modules component known that following module requires bootstrap() method call
     * on application initialization.
     *
     * @param bool $required
     * @return $this
     */
    public function setBootstrappable($required = null)
    {
        $this->boostrappable = $required;

        return $this;
    }

    /**
     * Register new file to be moved to public application directory ("root" directory alias).
     *
     * File should be located in module directory and defined by relative name. Destination location
     * can be different that original filename or have specified file permissions. All missing
     * directories will be created automatically with same file permissions.
     *
     * Examples:
     * $installer->registerFile(
     *      '/resources/scripts/plugin/script.js',
     *      'resources/script.js',
     *      File::RUNTIME
     * );
     *
     * @param string $destination Destination filename relative to "root" directory.
     * @param string $filename    Source filename relative to modules directory.
     * @param int    $mode        File mode, use File::RUNTIME for publicly accessible files.
     * @return $this
     * @throws ModuleException
     */
    public function registerFile($destination = null, $filename, $mode = FileManager::READONLY)
    {
        $filename = $this->file->normalizePath($filename);
        if (!$this->file->exists($this->moduleDirectory . $filename))
        {
            throw new ModuleException(
                "Unable to register file '{$filename}'', file not found in module directory."
            );
        }

        $this->files[$this->file->normalizePath($destination)] = [
            'source'  => $filename,
            'md5Hash' => $this->file->md5($this->moduleDirectory . $filename),
            'size'    => $this->file->size($this->moduleDirectory . $filename),
            'mode'    => $mode
        ];

        return $this;
    }

    /**
     * Register directory (destination parameter) name to be created in public application directory
     * ("root" directory). If "directory" parameter specified, all files located in that folder will
     * be additionally moved to destination directory with specified file permissions.
     *
     * Examples:
     * $installer->registerDirectory("/tempFiles/", null, File::RUNTIME);
     * $installer->registerDirectory("/resources/scripts/plugin/", "resources/", File::RUNTIME);
     *
     * @param string      $destination Destination directory name relative to root directory.
     * @param string|null $directory   Source directory name relative to modules directory.
     * @param int         $mode        File mode, use File::RUNTIME for publicly accessible files.
     * @return $this
     */
    public function registerDirectory(
        $destination = null,
        $directory = null,
        $mode = FileManager::READONLY
    )
    {
        if (!empty($directory))
        {
            $directory = $this->file->normalizePath($directory, true);
        }

        if ($destination != '' && $destination != '/')
        {
            $this->files[$destination] = [
                'source' => null,
                'md5'    => null,
                'size'   => null,
                'mode'   => $mode
            ];
        }

        if (!empty($directory))
        {
            if ($this->file->exists($this->moduleDirectory . $directory))
            {
                $directory = $this->file->normalizePath($this->moduleDirectory . $directory, true);
                $innerDirectory = substr($directory, strlen($this->moduleDirectory));

                foreach ($this->file->getFiles($directory) as $filename)
                {
                    $filename = $this->file->normalizePath($filename);

                    //Relative name
                    $filename = substr($filename, strlen($directory));
                    $this->registerFile(
                        $destination . '/' . $filename,
                        $this->file->normalizePath($innerDirectory . '/' . $filename),
                        $mode
                    );
                }
            }
        }

        return $this;
    }

    /**
     * Bind alias resolver, this method can be used to extend core files by module classes, method
     * is identical to Core::bind() however no closures supported (you still can use callbacks).
     *
     * Bindings will be mounted during initiating modules component.
     *
     * @param string                 $alias  Alias where singleton will be attached to.
     * @param string|object|callable Closure to resolve class instance, class instance or class name.
     * @return $this
     */
    public function addBinding($alias, $resolver)
    {
        $this->bindings[$alias] = $resolver;

        return $this;
    }

    /**
     * Register new module config, config will be merged with already existed file by one of selected
     * merge methods or using custom function.
     *
     * @param ConfigWriter $config
     * @param bool         $readConfig Automatically read config data from modules config directory.
     * @return $this
     * @throws ModuleException
     */
    public function registerConfig(ConfigWriter $config, $readConfig = true)
    {
        $readConfig && $config->readConfig($this->configDirectory);
        $this->configs[] = $config;

        return $this;
    }

    /**
     * Register new module migration, migration will be automatically copied to migrations directly.
     * Make sure class is reachable. In some cases better skip this function and declare special
     * command to register config.
     *
     * Example:
     * $installer->registerMigration('blog_posts', 'Vendor\Blog\Migrations\BlogPostsMigration');
     *
     * @param string $name      Migration name.
     * @param string $migration Migration class name (should be reachable by framework).
     * @return $this
     */
    public function registerMigration($name, $migration)
    {
        $this->migrations[$name] = $migration;

        return $this;
    }

    /**
     * Files registered to be moved to public application directory ("root" directory alias).
     *
     * @return array
     */
    public function getFiles()
    {
        return $this->files;
    }

    /**
     * Get all registered module bindings.
     *
     * @return array
     */
    public function getBindings()
    {
        return $this->bindings;
    }

    /**
     * All registered modules config writers.
     *
     * @return ConfigWriter[]
     */
    public function getConfigs()
    {
        return $this->configs;
    }

    /**
     * List of files which already exists in application public directory and conflicted with modules
     * files by size or content, this method should be called before module installation to make sure
     * that no user files will be removed or overwritten without notification. File conflicts can be
     * resolved by picking one of resolution methods.
     *
     * @return array
     */
    public function getConflicts()
    {
        $conflicts = [];
        foreach ($this->files as $filename => $definition)
        {
            if (empty($definition['source']))
            {
                //Directory
                continue;
            }

            $filename = $this->file->normalizePath(directory('root') . '/' . $filename);

            if (!$this->file->exists($filename) || $this->file->md5($filename) == $definition['md5Hash'])
            {
                //File not exists or identical
                continue;
            }

            $conflicts[$filename] = [
                'required'  => [
                    'md5Hash' => $definition['md5Hash'],
                    'size'    => $definition['size']
                ],
                'retrieved' => [
                    'md5Hash' => $this->file->md5($filename),
                    'size'    => $this->file->size($filename)
                ],
                'source'    => $definition['source']
            ];
        }

        return $conflicts;
    }

    /**
     * Copy all registered files to their public location, create directories and set-up permissions.
     *
     * @param int $conflicts Default tactic to resolve file conflicts, for right now spiral will
     *                       assume that we can simple overwrite all conflicted files.
     * @throws ModuleException
     */
    protected function mountFiles($conflicts = self::CONFLICTS_OVERWRITE)
    {
        if ($this->getConflicts() && !$conflicts)
        {
            throw new ModuleException(
                "Unable to process registered files, unresolved conflicts presented (no conflict tactic)."
            );
        }

        foreach ($this->files as $filename => $definition)
        {
            $filename = $this->file->normalizePath(directory('root') . '/' . $filename);

            if (!$definition['source'])
            {
                self::logger()->debug("Ensuring directory '{directory}' with mode '{mode}'.", [
                    'directory' => substr($this->file->relativePath($filename), 2),
                    'mode'      => decoct($definition['mode'])
                ]);

                //Directory
                $this->file->ensureDirectory($filename, $definition['mode']);
                continue;
            }

            if ($this->file->exists($filename))
            {
                if ($this->file->md5($filename) == $definition['md5Hash'])
                {
                    self::logger()->debug("Module file '[module]/{source}' already mounted.", [
                        'source'      => $definition['source'],
                        'destination' => substr($this->file->relativePath($filename), 2)
                    ]);

                    continue;
                }

                if ($conflicts == self::CONFLICTS_IGNORE)
                {
                    self::logger()->warning(
                        "Module file '[module]/{source}' already mounted and different version, ignoring.",
                        [
                            'source'      => $definition['source'],
                            'destination' => substr($this->file->relativePath($filename), 2)
                        ]
                    );
                    continue;
                }
                else
                {
                    self::logger()->warning(
                        "Module file '[module]/{source}' already mounted and different version, replacing.",
                        [
                            'source'      => $definition['source'],
                            'destination' => substr($this->file->relativePath($filename), 2)
                        ]
                    );
                }
            }
            else
            {
                self::logger()->debug(
                    "Mounting module file '[module]/{source}' into '{destination}'.",
                    [
                        'source'      => $definition['source'],
                        'destination' => substr($this->file->relativePath($filename), 2)
                    ]
                );
            }

            $source = $this->file->normalizePath($this->moduleDirectory . $definition['source']);

            //Copying with write() method to ensure directories and permissions, slower by easier
            $this->file->write($filename, $this->file->read($source), $definition['mode'], true);
        }
    }

    /**
     * Mounting configs (including merge operations).
     *
     * @param int $mode File mode, use File::RUNTIME for publicly accessible files.
     */
    protected function mountConfigs($mode = FileManager::READONLY)
    {
        foreach ($this->configs as $config)
        {
            $config->writeConfig(directory('config'), $mode, $this);

            self::logger()->debug("Updating configuration file '{config}'.", [
                'config' => $config->getName()
            ]);
        }
    }

    /**
     * Register all added migrations in DatabaseManager component.
     */
    protected function mountMigrations()
    {
        $repository = $this->dbal->migrationRepository();

        foreach ($this->migrations as $name => $migration)
        {
            $repository->registerMigration($name, $migration);

            self::logger()->debug("Mounting migration '{$name}'.", compact('name', 'migration'));
        }
    }

    /**
     * Perform module installation. This method will mount all files, configs and etc.
     *
     * @param int $conflicts Method to resolve file conflicts.
     * @throws ModuleException
     */
    public function install($conflicts = self::CONFLICTS_OVERWRITE)
    {
        self::logger()->info("Mounting configurations.");
        $this->mountConfigs();

        self::logger()->info("Mounting migrations.");
        $this->mountMigrations();

        $this->update();
    }

    /**
     * Perform module update, method will move all module files, no configs or migrations will be
     * created/altered.
     *
     * @param int $conflicts
     */
    public function update($conflicts = self::CONFLICTS_OVERWRITE)
    {
        self::logger()->info("Mounting files.");
        $this->mountFiles($conflicts);
    }
}