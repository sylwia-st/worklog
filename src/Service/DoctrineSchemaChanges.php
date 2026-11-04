<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

final class DoctrineSchemaChanges implements SchemaChanges
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function pendingSql(): array
    {
        return array_values((new SchemaTool($this->em))->getUpdateSchemaSql($this->em->getMetadataFactory()->getAllMetadata()));
    }
}
