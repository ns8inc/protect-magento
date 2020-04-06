<?php
namespace NS8\Protect\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Registry;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\UrlInterface;
use Magento\Integration\Model\ConfigBasedIntegrationManager;
use Magento\Store\Model\StoreManagerInterface;
use NS8\Protect\Helper\Config;
use NS8\Protect\Helper\CustomStatus;
use NS8\Protect\Helper\Order;
use NS8\ProtectSDK\Installer\Client as InstallerClient;
use NS8\ProtectSDK\Logging\Client as LoggingClient;

/**
 * Execute the install/upgrade logic for the Protect extension
 */
class Setup extends AbstractHelper
{
    /**
     * Registry key used to determine fetching the access token between setup and update
     */
    const ACCESS_TOKEN_SET_KEY = 'ns8_access_token_set';

    /**
     * The custom status helper.
     *
     * @var CustomStatus
     */
    protected $customStatus;

    /**
     * The config-based integration manager.
     *
     * @var ConfigBasedIntegrationManager
     */
    protected $integrationManager;

    /**
     * The logging client.
     *
     * @var LoggingClient
     */
    protected $loggingClient;

    /**
     * Config helper for accessing config data
     *
     * @var Config
     */
    protected $config;

    /**
     * @var Registry
     */

    protected $registry;

    /**
     * Store manager attribute to fetch store data
     *
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * Scope config manager to get config data
     *
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @param Config $config
     * @param ConfigBasedIntegrationManager $integrationManager
     * @param CustomStatus $customStatus
     * @param Registry $registry
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Config $config,
        ConfigBasedIntegrationManager $integrationManager,
        CustomStatus $customStatus,
        Registry $registry,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager
    ) {
        $this->config = $config;
        $this->customStatus = $customStatus;
        $this->integrationManager = $integrationManager;
        $this->scopeConfig = $scopeConfig;
        $this->registry = $registry;
        $this->storeManager = $storeManager;
        $this->loggingClient = new LoggingClient();
    }

    /**
     * Runs the install/upgrade logic for data (configuration, integration, etc)
     *
     * @param string $mode Should be "install" or "upgrade"
     * @param ModuleDataSetupInterface $setup
     * @param ModuleContextInterface $context
     * @return void
     */
    public function upgradeData(string $mode, ModuleDataSetupInterface $setup, ModuleContextInterface $context) : void
    {
        try {
            //Essential step.
            $setup->startSetup();

            // Create or update our custom statuses using the current mode
            $this->customStatus->setCustomStatuses('Running Data '.$mode);
            // Run the base integration config method. This does not trigger activation.
            $this->integrationManager->processIntegrationConfig([Config::NS8_INTEGRATION_NAME]);

            // Dispatch event to NS8 Protect that module has been installed/upgraded
            if (!$this->scopeConfig->getValue('ns8/protect/token')
                && !$this->registry->registry(self::ACCESS_TOKEN_SET_KEY)
            ) {
                $storeEmail = $this->scopeConfig->getValue('trans_email/ident_sales/email') ?? '';
                $storeUrl = rtrim($this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB), '/');
                $installRequestData = [
                    'storeUrl' => $storeUrl,
                    'email' => $storeEmail
                ];

                $installResult = InstallerClient::install('magento', $installRequestData);
                $this->config->setEncryptedConfigValue('ns8/protect/token', $installResult['accessToken']);
                // Set a registry value so we do not attempt to fetch the token a second time
                // if config value has not been saved yet
                $this->registry->register(self::ACCESS_TOKEN_SET_KEY, true);
            }

            // Update current eq8_score with value from v1 if it exists AND if the current score is null
            $connection = $setup->getConnection();
            $currentEq8Col = Order::EQ8_SCORE_COL;
            $tablesWithEq8Cols = ['sales_order', 'sales_order_grid'];

            foreach ($tablesWithEq8Cols as $tableName) {
                if ($connection->tableColumnExists($tableName, 'eq8_score')) {
                    $connection->update(
                        $tableName,
                        [$currentEq8Col => new \Zend_Db_Expr(sprintf('%s.%s', $tableName, 'eq8_score'))],
                        ['? IS NULL' => new \Zend_Db_Expr(sprintf('%s.%s', $tableName, $currentEq8Col))],
                    );
                }
            }
        } catch (Throwable $e) {
            $this->loggingClient->error("Protect $mode failed", $e);
        } finally {
            //Essential step.
            $setup->endSetup();
        }
    }

    /**
     * Runs the install/upgrade logic for the schema (DDL/DML scripts)
     *
     * @param string $mode Should be "install" or "upgrade"
     * @param SchemaSetupInterface $setup
     * @param ModuleContextInterface $context
     * @return void
     */
    public function upgradeSchema(string $mode, SchemaSetupInterface $setup, ModuleContextInterface $context) : void
    {
        try {
            //Essential step.
            $setup->startSetup();

            // Create or update our custom statuses using the current mode
            $this->customStatus->setCustomStatuses('Running Schema '.$mode);

            $connection = $setup->getConnection();
            $connection->addColumn(
                $setup->getTable('sales_order'),
                Order::EQ8_SCORE_COL,
                [
                    'type' => Table::TYPE_SMALLINT,
                    'nullable' => true,
                    'comment' => 'EQ8 Score'
                ]
            );
            $connection->addColumn(
                $setup->getTable('sales_order_grid'),
                Order::EQ8_SCORE_COL,
                [
                    'type' => Table::TYPE_SMALLINT,
                    'nullable' => true,
                    'comment' => 'EQ8 Score'
                ]
            );
            $connection->addIndex(
                $setup->getTable('sales_order'),
                $setup->getIdxName('sales_order', [Order::EQ8_SCORE_COL]),
                [Order::EQ8_SCORE_COL]
            );
            $connection->addIndex(
                $setup->getTable('sales_order_grid'),
                $setup->getIdxName('sales_order_grid', [Order::EQ8_SCORE_COL]),
                [Order::EQ8_SCORE_COL]
            );
        } catch (Throwable $e) {
            $this->loggingClient->error("Protect $mode failed", $e);
        } finally {
            //Essential step.
            $setup->endSetup();
        }
    }
}
