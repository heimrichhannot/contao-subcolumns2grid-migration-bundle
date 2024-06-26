<?php

namespace HeimrichHannot\Subcolumns2Grid\Manager;

use Doctrine\DBAL\DBALException as DBALDBALException;
use Doctrine\DBAL\Driver\Exception as DBALDriverException;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Result;
use HeimrichHannot\Subcolumns2Grid\Config\ColsetElementDTO;
use HeimrichHannot\Subcolumns2Grid\Config\MigrationConfig;
use HeimrichHannot\Subcolumns2Grid\Exception\MigrationException;
use HeimrichHannot\Subcolumns2Grid\Util\Constants;
use HeimrichHannot\Subcolumns2Grid\Util\Helper;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class AbstractAlchemist extends AbstractManager
{
    protected array $mapParentColumnsetId = [];

    public const NAME = 'alchemist';

    public function getName(): string {
        return $this::NAME;
    }

    /**
     * Check if there are relevant content elements in the database.
     *
     * Module content elements can be differentiated from subcolumn-bootstrap-bundle content elements,
     *   because the latter have a sc_columnset field that is not empty.
     */
    public abstract function checkIfContentElementsExist();

    public abstract function getContentElements(MigrationConfig $config): array;

    public abstract function checkIfFormFieldsExist();

    public abstract function getFormFields(MigrationConfig $config): array;

    /**
     * @throws MigrationException
     */
    protected abstract function identifierFromColsetElementDTO(MigrationConfig $config, ColsetElementDTO $dto): string;

    /**
     * @throws DBALDBALException|DBALDriverException|DBALException
     * @throws MigrationException
     */
    public function transform(SymfonyStyle $io, MigrationConfig $config)
    {
        $io->section("Migration of {$this->getName()} content elements and form fields");

        if (!$io->confirm("Transform {$this->getName()} content elements and form fields now?"))
        {
            $io->note("Skipping transformation of {$this->getName()} content elements and form fields.");
            return;
        }

        $io->text("Checking for {$this->getName()} content elements.");
        if ($this->checkIfContentElementsExist())
        {
            $io->text("Migrating {$this->getName()} content elements.");

            $contentElements = $this->getContentElements($config);
            $count = \count($contentElements);
            $io->info("Found $count {$this->getName()} content elements.");

            $this->transformColsetElements($contentElements);

            $io->success("Migrated $count {$this->getName()} content elements successfully.");
        }
        else
        {
            $io->info("No {$this->getName()} content elements found.");
        }

        $io->text("Checking for {$this->getName()} form fields.");
        if ($this->checkIfFormFieldsExist())
        {
            $io->text("Migrating {$this->getName()} form fields.");

            $formFields = $this->getFormFields($config);
            $count = \count($formFields);
            $io->info("Found $count {$this->getName()} form fields.");

            $this->transformColsetElements($formFields);

            $io->success("Migrated $count {$this->getName()} form fields successfully.");
        }
        else
        {
            $io->info("No {$this->getName()} form fields found.");
        }
    }

    /**
     * @return array<int, ColsetElementDTO[]> A map of parent IDs to their respective colset element data transfer
     *   objects, that may either represent content elements or form fields.
     *
     * @throws DBALException
     * @throws MigrationException
     */
    protected function dbResult2colsetElementDTOs(
        MigrationConfig $config,
        Result          $rows,
        string          $table,
        ?array          $columnsMap = null
    ): array {
        /** @var array<int, ColsetElementDTO[]> $contentElements */
        $contentElements = [];
        $parentElements = [];

        while ($row = $rows->fetchAssociative())
        {
            $ce = ColsetElementDTO::fromRow($row, $columnsMap);
            $ce->setTable($table);

            if (!$ce->isValid())
            {
                $config->addNote(
                    "Could not identify entity $table.id={$ce->getId()}. "
                    . "One or more database entries in $table might be corrupt."
                );
                continue;
            }

            try
            {
                $identifier = $this->identifierFromColsetElementDTO($config, $ce);
            }
            catch (MigrationException $e)
            {
                throw new MigrationException(
                    "Could not identify entity $table.id={$ce->getId()}. "
                    . $e->getMessage()
                );
            }

            if (!$identifier) {
                throw new MigrationException(
                    "Could not identify content element with ID {$ce->getId()}. "
                    . "One or more database entries in tl_content or tl_form_field might be corrupt."
                );
            }

            $ce->setIdentifier($identifier);

            $contentElements[$ce->getScParent()][] = $ce;

            if ($ce->getType() === Constants::CE_TYPE_COLSET_START || $ce->getType() === Constants::FF_TYPE_FORMCOL_START)
            {
                $parentElements[$ce->getId()] = $ce;
            }
        }

        foreach ($contentElements as $scParentId => $ces)
        {
            if (!($startDTO = $parentElements[$scParentId] ?? null))
            {
                continue;
            }

            foreach ($ces as $ce)
            {
                $ce->setStartDTO($startDTO);

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
     * @throws DBALDBALException|DBALDriverException|DBALException
     * @throws MigrationException
     */
    protected function transformColsetElements(array $colsetElements): void
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
     * @throws DBALDBALException|DBALDriverException|DBALException
     * @throws MigrationException
     */
    protected function transformColsetIntoGrid(int $parentId, array $ceDTOs): void
    {
        if (empty($ceDTOs)) return;

        $scColumnsetSelect = $this->helper->dbColumnExists('tl_content', 'sc_columnset') ? ', sc_columnset' : '';

        $errMsg = <<<MSG
        
        Please check manually and re-run the migration.
            
        SELECT `id`, `type`, `pid`, `ptable`, `sorting`, `tstamp`, `sc_sortid`, `sc_childs`,
               `sc_parent`, `sc_type`, `sc_name`$scColumnsetSelect FROM `tl_content`
        WHERE `sc_parent`="$parentId" AND `type` LIKE "colset%" OR `type` LIKE "formcol%"
        ORDER BY type DESC, id ASC;
        MSG;

        if (\count($ceDTOs) < 2) {
            throw new MigrationException("Not enough content elements found for colset to be valid." . $errMsg);
        }

        $identifier = $ceDTOs[0]->getIdentifier();
        $gridId = $this->migrationManager()->getGridIdFromMigratedIdentifier($identifier) ?? null;
        if (!$gridId) {
            throw new MigrationException("No migrated column-set \"$identifier\" found." . $errMsg);
        }

        $start = null;
        $parts = [];
        $stop = null;

        foreach ($ceDTOs as $ce)
        {
            switch ($ce->getType())
            {
                case Constants::CE_TYPE_COLSET_START:
                case Constants::FF_TYPE_FORMCOL_START:
                    if ($ce->getId() !== $parentId) {
                        throw new MigrationException(
                            "Start element's id does not match its sc_parent id ({$ce->getId()} !== $parentId)." . $errMsg
                        );
                    }
                    if ($start !== null) {
                        throw new MigrationException('Multiple start elements found for sub-column set.' . $errMsg);
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
                        throw new MigrationException('Multiple stop elements found for sub-column set.' . $errMsg);
                    }
                    $stop = $ce;
                    break;

                default:
                    throw new MigrationException('Invalid content element type found for sub-column set.' . $errMsg);
            }
        }

        if (!$start) throw new MigrationException('No start element found for subcolumn set.' . $errMsg);
        if (!$stop)  throw new MigrationException('No stop element found for subcolumn set.' . $errMsg);

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
            // as DBAL does not allow mixing named and positional parameters
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
}