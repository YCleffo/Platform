<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

/** Pages stay inside Core's authorized export transaction and configured artifact byte budget. */
interface PagedPersonalDataContributor extends PersonalDataContributor
{
    /** @return iterable<PersonalDataSection> */
    public function pages(PersonalDataExportContext $context): iterable;
}
