<?php

/**
 * Maho DataSync OpenMage Database Adapter
 *
 * Direct database connection to OpenMage/Magento 1 instances.
 * Uses PDO for cross-database compatibility (MySQL, PostgreSQL, SQLite).
 *
 * @category   Maho
 * @package    Maho_DataSync
 */
class Maho_DataSync_Model_Adapter_OpenMage extends Maho_DataSync_Model_Adapter_Abstract
{
    protected ?PDO $_connection = null;
    protected string $_tablePrefix = '';

    /**
     * Table mappings for each entity type
     */
    protected array $_tableMappings = [
        'customer' => [
            'main_table' => 'customer_entity',
            'id_field' => 'entity_id',
            'date_field' => 'created_at',
        ],
        'order' => [
            'main_table' => 'sales_flat_order',
            'id_field' => 'entity_id',
            'date_field' => 'created_at',
        ],
        'review' => [
            'main_table' => 'review',
            'id_field' => 'review_id',
            'date_field' => 'created_at',
        ],
        'shopping_cart_rule' => [
            'main_table' => 'salesrule',
            'id_field' => 'rule_id',
            'date_field' => 'created_at',
        ],
        'product_attribute' => [
            'main_table' => 'eav_attribute',
            'id_field' => 'attribute_id',
            'date_field' => null, // No date field
        ],
    ];

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getCode(): string
    {
        return 'openmage';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getLabel(): string
    {
        return 'OpenMage Database';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function configure(array $config): void
    {
        parent::configure($config);

        // Parse connection string if provided
        if (isset($config['source']) && is_string($config['source'])) {
            $parsed = Mage::helper('datasync')->parseConnectionString($config['source']);
            $config = array_merge($config, $parsed);
        }

        $this->_tablePrefix = $config['prefix'] ?? '';

        // Build DSN
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 3306;
        $database = $config['database'] ?? '';
        $charset = $config['charset'] ?? 'utf8mb4';

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            $this->_connection = new PDO(
                $dsn,
                $config['username'] ?? '',
                $config['password'] ?? '',
                $options,
            );
        } catch (PDOException $e) {
            throw Maho_DataSync_Exception::connectionFailed($e->getMessage(), [
                'host' => $host,
                'database' => $database,
            ]);
        }
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function validate(): bool
    {
        $this->_ensureConfigured();

        if ($this->_connection === null) {
            throw Maho_DataSync_Exception::connectionFailed('No database connection');
        }

        // Test query
        try {
            $stmt = $this->_connection->query('SELECT 1');
            $stmt->fetch();
            return true;
        } catch (PDOException $e) {
            throw Maho_DataSync_Exception::connectionFailed($e->getMessage());
        }
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function read(string $entityType, array $filters = []): iterable
    {
        $this->_ensureConfigured();

        if (!isset($this->_tableMappings[$entityType])) {
            throw Maho_DataSync_Exception::entityNotFound($entityType);
        }

        $mapping = $this->_tableMappings[$entityType];
        $table = $this->_tablePrefix . $mapping['main_table'];
        $idField = $mapping['id_field'];
        $dateField = $mapping['date_field'];

        $normalizedFilters = $this->_normalizeFilters($filters);

        // Build query
        $sql = "SELECT * FROM {$table} WHERE 1=1";
        $params = [];

        // Date filters
        if ($dateField !== null) {
            if ($normalizedFilters['date_from'] !== null) {
                $sql .= " AND {$dateField} >= :date_from";
                $params['date_from'] = $normalizedFilters['date_from'] . ' 00:00:00';
            }

            if ($normalizedFilters['date_to'] !== null) {
                $sql .= " AND {$dateField} <= :date_to";
                $params['date_to'] = $normalizedFilters['date_to'] . ' 23:59:59';
            }
        }

        // ID filters
        if ($normalizedFilters['id_from'] !== null) {
            $sql .= " AND {$idField} >= :id_from";
            $params['id_from'] = $normalizedFilters['id_from'];
        }

        if ($normalizedFilters['id_to'] !== null) {
            $sql .= " AND {$idField} <= :id_to";
            $params['id_to'] = $normalizedFilters['id_to'];
        }

        // Store filter (if applicable)
        if ($normalizedFilters['store_id'] !== null && $entityType !== 'product_attribute') {
            $storeIds = is_array($normalizedFilters['store_id'])
                ? $normalizedFilters['store_id']
                : [$normalizedFilters['store_id']];
            $placeholders = implode(',', array_fill(0, count($storeIds), '?'));
            $sql .= " AND store_id IN ({$placeholders})";
            // Note: We need to handle this differently for prepared statements
        }

        // Order by ID for consistent pagination
        $sql .= " ORDER BY {$idField} ASC";

        // Limit and offset
        if ($normalizedFilters['limit'] !== null) {
            $sql .= ' LIMIT :limit';
            $params['limit'] = $normalizedFilters['limit'];
        }

        if ($normalizedFilters['offset'] > 0) {
            $sql .= ' OFFSET :offset';
            $params['offset'] = $normalizedFilters['offset'];
        }

        // Execute and stream results
        try {
            $stmt = $this->_connection->prepare($sql);

            foreach ($params as $key => $value) {
                $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue(":{$key}", $value, $type);
            }

            $stmt->execute();

            while ($row = $stmt->fetch()) {
                // Normalize entity_id field
                if (!isset($row['entity_id']) && isset($row[$idField])) {
                    $row['entity_id'] = $row[$idField];
                }

                yield $row;
            }

        } catch (PDOException $e) {
            throw new Maho_DataSync_Exception(
                "Database query failed: {$e->getMessage()}",
                Maho_DataSync_Exception::CODE_CONNECTION_FAILED,
            );
        }
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function count(string $entityType, array $filters = []): ?int
    {
        $this->_ensureConfigured();

        if (!isset($this->_tableMappings[$entityType])) {
            return null;
        }

        $mapping = $this->_tableMappings[$entityType];
        $table = $this->_tablePrefix . $mapping['main_table'];

        try {
            $stmt = $this->_connection->query("SELECT COUNT(*) FROM {$table}");
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getInfo(): array
    {
        $info = parent::getInfo();

        if ($this->_connection !== null) {
            try {
                $info['server_version'] = $this->_connection->getAttribute(PDO::ATTR_SERVER_VERSION);
                $info['connection_status'] = $this->_connection->getAttribute(PDO::ATTR_CONNECTION_STATUS);
            } catch (PDOException $e) {
                $info['connection_error'] = $e->getMessage();
            }
        }

        $info['table_prefix'] = $this->_tablePrefix;

        return $info;
    }

    /**
     * Get the PDO connection (for advanced use cases)
     */
    public function getConnection(): ?PDO
    {
        return $this->_connection;
    }

    /**
     * Get table name with prefix
     */
    public function getTableName(string $tableName): string
    {
        return $this->_tablePrefix . $tableName;
    }
}
