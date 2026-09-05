<?php

namespace App\Domains\People\Skills\Enums;

/**
 * Workbook "Department/Shared" scope: a shared skill applies across the
 * company; a department skill belongs to one organization unit.
 */
enum SkillScope: string
{
    case Shared = 'shared';
    case Department = 'department';
}
