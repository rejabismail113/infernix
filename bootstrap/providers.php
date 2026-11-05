<?php

declare(strict_types=1);
use App\Kernel\KernelServiceProvider;
use App\Modules\Activities\ActivitiesServiceProvider;
use App\Modules\Administration\AdministrationServiceProvider;
use App\Modules\Assets\AssetsServiceProvider;
use App\Modules\Certifications\CertificationsServiceProvider;
use App\Modules\Commercial\CommercialServiceProvider;
use App\Modules\Credentials\CredentialsServiceProvider;
use App\Modules\Csr\CsrServiceProvider;
use App\Modules\DocumentCentre\DocumentCentreServiceProvider;
use App\Modules\Finance\FinanceServiceProvider;
use App\Modules\HumanResources\HumanResourcesServiceProvider;
use App\Modules\Procurement\ProcurementServiceProvider;
use App\Modules\Projects\ProjectsServiceProvider;
use App\Modules\Reporting\ReportingServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    KernelServiceProvider::class,
    FinanceServiceProvider::class,
    ProjectsServiceProvider::class,
    CommercialServiceProvider::class,
    HumanResourcesServiceProvider::class,
    AssetsServiceProvider::class,
    DocumentCentreServiceProvider::class,
    ActivitiesServiceProvider::class,
    CsrServiceProvider::class,
    CertificationsServiceProvider::class,
    ProcurementServiceProvider::class,
    ReportingServiceProvider::class,
    AdministrationServiceProvider::class,
    CredentialsServiceProvider::class,
];
