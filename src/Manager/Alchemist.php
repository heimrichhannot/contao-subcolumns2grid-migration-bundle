<?php

namespace HeimrichHannot\Subcolumns2Grid\Manager;

use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Result;
use HeimrichHannot\Subcolumns2Grid\Config\ColsetElementDTO;
use HeimrichHannot\Subcolumns2Grid\Config\MigrationConfig;
use HeimrichHannot\Subcolumns2Grid\Util\Constants;
use HeimrichHannot\Subcolumns2Grid\Util\Helper;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * This class is responsible for transforming module content elements and form fields into grid columns.
 */
class Alchemist extends AbstractManager
{
    public function transformBundle(SymfonyStyle $io, MigrationConfig $config)
    {

    }

    public function transformModule(SymfonyStyle $io, MigrationConfig $config)
    {
        $io->text('Checking for module content elements.');
        if ($this->checkIfModuleContentElementsExist())
        {
            $io->text('Migrating module content elements.');
            $this->transformModuleContentElements($config);

            $io->success('Migrated module content elements successfully.');
        }
        else
        {
            $io->info('No module content elements found.');
        }

        $io->text('Checking for module form fields.');
        if ($this->checkIfModuleFormFieldsExist())
        {
            $io->text('Migrating module form fields.');
            $this->transformModuleFormFields($config);

            $io->success('Migrated module form fields successfully.');
        }
        else
        {
            $io->info('No module form fields found.');
        }
    }

    public function transformModuleContentElements(MigrationConfig $config): void
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

        $contentElements = $this->dbResult2colsetElementDTOs($config, $result);

        $this->transformColsetElements($contentElements);
    }

    public function transformModuleFormFields(MigrationConfig $config): void
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

        $formFields = $this->dbResult2colsetElementDTOs($config, $result, [
            'scChildren' => 'fsc_childs',
            'scParent'   => 'fsc_parent',
            'scType'     => 'fsc_type',
            'scName'     => 'fsc_name',
            'scOrder'    => 'fsc_sortid',
        ], 'tl_form_field');

        $this->transformColsetElements($formFields);
    }

    /**
     * @return array<int, ColsetElementDTO[]> A map of parent IDs to their respective colset element data transfer
     *   objects, that may either represent content elements or form fields.
     */
    protected function dbResult2colsetElementDTOs(
        MigrationConfig $config,
        Result          $rows,
        ?array          $columnsMap = null,
        ?string         $table = null
    ): array {
        $currentProfile = $config->getProfile();

        /** @var array<int, ColsetElementDTO[]> $contentElements */
        $contentElements = [];

        while ($row = $rows->fetchAssociative())
        {
            $ce = ColsetElementDTO::fromRow($row, $columnsMap);
            if ($table !== null) {
                $ce->setTable($table);
            }

            $identifier = \sprintf('globals.%s.%s', $currentProfile, $ce->getScType());
            $ce->setIdentifier($identifier);

            $contentElements[$ce->getScParent()][] = $ce;
        }

        foreach ($contentElements as $scParentId => $ces)
        {
            foreach ($ces as $ce)
            {
                if (!$ce->getCustomTpl())
                {
                    $customTpl = $this->templateManager()->findColumnTemplate($config, $ce);
                    $ce->setCustomTpl($customTpl ?? '');
                }
            }
        }

        return $contentElements;
    }

    /**
     * @param array<int, ColsetElementDTO[]> $colsetElements
     * @throws DBALException|\Throwable
     */
    public function transformColsetElements(array $colsetElements): void
    {
        $this->connection->createSavepoint($uid = Helper::savepointId());

        foreach ($colsetElements as $parentId => $ceDTOs)
        {
            $this->transformColsetIntoGrid($parentId, $ceDTOs);
        }

        $this->connection->releaseSavepoint($uid);
    }

    /**
     * @param int $parentId
     * @param ColsetElementDTO[] $ceDTOs
     * @throws \DomainException
     */
    protected function transformColsetIntoGrid(int $parentId, array $ceDTOs): void
    {
        $errMsg = " Please check manually and re-run the migration.\n"
            . "(SELECT * FROM tl_content WHERE sc_parent=\"$parentId\" AND type LIKE \"colset%\" OR type LIKE \"formcol%\";)";

        if (empty($ceDTOs) || \count($ceDTOs) < 2) {
            throw new \DomainException("Not enough content elements found for colset to be valid." . $errMsg);
        }

        $identifier = $ceDTOs[0]->getIdentifier();
        $gridId = $this->migrationManager()->getGridIdFromMigratedIdentifier($identifier) ?? null;
        if (!$gridId) {
            throw new \DomainException("No migrated set \"$identifier\" found." . $errMsg);
        }

        $start = null;
        $parts = [];
        $stop = null;

        foreach ($ceDTOs as $ce) {
            switch ($ce->getType()) {
                case Constants::CE_TYPE_COLSET_START:
                case Constants::FF_TYPE_FORMCOL_START:
                    if ($ce->getId() !== $parentId) {
                        throw new \DomainException(
                            "Start element's id does not match its sc_parent id ({$ce->getId()} !== $parentId)." . $errMsg
                        );
                    }
                    if ($start !== null) {
                        throw new \DomainException('Multiple start elements found for sub-column set.' . $errMsg);
                    }
                    $start = $ce;
                    break;
                case Constants::CE_TYPE_COLSET_PART:
                case Constants::FF_TYPE_FORMCOL_PART:
                    $parts[] = $ce;
                    break;
                case Constants::CE_TYPE_COLSET_END:
                case Constants::FF_TYPE_FORMCOL_END:
                    if ($stop !== null) {
                        throw new \DomainException('Multiple stop elements found for sub-column set.' . $errMsg);
                    }
                    $stop = $ce;
                    break;
                default:
                    throw new \DomainException('Invalid content element type found for sub-column set.' . $errMsg);
            }
        }

        if (!$start) throw new \DomainException('No start element found for subcolumn set.' . $errMsg);
        if (!$stop)  throw new \DomainException('No stop element found for subcolumn set.' . $errMsg);

        $part = $parts[0] ?? null;

        $table = $start->getTable();

        /* ============================================== *\
         * Transform the start element into a grid start. *
        \* ============================================== */

        $stmt = $this->connection->prepare(<<<SQL
            UPDATE $table
               SET bs_grid_parent = :parentId,
                   bs_grid_name = :name,
                   bs_grid = :gridId,
                   type = :renameType,
                   customTpl = :customTpl
             WHERE id = :id
        SQL);

        $stmt->bindValue('parentId', 0);
        $stmt->bindValue('name', $start->getScName());
        $stmt->bindValue('gridId', $gridId);
        $stmt->bindValue('renameType', Constants::RENAME_TYPE[$start->getType()]);
        $stmt->bindValue('customTpl', $start->getCustomTpl() ?? '');
        $stmt->bindValue('id', $start->getId());

        $stmt->executeStatement();

        /* ======================================================= *\
         * Transform the child elements into grid columns and end. *
        \* ======================================================= */

        $childIds = \array_filter(\array_map(static function (ColsetElementDTO $row) use ($start) {
            $rowId = $row->getId();
            return $rowId !== $start->getId() ? $rowId : null;
        }, $ceDTOs));

        $placeholders = [];
        foreach ($childIds as $index => $childId)
            // necessary for parameter binding,
            // because DBAL does not allow mixing named and positional parameters
        {
            $placeholders[] = ':child_' . $index;
        }
        $placeholders = \implode(', ', $placeholders);

        $stmt = $this->connection->prepare(<<<SQL
            UPDATE $table
               SET bs_grid_parent = :parentId,
                   type = REPLACE(REPLACE(type, :oPart, :rPart), :oStop, :rStop),
                   customTpl = CASE 
                       WHEN customTpl != '' THEN customTpl
                       WHEN type = :oPart OR type = :rPart THEN :customTplPart
                       WHEN type = :oStop OR type = :rStop THEN :customTplStop
                       ELSE ''
                   END
             WHERE id IN ($placeholders)
               AND (type LIKE "colset%" OR type LIKE "formcol%")
        SQL);

        $stmt->bindValue('parentId', $start->getId());

        $oPart = $part ? ($part->getType() ?? Constants::CE_TYPE_COLSET_PART) : Constants::CE_TYPE_COLSET_PART;
        $stmt->bindValue('oPart', $oPart);
        $stmt->bindValue('rPart', Constants::RENAME_TYPE[$oPart]);
        $stmt->bindValue('customTplPart', $part ? ($part->getCustomTpl() ?? '') : '');

        $stmt->bindValue('oStop', $stop->getType());
        $stmt->bindValue('rStop', Constants::RENAME_TYPE[$stop->getType()]);
        $stmt->bindValue('customTplStop', $stop->getCustomTpl() ?? '');

        foreach ($childIds as $index => $childId) {
            $stmt->bindValue('child_' . $index, $childId, ParameterType::INTEGER);
        }

        $stmt->executeStatement();
    }

    /**
     * Check if there are module content elements in the database.
     *
     * Module content elements can be differentiated from subcolumn-bootstrap-bundle content elements,
     *   because the latter have a sc_columnset field that is not empty.
     */
    public function checkIfModuleContentElementsExist(): bool
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

    /**
     * @see self::checkIfModuleContentElementsExist() but for form fields.
     */
    public function checkIfModuleFormFieldsExist(): bool
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