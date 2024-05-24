<?php

namespace HeimrichHannot\Subcolumns2Grid\Manager;

use Doctrine\DBAL\DBALException as DBALDBALException;
use Doctrine\DBAL\Driver\Exception as DBALDriverException;
use Doctrine\DBAL\Exception as DBALException;
use HeimrichHannot\Subcolumns2Grid\Config\ColsetElementDTO;
use HeimrichHannot\Subcolumns2Grid\Config\MigrationConfig;
use HeimrichHannot\Subcolumns2Grid\Exception\MigrationException;

/**
 * This class is responsible for transforming module content elements and form fields into grid columns.
 */
class BundleAlchemist extends AbstractAlchemist
{
    public const NAME = 'bundle';

    /**
     * @throws MigrationException
     */
    protected function identifierFromColsetElementDTO(MigrationConfig $config, ColsetElementDTO $ce): string
    {
        return $ce->getIdentifier() ?? 'db.tl_columnset.' . $this->getScColumnsetIdFromColsetElementDTO($ce);
    }

    /**
     * @throws MigrationException
     */
    protected function getScColumnsetIdFromColsetElementDTO(ColsetElementDTO $ce): int
    {
        $columnsetId = $ce->getScColumnsetId();
        $pid = $ce->getPid();

        if (!$pid) throw new MigrationException('No pid found in colset element DTO.');

        if ($columnsetId)
        {
            $this->mapParentColumnsetId[$pid] = $columnsetId;
        }
        elseif (\array_key_exists($pid, $this->mapParentColumnsetId))
        {
            $columnsetId = $this->mapParentColumnsetId[$pid];
        }
        else throw new MigrationException('No columnset_id found in colset element DTO.');

        return $columnsetId;
    }

    /**
     * @throws DBALDBALException|DBALDriverException|DBALException
     */
    public function getContentElements(MigrationConfig $config): array
    {
        $scColumnsetExists = $this->helper->dbColumnExists('tl_content', 'sc_columnset');
        $sqlScColumnsetEmpty = $scColumnsetExists ? 'AND sc_columnset = ""' : '';
        $sqlScColumnsetSelect = $scColumnsetExists ? ', sc_columnset' : '';

        $stmt = $this->connection->prepare(<<<SQL
            SELECT id, pid, type, customTpl, sorting, sc_type, sc_childs, sc_parent, sc_name, sc_sortid,
                   addContainer, columnset_id $sqlScColumnsetSelect
              FROM tl_content
             WHERE `type` LIKE "colset%" $sqlScColumnsetEmpty
             ORDER BY `sc_parent` ASC, `type` DESC, `sorting` ASC
        SQL);
        $result = $stmt->executeQuery();

        return $this->dbResult2colsetElementDTOs($config, $result, 'tl_content');
    }

    /**
     * @throws DBALDriverException|DBALDBALException|DBALException
     */
    public function getFormFields(MigrationConfig $config): array
    {
        $scColumnsetExists = $this->helper->dbColumnExists('tl_form_field', 'sc_columnset');
        $sqlScColumnsetEmpty = $scColumnsetExists ? 'AND sc_columnset = ""' : '';
        $sqlScColumnsetSelect = $scColumnsetExists ? ', sc_columnset' : '';

        $stmt = $this->connection->prepare(<<<SQL
            SELECT id, pid, type, sorting, customTpl, fsc_type, fsc_childs, fsc_parent, fsc_name $sqlScColumnsetSelect
              FROM tl_form_field
             WHERE type LIKE "formcol%" $sqlScColumnsetEmpty
             ORDER BY fsc_parent ASC, `type` DESC, sorting ASC
        SQL);
        $result = $stmt->executeQuery();

        return $this->dbResult2colsetElementDTOs($config, $result, 'tl_form_field', [
            'scChildren'  => 'fsc_childs',
            'scParent'    => 'fsc_parent',
            'scType'      => 'fsc_type',
            'scName'      => 'fsc_name',
            'scOrder'     => 'fsc_sortid',
            'scColumnset' => 'sc_columnset'
        ]);
    }

    /**
     * {@inheritDoc}
     *
     * @throws DBALDriverException|DBALDBALException|DBALException
     */
    public function checkIfContentElementsExist(): bool
    {
        $sqlScColumnsetNotEmpty = $this->helper->dbColumnExists('tl_content', 'sc_columnset')
            ? 'AND sc_columnset != ""' : '';

        $stmt = $this->connection->prepare(<<<SQL
            SELECT COUNT(id)
              FROM tl_content
             WHERE type LIKE "colset%"
               $sqlScColumnsetNotEmpty
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
        $sqlScColumnsetNotEmpty = $this->helper->dbColumnExists('tl_form_field', 'sc_columnset')
            ? 'AND sc_columnset != ""' : '';

        $stmt = $this->connection->prepare(<<<SQL
            SELECT COUNT(id)
              FROM tl_form_field
             WHERE type LIKE "formcol%"
               $sqlScColumnsetNotEmpty
             LIMIT 1
        SQL);

        $result = $stmt->executeQuery();

        // if there are formcol elements with a fsc_type and a sc_columnset,
        // they have to be bundle form fields
        return (int)$result->fetchOne() > 0;
    }
}