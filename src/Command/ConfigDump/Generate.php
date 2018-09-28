<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\MagentoCloud\Command\ConfigDump;

use Magento\MagentoCloud\DB\ConnectionInterface;
use Magento\MagentoCloud\Filesystem\Driver\File;
use Magento\MagentoCloud\Filesystem\FileSystemException;
use Magento\MagentoCloud\Filesystem\Resolver\SharedConfig;
use Magento\MagentoCloud\Package\MagentoVersion;
use Magento\MagentoCloud\Package\UndefinedPackageException;
use Magento\MagentoCloud\Util\ArrayManager;
use Magento\MagentoCloud\Util\PhpFormatter;

/**
 * @inheritdoc
 */
class Generate
{
    /**
     * @var array
     */
    private $configKeys = [
        'scopes',
        'system/default/general/locale/code',
        'system/default/dev/static/sign',
        'system/default/dev/front_end_development_workflow',
        'system/default/dev/template',
        'system/default/dev/js',
        'system/default/dev/css',
        'system/default/advanced/modules_disable_output',
        'system/stores',
    ];

    /**
     * @var ConnectionInterface
     */
    private $connection;

    /**
     * @var File
     */
    private $file;

    /**
     * @var ArrayManager
     */
    private $arrayManager;

    /**
     * @var SharedConfig
     */
    private $sharedConfig;

    /**
     * @var MagentoVersion
     */
    private $magentoVersion;

    /**
     * @var PhpFormatter
     */
    private $phpFormatter;

    /**
     * @param ConnectionInterface $connection
     * @param File $file
     * @param ArrayManager $arrayManager
     * @param MagentoVersion $magentoVersion
     * @param SharedConfig $sharedConfig
     * @param PhpFormatter $phpFormatter
     */
    public function __construct(
        ConnectionInterface $connection,
        File $file,
        ArrayManager $arrayManager,
        MagentoVersion $magentoVersion,
        SharedConfig $sharedConfig,
        PhpFormatter $phpFormatter
    ) {
        $this->connection = $connection;
        $this->file = $file;
        $this->arrayManager = $arrayManager;
        $this->sharedConfig = $sharedConfig;
        $this->magentoVersion = $magentoVersion;
        $this->phpFormatter = $phpFormatter;
    }

    /**
     * {@inheritdoc}
     *
     * @throws UndefinedPackageException
     * @throws FileSystemException
     */
    public function execute()
    {
        if ($this->magentoVersion->isGreaterOrEqual('2.2')) {
            $this->configKeys[] = 'modules';
            $this->configKeys[] = 'system/websites';
        }

        $configFile = $this->sharedConfig->resolve();
        $oldConfig = require $configFile;
        $newConfig = [];

        foreach ($this->configKeys as $requiredConfigKey) {
            $oldConfigCopy = $oldConfig;
            $configKeys = explode('/', $requiredConfigKey);

            /**
             * Get value of the config recursively.
             */
            foreach ($configKeys as $configKey) {
                $oldConfigCopy = $oldConfigCopy[$configKey] ?? null;
            }

            /**
             * Setting value in new array.
             */
            if ($oldConfigCopy) {
                $newConfig = $this->arrayManager->nest($newConfig, $configKeys, $oldConfigCopy);
            }
        }

        /**
         * Only saving general/locale/code.
         */
        $storeCodes = isset($newConfig['system']['stores'])
            ? array_keys($newConfig['system']['stores'])
            : [];
        foreach ($storeCodes as $storeCode) {
            if (isset($newConfig['system']['stores'][$storeCode]['general']['locale']['code'])) {
                $temp = $newConfig['system']['stores'][$storeCode]['general']['locale']['code'];
                unset($newConfig['system']['stores'][$storeCode]);
                $newConfig['system']['stores'][$storeCode]['general']['locale']['code'] = $temp;
            } else {
                unset($newConfig['system']['stores'][$storeCode]);
            }
        }

        /**
         * Un-setting base_url.
         */
        unset(
            $newConfig['system']['stores']['admin']['web']['secure']['base_url'],
            $newConfig['system']['stores']['admin']['web']['unsecure']['base_url']
        );

        /**
         * Adding locales for admin user.
         */
        $newConfig['admin_user']['locale']['code'] = array_column(
            $this->connection->select('SELECT DISTINCT `interface_locale` FROM `admin_user`'),
            'interface_locale'
        );

        $this->file->filePutContents(
            $configFile,
            $this->phpFormatter->format($newConfig)
        );
    }
}