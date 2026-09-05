<?php

return [
    // Initial read seam: one explicitly published company-wide version per
    // tenant/company. Empty by default; no inferred eligibility or pay promise.
    // Shape: [tenantId => [companyId => ['policy_id' => '...', 'version' => '...']]].
    // This bootstrap source is not a publication workflow. Narrower scopes,
    // effective-dated selection and durable approvals require later plan 0004 work.
    'published_policies' => [],
];
