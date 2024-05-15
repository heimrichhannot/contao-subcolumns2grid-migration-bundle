<?php

namespace HeimrichHannot\Subcolumns2Grid\Manager;

use Doctrine\DBAL\DBALException as DBALDBALException;
use Doctrine\DBAL\Driver\Exception as DBALDriverException;
use Doctrine\DBAL\Exception as DBALException;
use HeimrichHannot\Subcolumns2Grid\Config\ColsetElementDTO;
use HeimrichHannot\Subcolumns2Grid\Config\MigrationConfig;

/**
 * This class is responsible for transforming module content elements and form fields into grid columns.
 */
class BundleAlchemist extends AbstractAlchemist
{
    public const NAME = 'bundle';

    protected function identifierFromColsetElementDTO(MigrationConfig $config, ColsetElementDTO $ce): string
    {
        return $ce->getIdentifier();
    }

    /**
     * @throws DBALDBALException|DBALDriverException|DBALException
     */
    public function getContentElements(MigrationConfig $config): array
    {
        $sqlScColumnsetEmpty = $this->dbColumnExists('tl_content', 'sc_columnset')
            ? 'AND sc_columnset = ""'
            : '';

        $stmt = $this->connection->prepare(<<<SQL
            SELECT id, type, customTpl, sc_childs, sc_parent, sc_name, sc_sortid, sc_columnset
              FROM tl_content
             WHERE type LIKE "colset%"
             ORDER BY sc_parent, sc_sortid
               $sqlScColumnsetEmpty
        SQL);
        $result = $stmt->executeQuery();

        return $this->dbResult2colsetElementDTOs($config, $result);
    }

    /**
     * @throws DBALDriverException|DBALDBALException|DBALException
     */
    public function getFormFields(MigrationConfig $config): array
    {
        $sqlScColumnsetEmpty = $this->dbColumnExists('tl_form_field', 'sc_columnset')
            ? 'AND sc_columnset = ""'
            : '';

        $stmt = $this->connection->prepare(<<<SQL
            SELECT id, type, customTpl, fsc_childs, fsc_parent, fsc_name, sc_columnset
              FROM tl_form_field
             WHERE type LIKE "formcol%"
               $sqlScColumnsetEmpty
        SQL);
        $result = $stmt->executeQuery();

        return $this->dbResult2colsetElementDTOs($config, $result, [
            'scChildren'  => 'fsc_childs',
            'scParent'    => 'fsc_parent',
            'scType'      => 'fsc_type',
            'scName'      => 'fsc_name',
            'scOrder'     => 'fsc_sortid',
            'scColumnset' => 'sc_columnset'
        ], 'tl_form_field');
    }

    /**
     * {@inheritDoc}
     *
     * @throws DBALDriverException|DBALDBALException|DBALException
     */
    public function checkIfContentElementsExist(): bool
    {
        if (!$this->dbColumnExists('tl_content', 'sc_columnset')) {
            return false;
        }

        $stmt = $this->connection->prepare(<<<SQL
            SELECT COUNT(id)
              FROM tl_content
             WHERE type LIKE "colset%"
               AND sc_columnset != ""
             LIMIT 1
        SQL);

        $result = $stmt->executeQuery();

        // if there are colset elements with a sc_columnset,
        // they have to be bundle content elements
        return (int)$result->fetchOne() > 0;
    }

    /**
     * @throws DBALDriverException|DBALDBALException|DBALException
     */
    public function checkIfFormFieldsExist(): bool
    {
        if (!$this->dbColumnExists('tl_form_field', 'sc_columnset')) {
            return false;
        }

        $stmt = $this->connection->prepare(<<<SQL
            SELECT COUNT(id)
              FROM tl_form_field
             WHERE type LIKE "formcol%"
               AND sc_columnset != ""
             LIMIT 1
        SQL);

        $result = $stmt->executeQuery();

        // if there are formcol elements with a fsc_type and a sc_columnset,
        // they have to be bundle form fields
        return (int)$result->fetchOne() > 0;
    }
}