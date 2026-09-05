<?php

namespace App\Domains\People\Training\Enums;

enum TrainingNeedSource: string
{
    case SkillGap = 'skill_gap';
    case NewMachineTechnology = 'new_machine_technology';
    case NewProductProcess = 'new_product_process';
    case LegalCertification = 'legal_certification';
    case CustomerRequirement = 'customer_requirement';
    case PerformanceImprovement = 'performance_improvement';
    case SuccessionBackup = 'succession_backup';
    case TransferPromotion = 'transfer_promotion';
}
