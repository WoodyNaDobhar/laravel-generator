<?php

namespace InfyOm\Generator\Utils;

use DB;
use Illuminate\Support\Str;
use InfyOm\Generator\Common\GeneratorField;
use InfyOm\Generator\Common\GeneratorFieldRelation;

class GeneratorForeignKey
{
    /** @var string */
    public $name;
    public $localField;
    public $foreignField;
    public $foreignTable;
    public $onUpdate;
    public $onDelete;
}

class GeneratorTable
{
    /** @var string */
    public $primaryKey;

    /** @var GeneratorForeignKey[] */
    public $foreignKeys;
}

class TableFieldsGenerator
{
    /** @var string */
    public $tableName;
    public $primaryKey;

    /** @var bool */
    public $defaultSearchable;

    /** @var array */
    public $timestamps;

    /** @var AbstractSchemaManager */
    private $schemaManager;

    /** @var Column[] */
    private $columns;

    /** @var GeneratorField[] */
    public $fields;

    /** @var GeneratorFieldRelation[] */
    public $relations;

    /** @var array */
    public $ignoredFields;

    public function __construct($tableName, $ignoredFields, $connection = '')
    {
        $this->tableName = $tableName;
        $this->ignoredFields = $ignoredFields;

        $platform = DB::getDriverName();
        $defaultMappings = [
            'enum' => 'string',
            'json' => 'text',
            'bit'  => 'boolean',
        ];

        $mappings = config('laravel_generator.from_table.doctrine_mappings', []);
        $mappings = array_merge($mappings, $defaultMappings);
        foreach ($mappings as $dbType => $doctrineType) {
            $platform->registerDoctrineTypeMapping($dbType, $doctrineType);
        }

        $columns = DB::select("
        SELECT COLUMN_NAME as name, DATA_TYPE as data_type,
               CHARACTER_MAXIMUM_LENGTH as max_length, IS_NULLABLE as is_nullable,
               COLUMN_DEFAULT as default_value, COLUMN_KEY as column_key
        FROM information_schema.columns
        WHERE table_name = :table AND table_schema = :schema
    ", ['table' => $tableName, 'schema' => DB::getDatabaseName()]);

        $this->columns = [];
        foreach ($columns as $column) {
            if (!in_array($column->getName(), $ignoredFields)) {
                $this->columns[] = $column;
            }
        }

        $this->primaryKey = $this->getPrimaryKeyOfTable($tableName);
        $this->timestamps = static::getTimestampFieldNames();
        $this->defaultSearchable = config('laravel_generator.options.tables_searchable_default', false);
    }

    /**
     * Prepares array of GeneratorField from table columns.
     */
    public function prepareFieldsFromTable()
    {
        foreach ($this->columns as $column) {
            $type = $column->getType()->getName();

            switch ($type) {
                case 'integer':
                    $field = $this->generateIntFieldInput($column, 'integer');
                    break;
                case 'smallint':
                    $field = $this->generateIntFieldInput($column, 'smallInteger');
                    break;
                case 'bigint':
                    $field = $this->generateIntFieldInput($column, 'bigInteger');
                    break;
                case 'boolean':
                    $name = Str::title(str_replace('_', ' ', $column->getName()));
                    $field = $this->generateField($column, 'boolean', 'checkbox');
                    break;
                case 'datetime':
                    $field = $this->generateField($column, 'datetime', 'date');
                    break;
                case 'datetimetz':
                    $field = $this->generateField($column, 'dateTimeTz', 'date');
                    break;
                case 'date':
                    $field = $this->generateField($column, 'date', 'date');
                    break;
                case 'time':
                    $field = $this->generateField($column, 'time', 'text');
                    break;
                case 'decimal':
                    $field = $this->generateNumberInput($column, 'decimal');
                    break;
                case 'float':
                    $field = $this->generateNumberInput($column, 'float');
                    break;
                case 'text':
                    $field = $this->generateField($column, 'text', 'textarea');
                    break;
                default:
                    $field = $this->generateField($column, 'string', 'text');
                    break;
            }

            if (strtolower($field->name) == 'password') {
                $field->htmlType = 'password';
            } elseif (strtolower($field->name) == 'email') {
                $field->htmlType = 'email';
            } elseif (in_array($field->name, $this->timestamps)) {
                $field->isSearchable = false;
                $field->isFillable = false;
                $field->inForm = false;
                $field->inIndex = false;
                $field->inView = false;
            }
            $field->isNotNull = $column->getNotNull();
            $field->description = $column->getComment() ?? ''; // get comments from table

            $this->fields[] = $field;
        }
    }

    /**
     * Get primary key of given table.
     *
     * @param string $tableName
     *
     * @return string|null The column name of the (simple) primary key
     */
    public function getPrimaryKeyOfTable($tableName)
    {
        $databaseName = DB::getDatabaseName();

        // Query to retrieve the primary key column name
        $primaryKey = DB::selectOne("
            SELECT COLUMN_NAME
            FROM information_schema.key_column_usage
            WHERE table_name = :table
            AND table_schema = :schema
            AND constraint_name = 'PRIMARY'
        ", [
            'table' => $tableName,
            'schema' => $databaseName
        ]);
    }

    /**
     * Get timestamp columns from config.
     *
     * @return array the set of [created_at column name, updated_at column name]
     */
    public static function getTimestampFieldNames()
    {
        if (!config('laravel_generator.timestamps.enabled', true)) {
            return [];
        }

        $createdAtName = config('laravel_generator.timestamps.created_at', 'created_at');
        $updatedAtName = config('laravel_generator.timestamps.updated_at', 'updated_at');
        $deletedAtName = config('laravel_generator.timestamps.deleted_at', 'deleted_at');

        return [$createdAtName, $updatedAtName, $deletedAtName];
    }

    /**
     * Generates integer text field for database.
     *
     * @param string $dbType
     * @param Column $column
     *
     * @return GeneratorField
     */
    private function generateIntFieldInput($column, $dbType)
    {
        $field = new GeneratorField();
        $field->name = $column->getName();
        $field->parseDBType($dbType);
        $field->htmlType = 'number';

        if ($column->getAutoincrement()) {
            $field->dbType .= ',true';
        } else {
            $field->dbType .= ',false';
        }

        if ($column->getUnsigned()) {
            $field->dbType .= ',true';
        }

        return $this->checkForPrimary($field);
    }

    /**
     * Check if key is primary key and sets field options.
     *
     * @param GeneratorField $field
     *
     * @return GeneratorField
     */
    private function checkForPrimary(GeneratorField $field)
    {
        if ($field->name == $this->primaryKey) {
            $field->isPrimary = true;
            $field->isFillable = false;
            $field->isSearchable = false;
            $field->inIndex = false;
            $field->inForm = false;
            $field->inView = false;
        }

        return $field;
    }

    /**
     * Generates field metadata for the given column.
     *
     * @param object $column Column metadata from information_schema.
     * @param string $dbType Database type for the column.
     * @param string $htmlType HTML input type for the field.
     * @return GeneratorField
     */
    private function generateField($column, $dbType, $htmlType)
    {
        $field = new GeneratorField();
        $field->name = $column->name;
        $field->fieldDetails = $this->tableDetails[$field->name] ?? null;

        // Parse database type and HTML input type for the field
        $field->parseDBType($dbType);
        $field->parseHtmlInput($htmlType);

        return $this->checkForPrimary($field);
    }

    /**
     * Fetches and structures table details for a given table name.
     *
     * @param string $tableName The name of the table.
     * @return array Associative array of column metadata.
     */
    private function fetchTableDetails($tableName)
    {
        $databaseName = DB::getDatabaseName();

        $columns = DB::select("
            SELECT COLUMN_NAME as name,
                DATA_TYPE as data_type,
                CHARACTER_MAXIMUM_LENGTH as max_length,
                IS_NULLABLE as is_nullable,
                COLUMN_DEFAULT as default_value,
                COLUMN_KEY as column_key
            FROM information_schema.columns
            WHERE table_name = :table AND table_schema = :schema
        ", [
            'table' => $tableName,
            'schema' => $databaseName
        ]);

        $details = [];
        foreach ($columns as $column) {
            $details[$column->name] = [
                'data_type' => $column->data_type,
                'max_length' => $column->max_length,
                'is_nullable' => $column->is_nullable === 'YES',
                'default_value' => $column->default_value,
                'is_primary' => $column->column_key === 'PRI',
            ];
        }

        return $details;
    }

    /**
     * Generates number field.
     *
     * @param Column $column
     * @param string $dbType
     *
     * @return GeneratorField
     */
    private function generateNumberInput($column, $dbType)
    {
        $field = new GeneratorField();
        $field->name = $column->getName();
        $field->parseDBType($dbType.','.$column->getPrecision().','.$column->getScale());
        $field->htmlType = 'number';

        if ($dbType === 'decimal') {
            $field->numberDecimalPoints = $column->getScale();
        }

        return $this->checkForPrimary($field);
    }

    /**
     * Prepares relations (GeneratorFieldRelation) array from table foreign keys.
     */
    public function prepareRelations()
    {
        $foreignKeys = $this->prepareForeignKeys();
        $this->checkForRelations($foreignKeys);
    }

    /**
     * Prepares foreign keys from all tables with required details.
     *
     * @return array Associative array of table names and their foreign keys.
     */
    public function prepareForeignKeys()
    {
        $databaseName = DB::getDatabaseName();

        // Step 1: Fetch all tables in the database
        $tables = DB::select("
            SELECT table_name 
            FROM information_schema.tables 
            WHERE table_schema = :schema
        ", ['schema' => $databaseName]);

        $fields = [];

        foreach ($tables as $table) {
            $tableName = $table->table_name;

            // Fetch primary key for the current table
            $primaryKey = $this->getPrimaryKeyOfTable($tableName);

            // Fetch foreign keys for the current table
            $foreignKeys = DB::select("
                SELECT 
                    kcu.CONSTRAINT_NAME AS name,
                    kcu.COLUMN_NAME AS local_column,
                    kcu.REFERENCED_TABLE_NAME AS foreign_table,
                    kcu.REFERENCED_COLUMN_NAME AS foreign_column,
                    rc.UPDATE_RULE AS on_update,
                    rc.DELETE_RULE AS on_delete
                FROM information_schema.key_column_usage AS kcu
                JOIN information_schema.referential_constraints AS rc
                ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                WHERE kcu.table_name = :table 
                AND kcu.table_schema = :schema
                AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            ", [
                'table' => $tableName,
                'schema' => $databaseName
            ]);

            // Format each foreign key for the generator
            $formattedForeignKeys = [];
            foreach ($foreignKeys as $fk) {
                $generatorForeignKey = new GeneratorForeignKey();
                $generatorForeignKey->name = $fk->name;
                $generatorForeignKey->localField = $fk->local_column;
                $generatorForeignKey->foreignField = $fk->foreign_column;
                $generatorForeignKey->foreignTable = $fk->foreign_table;
                $generatorForeignKey->onUpdate = $fk->on_update;
                $generatorForeignKey->onDelete = $fk->on_delete;

                $formattedForeignKeys[] = $generatorForeignKey;
            }

            // Set up the GeneratorTable for this table
            $generatorTable = new GeneratorTable();
            $generatorTable->primaryKey = $primaryKey;
            $generatorTable->foreignKeys = $formattedForeignKeys;

            $fields[$tableName] = $generatorTable;
        }

        return $fields;
    }

    /**
     * Prepares relations array from table foreign keys.
     *
     * @param GeneratorTable[] $tables
     */
    private function checkForRelations($tables)
    {
        // get Model table name and table details from tables list
        $modelTableName = $this->tableName;
        $modelTable = $tables[$modelTableName];
        unset($tables[$modelTableName]);

        $this->relations = [];

        // detects many to one rules for model table
        $manyToOneRelations = $this->detectManyToOne($tables, $modelTable);

        if (count($manyToOneRelations) > 0) {
            $this->relations = array_merge($this->relations, $manyToOneRelations);
        }

        foreach ($tables as $tableName => $table) {
            $foreignKeys = $table->foreignKeys;
            $primary = $table->primaryKey;

            // if foreign key count is 2 then check if many to many relationship is there
            if (count($foreignKeys) == 2) {
                $manyToManyRelation = $this->isManyToMany($tables, $tableName, $modelTable, $modelTableName);
                if ($manyToManyRelation) {
                    $this->relations[] = $manyToManyRelation;
                    continue;
                }
            }

            // iterate each foreign key and check for relationship
            foreach ($foreignKeys as $foreignKey) {
                // check if foreign key is on the model table for which we are using generator command
                if ($foreignKey->foreignTable == $modelTableName) {
                    // detect if one to one relationship is there
                    $isOneToOne = $this->isOneToOne($primary, $foreignKey, $modelTable->primaryKey);
                    if ($isOneToOne) {
                        $modelName = model_name_from_table_name($tableName);
                        $this->relations[] = GeneratorFieldRelation::parseRelation('1t1,'.$modelName);
                        continue;
                    }

                    // detect if one to many relationship is there
                    $isOneToMany = $this->isOneToMany($primary, $foreignKey, $modelTable->primaryKey);
                    if ($isOneToMany) {
                        $modelName = model_name_from_table_name($tableName);
                        $this->relations[] = GeneratorFieldRelation::parseRelation(
                            '1tm,'.$modelName.','.$foreignKey->localField
                        );
                        continue;
                    }
                }
            }
        }
    }

    /**
     * Detects many to many relationship
     * If table has only two foreign keys
     * Both foreign keys are primary key in foreign table
     * Also one is from model table and one is from diff table.
     *
     * @param GeneratorTable[] $tables
     * @param string           $tableName
     * @param GeneratorTable   $modelTable
     * @param string           $modelTableName
     *
     * @return bool|GeneratorFieldRelation
     */
    private function isManyToMany($tables, $tableName, $modelTable, $modelTableName)
    {
        // get table details
        $table = $tables[$tableName];

        $isAnyKeyOnModelTable = false;

        // many to many model table name
        $manyToManyTable = '';

        $foreignKeys = $table->foreignKeys;
        $primary = $table->primaryKey;

        // check if any foreign key is there from model table
        foreach ($foreignKeys as $foreignKey) {
            if ($foreignKey->foreignTable == $modelTableName) {
                $isAnyKeyOnModelTable = true;
            }
        }

        // if foreign key is there
        if (!$isAnyKeyOnModelTable) {
            return false;
        }

        foreach ($foreignKeys as $foreignKey) {
            $foreignField = $foreignKey->foreignField;
            $foreignTableName = $foreignKey->foreignTable;

            // if foreign table is model table
            if ($foreignTableName == $modelTableName) {
                $foreignTable = $modelTable;
            } else {
                $foreignTable = $tables[$foreignTableName];
                // get the many to many model table name
                $manyToManyTable = $foreignTableName;
            }

            // if foreign field is not primary key of foreign table
            // then it can not be many to many
            if ($foreignField != $foreignTable->primaryKey) {
                return false;
                break;
            }

            // if foreign field is primary key of this table
            // then it can not be many to many
            if ($foreignField == $primary) {
                return false;
            }
        }

        if (empty($manyToManyTable)) {
            return false;
        }

        $modelName = model_name_from_table_name($manyToManyTable);

        return GeneratorFieldRelation::parseRelation('mtm,'.$modelName.','.$tableName);
    }

    /**
     * Detects if one to one relationship is there
     * If foreign key of table is primary key of foreign table
     * Also foreign key field is primary key of this table.
     *
     * @param string              $primaryKey
     * @param GeneratorForeignKey $foreignKey
     * @param string              $modelTablePrimary
     *
     * @return bool
     */
    private function isOneToOne($primaryKey, $foreignKey, $modelTablePrimary)
    {
        if ($foreignKey->foreignField == $modelTablePrimary) {
            if ($foreignKey->localField == $primaryKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detects if one to many relationship is there
     * If foreign key of table is primary key of foreign table
     * Also foreign key field is not primary key of this table.
     *
     * @param string              $primaryKey
     * @param GeneratorForeignKey $foreignKey
     * @param string              $modelTablePrimary
     *
     * @return bool
     */
    private function isOneToMany($primaryKey, $foreignKey, $modelTablePrimary)
    {
        if ($foreignKey->foreignField == $modelTablePrimary) {
            if ($foreignKey->localField != $primaryKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect many to one relationship on model table
     * If foreign key of model table is primary key of foreign table.
     *
     * @param GeneratorTable[] $tables
     * @param GeneratorTable   $modelTable
     *
     * @return array
     */
    private function detectManyToOne($tables, $modelTable)
    {
        $manyToOneRelations = [];

        $foreignKeys = $modelTable->foreignKeys;

        foreach ($foreignKeys as $foreignKey) {
            $foreignTable = $foreignKey->foreignTable;
            $foreignField = $foreignKey->foreignField;

            if (!isset($tables[$foreignTable])) {
                continue;
            }

            if ($foreignField == $tables[$foreignTable]->primaryKey) {
                $modelName = model_name_from_table_name($foreignTable);
                $manyToOneRelations[] = GeneratorFieldRelation::parseRelation(
                    'mt1,'.$modelName.','.$foreignKey->localField
                );
            }
        }

        return $manyToOneRelations;
    }
}
