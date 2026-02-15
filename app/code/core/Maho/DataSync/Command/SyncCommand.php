<?php

/**
 * Maho
 *
 * @package    Maho_DataSync
 * @copyright  Copyright (c) 2025-2026 Maho (https://mahocommerce.com)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;

#[AsCommand(
    name: 'datasync:sync',
    description: 'Sync data from external sources (CSV, OpenMage, WooCommerce, Shopify, Magento 2)',
)]
class Maho_DataSync_Command_SyncCommand extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument(
                'entity',
                InputArgument::REQUIRED,
                'Entity type to sync (product, customer, category, order, invoice, shipment, creditmemo, productattribute)',
            )
            ->addArgument(
                'source',
                InputArgument::REQUIRED,
                'Source identifier (source_system name, e.g., "legacy", "woocommerce", "csv")',
            )
            ->addOption(
                'adapter',
                'a',
                InputOption::VALUE_REQUIRED,
                'Source adapter type (csv, openmage, woocommerce, shopify, magento2)',
                'csv',
            )
            ->addOption(
                'file',
                'f',
                InputOption::VALUE_REQUIRED,
                'CSV file path (for csv adapter)',
            )
            ->addOption(
                'url',
                'u',
                InputOption::VALUE_REQUIRED,
                'Remote API URL (for API-based adapters)',
            )
            ->addOption(
                'api-key',
                'k',
                InputOption::VALUE_REQUIRED,
                'API key for authentication',
            )
            ->addOption(
                'api-secret',
                null,
                InputOption::VALUE_REQUIRED,
                'API secret for authentication',
            )
            ->addOption(
                'on-duplicate',
                'd',
                InputOption::VALUE_REQUIRED,
                'How to handle duplicates: skip, update, merge, error',
                'error',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Validate without actually importing',
            )
            ->addOption(
                'skip-invalid',
                null,
                InputOption::VALUE_NONE,
                'Skip invalid records instead of failing',
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Limit number of records to import',
            )
            ->addOption(
                'date-from',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter: import only records modified after this date',
            )
            ->addOption(
                'date-to',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter: import only records modified before this date',
            )
            // Product-specific options
            ->addOption(
                'auto-link-configurables',
                null,
                InputOption::VALUE_NONE,
                '[Product] Auto-link simple products to configurables based on position in CSV',
            )
            ->addOption(
                'options-mode',
                null,
                InputOption::VALUE_REQUIRED,
                '[Product] Custom options handling: replace, merge, append',
                'replace',
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entityType = $input->getArgument('entity');
        $sourceSystem = $input->getArgument('source');
        $adapterType = $input->getOption('adapter');

        // Bootstrap Mage
        Mage::app('admin');

        $output->writeln("<info>DataSync: {$entityType} from {$sourceSystem}</info>");

        try {
            // Create adapter based on type
            $adapter = $this->createAdapter($input, $output, $adapterType);
            if (!$adapter) {
                return Command::FAILURE;
            }

            // Build filters
            $filters = [];
            if ($input->getOption('limit')) {
                $filters['limit'] = (int) $input->getOption('limit');
            }
            if ($input->getOption('date-from')) {
                $filters['date_from'] = $input->getOption('date-from');
            }
            if ($input->getOption('date-to')) {
                $filters['date_to'] = $input->getOption('date-to');
            }

            // Build entity options
            $entityOptions = [];
            if ($input->getOption('auto-link-configurables')) {
                $entityOptions['auto_link_configurables'] = true;
            }
            if ($input->getOption('options-mode')) {
                $entityOptions['options_mode'] = $input->getOption('options-mode');
            }

            // Create and configure engine
            /** @var Maho_DataSync_Model_Engine $engine */
            $engine = Mage::getModel('datasync/engine');
            $engine->setSourceAdapter($adapter)
                ->setSourceSystem($sourceSystem)
                ->setFilters($filters)
                ->setDryRun($input->getOption('dry-run'))
                ->setOnDuplicate($input->getOption('on-duplicate'))
                ->setSkipInvalid($input->getOption('skip-invalid'))
                ->setEntityOptions($entityOptions);

            // Set progress callback
            $engine->setProgressCallback(function (string $message) use ($output) {
                if ($output->isVerbose()) {
                    $output->writeln("  {$message}");
                }
            });

            // Run sync
            $result = $engine->sync($entityType);

            // Display results
            $output->writeln('');
            $output->writeln($result->getSummary());

            if ($result->hasErrors()) {
                $output->writeln('');
                $output->writeln('<comment>Errors:</comment>');
                foreach ($result->getErrors() as $error) {
                    $output->writeln("  <error>{$error['message']}</error>");
                }
            }

            return $result->hasErrors() ? Command::FAILURE : Command::SUCCESS;

        } catch (Maho_DataSync_Exception $e) {
            $output->writeln("<error>DataSync Error: {$e->getMessage()}</error>");
            return Command::FAILURE;
        } catch (Exception $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            if ($output->isVerbose()) {
                $output->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    /**
     * Create source adapter based on type
     */
    protected function createAdapter(
        InputInterface $input,
        OutputInterface $output,
        string $type,
    ): ?Maho_DataSync_Model_Adapter_Interface {
        switch ($type) {
            case 'csv':
                $file = $input->getOption('file');
                if (!$file) {
                    $output->writeln('<error>CSV adapter requires --file option</error>');
                    return null;
                }
                if (!file_exists($file)) {
                    $output->writeln("<error>CSV file not found: {$file}</error>");
                    return null;
                }
                /** @var Maho_DataSync_Model_Adapter_Csv $adapter */
                $adapter = Mage::getModel('datasync/adapter_csv');
                $adapter->setFilePath($file);
                return $adapter;

            case 'openmage':
                return $this->createApiAdapter('datasync/adapter_openmage', $input, $output);

            case 'woocommerce':
                return $this->createApiAdapter('datasync/adapter_woocommerce', $input, $output);

            case 'shopify':
                return $this->createApiAdapter('datasync/adapter_shopify', $input, $output);

            case 'magento2':
                return $this->createApiAdapter('datasync/adapter_magento2', $input, $output);

            default:
                $output->writeln("<error>Unknown adapter type: {$type}</error>");
                return null;
        }
    }

    /**
     * Create API-based adapter with credentials
     */
    protected function createApiAdapter(
        string $model,
        InputInterface $input,
        OutputInterface $output,
    ): ?Maho_DataSync_Model_Adapter_Interface {
        $url = $input->getOption('url');
        if (!$url) {
            $output->writeln('<error>API adapter requires --url option</error>');
            return null;
        }

        $apiKey = $input->getOption('api-key');
        $apiSecret = $input->getOption('api-secret');

        $adapter = Mage::getModel($model);
        if (!$adapter instanceof Maho_DataSync_Model_Adapter_Abstract) {
            $output->writeln("<error>Invalid adapter model: {$model}</error>");
            return null;
        }
        $adapter->setBaseUrl($url);

        if ($apiKey) {
            $adapter->setApiKey($apiKey);
        }
        if ($apiSecret) {
            $adapter->setApiSecret($apiSecret);
        }

        return $adapter;
    }
}
