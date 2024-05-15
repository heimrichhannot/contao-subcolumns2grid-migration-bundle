<?php

namespace HeimrichHannot\Subcolumns2Grid\Manager;

use HeimrichHannot\Subcolumns2Grid\Config\ColsetElementDTO;
use HeimrichHannot\Subcolumns2Grid\Config\MigrationConfig;

/**
 * This class is responsible for transforming module content elements and form fields into grid columns.
 */
class ModuleAlchemist extends AbstractAlchemist
{
    public const NAME = 'module';

    protected function identifierFromColsetElementDTO(MigrationConfig $config, ColsetElementDTO $ce): string
    {
        return \sprintf('globals.%s.%s', $config->getProfile(), $ce->getScType());
    }

    public function getContentElements(MigrationConfig $config): array
    {
        $sqlScColumnsetEmpty = $this->dbColumnExists('tl_content', 'sc_columnset')
            ? 'AND sc_columnset = ""'
            : '';

        $stmt = $this->connection->prepare(<<<SQL
            SELECT id, type, customTpl, sc_childs, sc_parent, sc_type, sc_name, sc_sortid
              FROM tl_content
             WHERE type LIKE "colset%"
             ORDER BY sc_parent, sc_sortid
               $sqlScColumnsetEmpty
        SQL);
        $result = $stmt->executeQuery();

        return $this->dbResult2colsetElementDTOs($config, $result);
    }

    public function getFormFields(MigrationConfig $config): array
    {
        $sqlScColumnsetEmpty = $this->dbColumnExists('tl_form_field', 'sc_columnset')
            ? 'AND sc_columnset = ""'
            : '';

        $stmt = $this->connection->prepare(<<<SQL
            SELECT id, type, customTpl, fsc_childs, fsc_parent, fsc_type, fsc_name
              FROM tl_form_field
             WHERE type LIKE "formcol%"
               $sqlScColumnsetEmpty
        SQL);
        $result = $stmt->executeQuery();

        return $this->dbResult2colsetElementDTOs($config, $result, [
            'scChildren' => 'fsc_childs',
            'scParent'   => 'fsc_parent',
            'scType'     => 'fsc_type',
            'scName'     => 'fsc_name',
            'scOrder'    => 'fsc_sortid',
        ], 'tl_form_field');
    }

    /**
     * {@inheritDoc}
     */
    public function checkIfContentElementsExist(): bool
    {
        $sqlColsetEmpty = $this->dbColumnExists('tl_content', 'sc_columnset')
            ? 'AND sc_columnset = ""'
            : '';

        $stmt = $this->connection->prepare(<<<SQL
            SELECT COUNT(id)
              FROM tl_content
             WHERE type LIKE "colset%"
               $sqlColsetEmpty
               AND sc_type != ""
             LIMIT 1
        SQL);

        $result = $stmt->executeQuery();

        // if there are colset elements with a sc_type but no sc_columnset,
        // they have to be module content elements
        return (int)$result->fetchOne() > 0;
    }

    public function checkIfFormFieldsExist(): bool
    {
        $sqlColsetEmpty = $this->dbColumnExists('tl_form_field', 'sc_columnset')
            ? 'AND sc_columnset = ""'
            : '';

        $stmt = $this->connection->prepare(<<<SQL
            SELECT COUNT(id)
              FROM tl_form_field
             WHERE type LIKE "formcol%"
               $sqlColsetEmpty
               AND fsc_type != ""
             LIMIT 1
        SQL);

        $result = $stmt->executeQuery();

        // if there are colset elements with a fsc_type but no sc_columnset,
        // they have to be module form fields
        return (int)$result->fetchOne() > 0;
    }
}